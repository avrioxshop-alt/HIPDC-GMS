<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/user_management.php';

requireRequestMethod('POST');
$actor = requireAdministrator('users.manage_status');
requireValidCsrfToken();
$data = readJsonRequestBody();

try {
    $userId = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleUserInputException($exception);
}

$status = $data['status'] ?? null;

if (!is_string($status) || !in_array($status, ['active', 'inactive'], true)) {
    validationError('Status must be active or inactive.');
}

if ($userId === $actor['id'] && $status !== 'active') {
    forbiddenResponse('You cannot deactivate your own account.');
}

$connection = getDatabaseConnection();
$connection->beginTransaction();

try {
    lockAdministratorRole($connection);
    $target = getManagedUser($connection, $userId, true);

    if ($target === null) {
        $connection->rollBack();
        notFoundResponse('User not found.');
    }

    $willBeActiveAdmin = $target['role_code'] === 'admin' && $status === 'active';

    if (!canRemoveAdministrator($connection, $target, $willBeActiveAdmin)) {
        $connection->rollBack();
        jsonResponse(false, 'The last active administrator cannot be deactivated.', [], 409);
    }

    $statement = $connection->prepare(
        'UPDATE users
         SET status = :status, auth_version = auth_version + 1
         WHERE id = :user_id'
    );
    $statement->execute(['status' => $status, 'user_id' => $userId]);
    $connection->commit();
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }

    throw $exception;
}

logAdministrativeAction($actor['id'], 'user.status.' . $status, $userId);
$user = getManagedUser($connection, $userId);
successResponse(['user' => formatManagedUser($connection, $user)], 'User status updated.');
