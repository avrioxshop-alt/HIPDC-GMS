<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/visitor_goods.php';

$actor = requireOperationalPermission('goods.cancel_own');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($_GET['id'] ?? null);
    $reason = validateOperationalText($data, 'reason', 1000);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $currentQuery = $connection->prepare(
        'SELECT status,submitted_by,gate_id,direction
         FROM goods_declarations WHERE id=:id'
    );
    $currentQuery->execute(['id' => $id]);
    $current = $currentQuery->fetch();
    if (!$current) {
        $connection->rollBack();
        notFoundResponse('Goods declaration not found.');
    }
    assertGoodsDeclarationScope($actor, (int) $current['submitted_by']);
    $request = null;
    if ($current['status'] === 'pending') {
        $approval = $connection->prepare(
            'SELECT id,status FROM approval_requests WHERE goods_declaration_id=:id FOR UPDATE'
        );
        $approval->execute(['id' => $id]);
        $request = $approval->fetch();
        if (!$request || $request['status'] !== 'pending') {
            $connection->rollBack();
            jsonResponse(false, 'The linked approval request is no longer pending.', [], 409);
        }
    }
    $lock = $connection->prepare(
        'SELECT status,submitted_by,gate_id,direction
         FROM goods_declarations WHERE id=:id FOR UPDATE'
    );
    $lock->execute(['id' => $id]);
    $declaration = $lock->fetch();
    if (!$declaration) {
        $connection->rollBack();
        notFoundResponse('Goods declaration not found.');
    }
    assertGoodsDeclarationScope($actor, (int) $declaration['submitted_by']);
    if (!in_array($declaration['status'], ['draft', 'pending'], true)) {
        $connection->rollBack();
        jsonResponse(false, 'Only draft or pending declarations can be cancelled.', [], 409);
    }
    if ($declaration['gate_id'] !== null) {
        requireAssignedGateAccess($actor, (int) $declaration['gate_id'], $declaration['direction']);
    }
    if ($declaration['status'] !== $current['status']) {
        $connection->rollBack();
        jsonResponse(false, 'The declaration status changed; reload and try again.', [], 409);
    }
    if ($declaration['status'] === 'pending') {
        if (!$request || $request['status'] !== 'pending') {
            $connection->rollBack();
            jsonResponse(false, 'The linked approval request is no longer pending.', [], 409);
        }
        $cancelApproval = $connection->prepare(
            'UPDATE approval_requests
             SET status=\'cancelled\',decision_reason=:reason,decided_by=:actor,decided_at=CURRENT_TIMESTAMP
             WHERE id=:id AND status=\'pending\''
        );
        $cancelApproval->execute([
            'reason' => $reason,
            'actor' => $actor['id'],
            'id' => $request['id'],
        ]);
        $history = $connection->prepare(
            'INSERT INTO approval_decisions
                (approval_request_id,actor_id,previous_status,new_status,reason)
             VALUES (:request,:actor,\'pending\',\'cancelled\',:reason)'
        );
        $history->execute([
            'request' => $request['id'],
            'actor' => $actor['id'],
            'reason' => $reason,
        ]);
        writeOperationalAudit(
            $connection,
            $actor['id'],
            'approval.cancel',
            'approval_request',
            (int) $request['id']
        );
    }
    $update = $connection->prepare(
        'UPDATE goods_declarations SET status=\'cancelled\' WHERE id=:id'
    );
    $update->execute(['id' => $id]);
    writeOperationalAudit($connection, $actor['id'], 'goods.cancel', 'goods_declaration', $id);
    $connection->commit();
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse(['declaration' => goodsDeclarationRecord($connection, $id)], 'Goods declaration cancelled.');
