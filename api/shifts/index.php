<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supporting.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    $actor = requireAdministrator('shifts.manage');
    $data = requireJsonPostWithCsrf();
    $connection = getDatabaseConnection();
    try {
        $shift = validateShiftFields($connection, $data);
        $connection->beginTransaction();
        lockShiftResources($connection, $shift['officer_id'], $shift['gate_id']);
        $shift = validateShiftFields($connection, $data);
        assertNoShiftConflict($connection, $shift);
        $insert = $connection->prepare(
            'INSERT INTO officer_shifts
                (reference,officer_id,gate_id,lane_id,direction,starts_at,ends_at,notes,assigned_by)
             VALUES
                (:reference,:officer_id,:gate_id,:lane_id,:direction,:starts_at,:ends_at,:notes,:assigned_by)'
        );
        $insert->execute([
            'reference' => makeReference('SHIFT'),
            'officer_id' => $shift['officer_id'],
            'gate_id' => $shift['gate_id'],
            'lane_id' => $shift['lane_id'],
            'direction' => $shift['direction'],
            'starts_at' => $shift['starts_at'],
            'ends_at' => $shift['ends_at'],
            'notes' => $shift['notes'],
            'assigned_by' => $actor['id'],
        ]);
        $id = (int) $connection->lastInsertId();
        writeOperationalAudit($connection, $actor['id'], 'officer_shift.create', 'officer_shift', $id);
        createUserNotification(
            $connection,
            $shift['officer_id'],
            'shift',
            'Officer shift assigned',
            'A new gate shift has been assigned to you.',
            'officer_shift',
            $id,
            $actor['id']
        );
        $connection->commit();
    } catch (DomainException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        jsonResponse(false, $exception->getMessage(), [], 409);
    } catch (InvalidArgumentException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        handleOperationalValidation($exception);
    } catch (PDOException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        respondToOperationalConflict($exception, 'The Officer shift conflicts with an existing record.');
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }

    successResponse(['shift' => officerShiftRecord($connection, $id)], 'Officer shift assigned.', 201);
}

if ($method !== 'GET') {
    header('Allow: GET, POST');
    jsonResponse(false, 'The request method is not allowed.', [], 405);
}

$actor = requireOperationalPermission('shifts.view');
$pagination = getPaginationParameters();
$search = getSearchParameter();
$status = getOperationalStatusFilter(['scheduled', 'cancelled']);
$where = [];
$parameters = [];
if ($actor['role']['code'] !== 'admin') {
    $where[] = 's.officer_id=:officer_id';
    $parameters['officer_id'] = $actor['id'];
}
if ($status !== null) {
    $where[] = 's.status=:status';
    $parameters['status'] = $status;
}
if ($search !== '') {
    $where[] = '(s.reference LIKE :reference OR u.full_name LIKE :officer
        OR g.name LIKE :gate OR l.name LIKE :lane)';
    $like = '%' . addcslashes($search, '\\_%') . '%';
    $parameters['reference'] = $like;
    $parameters['officer'] = $like;
    $parameters['gate'] = $like;
    $parameters['lane'] = $like;
}
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$from = ' FROM officer_shifts s
          JOIN users u ON u.id=s.officer_id
          JOIN gates g ON g.id=s.gate_id
          LEFT JOIN lanes l ON l.id=s.lane_id';
$connection = getDatabaseConnection();
$count = $connection->prepare('SELECT COUNT(*)' . $from . $whereSql);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT s.id,s.reference,s.officer_id,u.full_name AS officer_name,
            s.gate_id,g.code AS gate_code,g.name AS gate_name,s.lane_id,
            l.code AS lane_code,l.name AS lane_name,s.direction,s.starts_at,s.ends_at,
            s.status,s.notes,s.assigned_by,s.created_at,s.updated_at'
    . $from . $whereSql . ' ORDER BY s.starts_at DESC,s.id DESC LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$list->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$list->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$list->execute();
$shifts = $list->fetchAll();
foreach ($shifts as &$shift) {
    foreach (['id', 'officer_id', 'gate_id', 'lane_id', 'assigned_by'] as $field) {
        $shift[$field] = $shift[$field] === null ? null : (int) $shift[$field];
    }
}
unset($shift);
successResponse([
    'shifts' => $shifts,
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Officer shifts loaded.');
