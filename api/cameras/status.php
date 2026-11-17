<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supporting.php';

$actor = requireAdministrator('cameras.manage');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($data['id'] ?? null, 'camera id');
    $status = $data['status'] ?? null;
    if (!is_string($status) || !in_array($status, ['active', 'inactive', 'maintenance'], true)) {
        throw new InvalidArgumentException('Camera status is invalid.');
    }
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $update = $connection->prepare(
        'UPDATE cameras SET status=:status,updated_by=:updated_by WHERE id=:id'
    );
    $update->execute(['status' => $status, 'updated_by' => $actor['id'], 'id' => $id]);
    if ($update->rowCount() === 0) {
        $exists = $connection->prepare('SELECT id FROM cameras WHERE id=:id');
        $exists->execute(['id' => $id]);
        if ($exists->fetchColumn() === false) {
            $connection->rollBack();
            notFoundResponse('Camera record not found.');
        }
    }
    writeOperationalAudit($connection, $actor['id'], 'camera.status', 'camera', $id, ['status' => $status]);
    $connection->commit();
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse(['camera' => cameraRecord($connection, $id)], 'Camera status updated.');
