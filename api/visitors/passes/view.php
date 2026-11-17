<?php

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/visitor_goods.php';

requireRequestMethod('GET');
$actor = requireOperationalPermission('visitors.passes.view');
try {
    $id = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$connection = getDatabaseConnection();
$pass = visitorPassRecord($connection, $id);
if ($pass === null) {
    notFoundResponse('Visitor pass not found.');
}
if ($actor['role']['code'] !== 'admin' && $pass['submitted_by'] !== $actor['id']) {
    forbiddenResponse();
}
requireAssignedGateAccess($actor, $pass['gate_id'], $pass['direction']);
$pass['approval'] = visitorPassApprovalRecord($connection, $id);
successResponse(['pass' => $pass], 'Visitor pass loaded.');
