<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/visitor_goods.php';

requireRequestMethod('GET');
$actor = requireOperationalPermission('visitors.view');
try {
    $id = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$connection = getDatabaseConnection();
$visitor = visitorRecord($connection, $id);
if ($visitor === null) {
    notFoundResponse('Visitor not found.');
}
assertVisitorScope($actor, $visitor['created_by']);
$passGates = $connection->prepare(
    'SELECT gate_id,direction FROM visitor_passes WHERE visitor_id=:visitor_id'
);
$passGates->execute(['visitor_id' => $id]);
foreach ($passGates->fetchAll() as $passGate) {
    requireAssignedGateAccess($actor, (int) $passGate['gate_id'], $passGate['direction']);
}
$pagination = getPaginationParameters();
$passCount = $connection->prepare('SELECT COUNT(*) FROM visitor_passes WHERE visitor_id=:visitor_id');
$passCount->execute(['visitor_id' => $id]);
$passList = $connection->prepare(
    'SELECT id FROM visitor_passes WHERE visitor_id=:visitor_id
     ORDER BY created_at DESC,id DESC LIMIT :limit OFFSET :offset'
);
$passList->bindValue(':visitor_id', $id, PDO::PARAM_INT);
$passList->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$passList->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$passList->execute();
$passHistory = [];
foreach ($passList->fetchAll(PDO::FETCH_COLUMN) as $passId) {
    $passId = (int) $passId;
    $passHistory[] = [
        'pass' => visitorPassRecord($connection, $passId),
        'approval' => visitorPassApprovalRecord($connection, $passId),
    ];
}
successResponse([
    'visitor_id' => $id,
    'events' => visitorHistory($connection, $id),
    'passes' => $passHistory,
    'pagination' => paginationResult($pagination, (int) $passCount->fetchColumn()),
], 'Visitor history loaded.');
