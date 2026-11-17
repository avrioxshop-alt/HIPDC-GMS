<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';
requireRequestMethod('GET');
requireOperationalPermission('drivers.view');
try {
    $id = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$driver = driverRecord(getDatabaseConnection(), $id);
if ($driver === null) {
    notFoundResponse('Driver not found.');
}
successResponse(['driver' => $driver], 'Driver loaded.');
