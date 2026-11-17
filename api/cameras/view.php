<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supporting.php';

requireRequestMethod('GET');
$actor = requireOperationalPermission('cameras.view');
try {
    $id = parsePositiveId($_GET['id'] ?? null, 'camera id');
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$camera = cameraRecord(getDatabaseConnection(), $id);
if ($camera === null) {
    notFoundResponse('Camera record not found.');
}
requireCameraGateScope(getDatabaseConnection(), $actor, $camera['gate_id']);
successResponse(['camera' => $camera], 'Camera metadata loaded.');
