<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';
requireRequestMethod('GET');
requireOperationalPermission('vehicles.view');
try {
    $id = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$vehicle = vehicleRecord(getDatabaseConnection(), $id);
if ($vehicle === null) {
    notFoundResponse('Vehicle not found.');
}
successResponse(['vehicle' => $vehicle], 'Vehicle loaded.');
