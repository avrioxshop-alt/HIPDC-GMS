<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/gate_operations.php';

requireRequestMethod('GET');
$actor = requireOperationalPermission('gate_operations.view');
$connection = getDatabaseConnection();
requireAnyGateOperationAssignment($connection, $actor);
try {
    $fromDate = validateOptionalDate($_GET['from'] ?? null, 'From date')
        ?? (string) $connection->query('SELECT CURRENT_DATE')->fetchColumn();
    $toDate = validateOptionalDate($_GET['to'] ?? null, 'To date')
        ?? (string) $connection->query('SELECT CURRENT_DATE')->fetchColumn();
    if ($fromDate > $toDate) {
        throw new InvalidArgumentException('From date must not be later than to date.');
    }
    $start = new DateTimeImmutable($fromDate);
    $end = new DateTimeImmutable($toDate);
    if ((int) $start->diff($end)->format('%a') > 31) {
        throw new InvalidArgumentException('Date ranges may not exceed 32 calendar days.');
    }
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

$scope = accessEventScopeSql();
$parameters = [
    'scope_role_id' => $actor['role']['id'],
    'scope_user_id' => $actor['id'],
    'from_date' => $fromDate . ' 00:00:00',
    'to_date' => $toDate,
];
$where = $scope
    . ' AND e.event_at>=:from_date AND e.event_at<DATE_ADD(:to_date,INTERVAL 1 DAY)';
$statement = $connection->prepare(
    'SELECT COUNT(*) AS total,
            SUM(CASE WHEN e.event_type=\'entry\' THEN 1 ELSE 0 END) AS entries,
            SUM(CASE WHEN e.event_type=\'exit\' THEN 1 ELSE 0 END) AS exits,
            SUM(CASE WHEN e.access_result=\'granted\' THEN 1 ELSE 0 END) AS granted,
            SUM(CASE WHEN e.access_result=\'denied\' THEN 1 ELSE 0 END) AS denied,
            SUM(CASE WHEN e.access_method=\'manual\' THEN 1 ELSE 0 END) AS manual,
            SUM(CASE WHEN e.access_method=\'qr\' THEN 1 ELSE 0 END) AS qr,
            SUM(CASE WHEN e.access_method=\'anpr\' THEN 1 ELSE 0 END) AS anpr
     FROM access_events e WHERE ' . $where
);
$statement->execute($parameters);
$summary = $statement->fetch();
foreach (['total', 'entries', 'exits', 'granted', 'denied', 'manual', 'qr', 'anpr'] as $field) {
    $summary[$field] = (int) ($summary[$field] ?? 0);
}
$summary['from'] = $fromDate;
$summary['to'] = $toDate;
successResponse(['summary' => $summary], 'Gate activity summary loaded.');
