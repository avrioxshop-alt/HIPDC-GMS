<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/visitor_goods.php';

requireRequestMethod('GET');
$actor = requireOperationalPermission('goods.view');
try {
    $id = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$connection = getDatabaseConnection();
$declaration = goodsDeclarationRecord($connection, $id);
if ($declaration === null) {
    notFoundResponse('Goods declaration not found.');
}
assertGoodsDeclarationScope($actor, $declaration['submitted_by']);
if ($declaration['gate_id'] !== null) {
    requireAssignedGateAccess($actor, $declaration['gate_id'], $declaration['direction']);
}
$approvalQuery = $connection->prepare(
    'SELECT id FROM approval_requests WHERE goods_declaration_id=:id LIMIT 1'
);
$approvalQuery->execute(['id' => $id]);
$approvalId = $approvalQuery->fetchColumn();
$declaration['approval'] = $approvalId === false
    ? null
    : approvalRecord($connection, (int) $approvalId);
successResponse(['declaration' => $declaration], 'Goods declaration loaded.');
