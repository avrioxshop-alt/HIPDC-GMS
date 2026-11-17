<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/gate_operations.php';

requireRequestMethod('GET');
$actor = requireOperationalPermission('gate_operations.view');
$pagination = getPaginationParameters();
$connection = getDatabaseConnection();
try {
    $type = $_GET['type'] ?? null;
    if (!is_string($type) || !in_array($type, ['vehicle', 'driver', 'visitor', 'goods'], true)) {
        throw new InvalidArgumentException('Lookup type must be vehicle, driver, visitor, or goods.');
    }
    $query = getSearchParameter();
    if ($query === '') {
        throw new InvalidArgumentException('A non-empty q search value is required.');
    }
    $gateId = parsePositiveId($_GET['gate_id'] ?? null, 'gate_id');
    $laneId = validateOptionalId($_GET, 'lane_id');
    $direction = $_GET['direction'] ?? null;
    if (!is_string($direction) || !in_array($direction, ['entry', 'exit'], true)) {
        throw new InvalidArgumentException('Direction must be entry or exit.');
    }
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
if (!userCanAccessGate($connection, $actor['id'], $gateId, $direction)) {
    forbiddenResponse('You are not assigned to this gate direction.');
}
if ($laneId !== null) {
    try {
        $lane = validateLaneReference($connection, $laneId, $gateId, $direction);
        if ($lane['status'] !== 'active') {
            throw new InvalidArgumentException('The selected lane is inactive.');
        }
    } catch (InvalidArgumentException $exception) {
        handleOperationalValidation($exception);
    }
}

$limit = $pagination['per_page'];
$offset = $pagination['offset'];
$like = '%' . addcslashes($query, '\\_%') . '%';
$items = [];
$total = 0;
if ($type === 'vehicle') {
    try {
        [, $normalized] = normalizeGatePlate($query);
    } catch (InvalidArgumentException $exception) {
        handleOperationalValidation($exception);
    }
    $count = $connection->prepare(
        'SELECT COUNT(*) FROM vehicles v
         LEFT JOIN companies c ON c.id=v.company_id
         WHERE v.plate_normalized=:plate AND v.status=\'active\'
           AND (c.id IS NULL OR c.status=\'active\')'
    );
    $count->execute(['plate' => $normalized]);
    $total = (int) $count->fetchColumn();
    $list = $connection->prepare(
        'SELECT v.id,v.plate_number,v.vehicle_type,v.company_id,c.name AS company_name,
                v.assigned_driver_id,d.full_name AS assigned_driver_name,v.status
         FROM vehicles v
         LEFT JOIN companies c ON c.id=v.company_id
         LEFT JOIN drivers d ON d.id=v.assigned_driver_id
         WHERE v.plate_normalized=:plate AND v.status=\'active\'
           AND (c.id IS NULL OR c.status=\'active\')
         ORDER BY v.id LIMIT :limit OFFSET :offset'
    );
    $list->bindValue(':plate', $normalized, PDO::PARAM_STR);
    $list->bindValue(':limit', $limit, PDO::PARAM_INT);
    $list->bindValue(':offset', $offset, PDO::PARAM_INT);
    $list->execute();
    $items = $list->fetchAll();
} elseif ($type === 'driver') {
    $count = $connection->prepare(
        'SELECT COUNT(*) FROM drivers d LEFT JOIN companies c ON c.id=d.company_id
         WHERE d.status=\'active\' AND (c.id IS NULL OR c.status=\'active\')
           AND (d.full_name LIKE :name OR d.license_number LIKE :license)'
    );
    $count->execute(['name' => $like, 'license' => $like]);
    $total = (int) $count->fetchColumn();
    $list = $connection->prepare(
        'SELECT d.id,d.full_name,d.license_number,d.company_id,c.name AS company_name,d.status
         FROM drivers d LEFT JOIN companies c ON c.id=d.company_id
         WHERE d.status=\'active\' AND (c.id IS NULL OR c.status=\'active\')
           AND (d.full_name LIKE :name OR d.license_number LIKE :license)
         ORDER BY d.full_name,d.id LIMIT :limit OFFSET :offset'
    );
    $list->bindValue(':name', $like, PDO::PARAM_STR);
    $list->bindValue(':license', $like, PDO::PARAM_STR);
    $list->bindValue(':limit', $limit, PDO::PARAM_INT);
    $list->bindValue(':offset', $offset, PDO::PARAM_INT);
    $list->execute();
    $items = $list->fetchAll();
} elseif ($type === 'visitor') {
    $count = $connection->prepare(
        'SELECT COUNT(DISTINCT v.id)
         FROM visitors v
         LEFT JOIN companies c ON c.id=v.company_id
         JOIN visitor_passes vp ON vp.visitor_id=v.id
         JOIN approval_requests ar ON ar.visitor_pass_id=vp.id
         WHERE v.status=\'active\' AND (c.id IS NULL OR c.status=\'active\')
           AND vp.status=\'active\' AND ar.status=\'approved\'
           AND vp.gate_id=:gate_id AND vp.direction=:direction
           AND vp.valid_from<=CURRENT_TIMESTAMP AND vp.expires_at>CURRENT_TIMESTAMP
           AND v.expected_arrival_at<=CURRENT_TIMESTAMP
           AND (v.expected_departure_at IS NULL OR v.expected_departure_at>=CURRENT_TIMESTAMP)
           AND (v.full_name LIKE :name OR v.reference LIKE :reference OR vp.reference LIKE :pass_reference)'
    );
    $count->execute([
        'gate_id' => $gateId,
        'direction' => $direction,
        'name' => $like,
        'reference' => $like,
        'pass_reference' => $like,
    ]);
    $total = (int) $count->fetchColumn();
    $list = $connection->prepare(
        'SELECT DISTINCT v.id,v.reference,v.full_name,v.company_id,c.name AS company_name,
                vp.reference AS pass_reference,v.expected_arrival_at,v.expected_departure_at
         FROM visitors v
         LEFT JOIN companies c ON c.id=v.company_id
         JOIN visitor_passes vp ON vp.visitor_id=v.id
         JOIN approval_requests ar ON ar.visitor_pass_id=vp.id
         WHERE v.status=\'active\' AND (c.id IS NULL OR c.status=\'active\')
           AND vp.status=\'active\' AND ar.status=\'approved\'
           AND vp.gate_id=:gate_id AND vp.direction=:direction
           AND vp.valid_from<=CURRENT_TIMESTAMP AND vp.expires_at>CURRENT_TIMESTAMP
           AND v.expected_arrival_at<=CURRENT_TIMESTAMP
           AND (v.expected_departure_at IS NULL OR v.expected_departure_at>=CURRENT_TIMESTAMP)
           AND (v.full_name LIKE :name OR v.reference LIKE :reference OR vp.reference LIKE :pass_reference)
         ORDER BY v.full_name,v.id LIMIT :limit OFFSET :offset'
    );
    $list->bindValue(':gate_id', $gateId, PDO::PARAM_INT);
    $list->bindValue(':direction', $direction, PDO::PARAM_STR);
    $list->bindValue(':name', $like, PDO::PARAM_STR);
    $list->bindValue(':reference', $like, PDO::PARAM_STR);
    $list->bindValue(':pass_reference', $like, PDO::PARAM_STR);
    $list->bindValue(':limit', $limit, PDO::PARAM_INT);
    $list->bindValue(':offset', $offset, PDO::PARAM_INT);
    $list->execute();
    $items = $list->fetchAll();
} else {
    $laneFilter = $laneId === null
        ? 'gd.lane_id IS NULL'
        : '(gd.lane_id IS NULL OR gd.lane_id=:lane_id)';
    $count = $connection->prepare(
        'SELECT COUNT(*) FROM goods_declarations gd
         JOIN companies c ON c.id=gd.company_id
         WHERE gd.status=\'approved\' AND c.status=\'active\'
           AND gd.direction=:direction
           AND (gd.gate_id IS NULL OR gd.gate_id=:gate_id)
           AND ' . $laneFilter . '
           AND NOT EXISTS (
               SELECT 1 FROM access_events completed
               WHERE completed.goods_declaration_id=gd.id
                 AND completed.access_result=\'granted\'
           )
           AND (gd.reference LIKE :reference OR c.name LIKE :company)'
    );
    $count->execute([
        'direction' => $direction,
        'gate_id' => $gateId,
        'reference' => $like,
        'company' => $like,
    ] + ($laneId === null ? [] : ['lane_id' => $laneId]));
    $total = (int) $count->fetchColumn();
    $list = $connection->prepare(
        'SELECT gd.id,gd.reference,gd.direction,gd.company_id,c.name AS company_name,
                gd.vehicle_id,v.plate_number,gd.driver_id,d.full_name AS driver_name,
                gd.gate_id,gd.lane_id,gd.status
         FROM goods_declarations gd
         JOIN companies c ON c.id=gd.company_id
         LEFT JOIN vehicles v ON v.id=gd.vehicle_id
         LEFT JOIN drivers d ON d.id=gd.driver_id
         WHERE gd.status=\'approved\' AND c.status=\'active\'
           AND gd.direction=:direction
           AND (gd.gate_id IS NULL OR gd.gate_id=:gate_id)
           AND ' . $laneFilter . '
           AND NOT EXISTS (
               SELECT 1 FROM access_events completed
               WHERE completed.goods_declaration_id=gd.id
                 AND completed.access_result=\'granted\'
           )
           AND (gd.reference LIKE :reference OR c.name LIKE :company)
         ORDER BY gd.created_at DESC,gd.id DESC LIMIT :limit OFFSET :offset'
    );
    foreach (
        [
            'direction' => $direction,
            'gate_id' => $gateId,
            'reference' => $like,
            'company' => $like,
        ] + ($laneId === null ? [] : ['lane_id' => $laneId])
        as $key => $value
    ) {
        $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $list->bindValue(':limit', $limit, PDO::PARAM_INT);
    $list->bindValue(':offset', $offset, PDO::PARAM_INT);
    $list->execute();
    $items = $list->fetchAll();
}

foreach ($items as &$item) {
    foreach (['id', 'company_id', 'assigned_driver_id', 'vehicle_id', 'driver_id', 'gate_id', 'lane_id'] as $field) {
        if (array_key_exists($field, $item)) {
            $item[$field] = $item[$field] === null ? null : (int) $item[$field];
        }
    }
}
unset($item);
successResponse([
    'items' => $items,
    'pagination' => paginationResult($pagination, $total),
], 'Gate lookup completed.');
