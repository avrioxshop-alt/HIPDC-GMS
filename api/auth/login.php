<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

requireRequestMethod('POST');
requireValidCsrfToken();
$data = readJsonRequestBody();

$username = trim((string) ($data['username'] ?? ''));
$password = $data['password'] ?? null;

if (
    $username === ''
    || strlen($username) > 64
    || !is_string($password)
    || strlen($password) > 1024
) {
    validationError('Username and password are required.');
}

$connection = getDatabaseConnection();
$statement = $connection->prepare(
    'SELECT id, password_hash, status, auth_version
     FROM users
     WHERE username = :username
     LIMIT 1'
);
$statement->execute(['username' => $username]);
$account = $statement->fetch();
$dummyPasswordHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
$passwordMatches = password_verify(
    $password,
    $account ? $account['password_hash'] : $dummyPasswordHash
);

if (!$account || $account['status'] !== 'active' || !$passwordMatches) {
    unauthorizedResponse('Invalid username or password.');
}

if (!regenerateSessionId()) {
    throw new RuntimeException('The authenticated session could not be established.');
}

$_SESSION['user_id'] = (int) $account['id'];
$_SESSION['auth_version'] = (int) $account['auth_version'];
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$updateLogin = $connection->prepare(
    'UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :user_id'
);
$updateLogin->execute(['user_id' => (int) $account['id']]);

$user = loadUserContext($connection, (int) $account['id']);
unset($user['auth_version']);
successResponse(
    ['user' => $user],
    'Login successful.'
);
