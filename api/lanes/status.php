<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';
$actor = requireOperationalPermission('gates.manage_status');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$status = $data['status'] ?? null;
if (!is_string($status) || !in_array($status, ['active', 'inactive'], true)) {
    validationError('Status must be active or inactive.');
}
$connection = getDatabaseConnection();
$connection->beginTransaction();
try {
    $update = $connection->prepare('UPDATE lanes SET status=:status WHERE id=:id');
    $update->execute(['status' => $status, 'id' => $id]);
    if ($update->rowCount() === 0 && laneRecord($connection, $id) === null) {
        $connection->rollBack();
        notFoundResponse('Lane not found.');
    }
    writeOperationalAudit($connection, $actor['id'], 'lane.status.' . $status, 'lane', $id);
    $connection->commit();
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse(['lane' => laneRecord($connection, $id)], 'Lane status updated.');
