<?php

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/gate_operations.php';

requireRequestMethod('GET');
$actor = requireOperationalPermission('gate_operations.view');
$pagination = getPaginationParameters();
try {
    $visitorId = parsePositiveId($_GET['visitor_id'] ?? null, 'visitor_id');
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

$connection = getDatabaseConnection();
$visitor = visitorRecord($connection, $visitorId);
if ($visitor === null) {
    notFoundResponse('Visitor not found.');
}
$scope = accessEventScopeSql();
$parameters = [
    'visitor_id' => $visitorId,
    'scope_role_id' => $actor['role']['id'],
    'scope_user_id' => $actor['id'],
];
$from = ' FROM access_events e
          JOIN gates g ON g.id=e.gate_id
          LEFT JOIN lanes l ON l.id=e.lane_id
          LEFT JOIN users u ON u.id=e.actor_id
          LEFT JOIN gate_devices dev ON dev.id=e.device_id
          LEFT JOIN vehicles v ON v.id=e.vehicle_id
          LEFT JOIN goods_declarations gd ON gd.id=e.goods_declaration_id';
$where = ' WHERE e.visitor_id=:visitor_id AND ' . $scope;
$count = $connection->prepare('SELECT COUNT(*)' . $from . $where);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT e.id,e.reference,e.subject_type,e.event_type,e.gate_id,g.name AS gate_name,
            e.lane_id,l.name AS lane_name,e.visitor_pass_id,vp.reference AS pass_reference,
            e.vehicle_id,v.plate_number,e.goods_declaration_id,gd.reference AS goods_reference,
            e.actor_id,u.full_name AS actor_name,e.device_id,dev.name AS device_name,
            e.access_result,e.denial_reason,e.access_method,e.event_at'
    . $from
    . ' LEFT JOIN visitor_passes vp ON vp.id=e.visitor_pass_id'
    . $where
    . ' ORDER BY e.event_at DESC,e.id DESC LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$list->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$list->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$list->execute();
$events = $list->fetchAll();
foreach ($events as &$event) {
    foreach (
        ['id', 'gate_id', 'lane_id', 'visitor_pass_id', 'vehicle_id', 'goods_declaration_id',
         'actor_id', 'device_id'] as $field
    ) {
        $event[$field] = $event[$field] === null ? null : (int) $event[$field];
    }
}
unset($event);
successResponse([
    'visitor' => [
        'id' => $visitor['id'],
        'reference' => $visitor['reference'],
        'full_name' => $visitor['full_name'],
    ],
    'events' => $events,
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Visitor access history loaded.');
