<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/user_management.php';

requireRequestMethod('GET');
requireAdministrator('users.view');

try {
    $userId = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleUserInputException($exception);
}

$connection = getDatabaseConnection();
$user = getManagedUser($connection, $userId);

if ($user === null) {
    notFoundResponse('User not found.');
}

$permissionStatement = $connection->prepare(
    'SELECT p.code
     FROM role_permissions AS rp
     INNER JOIN permissions AS p ON p.id = rp.permission_id
     WHERE rp.role_id = :role_id
     ORDER BY p.code'
);
$permissionStatement->execute(['role_id' => $user['role_id']]);

successResponse(
    [
        'user' => formatManagedUser($connection, $user),
        'permissions' => array_column($permissionStatement->fetchAll(), 'code'),
    ],
    'User loaded.'
);
