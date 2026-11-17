<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';
requireRequestMethod('GET');
requireOperationalPermission('gates.view');
try {
    $id = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$lane = laneRecord(getDatabaseConnection(), $id);
if ($lane === null) {
    notFoundResponse('Lane not found.');
}
successResponse(['lane' => $lane], 'Lane loaded.');
