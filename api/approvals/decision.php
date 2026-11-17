<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/visitor_goods.php';

$actor = requireOperationalPermission('approvals.decide');
if ($actor['role']['code'] !== 'admin') {
    forbiddenResponse();
}
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($_GET['id'] ?? null);
    $status = $data['status'] ?? null;
    if (!is_string($status) || !in_array($status, ['approved', 'denied'], true)) {
        throw new InvalidArgumentException('Decision status must be approved or denied.');
    }
    $reason = validateOperationalText($data, 'reason', 4000, $status === 'denied');
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

$connection = getDatabaseConnection();
$issuedQrToken = null;
try {
    $connection->beginTransaction();
    $lock = $connection->prepare(
        'SELECT status,request_type,visitor_pass_id,goods_declaration_id,
                gate_id,direction,assigned_reviewer_id
         FROM approval_requests WHERE id=:id FOR UPDATE'
    );
    $lock->execute(['id' => $id]);
    $request = $lock->fetch();
    if (!$request) {
        $connection->rollBack();
        notFoundResponse('Approval request not found.');
    }
    if ($request['status'] !== 'pending') {
        $connection->rollBack();
        jsonResponse(false, 'Only pending requests can be decided.', [], 409);
    }
    if (
        $request['assigned_reviewer_id'] !== null
        && (int) $request['assigned_reviewer_id'] !== $actor['id']
    ) {
        forbiddenResponse('This request is assigned to another reviewer.');
    }
    if ($request['gate_id'] !== null) {
        requireAssignedGateAccess($actor, (int) $request['gate_id'], $request['direction']);
    }
    $update = $connection->prepare(
        'UPDATE approval_requests
         SET status=:status,decision_reason=:reason,decided_by=:actor,decided_at=CURRENT_TIMESTAMP
         WHERE id=:id AND status=\'pending\''
    );
    $update->execute([
        'status' => $status,
        'reason' => $reason,
        'actor' => $actor['id'],
        'id' => $id,
    ]);
    if ($update->rowCount() !== 1) {
        $connection->rollBack();
        jsonResponse(false, 'The request status changed; reload and try again.', [], 409);
    }
    $history = $connection->prepare(
        'INSERT INTO approval_decisions
            (approval_request_id,actor_id,previous_status,new_status,reason)
         VALUES (:request,:actor,\'pending\',:status,:reason)'
    );
    $history->execute([
        'request' => $id,
        'actor' => $actor['id'],
        'status' => $status,
        'reason' => $reason,
    ]);
    writeOperationalAudit($connection, $actor['id'], 'approval.' . $status, 'approval_request', $id);

    if ($request['request_type'] === 'visitor_pass') {
        $passLock = $connection->prepare(
            'SELECT p.status,p.valid_from,p.expires_at,v.status AS visitor_status
             FROM visitor_passes p
             JOIN visitors v ON v.id=p.visitor_id
             WHERE p.id=:id FOR UPDATE'
        );
        $passLock->execute(['id' => $request['visitor_pass_id']]);
        $pass = $passLock->fetch();
        if (!$pass || $pass['status'] !== 'pending_approval') {
            $connection->rollBack();
            jsonResponse(false, 'The linked visitor pass is not pending approval.', [], 409);
        }
        if ($status === 'approved') {
            $now = (string) $connection->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
            if ($pass['visitor_status'] !== 'active' || $pass['expires_at'] <= $now) {
                $connection->rollBack();
                jsonResponse(false, 'The visitor or pass validity window is no longer eligible.', [], 409);
            }
            $issuedQrToken = bin2hex(random_bytes(32));
            $activatePass = $connection->prepare(
                'UPDATE visitor_passes
                 SET status=\'active\',qr_token_hash=:token_hash,issued_by=:issuer,issued_at=CURRENT_TIMESTAMP
                 WHERE id=:id AND status=\'pending_approval\''
            );
            $activatePass->bindValue(':token_hash', hash('sha256', hex2bin($issuedQrToken), true), PDO::PARAM_LOB);
            $activatePass->bindValue(':issuer', $actor['id'], PDO::PARAM_INT);
            $activatePass->bindValue(':id', (int) $request['visitor_pass_id'], PDO::PARAM_INT);
            $activatePass->execute();
            if ($activatePass->rowCount() !== 1) {
                $connection->rollBack();
                jsonResponse(false, 'The visitor pass status changed; reload and try again.', [], 409);
            }
            writeOperationalAudit(
                $connection,
                $actor['id'],
                'visitor.pass.issue',
                'visitor_pass',
                (int) $request['visitor_pass_id']
            );
        } else {
            $denyPass = $connection->prepare(
                'UPDATE visitor_passes SET status=\'denied\' WHERE id=:id AND status=\'pending_approval\''
            );
            $denyPass->execute(['id' => $request['visitor_pass_id']]);
            if ($denyPass->rowCount() !== 1) {
                $connection->rollBack();
                jsonResponse(false, 'The visitor pass status changed; reload and try again.', [], 409);
            }
            writeOperationalAudit(
                $connection,
                $actor['id'],
                'visitor.pass.deny',
                'visitor_pass',
                (int) $request['visitor_pass_id']
            );
        }
    } elseif ($request['request_type'] === 'goods_declaration') {
        $declarationStatus = $status;
        $updateDeclaration = $connection->prepare(
            'UPDATE goods_declarations SET status=:status
             WHERE id=:id AND status=\'pending\''
        );
        $updateDeclaration->execute([
            'status' => $declarationStatus,
            'id' => $request['goods_declaration_id'],
        ]);
        if ($updateDeclaration->rowCount() !== 1) {
            $connection->rollBack();
            jsonResponse(false, 'The linked goods declaration is not pending.', [], 409);
        }
        writeOperationalAudit(
            $connection,
            $actor['id'],
            'goods.' . $declarationStatus,
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
$response = ['request' => approvalRecord($connection, $id)];
if ($issuedQrToken !== null) {
    $response['visitor_pass'] = visitorPassRecord($connection, (int) $request['visitor_pass_id']);
    $response['qr_token'] = $issuedQrToken;
}
successResponse($response, 'Approval decision recorded.');
