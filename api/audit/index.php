<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';

requireRequestMethod('GET');
$actor = requireAdministrator('audit.view');
$pagination = getPaginationParameters();
$where = [];
$parameters = [];
try {
    if (isset($_GET['actor_id']) && $_GET['actor_id'] !== '') {
        $parameters['actor_id'] = parsePositiveId($_GET['actor_id'], 'actor id');
        $where[] = 'a.actor_id=:actor_id';
    }
    foreach (['action', 'entity_type'] as $field) {
        if (isset($_GET[$field]) && $_GET[$field] !== '') {
            $parameters[$field] = validateOperationalText($_GET, $field, $field === 'action' ? 80 : 48, true);
            $where[] = 'a.' . $field . '=:' . $field;
        }
    }
    $fromDate = validateOptionalDate($_GET['from'] ?? null, 'From date');
    $toDate = validateOptionalDate($_GET['to'] ?? null, 'To date');
    if ($fromDate !== null && $toDate !== null && $fromDate > $toDate) {
        throw new InvalidArgumentException('From date must not be later than to date.');
    }
    if ($fromDate !== null) {
        $where[] = 'a.created_at>=:from_date';
        $parameters['from_date'] = $fromDate . ' 00:00:00';
    }
    if ($toDate !== null) {
        $where[] = 'a.created_at<DATE_ADD(:to_date,INTERVAL 1 DAY)';
        $parameters['to_date'] = $toDate;
    }
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$connection = getDatabaseConnection();
$count = $connection->prepare('SELECT COUNT(*) FROM audit_events a' . $whereSql);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT a.id,a.actor_id,u.username AS actor_username,u.full_name AS actor_name,
            a.action,a.entity_type,a.entity_id,a.metadata,a.created_at
     FROM audit_events a LEFT JOIN users u ON u.id=a.actor_id'
    . $whereSql . ' ORDER BY a.created_at DESC,a.id DESC LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$list->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$list->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$list->execute();
$events = $list->fetchAll();
foreach ($events as &$event) {
    foreach (['id', 'actor_id', 'entity_id'] as $field) {
        $event[$field] = $event[$field] === null ? null : (int) $event[$field];
    }
    if ($event['metadata'] !== null) {
        $event['metadata'] = json_decode($event['metadata'], true, 32, JSON_THROW_ON_ERROR);
    }
}
unset($event);
successResponse([
    'events' => $events,
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Audit events loaded.');
