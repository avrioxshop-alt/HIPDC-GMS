<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    $actor = requireOperationalPermission('gates.create');
    $data = requireJsonPostWithCsrf();
    try {
        $gateId = parsePositiveId($data['gate_id'] ?? null, 'gate_id');
        $code = validateOperationalText($data, 'code', 64, true);
        $name = validateOperationalText($data, 'name', 120, true);
        $direction = $data['direction'] ?? null;
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $code)) {
            throw new InvalidArgumentException('Lane code may contain letters, numbers, dots, underscores, or hyphens.');
        }
        if (!is_string($direction) || !in_array($direction, ['entry', 'exit', 'both'], true)) {
            throw new InvalidArgumentException('Lane direction must be entry, exit, or both.');
        }
    } catch (InvalidArgumentException $exception) {
        handleOperationalValidation($exception);
    }
    $connection = getDatabaseConnection();
    try {
        $connection->beginTransaction();
        $gate = validateGateDefinition($connection, $gateId);
        if (
            ($direction === 'entry' && !(int) $gate['supports_entry'])
            || ($direction === 'exit' && !(int) $gate['supports_exit'])
            || ($direction === 'both' && (!(int) $gate['supports_entry'] || !(int) $gate['supports_exit']))
        ) {
            throw new InvalidArgumentException('The lane direction is not supported by its gate.');
        }
        $insert = $connection->prepare(
            'INSERT INTO lanes (gate_id,code,name,direction,created_by)
             VALUES (:gate_id,:code,:name,:direction,:created_by)'
        );
        $insert->execute([
            'gate_id' => $gateId,
            'code' => $code,
            'name' => $name,
            'direction' => $direction,
            'created_by' => $actor['id'],
        ]);
        $id = (int) $connection->lastInsertId();
        writeOperationalAudit($connection, $actor['id'], 'lane.create', 'lane', $id);
        $connection->commit();
    } catch (InvalidArgumentException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        handleOperationalValidation($exception);
    } catch (PDOException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        respondToOperationalConflict($exception, 'Lane code or name is already used at this gate.');
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
    successResponse(['lane' => laneRecord($connection, $id)], 'Lane created.', 201);
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
if (isset($_GET['gate_id']) && $_GET['gate_id'] !== '') {
    try {
        $parameters['gate_id'] = parsePositiveId($_GET['gate_id'], 'gate_id');
        $where[] = 'l.gate_id=:gate_id';
    } catch (InvalidArgumentException $exception) {
        handleOperationalValidation($exception);
    }
}
if ($status !== null) {
    $where[] = 'l.status=:status';
    $parameters['status'] = $status;
}
if ($search !== '') {
    $where[] = '(l.code LIKE :code OR l.name LIKE :name OR g.name LIKE :gate_name)';
    $like = '%' . addcslashes($search, '\\_%') . '%';
    $parameters['code'] = $like;
    $parameters['name'] = $like;
    $parameters['gate_name'] = $like;
}
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$connection = getDatabaseConnection();
$from = ' FROM lanes l JOIN gates g ON g.id=l.gate_id';
$count = $connection->prepare('SELECT COUNT(*)' . $from . $whereSql);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT l.id,l.gate_id,g.code AS gate_code,g.name AS gate_name,l.code,l.name,
            l.direction,l.status,l.created_at,l.updated_at'
    . $from . $whereSql . ' ORDER BY g.name,l.name,l.id LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$list->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$list->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$list->execute();
$lanes = $list->fetchAll();
foreach ($lanes as &$lane) {
    $lane['id'] = (int) $lane['id'];
    $lane['gate_id'] = (int) $lane['gate_id'];
}
unset($lane);
successResponse([
    'lanes' => $lanes,
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Lanes loaded.');
