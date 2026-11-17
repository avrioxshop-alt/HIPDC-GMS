<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    $actor = requireOperationalPermission('gates.create');
    $data = requireJsonPostWithCsrf();
    try {
        $code = validateOperationalText($data, 'code', 64, true);
        $name = validateOperationalText($data, 'name', 120, true);
        $description = validateOperationalText($data, 'description', 255);
        $location = validateOperationalText($data, 'location', 160);
        $supportsEntry = validateBooleanField($data, 'supports_entry', true);
        $supportsExit = validateBooleanField($data, 'supports_exit', true);
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $code)) {
            throw new InvalidArgumentException('Gate code may contain letters, numbers, dots, underscores, or hyphens.');
        }
        if (!$supportsEntry && !$supportsExit) {
            throw new InvalidArgumentException('A gate must support entry, exit, or both directions.');
        }
    } catch (InvalidArgumentException $exception) {
        handleOperationalValidation($exception);
    }
    $connection = getDatabaseConnection();
    try {
        $connection->beginTransaction();
        $insert = $connection->prepare(
            'INSERT INTO gates
                (code,name,description,location,supports_entry,supports_exit)
             VALUES (:code,:name,:description,:location,:entry,:exit)'
        );
        $insert->execute([
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'location' => $location,
            'entry' => (int) $supportsEntry,
            'exit' => (int) $supportsExit,
        ]);
        $id = (int) $connection->lastInsertId();
        writeOperationalAudit($connection, $actor['id'], 'gate.create', 'gate', $id);
        $connection->commit();
    } catch (PDOException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        respondToOperationalConflict($exception, 'Gate code or name is already in use.');
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
    successResponse(['gate' => gateRecord($connection, $id)], 'Gate created.', 201);
}

if ($method !== 'GET') {
    header('Allow: GET, POST');
    jsonResponse(false, 'The request method is not allowed.', [], 405);
}

requireOperationalPermission('gates.view');
$pagination = getPaginationParameters();
$search = getSearchParameter();
$status = getOperationalStatusFilter(['active', 'inactive']);
$where = [];
$parameters = [];
if ($status !== null) {
    $where[] = 'g.status=:status';
    $parameters['status'] = $status;
}
if ($search !== '') {
    $where[] = '(g.code LIKE :code OR g.name LIKE :name OR g.location LIKE :location)';
    $like = '%' . addcslashes($search, '\\_%') . '%';
    $parameters['code'] = $like;
    $parameters['name'] = $like;
    $parameters['location'] = $like;
}
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$connection = getDatabaseConnection();
$count = $connection->prepare('SELECT COUNT(*) FROM gates g' . $whereSql);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT g.id,g.code,g.name,g.description,g.location,g.supports_entry,
            g.supports_exit,g.status,g.created_at,g.updated_at
     FROM gates g' . $whereSql . ' ORDER BY g.name,g.id LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $list->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$list->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$list->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$list->execute();
$gates = $list->fetchAll();
foreach ($gates as &$gate) {
    $gate['id'] = (int) $gate['id'];
    $gate['supports_entry'] = (bool) $gate['supports_entry'];
    $gate['supports_exit'] = (bool) $gate['supports_exit'];
}
unset($gate);
successResponse([
    'gates' => $gates,
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Gates loaded.');
