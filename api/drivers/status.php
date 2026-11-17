<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';
$actor = requireOperationalPermission('drivers.manage_status');
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
    $update = $connection->prepare('UPDATE drivers SET status=:status WHERE id=:id');
    $update->execute(['status' => $status, 'id' => $id]);
    if ($update->rowCount() === 0 && driverRecord($connection, $id) === null) {
        $connection->rollBack();
        notFoundResponse('Driver not found.');
    }
    writeOperationalAudit($connection, $actor['id'], 'driver.status.' . $status, 'driver', $id);
    $connection->commit();
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse(['driver' => driverRecord($connection, $id)], 'Driver status updated.');
