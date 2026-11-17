<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/user_management.php';

$actor = requireAdministrator('users.view');
$connection = getDatabaseConnection();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'POST') {
    if (!in_array('users.create', $actor['permissions'], true)) {
        forbiddenResponse();
    }

    requireValidCsrfToken();
    $data = readJsonRequestBody();

    try {
        $profile = normalizeUserProfile($data);
        $password = validateAccountPassword($data['password'] ?? null);
        $roleId = parsePositiveId($data['role_id'] ?? null, 'role_id');
    } catch (InvalidArgumentException $exception) {
        handleUserInputException($exception);
    }

    $role = getRoleById($connection, $roleId);

    if ($role === null) {
        validationError('The selected role does not exist.');
    }

    try {
        $connection->beginTransaction();

        if ($role['code'] === 'admin') {
            lockAdministratorRole($connection);
        }

        $statement = $connection->prepare(
            'INSERT INTO users (role_id, username, full_name, email, password_hash, status)
             VALUES (:role_id, :username, :full_name, :email, :password_hash, \'active\')'
        );
        $statement->execute([
            'role_id' => $roleId,
            'username' => $profile['username'],
            'full_name' => $profile['name'],
            'email' => $profile['email'],
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        $userId = (int) $connection->lastInsertId();
        $connection->commit();
    } catch (PDOException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        respondToDuplicateUser($exception);
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        throw $exception;
    }

    logAdministrativeAction($actor['id'], 'user.create', $userId);
    $user = getManagedUser($connection, $userId);

    successResponse(
        ['user' => formatManagedUser($connection, $user)],
        'User created.',
        201
    );
}

if ($method !== 'GET') {
    header('Allow: GET, POST');
    jsonResponse(false, 'The request method is not allowed.', [], 405);
}

try {
    $page = parsePositiveId($_GET['page'] ?? 1, 'page');
    $perPage = parsePositiveId($_GET['per_page'] ?? 25, 'per_page');
} catch (InvalidArgumentException $exception) {
    handleUserInputException($exception);
}

if ($perPage > 100 || $page > intdiv(PHP_INT_MAX, $perPage)) {
    validationError('Pagination values are outside the supported range.');
}

$offset = ($page - 1) * $perPage;
$status = $_GET['status'] ?? '';
$search = $_GET['q'] ?? '';

if (!is_string($search) || strlen($search) > 254) {
    validationError('Search text must be 254 characters or fewer.');
}

$search = trim($search);

if ($status !== '' && !in_array($status, ['active', 'inactive'], true)) {
    validationError('Status must be active or inactive.');
}

$where = [];
$parameters = [];

if ($status !== '') {
    $where[] = 'u.status = :status';
    $parameters['status'] = $status;
}

if ($search !== '') {
    $where[] = '(u.username LIKE :search_username OR u.full_name LIKE :search_name OR u.email LIKE :search_email)';
    $searchValue = '%' . addcslashes($search, '\\_%') . '%';
    $parameters['search_username'] = $searchValue;
    $parameters['search_name'] = $searchValue;
    $parameters['search_email'] = $searchValue;
}

$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$countStatement = $connection->prepare(
    'SELECT COUNT(*) FROM users AS u' . $whereSql
);
$countStatement->execute($parameters);
$total = (int) $countStatement->fetchColumn();

$listStatement = $connection->prepare(
    'SELECT u.id, u.role_id, u.username, u.full_name, u.email, u.status,
            u.created_at, u.updated_at, u.last_login_at, r.code AS role_code,
            r.name AS role_name
     FROM users AS u
     INNER JOIN roles AS r ON r.id = u.role_id'
     . $whereSql
     . ' ORDER BY u.created_at DESC, u.id DESC LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $listStatement->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$listStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStatement->execute();

$users = array_map(
    static fn (array $user): array => formatManagedUser($connection, $user),
    $listStatement->fetchAll()
);

successResponse(
    [
        'users' => $users,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ],
    ],
    'Users loaded.'
);
