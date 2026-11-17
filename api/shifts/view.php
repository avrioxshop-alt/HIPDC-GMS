<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supporting.php';

requireRequestMethod('GET');
$actor = requireOperationalPermission('shifts.view');
try {
    $id = parsePositiveId($_GET['id'] ?? null, 'shift id');
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$connection = getDatabaseConnection();
$shift = officerShiftRecord($connection, $id);
if ($shift === null) {
    notFoundResponse('Officer shift not found.');
}
if ($actor['role']['code'] !== 'admin' && $shift['officer_id'] !== $actor['id']) {
    forbiddenResponse();
}
successResponse(['shift' => $shift], 'Officer shift loaded.');
