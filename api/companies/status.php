<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';

$actor = requireOperationalPermission('companies.manage_status');
$data = requireJsonPostWithCsrf();

try {
    $id = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

$status = $data['status'] ?? null;
if (!is_string($status) || !in_array($status, ['active', 'inactive'], true)) {
    validationError('Status must be active or inactive.');
}

$connection = getDatabaseConnection();
$connection->beginTransaction();
try {
    $statement = $connection->prepare('UPDATE companies SET status=:status WHERE id=:id');
    $statement->execute(['status' => $status, 'id' => $id]);
    if ($statement->rowCount() === 0 && companyRecord($connection, $id) === null) {
        $connection->rollBack();
        notFoundResponse('Company not found.');
    }
    writeOperationalAudit($connection, $actor['id'], 'company.status.' . $status, 'company', $id);
    $connection->commit();
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse(['company' => companyRecord($connection, $id)], 'Company status updated.');
