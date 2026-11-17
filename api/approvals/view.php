<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';
requireRequestMethod('GET');
$actor = requireOperationalPermission('approvals.view');
try {
    $id = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$connection = getDatabaseConnection();
$request = approvalRecord($connection, $id);
if ($request === null) {
    notFoundResponse('Approval request not found.');
}
if ($actor['role']['code'] !== 'admin' && $request['submitted_by'] !== $actor['id']) {
    forbiddenResponse();
}
if ($request['gate_id'] !== null) {
    requireAssignedGateAccess($actor, $request['gate_id'], $request['direction']);
}
successResponse(['request' => $request], 'Approval request loaded.');
