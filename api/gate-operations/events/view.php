<?php

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/gate_operations.php';

$actor = requireOperationalPermission('gate_operations.view');
requireRequestMethod('GET');
try {
    $id = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$connection = getDatabaseConnection();
$event = accessEventRecord($connection, $id);
if ($event === null) {
    notFoundResponse('Access event not found.');
}
if (!userCanViewGateEvent($connection, $actor['id'], $event['gate_id'], $event['event_type'])) {
    forbiddenResponse('You are not assigned to this gate direction.');
}
successResponse(['event' => $event], 'Access event loaded.');
