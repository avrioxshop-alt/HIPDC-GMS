<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';
$actor = requireOperationalPermission('schedules.cancel');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$status = $data['status'] ?? null;
if ($status !== 'cancelled') {
    validationError('The only supported schedule transition is to cancelled.');
}
$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $schedule = scheduleRecord($connection, $id);
    if ($schedule === null) {
        $connection->rollBack();
        notFoundResponse('Schedule not found.');
    }
    if ($schedule['status'] !== 'scheduled') {
        $connection->rollBack();
        jsonResponse(false, 'Only scheduled records can be cancelled.', [], 409);
    }
    if ($actor['role']['code'] !== 'admin' && (int) $schedule['created_by'] !== $actor['id']) {
        forbiddenResponse('You can only cancel schedules you created.');
    }
    if ($schedule['gate_id'] !== null) {
        requireAssignedGateAccess($actor, $schedule['gate_id'], $schedule['direction']);
    }
    $update = $connection->prepare(
        'UPDATE schedules SET status=\'cancelled\' WHERE id=:id AND status=\'scheduled\''
    );
    $update->execute(['id' => $id]);
    if ($update->rowCount() !== 1) {
        $connection->rollBack();
        jsonResponse(false, 'The schedule status changed; reload and try again.', [], 409);
    }
    writeOperationalAudit($connection, $actor['id'], 'schedule.cancel', 'schedule', $id);
    $connection->commit();
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse(['schedule' => scheduleRecord($connection, $id)], 'Schedule cancelled.');
