<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/user_management.php';

requireRequestMethod('POST');
$actor = requireAdministrator('users.assign_role');
requireValidCsrfToken();
$data = readJsonRequestBody();

try {
    $userId = parsePositiveId($_GET['id'] ?? null);
    $roleId = parsePositiveId($data['role_id'] ?? null, 'role_id');
} catch (InvalidArgumentException $exception) {
    handleUserInputException($exception);
}

if ($userId === $actor['id']) {
    forbiddenResponse('You cannot change your own account role.');
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

    $newRole = getRoleById($connection, $roleId, true);

    if ($newRole === null) {
        $connection->rollBack();
        validationError('The selected role does not exist.');
    }

    $willBeActiveAdmin = $target['status'] === 'active' && $newRole['code'] === 'admin';

    if (!canRemoveAdministrator($connection, $target, $willBeActiveAdmin)) {
        $connection->rollBack();
        jsonResponse(false, 'The last active administrator cannot be demoted.', [], 409);
    }

    $statement = $connection->prepare(
        'UPDATE users SET role_id = :role_id WHERE id = :user_id'
    );
    $statement->execute(['role_id' => $roleId, 'user_id' => $userId]);
    $connection->commit();
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }

    throw $exception;
}

logAdministrativeAction($actor['id'], 'user.role.' . $newRole['code'], $userId);
$user = getManagedUser($connection, $userId);
successResponse(['user' => formatManagedUser($connection, $user)], 'User role updated.');
