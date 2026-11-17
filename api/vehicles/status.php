<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';
$actor = requireOperationalPermission('vehicles.manage_status');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$status = $data['status'] ?? null;
if (!is_string($status) || !in_array($status, ['active', 'inactive', 'blocked'], true)) {
    validationError('Status must be active, inactive, or blocked.');
}
$connection = getDatabaseConnection();
$connection->beginTransaction();
try {
    $update = $connection->prepare('UPDATE vehicles SET status=:status WHERE id=:id');
    $update->execute(['status' => $status, 'id' => $id]);
    if ($update->rowCount() === 0 && vehicleRecord($connection, $id) === null) {
        $connection->rollBack();
        notFoundResponse('Vehicle not found.');
    }
    writeOperationalAudit($connection, $actor['id'], 'vehicle.status.' . $status, 'vehicle', $id);
    $connection->commit();
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse(['vehicle' => vehicleRecord($connection, $id)], 'Vehicle status updated.');
