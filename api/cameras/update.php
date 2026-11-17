<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supporting.php';

$actor = requireAdministrator('cameras.manage');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($data['id'] ?? null, 'camera id');
    $camera = validateCameraFields(getDatabaseConnection(), $data);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $exists = $connection->prepare('SELECT id FROM cameras WHERE id=:id FOR UPDATE');
    $exists->execute(['id' => $id]);
    if ($exists->fetchColumn() === false) {
        $connection->rollBack();
        notFoundResponse('Camera record not found.');
    }
    $update = $connection->prepare(
        'UPDATE cameras
         SET name=:name,model=:model,serial_reference=:serial,location=:location,
             gate_id=:gate_id,lane_id=:lane_id,configuration=:configuration,updated_by=:updated_by
         WHERE id=:id'
    );
    $update->execute([
        'name' => $camera['name'],
        'model' => $camera['model'],
        'serial' => $camera['serial_reference'],
        'location' => $camera['location'],
        'gate_id' => $camera['gate_id'],
        'lane_id' => $camera['lane_id'],
        'configuration' => $camera['configuration'] === null
            ? null
            : json_encode($camera['configuration'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'updated_by' => $actor['id'],
        'id' => $id,
    ]);
    writeOperationalAudit($connection, $actor['id'], 'camera.update', 'camera', $id);
    $connection->commit();
} catch (PDOException $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    respondToOperationalConflict($exception, 'Camera serial number is already in use.');
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse(['camera' => cameraRecord($connection, $id)], 'Camera metadata updated.');
