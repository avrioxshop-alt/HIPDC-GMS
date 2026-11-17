<?php

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/gate_operations.php';

requireRequestMethod('GET');
$actor = requireOperationalPermission('gate_operations.view');
$pagination = getPaginationParameters();
$search = getSearchParameter();
$connection = getDatabaseConnection();
$where = [accessEventScopeSql()];
$parameters = [
    'scope_role_id' => $actor['role']['id'],
    'scope_user_id' => $actor['id'],
];

try {
    foreach (
        [
            'gate_id' => 'e.gate_id',
            'lane_id' => 'e.lane_id',
            'vehicle_id' => 'e.vehicle_id',
            'visitor_id' => 'e.visitor_id',
            'driver_id' => 'e.driver_id',
            'company_id' => 'e.company_id',
        ] as $parameter => $column
    ) {
        if (isset($_GET[$parameter]) && $_GET[$parameter] !== '') {
            $parameters[$parameter] = parsePositiveId($_GET[$parameter], $parameter);
            $where[] = $column . '=:' . $parameter;
        }
    }
    foreach (
        [
            'direction' => ['entry', 'exit'],
            'result' => ['granted', 'denied'],
            'method' => ['manual', 'qr', 'anpr'],
        ] as $parameter => $allowed
    ) {
        $value = $_GET[$parameter] ?? '';
        if (!is_string($value) || ($value !== '' && !in_array($value, $allowed, true))) {
            throw new InvalidArgumentException(ucfirst($parameter) . ' filter is invalid.');
        }
        if ($value !== '') {
            $column = $parameter === 'direction'
                ? 'e.event_type'
                : ($parameter === 'result' ? 'e.access_result' : 'e.access_method');
            $where[] = $column . '=:' . $parameter;
            $parameters[$parameter] = $value;
        }
    }
    $fromDate = validateOptionalDate($_GET['from'] ?? null, 'From date');
    $toDate = validateOptionalDate($_GET['to'] ?? null, 'To date');
    if ($fromDate !== null && $toDate !== null && $fromDate > $toDate) {
        throw new InvalidArgumentException('From date must not be later than to date.');
    }
    if ($fromDate !== null && $toDate !== null) {
        $start = new DateTimeImmutable($fromDate);
        $end = new DateTimeImmutable($toDate);
        if ((int) $start->diff($end)->format('%a') > 31) {
            throw new InvalidArgumentException('Date ranges may not exceed 32 calendar days.');
        }
    }
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
if ($fromDate !== null) {
    $where[] = 'e.event_at>=:from_date';
    $parameters['from_date'] = $fromDate . ' 00:00:00';
}
if ($toDate !== null) {
    $where[] = 'e.event_at<DATE_ADD(:to_date,INTERVAL 1 DAY)';
    $parameters['to_date'] = $toDate;
}
if ($search !== '') {
    $searchColumns = [
        'e.reference',
        'e.vehicle_plate_observed',
        'v.plate_number',
        'vis.full_name',
        'vis.reference',
        'vp.reference',
        'd.full_name',
        'c.name',
        'gd.reference',
    ];
    $searchParts = [];
    foreach ($searchColumns as $index => $column) {
        $parameter = 'search_' . $index;
        $searchParts[] = $column . ' LIKE :' . $parameter;
        $parameters[$parameter] = '%' . addcslashes($search, '\\_%') . '%';
    }
    $where[] = '(' . implode(' OR ', $searchParts) . ')';
}
$whereSql = ' WHERE ' . implode(' AND ', $where);
$from = ' FROM access_events e
          LEFT JOIN vehicles v ON v.id=e.vehicle_id
          LEFT JOIN visitors vis ON vis.id=e.visitor_id
          LEFT JOIN visitor_passes vp ON vp.id=e.visitor_pass_id
          LEFT JOIN drivers d ON d.id=e.driver_id
          LEFT JOIN companies c ON c.id=e.company_id
          LEFT JOIN goods_declarations gd ON gd.id=e.goods_declaration_id';
$count = $connection->prepare('SELECT COUNT(*)' . $from . $whereSql);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT e.id,e.reference,e.subject_type,e.event_type,e.gate_id,g.code AS gate_code,
            g.name AS gate_name,e.lane_id,l.name AS lane_name,e.vehicle_id,
            COALESCE(e.vehicle_plate_observed,v.plate_number) AS plate_number,
            e.driver_id,d.full_name AS driver_name,e.visitor_id,vis.reference AS visitor_reference,
            vis.full_name AS visitor_name,e.goods_declaration_id,gd.reference AS goods_reference,
            e.company_id,c.name AS company_name,e.actor_id,u.full_name AS actor_name,
            e.device_id,dev.name AS device_name,e.access_result,e.denial_reason,
            e.access_method,e.override_reason,e.event_at'
    . $from
    . ' LEFT JOIN gates g ON g.id=e.gate_id LEFT JOIN lanes l ON l.id=e.lane_id
       LEFT JOIN users u ON u.id=e.actor_id LEFT JOIN gate_devices dev ON dev.id=e.device_id'
    . $whereSql
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
        ['id', 'gate_id', 'lane_id', 'vehicle_id', 'driver_id', 'visitor_id', 'goods_declaration_id',
         'company_id', 'actor_id', 'device_id'] as $field
    ) {
        $event[$field] = $event[$field] === null ? null : (int) $event[$field];
    }
}
unset($event);
successResponse([
    'events' => $events,
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Access events loaded.');
