<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';
requireRequestMethod('GET');
$actor = requireOperationalPermission('schedules.view');
try {
    $id = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$connection = getDatabaseConnection();
$schedule = scheduleRecord($connection, $id);
if ($schedule === null) {
    notFoundResponse('Schedule not found.');
}
if ($actor['role']['code'] !== 'admin' && (int) $schedule['created_by'] !== $actor['id']) {
    if ($schedule['gate_id'] !== null) {
        requireAssignedGateAccess($actor, $schedule['gate_id'], $schedule['direction']);
    }
}
if ($schedule['gate_id'] !== null) {
    requireAssignedGateAccess($actor, $schedule['gate_id'], $schedule['direction']);
}
successResponse(['schedule' => $schedule], 'Schedule loaded.');
