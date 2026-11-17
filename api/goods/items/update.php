<?php

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/visitor_goods.php';

$actor = requireOperationalPermission('goods.update');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($_GET['id'] ?? null);
    $item = validateGoodsItems([$data])[0];
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
        jsonResponse(false, 'Items can only be updated on a draft declaration.', [], 409);
    }
    if ($declaration['gate_id'] !== null) {
        requireAssignedGateAccess($actor, (int) $declaration['gate_id'], $declaration['direction']);
    }
    $update = $connection->prepare(
        'UPDATE goods_items SET description=:description,item_code=:item_code,quantity=:quantity,
             unit=:unit,serial_number=:serial_number,remarks=:remarks
         WHERE id=:id AND declaration_id=:declaration_id'
    );
    $update->execute([
        'description' => $item['description'],
        'item_code' => $item['item_code'],
        'quantity' => $item['quantity'],
        'unit' => $item['unit'],
        'serial_number' => $item['serial_number'],
        'remarks' => $item['remarks'],
        'id' => $id,
        'declaration_id' => $declarationId,
    ]);
    writeOperationalAudit(
        $connection,
        $actor['id'],
        'goods.item.update',
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
successResponse(['item' => goodsItemRecord($connection, $id)], 'Goods item updated.');
