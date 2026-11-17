<?php

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/visitor_goods.php';

$actor = requireOperationalPermission('visitors.passes.revoke');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($_GET['id'] ?? null);
    $reason = validateOperationalText($data, 'reason', 1000, true);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $currentQuery = $connection->prepare(
        'SELECT p.status,p.gate_id,p.direction,a.id AS approval_id
         FROM visitor_passes p
         LEFT JOIN approval_requests a ON a.visitor_pass_id=p.id
         WHERE p.id=:id'
    );
    $currentQuery->execute(['id' => $id]);
    $current = $currentQuery->fetch();
    if (!$current) {
        $connection->rollBack();
        notFoundResponse('Visitor pass not found.');
    }
    requireAssignedGateAccess($actor, (int) $current['gate_id'], $current['direction']);
    if (!in_array($current['status'], ['pending_approval', 'active'], true)) {
        $connection->rollBack();
        jsonResponse(false, 'Only pending or active passes can be revoked.', [], 409);
    }
    $approvalId = null;
    if ($current['status'] === 'pending_approval') {
        if ($current['approval_id'] === null) {
            throw new RuntimeException('Pending visitor pass has no matching pending approval request.');
        }
        $approvalId = (int) $current['approval_id'];
        $approvalLock = $connection->prepare(
            'SELECT id,status FROM approval_requests WHERE id=:id FOR UPDATE'
        );
        $approvalLock->execute(['id' => $approvalId]);
        $approval = $approvalLock->fetch();
        if (!$approval || $approval['status'] !== 'pending') {
            $connection->rollBack();
            jsonResponse(false, 'The approval request is no longer pending.', [], 409);
        }
    }
    $lock = $connection->prepare(
        'SELECT status,gate_id,direction FROM visitor_passes WHERE id=:id FOR UPDATE'
    );
    $lock->execute(['id' => $id]);
    $pass = $lock->fetch();
    if (!$pass || $pass['status'] !== $current['status']) {
        $connection->rollBack();
        jsonResponse(false, 'The pass status changed; reload and try again.', [], 409);
    }
    if ($pass['status'] === 'pending_approval') {
        $cancelApproval = $connection->prepare(
            'UPDATE approval_requests
             SET status=\'cancelled\',decision_reason=:reason,decided_by=:actor,decided_at=CURRENT_TIMESTAMP
             WHERE id=:id AND status=\'pending\''
        );
        $cancelApproval->execute(['reason' => $reason, 'actor' => $actor['id'], 'id' => $approvalId]);
        $history = $connection->prepare(
            'INSERT INTO approval_decisions
                (approval_request_id,actor_id,previous_status,new_status,reason)
             VALUES (:request,:actor,\'pending\',\'cancelled\',:reason)'
        );
        $history->execute(['request' => $approvalId, 'actor' => $actor['id'], 'reason' => $reason]);
        writeOperationalAudit($connection, $actor['id'], 'approval.cancel', 'approval_request', $approvalId);
    }
    if ($pass['status'] === 'pending_approval') {
        $update = $connection->prepare(
            'UPDATE visitor_passes SET status=\'cancelled\'
             WHERE id=:id AND status=\'pending_approval\''
        );
        $update->execute(['id' => $id]);
    } else {
        $update = $connection->prepare(
            'UPDATE visitor_passes
             SET status=\'revoked\',revoked_by=:actor,revoked_at=CURRENT_TIMESTAMP,
                 revocation_reason=:reason
             WHERE id=:id AND status=\'active\''
        );
        $update->execute(['actor' => $actor['id'], 'reason' => $reason, 'id' => $id]);
    }
    if ($update->rowCount() !== 1) {
        $connection->rollBack();
        jsonResponse(false, 'The pass status changed; reload and try again.', [], 409);
    }
    writeOperationalAudit(
        $connection,
        $actor['id'],
        $pass['status'] === 'pending_approval' ? 'visitor.pass.cancel' : 'visitor.pass.revoke',
        'visitor_pass',
        $id
    );
    $connection->commit();
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse(['pass' => visitorPassRecord($connection, $id)], 'Visitor pass revoked or cancelled.');
