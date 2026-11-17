<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/visitor_goods.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    $actor = requireOperationalPermission('goods.create');
    $data = requireJsonPostWithCsrf();
    try {
        $direction = $data['direction'] ?? null;
        if (!is_string($direction) || !in_array($direction, ['entry', 'exit'], true)) {
            throw new InvalidArgumentException('Direction must be entry or exit.');
        }
        $companyId = validateOptionalId($data, 'company_id');
        if ($companyId === null) {
            throw new InvalidArgumentException('Company is required.');
        }
        $vehicleId = validateOptionalId($data, 'vehicle_id');
        $driverId = validateOptionalId($data, 'driver_id');
        $scheduleId = validateOptionalId($data, 'schedule_id');
        $gateId = validateOptionalId($data, 'gate_id');
        $laneId = validateOptionalId($data, 'lane_id');
        $purpose = validateOperationalText($data, 'purpose', 255, true);
        $items = validateGoodsItems($data['items'] ?? null);
    } catch (InvalidArgumentException $exception) {
        handleOperationalValidation($exception);
    }

    $connection = getDatabaseConnection();
    try {
        $connection->beginTransaction();
        validateGoodsReferences(
            $connection,
            $actor,
            $companyId,
            $vehicleId,
            $driverId,
            $scheduleId,
            $gateId,
            $laneId,
            $direction
        );
        $insert = $connection->prepare(
            'INSERT INTO goods_declarations
                (reference,direction,company_id,vehicle_id,driver_id,schedule_id,gate_id,lane_id,
                 purpose,submitted_by)
             VALUES
                (:reference,:direction,:company_id,:vehicle_id,:driver_id,:schedule_id,:gate_id,:lane_id,
                 :purpose,:submitted_by)'
        );
        $insert->execute([
            'reference' => makeReference('GDS'),
            'direction' => $direction,
            'company_id' => $companyId,
            'vehicle_id' => $vehicleId,
            'driver_id' => $driverId,
            'schedule_id' => $scheduleId,
            'gate_id' => $gateId,
            'lane_id' => $laneId,
            'purpose' => $purpose,
            'submitted_by' => $actor['id'],
        ]);
        $id = (int) $connection->lastInsertId();
        insertGoodsItems($connection, $id, $items);
        writeOperationalAudit(
            $connection,
            $actor['id'],
            'goods.create',
            'goods_declaration',
            $id,
            ['item_count' => count($items)]
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
        respondToOperationalConflict($exception, 'Goods declaration references conflict with existing records.');
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
    successResponse(
        ['declaration' => goodsDeclarationRecord($connection, $id)],
        'Goods declaration draft created.',
        201
    );
}

if ($method !== 'GET') {
    header('Allow: GET, POST');
    jsonResponse(false, 'The request method is not allowed.', [], 405);
}

$actor = requireOperationalPermission('goods.view');
$pagination = getPaginationParameters();
$search = getSearchParameter();
$status = getOperationalStatusFilter(['draft', 'pending', 'approved', 'denied', 'cancelled']);
$where = [];
$parameters = [];
if ($status !== null) {
    $where[] = 'd.status=:status';
    $parameters['status'] = $status;
}
if ($search !== '') {
    $where[] = '(d.reference LIKE :reference OR d.purpose LIKE :purpose
        OR c.name LIKE :company OR v.plate_number LIKE :plate
        OR dr.full_name LIKE :driver OR EXISTS
            (SELECT 1 FROM goods_items gi WHERE gi.declaration_id=d.id
             AND (gi.description LIKE :item_description OR gi.item_code LIKE :item_code)))';
    $like = '%' . addcslashes($search, '\\_%') . '%';
    foreach (['reference', 'purpose', 'company', 'plate', 'driver', 'item_description', 'item_code'] as $field) {
        $parameters[$field] = $like;
    }
}
if ($actor['role']['code'] !== 'admin') {
    $where[] = 'd.submitted_by=:scope_user';
    $parameters['scope_user'] = $actor['id'];
}
$where[] = '(d.gate_id IS NULL OR EXISTS (
    SELECT 1 FROM user_gate_assignments uga
    JOIN gates scope_gate ON scope_gate.id=uga.gate_id
    JOIN role_permissions rp ON rp.role_id=:scope_role
    JOIN permissions permission ON permission.id=rp.permission_id
    WHERE uga.user_id=:scope_gate_user AND uga.gate_id=d.gate_id
      AND uga.direction IN (d.direction,\'both\')
      AND permission.code=CONCAT(\'gate.\',d.direction)
      AND scope_gate.status=\'active\'
      AND ((d.direction=\'entry\' AND scope_gate.supports_entry=1)
        OR (d.direction=\'exit\' AND scope_gate.supports_exit=1))
))';
$parameters['scope_role'] = $actor['role']['id'];
$parameters['scope_gate_user'] = $actor['id'];
$whereSql = ' WHERE ' . implode(' AND ', $where);
$from = ' FROM goods_declarations d
          JOIN companies c ON c.id=d.company_id
          LEFT JOIN vehicles v ON v.id=d.vehicle_id
          LEFT JOIN drivers dr ON dr.id=d.driver_id';
$connection = getDatabaseConnection();
$count = $connection->prepare('SELECT COUNT(*)' . $from . $whereSql);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT d.id,d.reference,d.direction,d.company_id,c.name AS company_name,
            d.vehicle_id,v.plate_number,d.driver_id,dr.full_name AS driver_name,
            d.schedule_id,d.gate_id,d.lane_id,d.purpose,d.submitted_by,
            d.submitted_at,d.status,d.created_at,d.updated_at,
            (SELECT COUNT(*) FROM goods_items gi WHERE gi.declaration_id=d.id) AS item_count'
    . $from . $whereSql . ' ORDER BY d.created_at DESC,d.id DESC LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$list->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$list->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$list->execute();
$declarations = $list->fetchAll();
foreach ($declarations as &$declaration) {
    foreach (['id', 'company_id', 'vehicle_id', 'driver_id', 'schedule_id', 'gate_id', 'lane_id', 'submitted_by', 'item_count'] as $field) {
        $declaration[$field] = $declaration[$field] === null ? null : (int) $declaration[$field];
    }
}
unset($declaration);
successResponse([
    'declarations' => $declarations,
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Goods declarations loaded.');
