<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/user_management.php';

requireRequestMethod('POST');
$actor = requireAdministrator('users.update');
requireValidCsrfToken();

try {
    $userId = parsePositiveId($_GET['id'] ?? null);
    $profile = normalizeUserProfile(readJsonRequestBody());
} catch (InvalidArgumentException $exception) {
    handleUserInputException($exception);
}

$connection = getDatabaseConnection();

try {
    $statement = $connection->prepare(
        'UPDATE users
         SET username = :username, full_name = :full_name, email = :email
         WHERE id = :user_id'
    );
    $statement->execute([
        'username' => $profile['username'],
        'full_name' => $profile['name'],
        'email' => $profile['email'],
        'user_id' => $userId,
    ]);
} catch (PDOException $exception) {
    respondToDuplicateUser($exception);
}

$user = getManagedUser($connection, $userId);

if ($user === null) {
    notFoundResponse('User not found.');
}

logAdministrativeAction($actor['id'], 'user.update', $userId);
successResponse(['user' => formatManagedUser($connection, $user)], 'User updated.');
