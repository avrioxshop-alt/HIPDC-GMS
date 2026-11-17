<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/visitor_goods.php';

$actor = requireOperationalPermission('visitors.manage_status');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($_GET['id'] ?? null);
    $status = $data['status'] ?? null;
    if (!is_string($status) || !in_array($status, ['active', 'inactive'], true)) {
        throw new InvalidArgumentException('Visitor status must be active or inactive.');
    }
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $lock = $connection->prepare('SELECT id FROM visitors WHERE id=:id FOR UPDATE');
    $lock->execute(['id' => $id]);
    if ($lock->fetchColumn() === false) {
        $connection->rollBack();
        notFoundResponse('Visitor not found.');
    }
    $update = $connection->prepare('UPDATE visitors SET status=:status WHERE id=:id');
    $update->execute(['status' => $status, 'id' => $id]);
    writeOperationalAudit($connection, $actor['id'], 'visitor.status', 'visitor', $id, ['status' => $status]);
    $connection->commit();
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse(['visitor' => visitorRecord($connection, $id)], 'Visitor status updated.');
