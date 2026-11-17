<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';

$actor = requireOperationalPermission('approvals.assign');
if ($actor['role']['code'] !== 'admin') {
    forbiddenResponse();
}
$data = requireJsonPostWithCsrf();

try {
    $id = parsePositiveId($_GET['id'] ?? null);
    $reviewerId = validateOptionalId($data, 'reviewer_id');
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $lock = $connection->prepare(
        'SELECT status,gate_id,direction FROM approval_requests WHERE id=:id FOR UPDATE'
    );
    $lock->execute(['id' => $id]);
    $request = $lock->fetch();
    if (!$request) {
        $connection->rollBack();
        notFoundResponse('Approval request not found.');
    }
    if ($request['status'] !== 'pending') {
        $connection->rollBack();
        jsonResponse(false, 'Only pending requests can be assigned.', [], 409);
    }
    if ($request['gate_id'] !== null) {
        requireAssignedGateAccess($actor, (int) $request['gate_id'], $request['direction']);
    }
    if ($reviewerId !== null) {
        $reviewer = loadUserContext($connection, $reviewerId);
        if (
            $reviewer === null
            || $reviewer['role']['code'] !== 'admin'
            || !in_array('approvals.decide', $reviewer['permissions'], true)
        ) {
            throw new InvalidArgumentException('Reviewer must be an active administrator allowed to decide requests.');
        }
        if (
            $request['gate_id'] !== null
            && !userCanAccessGate(
                $connection,
                $reviewerId,
                (int) $request['gate_id'],
                $request['direction']
            )
        ) {
            throw new InvalidArgumentException('Reviewer is not assigned to the request gate and direction.');
        }
    }
    $update = $connection->prepare(
        'UPDATE approval_requests SET assigned_reviewer_id=:reviewer_id WHERE id=:id'
    );
    $update->execute(['reviewer_id' => $reviewerId, 'id' => $id]);
    writeOperationalAudit(
        $connection,
        $actor['id'],
        $reviewerId === null ? 'approval.unassign' : 'approval.assign',
        'approval_request',
        $id,
        ['reviewer_id' => $reviewerId]
    );
    $connection->commit();
} catch (InvalidArgumentException $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    handleOperationalValidation($exception);
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}

successResponse(['request' => approvalRecord($connection, $id)], 'Approval reviewer assignment updated.');
