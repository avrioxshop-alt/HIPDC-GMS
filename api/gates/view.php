<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';
requireRequestMethod('GET');
requireOperationalPermission('gates.view');
try {
    $id = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$connection = getDatabaseConnection();
$gate = gateRecord($connection, $id);
if ($gate === null) {
    notFoundResponse('Gate not found.');
}
$lanes = $connection->prepare(
    'SELECT id,gate_id,code,name,direction,status,created_at,updated_at
     FROM lanes WHERE gate_id=:gate_id ORDER BY name,id'
);
$lanes->execute(['gate_id' => $id]);
$gate['lanes'] = $lanes->fetchAll();
foreach ($gate['lanes'] as &$lane) {
    $lane['id'] = (int) $lane['id'];
    $lane['gate_id'] = (int) $lane['gate_id'];
}
unset($lane);
successResponse(['gate' => $gate], 'Gate loaded.');
