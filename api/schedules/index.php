<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    $actor = requireOperationalPermission('schedules.create');
    $data = requireJsonPostWithCsrf();
    try {
        $companyId = validateOptionalId($data, 'company_id');
        $vehicleId = validateOptionalId($data, 'vehicle_id');
        $driverId = validateOptionalId($data, 'driver_id');
        if ($vehicleId === null || $driverId === null) {
            throw new InvalidArgumentException('Vehicle and driver are required.');
        }
        $gateId = validateOptionalId($data, 'gate_id');
        $laneId = validateOptionalId($data, 'lane_id');
        $direction = $data['direction'] ?? null;
        if (!is_string($direction) || !in_array($direction, ['entry', 'exit'], true)) {
            throw new InvalidArgumentException('Direction must be entry or exit.');
        }
        $scheduledAt = validateOperationalDateTime($data['scheduled_at'] ?? null);
        $purpose = validateOperationalText($data, 'purpose', 120, true);
        $cargoSummary = validateOperationalText($data, 'cargo_summary', 500);
    } catch (InvalidArgumentException $exception) {
        handleOperationalValidation($exception);
    }

    $connection = getDatabaseConnection();
    try {
        $connection->beginTransaction();
        $vehicle = validateVehicleReference($connection, $vehicleId, $companyId);
        $driver = validateDriverReference($connection, $driverId, $companyId);
        if ($companyId === null) {
            $vehicleCompanyId = $vehicle['company_id'] === null ? null : (int) $vehicle['company_id'];
            $driverCompanyId = $driver['company_id'] === null ? null : (int) $driver['company_id'];
            if ($vehicleCompanyId !== null && $driverCompanyId !== null && $vehicleCompanyId !== $driverCompanyId) {
                throw new InvalidArgumentException('Vehicle and driver belong to different companies.');
            }
            $companyId = $vehicleCompanyId ?? $driverCompanyId;
        }
        validateCompanyReference($connection, $companyId);
        $lane = validateLaneReference($connection, $laneId, $gateId, $direction);
        if ($gateId !== null) {
            validateGateDirection($connection, $gateId, $direction);
            requireAssignedGateAccess($actor, $gateId, $direction);
            if ($lane !== null && $lane['status'] !== 'active') {
                throw new InvalidArgumentException('The selected lane is inactive.');
            }
        }
        $insert = $connection->prepare(
            'INSERT INTO schedules
                (reference,company_id,vehicle_id,driver_id,gate_id,lane_id,direction,
                 scheduled_at,purpose,cargo_summary,created_by)
             VALUES
                (:reference,:company_id,:vehicle_id,:driver_id,:gate_id,:lane_id,:direction,
                 :scheduled_at,:purpose,:cargo_summary,:created_by)'
        );
        $insert->execute([
            'reference' => makeReference('SCH'),
            'company_id' => $companyId,
            'vehicle_id' => $vehicleId,
            'driver_id' => $driverId,
            'gate_id' => $gateId,
            'lane_id' => $laneId,
            'direction' => $direction,
            'scheduled_at' => $scheduledAt,
            'purpose' => $purpose,
            'cargo_summary' => $cargoSummary,
            'created_by' => $actor['id'],
        ]);
        $id = (int) $connection->lastInsertId();
        writeOperationalAudit($connection, $actor['id'], 'schedule.create', 'schedule', $id);
        $connection->commit();
    } catch (InvalidArgumentException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        handleOperationalValidation($exception);
    } catch (PDOException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        respondToOperationalConflict($exception, 'Schedule references conflict with existing records.');
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
    successResponse(['schedule' => scheduleRecord($connection, $id)], 'Schedule created.', 201);
}

if ($method !== 'GET') {
    header('Allow: GET, POST');
    jsonResponse(false, 'The request method is not allowed.', [], 405);
}

$actor = requireOperationalPermission('schedules.view');
$pagination = getPaginationParameters();
$search = getSearchParameter();
$status = getOperationalStatusFilter(['scheduled', 'cancelled']);
$where = [];
$parameters = [];
if ($status !== null) {
    $where[] = 's.status=:status';
    $parameters['status'] = $status;
}
if ($search !== '') {
    $where[] = '(s.reference LIKE :reference OR v.plate_number LIKE :plate OR c.name LIKE :company OR d.full_name LIKE :driver)';
    $like = '%' . addcslashes($search, '\\_%') . '%';
    foreach (['reference', 'plate', 'company', 'driver'] as $field) {
        $parameters[$field] = $like;
    }
}
if ($actor['role']['code'] !== 'admin') {
    $where[] = '(s.gate_id IS NULL OR EXISTS (
        SELECT 1 FROM user_gate_assignments uga
        JOIN gates assigned_gate ON assigned_gate.id=uga.gate_id
        JOIN role_permissions rp ON rp.role_id=:scope_role
        JOIN permissions permission ON permission.id=rp.permission_id
        WHERE uga.user_id=:scope_user AND uga.gate_id=s.gate_id
          AND uga.direction IN (s.direction,\'both\')
          AND permission.code=CONCAT(\'gate.\',s.direction)
          AND assigned_gate.status=\'active\'
          AND ((s.direction=\'entry\' AND assigned_gate.supports_entry=1)
            OR (s.direction=\'exit\' AND assigned_gate.supports_exit=1))
    ))';
    $parameters['scope_role'] = $actor['role']['id'];
    $parameters['scope_user'] = $actor['id'];
}
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$from = ' FROM schedules s
          LEFT JOIN companies c ON c.id=s.company_id
          JOIN vehicles v ON v.id=s.vehicle_id
          JOIN drivers d ON d.id=s.driver_id';
$connection = getDatabaseConnection();
$count = $connection->prepare('SELECT COUNT(*)' . $from . $whereSql);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT s.id,s.reference,s.company_id,c.name AS company_name,s.vehicle_id,
            v.plate_number,v.vehicle_type,v.make,v.model,s.driver_id,d.full_name AS driver_name,
            s.gate_id,g.name AS gate_name,s.lane_id,l.name AS lane_name,s.direction,
            s.scheduled_at,s.purpose,s.cargo_summary,s.status,s.created_at,s.updated_at'
    . $from . ' LEFT JOIN gates g ON g.id=s.gate_id LEFT JOIN lanes l ON l.id=s.lane_id'
    . $whereSql . ' ORDER BY s.scheduled_at DESC,s.id DESC LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$list->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$list->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$list->execute();
$schedules = $list->fetchAll();
foreach ($schedules as &$schedule) {
    foreach (['id','company_id','vehicle_id','driver_id','gate_id','lane_id'] as $field) {
        $schedule[$field] = $schedule[$field] === null ? null : (int) $schedule[$field];
    }
}
unset($schedule);
successResponse([
    'schedules' => $schedules,
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Schedules loaded.');
