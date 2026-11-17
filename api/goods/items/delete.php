<?php

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/visitor_goods.php';

$actor = requireOperationalPermission('goods.update');
requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $itemLock = $connection->prepare(
        'SELECT declaration_id FROM goods_items WHERE id=:id'
    );
    $itemLock->execute(['id' => $id]);
    $declarationId = $itemLock->fetchColumn();
    if ($declarationId === false) {
        $connection->rollBack();
        notFoundResponse('Goods item not found.');
    }
    $declarationLock = $connection->prepare(
        'SELECT status,submitted_by,gate_id,direction
         FROM goods_declarations WHERE id=:id FOR UPDATE'
    );
    $declarationLock->execute(['id' => $declarationId]);
    $declaration = $declarationLock->fetch();
    assertGoodsDeclarationScope($actor, (int) $declaration['submitted_by']);
    if ($declaration['status'] !== 'draft') {
        $connection->rollBack();
        jsonResponse(false, 'Items can only be removed from a draft declaration.', [], 409);
    }
    if ($declaration['gate_id'] !== null) {
        requireAssignedGateAccess($actor, (int) $declaration['gate_id'], $declaration['direction']);
    }
    $delete = $connection->prepare('DELETE FROM goods_items WHERE id=:id');
    $delete->execute(['id' => $id]);
    writeOperationalAudit(
        $connection,
        $actor['id'],
        'goods.item.remove',
        'goods_declaration',
        (int) $declarationId,
        ['item_id' => $id]
    );
    $connection->commit();
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse([], 'Goods item removed.');
