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
successResponse(['visitor' => $visitor], 'Visitor loaded.');
