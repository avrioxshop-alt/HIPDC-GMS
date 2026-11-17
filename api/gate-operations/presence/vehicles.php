<?php

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/gate_operations.php';

requireRequestMethod('GET');
$actor = requireOperationalPermission('gate_operations.view');
$connection = getDatabaseConnection();
requireAnyGateOperationAssignment($connection, $actor);
$pagination = getPaginationParameters();
$search = getSearchParameter();
$parameters = [
    'scope_role_id' => $actor['role']['id'],
    'scope_user_id' => $actor['id'],
];
$where = [
    accessEventScopeSql(),
    'e.access_result=\'granted\'',
    'e.subject_type=\'vehicle\'',
    'e.event_type=\'entry\'',
    'e.id=(
        SELECT latest.id FROM access_events latest
        WHERE latest.vehicle_id=e.vehicle_id
          AND latest.subject_type=\'vehicle\'
          AND latest.access_result=\'granted\'
        ORDER BY latest.event_at DESC,latest.id DESC LIMIT 1
    )',
];
if ($search !== '') {
    $like = '%' . addcslashes($search, '\\_%') . '%';
    $where[] = '(v.plate_number LIKE :plate OR c.name LIKE :company OR d.full_name LIKE :driver)';
    $parameters['plate'] = $like;
    $parameters['company'] = $like;
    $parameters['driver'] = $like;
}
$whereSql = ' WHERE ' . implode(' AND ', $where);
$from = ' FROM access_events e
          JOIN vehicles v ON v.id=e.vehicle_id
          LEFT JOIN companies c ON c.id=v.company_id
          LEFT JOIN drivers d ON d.id=e.driver_id
          JOIN gates g ON g.id=e.gate_id
          LEFT JOIN lanes l ON l.id=e.lane_id';
$count = $connection->prepare('SELECT COUNT(*)' . $from . $whereSql);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT v.id AS vehicle_id,v.plate_number,v.vehicle_type,v.company_id,c.name AS company_name,
            e.driver_id,d.full_name AS driver_name,e.gate_id,g.name AS gate_name,
            e.lane_id,l.name AS lane_name,e.reference AS entry_event_reference,e.event_at AS entered_at
    ' . $from . $whereSql
    . ' ORDER BY e.event_at DESC,e.id DESC LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$list->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$list->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$list->execute();
$vehicles = $list->fetchAll();
foreach ($vehicles as &$vehicle) {
    foreach (['vehicle_id', 'company_id', 'driver_id', 'gate_id', 'lane_id'] as $field) {
        $vehicle[$field] = $vehicle[$field] === null ? null : (int) $vehicle[$field];
    }
}
unset($vehicle);
successResponse([
    'vehicles' => $vehicles,
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Current vehicle presence loaded.');
