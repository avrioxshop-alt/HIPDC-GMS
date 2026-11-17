<?php

require_once __DIR__ . '/../config/database.php';

function requireRequestMethod(string $method): void
{
    $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($requestMethod !== strtoupper($method)) {
        header('Allow: ' . strtoupper($method));
        jsonResponse(false, 'The request method is not allowed.', [], 405);
    }
}

function readJsonRequestBody(): array
{
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));

    if ($contentType !== 'application/json') {
        jsonResponse(false, 'A JSON request body is required.', [], 415);
    }

    $body = file_get_contents('php://input');

    if (!is_string($body) || strlen($body) > 1048576) {
        jsonResponse(false, 'The request body is invalid or too large.', [], 413);
    }

    try {
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        validationError('The request body must contain valid JSON.');
    }

    if (!is_array($data)) {
        validationError('The request body must be a JSON object.');
    }

    return $data;
}

function loadUserContext(PDO $connection, int $userId): ?array
{
    $statement = $connection->prepare(
        'SELECT u.id, u.username, u.full_name, u.email, u.status, u.auth_version, u.created_at,
                u.updated_at, u.last_login_at, r.id AS role_id, r.code AS role_code,
                r.name AS role_name
         FROM users AS u
         INNER JOIN roles AS r ON r.id = u.role_id
         WHERE u.id = :user_id
         LIMIT 1'
    );
    $statement->execute(['user_id' => $userId]);
    $user = $statement->fetch();

    if (!$user || $user['status'] !== 'active') {
        return null;
    }

    $permissionStatement = $connection->prepare(
        'SELECT p.code
         FROM role_permissions AS rp
         INNER JOIN permissions AS p ON p.id = rp.permission_id
         WHERE rp.role_id = :role_id
         ORDER BY p.code'
    );
    $permissionStatement->execute(['role_id' => $user['role_id']]);

    return [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'name' => $user['full_name'],
        'email' => $user['email'],
        'status' => $user['status'],
        'auth_version' => (int) $user['auth_version'],
        'created_at' => $user['created_at'],
        'updated_at' => $user['updated_at'],
        'last_login_at' => $user['last_login_at'],
        'role' => [
            'id' => (int) $user['role_id'],
            'code' => $user['role_code'],
            'name' => $user['role_name'],
        ],
        'permissions' => array_column($permissionStatement->fetchAll(), 'code'),
    ];
}

function requireAuthenticatedUser(): array
{
    $userId = $_SESSION['user_id'] ?? null;

    $authVersion = $_SESSION['auth_version'] ?? null;

    if (
        !is_int($userId)
        || $userId < 1
        || !is_int($authVersion)
        || $authVersion < 0
    ) {
        unauthorizedResponse();
    }

    $user = loadUserContext(getDatabaseConnection(), $userId);

    if ($user === null || $user['auth_version'] !== $authVersion) {
        unset($_SESSION['user_id'], $_SESSION['auth_version']);
        regenerateSessionId();
        unauthorizedResponse('The session is no longer valid.');
    }

    return $user;
}

function requireAdministrator(string $permission): array
{
    $user = requireAuthenticatedUser();

    if (
        $user['role']['code'] !== 'admin'
        || !in_array($permission, $user['permissions'], true)
    ) {
        forbiddenResponse();
    }

    return $user;
}

function userCanAccessGate(
    PDO $connection,
    int $userId,
    int $gateId,
    string $direction
): bool {
    if (!in_array($direction, ['entry', 'exit'], true)) {
        return false;
    }

    $statement = $connection->prepare(
        'SELECT 1
         FROM users AS u
         INNER JOIN role_permissions AS rp ON rp.role_id = u.role_id
         INNER JOIN permissions AS p ON p.id = rp.permission_id
         INNER JOIN user_gate_assignments AS uga ON uga.user_id = u.id
         INNER JOIN gates AS g ON g.id = uga.gate_id
         WHERE u.id = :user_id
           AND u.status = \'active\'
           AND g.id = :gate_id
           AND g.status = \'active\'
           AND p.code = :permission
           AND uga.direction IN (:assignment_direction, \'both\')
           AND (
                (:gate_direction_entry = \'entry\' AND g.supports_entry = 1)
                OR (:gate_direction_exit = \'exit\' AND g.supports_exit = 1)
           )
         LIMIT 1'
    );
    $statement->execute([
        'user_id' => $userId,
        'gate_id' => $gateId,
        'permission' => 'gate.' . $direction,
        'assignment_direction' => $direction,
        'gate_direction_entry' => $direction,
        'gate_direction_exit' => $direction,
    ]);

    return $statement->fetchColumn() !== false;
}

function requireGateAccess(int $gateId, string $direction): array
{
    $user = requireAuthenticatedUser();

    if (!userCanAccessGate(getDatabaseConnection(), $user['id'], $gateId, $direction)) {
        forbiddenResponse('You do not have permission for this gate direction.');
    }

    return $user;
}

function getUserGateAssignments(PDO $connection, int $userId): array
{
    $statement = $connection->prepare(
        'SELECT g.id AS gate_id, g.code AS gate_code, g.name AS gate_name,
                uga.direction, uga.created_at AS assigned_at
         FROM user_gate_assignments AS uga
         INNER JOIN gates AS g ON g.id = uga.gate_id
         WHERE uga.user_id = :user_id
         ORDER BY g.name, g.id'
    );
    $statement->execute(['user_id' => $userId]);

    return array_map(
        static function (array $assignment): array {
            return [
                'gate_id' => (int) $assignment['gate_id'],
                'gate_code' => $assignment['gate_code'],
                'gate_name' => $assignment['gate_name'],
                'direction' => $assignment['direction'],
                'assigned_at' => $assignment['assigned_at'],
            ];
        },
        $statement->fetchAll()
    );
}
