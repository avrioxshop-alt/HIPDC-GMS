<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/visitor_goods.php';

$actor = requireOperationalPermission('goods.submit');
requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $lock = $connection->prepare(
        'SELECT reference,direction,company_id,vehicle_id,driver_id,schedule_id,gate_id,lane_id,
                purpose,status,submitted_by
         FROM goods_declarations WHERE id=:id FOR UPDATE'
    );
    $lock->execute(['id' => $id]);
    $declaration = $lock->fetch();
    if (!$declaration) {
        $connection->rollBack();
        notFoundResponse('Goods declaration not found.');
    }
    assertGoodsDeclarationScope($actor, (int) $declaration['submitted_by']);
    if ($declaration['status'] !== 'draft') {
        $connection->rollBack();
        jsonResponse(false, 'Only draft declarations can be submitted.', [], 409);
    }
    validateGoodsReferences(
        $connection,
        $actor,
        (int) $declaration['company_id'],
        $declaration['vehicle_id'] === null ? null : (int) $declaration['vehicle_id'],
        $declaration['driver_id'] === null ? null : (int) $declaration['driver_id'],
        $declaration['schedule_id'] === null ? null : (int) $declaration['schedule_id'],
        $declaration['gate_id'] === null ? null : (int) $declaration['gate_id'],
        $declaration['lane_id'] === null ? null : (int) $declaration['lane_id'],
        $declaration['direction']
    );
    $itemCount = $connection->prepare('SELECT COUNT(*) FROM goods_items WHERE declaration_id=:id');
    $itemCount->execute(['id' => $id]);
    $count = (int) $itemCount->fetchColumn();
    if ($count < 1 || $count > 100) {
        throw new InvalidArgumentException('A declaration must contain between 1 and 100 goods items before submission.');
    }
    $approvalInsert = $connection->prepare(
        'INSERT INTO approval_requests
            (reference,request_type,title,note,details,company_id,driver_id,vehicle_id,
             schedule_id,goods_declaration_id,gate_id,lane_id,direction,submitted_by,status)
         VALUES
            (:reference,\'goods_declaration\',:title,NULL,:details,:company_id,:driver_id,:vehicle_id,
             :schedule_id,:declaration_id,:gate_id,:lane_id,:direction,:submitted_by,\'pending\')'
    );
    $approvalInsert->execute([
        'reference' => makeReference('APR'),
        'title' => 'Goods declaration ' . $declaration['reference'],
        'details' => json_encode(
            ['declaration_id' => $id, 'item_count' => $count],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ),
        'company_id' => $declaration['company_id'],
        'driver_id' => $declaration['driver_id'],
        'vehicle_id' => $declaration['vehicle_id'],
        'schedule_id' => $declaration['schedule_id'],
        'declaration_id' => $id,
        'gate_id' => $declaration['gate_id'],
        'lane_id' => $declaration['lane_id'],
        'direction' => $declaration['direction'],
        'submitted_by' => $actor['id'],
    ]);
    $approvalId = (int) $connection->lastInsertId();
    $update = $connection->prepare(
        'UPDATE goods_declarations
         SET status=\'pending\',submitted_at=CURRENT_TIMESTAMP
         WHERE id=:id AND status=\'draft\''
    );
    $update->execute(['id' => $id]);
    if ($update->rowCount() !== 1) {
        $connection->rollBack();
        jsonResponse(false, 'The declaration status changed; reload and try again.', [], 409);
    }
    writeOperationalAudit($connection, $actor['id'], 'goods.submit', 'goods_declaration', $id);
    writeOperationalAudit($connection, $actor['id'], 'approval.submit', 'approval_request', $approvalId);
    $connection->commit();
} catch (InvalidArgumentException $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    handleOperationalValidation($exception);
} catch (PDOException $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    respondToOperationalConflict($exception, 'Goods declaration approval conflicts with existing records.');
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse([
    'declaration' => goodsDeclarationRecord($connection, $id),
    'approval' => approvalRecord($connection, $approvalId),
], 'Goods declaration submitted for approval.');
