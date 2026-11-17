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
    'e.subject_type=\'visitor\'',
    'e.event_type=\'entry\'',
    'e.id=(
        SELECT latest.id FROM access_events latest
        WHERE latest.visitor_id=e.visitor_id
          AND latest.subject_type=\'visitor\'
          AND latest.access_result=\'granted\'
        ORDER BY latest.event_at DESC,latest.id DESC LIMIT 1
    )',
];
if ($search !== '') {
    $like = '%' . addcslashes($search, '\\_%') . '%';
    $where[] = '(v.full_name LIKE :name OR v.reference LIKE :reference OR c.name LIKE :company)';
    $parameters['name'] = $like;
    $parameters['reference'] = $like;
    $parameters['company'] = $like;
}
$whereSql = ' WHERE ' . implode(' AND ', $where);
$from = ' FROM access_events e
          JOIN visitors v ON v.id=e.visitor_id
          LEFT JOIN companies c ON c.id=v.company_id
          LEFT JOIN visitor_passes vp ON vp.id=e.visitor_pass_id
          JOIN gates g ON g.id=e.gate_id
          LEFT JOIN lanes l ON l.id=e.lane_id';
$count = $connection->prepare('SELECT COUNT(*)' . $from . $whereSql);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT v.id AS visitor_id,v.reference AS visitor_reference,v.full_name,
            v.company_id,c.name AS company_name,vp.reference AS pass_reference,
            e.gate_id,g.name AS gate_name,e.lane_id,l.name AS lane_name,
            e.reference AS entry_event_reference,e.event_at AS entered_at
    ' . $from . $whereSql
    . ' ORDER BY e.event_at DESC,e.id DESC LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$list->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$list->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$list->execute();
$visitors = $list->fetchAll();
foreach ($visitors as &$visitor) {
    foreach (['visitor_id', 'company_id', 'gate_id', 'lane_id'] as $field) {
        $visitor[$field] = $visitor[$field] === null ? null : (int) $visitor[$field];
    }
}
unset($visitor);
successResponse([
    'visitors' => $visitors,
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Current visitor presence loaded.');
