<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/user_management.php';

requireRequestMethod('POST');
$actor = requireAdministrator('users.change_password');
requireValidCsrfToken();
$data = readJsonRequestBody();

try {
    $userId = parsePositiveId($_GET['id'] ?? null);
    $password = validateAccountPassword($data['password'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleUserInputException($exception);
}

$connection = getDatabaseConnection();

if (getManagedUser($connection, $userId) === null) {
    notFoundResponse('User not found.');
}

$statement = $connection->prepare(
    'UPDATE users
     SET password_hash = :password_hash, auth_version = auth_version + 1
     WHERE id = :user_id'
);
$statement->execute([
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'user_id' => $userId,
]);

logAdministrativeAction($actor['id'], 'user.password.change', $userId);
successResponse([], 'User password changed.');
