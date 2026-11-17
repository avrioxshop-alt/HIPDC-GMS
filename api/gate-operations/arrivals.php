<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';

requireRequestMethod('GET');
$actor = requireOperationalPermission('gate_operations.view');
$pagination = getPaginationParameters();
$connection = getDatabaseConnection();
try {
    $gateId = parsePositiveId($_GET['gate_id'] ?? null, 'gate_id');
    $directionFilter = $_GET['direction'] ?? '';
    if ($directionFilter !== '' && !in_array($directionFilter, ['entry', 'exit'], true)) {
        throw new InvalidArgumentException('Direction must be entry or exit.');
    }
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

$directions = $directionFilter === '' ? ['entry', 'exit'] : [$directionFilter];
$authorized = array_values(array_filter(
    $directions,
    static fn (string $direction): bool => userCanAccessGate(
        $connection,
        $actor['id'],
        $gateId,
        $direction
    )
));
if ($authorized === []) {
    forbiddenResponse('You are not assigned to this gate direction.');
}

$placeholders = [];
$parameters = ['gate_id' => $gateId];
foreach ($authorized as $index => $direction) {
    $name = 'direction' . $index;
    $placeholders[] = ':' . $name;
    $parameters[$name] = $direction;
}
$where = 's.gate_id=:gate_id AND s.direction IN (' . implode(',', $placeholders) . ')
          AND s.status=\'scheduled\' AND DATE(s.scheduled_at)=CURRENT_DATE';
$from = ' FROM schedules s
          JOIN vehicles v ON v.id=s.vehicle_id
          JOIN drivers d ON d.id=s.driver_id
          LEFT JOIN companies c ON c.id=s.company_id
          LEFT JOIN lanes l ON l.id=s.lane_id';
$count = $connection->prepare('SELECT COUNT(*)' . $from . ' WHERE ' . $where);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT s.id,s.reference,s.direction,s.scheduled_at,s.purpose,s.cargo_summary,
            s.company_id,c.name AS company_name,s.vehicle_id,v.plate_number,
            s.driver_id,d.full_name AS driver_name,s.lane_id,l.name AS lane_name
     ' . $from . ' WHERE ' . $where
    . ' ORDER BY s.scheduled_at,s.id LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$list->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$list->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$list->execute();
$arrivals = $list->fetchAll();
foreach ($arrivals as &$arrival) {
    foreach (['id', 'company_id', 'vehicle_id', 'driver_id', 'lane_id'] as $field) {
        $arrival[$field] = $arrival[$field] === null ? null : (int) $arrival[$field];
    }
}
unset($arrival);
successResponse([
    'arrivals' => $arrivals,
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Expected arrivals loaded. Schedules are not physical gate events.');
