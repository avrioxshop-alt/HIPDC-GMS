<?php

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/visitor_goods.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    $actor = requireOperationalPermission('goods.update');
    $data = requireJsonPostWithCsrf();
    try {
        $declarationId = parsePositiveId($_GET['declaration_id'] ?? null, 'declaration_id');
        $item = validateGoodsItems([$data])[0];
    } catch (InvalidArgumentException $exception) {
        handleOperationalValidation($exception);
    }

    $connection = getDatabaseConnection();
    try {
        $connection->beginTransaction();
        $lock = $connection->prepare(
            'SELECT status,submitted_by,gate_id,direction
             FROM goods_declarations WHERE id=:id FOR UPDATE'
        );
        $lock->execute(['id' => $declarationId]);
        $declaration = $lock->fetch();
        if (!$declaration) {
            $connection->rollBack();
            notFoundResponse('Goods declaration not found.');
        }
        assertGoodsDeclarationScope($actor, (int) $declaration['submitted_by']);
        if ($declaration['status'] !== 'draft') {
            $connection->rollBack();
            jsonResponse(false, 'Items can only be added to a draft declaration.', [], 409);
        }
        if ($declaration['gate_id'] !== null) {
            requireAssignedGateAccess($actor, (int) $declaration['gate_id'], $declaration['direction']);
        }
        $count = $connection->prepare('SELECT COUNT(*) FROM goods_items WHERE declaration_id=:id');
        $count->execute(['id' => $declarationId]);
        if ((int) $count->fetchColumn() >= 100) {
            $connection->rollBack();
            jsonResponse(false, 'A declaration can contain at most 100 goods items.', [], 409);
        }
        insertGoodsItems($connection, $declarationId, [$item]);
        $itemId = (int) $connection->lastInsertId();
        writeOperationalAudit(
            $connection,
            $actor['id'],
            'goods.item.add',
            'goods_declaration',
            $declarationId,
            ['item_id' => $itemId]
        );
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
    successResponse(['item' => goodsItemRecord($connection, $itemId)], 'Goods item added.', 201);
}

if ($method !== 'GET') {
    header('Allow: GET, POST');
    jsonResponse(false, 'The request method is not allowed.', [], 405);
}

$actor = requireOperationalPermission('goods.view');
try {
    $declarationId = parsePositiveId($_GET['declaration_id'] ?? null, 'declaration_id');
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$connection = getDatabaseConnection();
$declaration = goodsDeclarationRecord($connection, $declarationId, false);
if ($declaration === null) {
    notFoundResponse('Goods declaration not found.');
}
assertGoodsDeclarationScope($actor, $declaration['submitted_by']);
if ($declaration['gate_id'] !== null) {
    requireAssignedGateAccess($actor, $declaration['gate_id'], $declaration['direction']);
}
$items = $connection->prepare(
    'SELECT id,description,item_code,quantity,unit,serial_number,remarks,created_at,updated_at
     FROM goods_items WHERE declaration_id=:id ORDER BY id'
);
$items->execute(['id' => $declarationId]);
$records = $items->fetchAll();
foreach ($records as &$item) {
    $item['id'] = (int) $item['id'];
}
unset($item);
successResponse(['items' => $records], 'Goods items loaded.');
