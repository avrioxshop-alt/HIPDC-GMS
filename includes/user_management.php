<?php

require_once __DIR__ . '/auth.php';

function parsePositiveId(mixed $value, string $field = 'id'): int
{
    if (
        is_int($value)
        && $value > 0
    ) {
        return $value;
    }

    if (
        !is_string($value)
        || $value === ''
        || !ctype_digit($value)
        || strlen($value) > strlen((string) PHP_INT_MAX)
        || (int) $value < 1
    ) {
        throw new InvalidArgumentException($field . ' must be a positive integer.');
    }

    return (int) $value;
}

function normalizeUserProfile(array $data): array
{
    foreach (['username', 'name', 'email'] as $field) {
        if (!isset($data[$field]) || !is_string($data[$field])) {
            throw new InvalidArgumentException(ucfirst($field) . ' must be a string.');
        }
    }

    $username = trim($data['username']);
    $name = trim($data['name']);
    $email = strtolower(trim($data['email']));
    $nameLength = preg_match_all('/./us', $name);

    if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
        throw new InvalidArgumentException(
            'Username must be 3-64 characters and use letters, numbers, dots, underscores, or hyphens.'
        );
    }

    if (
        $nameLength === false
        || $nameLength < 1
        || $nameLength > 120
        || preg_match('/[\x00-\x1F\x7F]/u', $name) !== 0
    ) {
        throw new InvalidArgumentException('Name must contain 1-120 valid characters.');
    }

    if (
        strlen($email) > 254
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false
    ) {
        throw new InvalidArgumentException('A valid email address is required.');
    }

    return [
        'username' => $username,
        'name' => $name,
        'email' => $email,
    ];
}

function validateAccountPassword(mixed $password): string
{
    if (
        !is_string($password)
        || strlen($password) < 12
        || strlen($password) > 72
    ) {
        throw new InvalidArgumentException('Password must be 12-72 bytes long.');
    }

    return $password;
}

function getRoleById(PDO $connection, int $roleId, bool $lock = false): ?array
{
    $sql = 'SELECT id, code, name FROM roles WHERE id = :role_id LIMIT 1';

    if ($lock) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $connection->prepare($sql);
    $statement->execute(['role_id' => $roleId]);
    $role = $statement->fetch();

    if (!$role) {
        return null;
    }

    $role['id'] = (int) $role['id'];
    return $role;
}

function lockAdministratorRole(PDO $connection): int
{
    $statement = $connection->query(
        'SELECT id FROM roles WHERE code = \'admin\' FOR UPDATE'
    );
    $roleId = $statement->fetchColumn();

    if ($roleId === false) {
        throw new RuntimeException('The Admin role is missing. Run the authentication migration.');
    }

    return (int) $roleId;
}

function countActiveAdministrators(PDO $connection): int
{
    $statement = $connection->query(
        'SELECT COUNT(*)
         FROM users AS u
         INNER JOIN roles AS r ON r.id = u.role_id
         WHERE r.code = \'admin\' AND u.status = \'active\''
    );

    return (int) $statement->fetchColumn();
}

function canRemoveAdministrator(PDO $connection, array $user, bool $willBeActiveAdmin): bool
{
    if (
        $user['role_code'] !== 'admin'
        || $user['status'] !== 'active'
        || $willBeActiveAdmin
    ) {
        return true;
    }

    return countActiveAdministrators($connection) > 1;
}

function getManagedUser(PDO $connection, int $userId, bool $lock = false): ?array
{
    $sql = 'SELECT u.id, u.role_id, u.username, u.full_name, u.email, u.status,
                   u.created_at, u.updated_at, u.last_login_at, r.code AS role_code,
                   r.name AS role_name
            FROM users AS u
            INNER JOIN roles AS r ON r.id = u.role_id
            WHERE u.id = :user_id
            LIMIT 1';

    if ($lock) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $connection->prepare($sql);
    $statement->execute(['user_id' => $userId]);
    $user = $statement->fetch();

    if (!$user) {
        return null;
    }

    $user['id'] = (int) $user['id'];
    $user['role_id'] = (int) $user['role_id'];
    return $user;
}

function formatManagedUser(PDO $connection, array $user, bool $includeGates = true): array
{
    $formatted = [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'name' => $user['full_name'],
        'email' => $user['email'],
        'status' => $user['status'],
        'role' => [
            'id' => (int) $user['role_id'],
            'code' => $user['role_code'],
            'name' => $user['role_name'],
        ],
        'created_at' => $user['created_at'],
        'updated_at' => $user['updated_at'],
        'last_login_at' => $user['last_login_at'],
    ];

    if ($includeGates) {
        $formatted['gate_assignments'] = getUserGateAssignments($connection, $formatted['id']);
    }

    return $formatted;
}

function logAdministrativeAction(int $actorId, string $action, int $targetId): void
{
    error_log(
        'HIPDC administrative action: actor_id=' . $actorId
        . ' action=' . $action
        . ' target_id=' . $targetId
    );
}

function handleUserInputException(InvalidArgumentException $exception): void
{
    validationError($exception->getMessage());
}

function respondToDuplicateUser(PDOException $exception): void
{
    if ($exception->getCode() === '23000') {
        jsonResponse(false, 'Username or email is already in use.', [], 409);
    }

    throw $exception;
}
