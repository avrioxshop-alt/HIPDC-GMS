<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';

$actor = requireOperationalPermission('approvals.cancel_own');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($_GET['id'] ?? null);
    $reason = validateOperationalText($data, 'reason', 4000);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $lock = $connection->prepare(
        'SELECT status,submitted_by,request_type,visitor_pass_id,goods_declaration_id,gate_id,direction
         FROM approval_requests WHERE id=:id FOR UPDATE'
    );
    $lock->execute(['id' => $id]);
    $request = $lock->fetch();
    if (!$request) {
        $connection->rollBack();
        notFoundResponse('Approval request not found.');
    }
    if ((int) $request['submitted_by'] !== $actor['id']) {
        forbiddenResponse('You can only cancel requests you submitted.');
    }
    if ($request['status'] !== 'pending') {
        $connection->rollBack();
        jsonResponse(false, 'Only pending requests can be cancelled.', [], 409);
    }
    if ($request['gate_id'] !== null) {
        requireAssignedGateAccess($actor, (int) $request['gate_id'], $request['direction']);
    }
    $update = $connection->prepare(
        'UPDATE approval_requests SET status=\'cancelled\'
         WHERE id=:id AND status=\'pending\''
    );
    $update->execute(['id' => $id]);
    if ($update->rowCount() !== 1) {
        $connection->rollBack();
        jsonResponse(false, 'The request status changed; reload and try again.', [], 409);
    }
    $history = $connection->prepare(
        'INSERT INTO approval_decisions
            (approval_request_id,actor_id,previous_status,new_status,reason)
         VALUES (:request,:actor,\'pending\',\'cancelled\',:reason)'
    );
    $history->execute([
        'request' => $id,
        'actor' => $actor['id'],
        'reason' => $reason,
    ]);
    writeOperationalAudit($connection, $actor['id'], 'approval.cancel', 'approval_request', $id);
    if ($request['request_type'] === 'visitor_pass') {
        $cancelPass = $connection->prepare(
            'UPDATE visitor_passes SET status=\'cancelled\'
             WHERE id=:id AND status=\'pending_approval\''
        );
        $cancelPass->execute(['id' => $request['visitor_pass_id']]);
        if ($cancelPass->rowCount() !== 1) {
            $connection->rollBack();
            jsonResponse(false, 'The linked visitor pass is no longer pending.', [], 409);
        }
        writeOperationalAudit(
            $connection,
            $actor['id'],
            'visitor.pass.cancel',
            'visitor_pass',
            (int) $request['visitor_pass_id']
        );
    } elseif ($request['request_type'] === 'goods_declaration') {
        $cancelDeclaration = $connection->prepare(
            'UPDATE goods_declarations SET status=\'cancelled\'
             WHERE id=:id AND status=\'pending\''
        );
        $cancelDeclaration->execute(['id' => $request['goods_declaration_id']]);
        if ($cancelDeclaration->rowCount() !== 1) {
            $connection->rollBack();
            jsonResponse(false, 'The linked goods declaration is no longer pending.', [], 409);
        }
        writeOperationalAudit(
            $connection,
            $actor['id'],
            'goods.cancel',
            'goods_declaration',
            (int) $request['goods_declaration_id']
        );
    }
    $connection->commit();
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse(['request' => approvalRecord($connection, $id)], 'Approval request cancelled.');
