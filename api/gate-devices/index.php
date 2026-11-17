<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/gate_devices.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    jsonResponse(false, 'The request method is not allowed.', [], 405);
}
$actor = requireAdministrator('gate_devices.manage');
$connection = getDatabaseConnection();

if ($method === 'POST') {
    $data = requireJsonPostWithCsrf();
    try {
        $name = validateOperationalText($data, 'name', 120, true);
        $deviceType = $data['device_type'] ?? null;
        if (
            !is_string($deviceType)
            || !in_array($deviceType, ['qr_scanner', 'anpr', 'camera', 'gate_controller'], true)
        ) {
            throw new InvalidArgumentException('Device type is invalid.');
        }
        $gateId = validateOptionalId($data, 'gate_id');
        $laneId = validateOptionalId($data, 'lane_id');
        $direction = $data['direction'] ?? null;
        if (
            $gateId === null
            || $laneId === null
            || !is_string($direction)
            || !in_array($direction, ['entry', 'exit'], true)
        ) {
            throw new InvalidArgumentException('Gate, lane, and direction are required.');
        }
    } catch (InvalidArgumentException $exception) {
        handleOperationalValidation($exception);
    }

    requireGateDeviceScope($connection, $actor, $gateId, $direction);
    try {
        $connection->beginTransaction();
        $gate = $connection->prepare(
            'SELECT status,supports_entry,supports_exit FROM gates WHERE id=:id FOR UPDATE'
        );
        $gate->execute(['id' => $gateId]);
        $gateRecord = $gate->fetch();
        $lane = $connection->prepare(
            'SELECT gate_id,direction,status FROM lanes WHERE id=:id FOR UPDATE'
        );
        $lane->execute(['id' => $laneId]);
        $laneRecord = $lane->fetch();
        if (!$gateRecord || $gateRecord['status'] !== 'active') {
            throw new InvalidArgumentException('An active gate is required.');
        }
        if (
            ($direction === 'entry' && (int) $gateRecord['supports_entry'] !== 1)
            || ($direction === 'exit' && (int) $gateRecord['supports_exit'] !== 1)
        ) {
            throw new InvalidArgumentException('The gate does not support this direction.');
        }
        if (
            !$laneRecord
            || (int) $laneRecord['gate_id'] !== $gateId
            || $laneRecord['status'] !== 'active'
            || ($laneRecord['direction'] !== 'both' && $laneRecord['direction'] !== $direction)
        ) {
            throw new InvalidArgumentException('An active lane supporting this gate direction is required.');
        }

        $created = createGateDevice(
            $connection,
            $actor['id'],
            $name,
            $deviceType,
            $gateId,
            $laneId,
            $direction
        );
        writeOperationalAudit(
            $connection,
            $actor['id'],
            'gate_device.create',
            'gate_device',
            $created['device']['id'],
            ['device_type' => $deviceType, 'gate_id' => $gateId, 'lane_id' => $laneId]
        );
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
        throw $exception;
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }

    successResponse($created, 'Device registered. Copy its token now; it will not be shown again.', 201);
}

if ($method !== 'GET') {
    header('Allow: GET, POST');
    jsonResponse(false, 'The request method is not allowed.', [], 405);
}

$pagination = getPaginationParameters();
$status = getOperationalStatusFilter(['active', 'inactive']);
$where = [];
$parameters = ['scope_user' => $actor['id']];
if ($status !== null) {
    $where[] = 'd.status=:status';
    $parameters['status'] = $status;
}
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$scopeSql = 'EXISTS (
    SELECT 1
    FROM user_gate_assignments uga
    JOIN role_permissions rp ON rp.role_id=:scope_role
    JOIN permissions p ON p.id=rp.permission_id
    WHERE uga.user_id=:scope_user
      AND uga.gate_id=d.gate_id
      AND uga.direction IN (d.direction,\'both\')
      AND p.code=CONCAT(\'gate.\',d.direction)
)';
$actor['role']['id'] = (int) $actor['role']['id'];
$parameters['scope_role'] = $actor['role']['id'];
$from = ' FROM gate_devices d
          JOIN gates g ON g.id=d.gate_id
          JOIN lanes l ON l.id=d.lane_id
          JOIN users u ON u.id=d.created_by';
$filterSql = $where === [] ? '' : ' AND ' . implode(' AND ', $where);
$count = $connection->prepare(
    'SELECT COUNT(*)' . $from . ' WHERE ' . $scopeSql . $filterSql
);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT d.id,d.public_id,d.name,d.device_type,d.gate_id,g.code AS gate_code,
            g.name AS gate_name,d.lane_id,l.code AS lane_code,l.name AS lane_name,
            d.direction,d.status,d.last_sequence,d.created_at,d.updated_at'
    . $from
    . ' WHERE ' . $scopeSql
    . $filterSql
    . ' ORDER BY g.name,l.name,d.name,d.id LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$list->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$list->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$list->execute();
$devices = $list->fetchAll();
foreach ($devices as &$device) {
    foreach (['id', 'gate_id', 'lane_id', 'last_sequence'] as $field) {
        $device[$field] = (int) $device[$field];
    }
}
unset($device);
successResponse([
    'devices' => $devices,
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Gate devices loaded.');
