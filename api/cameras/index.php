<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supporting.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    $actor = requireAdministrator('cameras.manage');
    $data = requireJsonPostWithCsrf();
    $connection = getDatabaseConnection();
    try {
        $camera = validateCameraFields($connection, $data);
    } catch (InvalidArgumentException $exception) {
        handleOperationalValidation($exception);
    }
    try {
        $connection->beginTransaction();
        $insert = $connection->prepare(
            'INSERT INTO cameras
                (reference,name,model,serial_reference,location,gate_id,lane_id,configuration,created_by)
             VALUES
                (:reference,:name,:model,:serial,:location,:gate_id,:lane_id,:configuration,:created_by)'
        );
        $insert->execute([
            'reference' => makeReference('CAM'),
            'name' => $camera['name'],
            'model' => $camera['model'],
            'serial' => $camera['serial_reference'],
            'location' => $camera['location'],
            'gate_id' => $camera['gate_id'],
            'lane_id' => $camera['lane_id'],
            'configuration' => $camera['configuration'] === null
                ? null
                : json_encode($camera['configuration'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_by' => $actor['id'],
        ]);
        $id = (int) $connection->lastInsertId();
        writeOperationalAudit($connection, $actor['id'], 'camera.create', 'camera', $id);
        $connection->commit();
    } catch (PDOException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        respondToOperationalConflict($exception, 'Camera reference or serial number is already in use.');
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
    successResponse(['camera' => cameraRecord($connection, $id)], 'Camera metadata registered.', 201);
}

if ($method !== 'GET') {
    header('Allow: GET, POST');
    jsonResponse(false, 'The request method is not allowed.', [], 405);
}

$actor = requireOperationalPermission('cameras.view');
$pagination = getPaginationParameters();
$search = getSearchParameter();
$status = getOperationalStatusFilter(['active', 'inactive', 'maintenance']);
$monitoring = getOperationalStatusFilter(['unknown', 'healthy', 'degraded', 'offline']);
$where = [];
$parameters = [];
if ($actor['role']['code'] !== 'admin') {
    $where[] = cameraGateScopeSql();
    $parameters['camera_scope_role'] = $actor['role']['id'];
    $parameters['camera_scope_user'] = $actor['id'];
}
if ($status !== null) {
    $where[] = 'c.status=:status';
    $parameters['status'] = $status;
}
if ($monitoring !== null) {
    $where[] = 'c.monitoring_status=:monitoring_status';
    $parameters['monitoring_status'] = $monitoring;
}
if ($search !== '') {
    $where[] = '(c.reference LIKE :reference OR c.name LIKE :name OR c.model LIKE :model
        OR c.serial_reference LIKE :serial OR g.name LIKE :gate OR l.name LIKE :lane)';
    $like = '%' . addcslashes($search, '\\_%') . '%';
    foreach (['reference', 'name', 'model', 'serial', 'gate', 'lane'] as $field) {
        $parameters[$field] = $like;
    }
}
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$from = ' FROM cameras c
          JOIN gates g ON g.id=c.gate_id
          LEFT JOIN lanes l ON l.id=c.lane_id';
$connection = getDatabaseConnection();
$count = $connection->prepare('SELECT COUNT(*)' . $from . $whereSql);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT c.id,c.reference,c.name,c.model,c.serial_reference,c.location,c.gate_id,
            g.code AS gate_code,g.name AS gate_name,c.lane_id,l.code AS lane_code,l.name AS lane_name,
            c.status,c.monitoring_status,c.monitoring_notes,c.last_monitored_at,
            c.configuration,c.created_at,c.updated_at'
    . $from . $whereSql . ' ORDER BY g.name,c.name,c.id LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$list->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$list->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$list->execute();
$cameras = $list->fetchAll();
foreach ($cameras as &$camera) {
    foreach (['id', 'gate_id', 'lane_id'] as $field) {
        $camera[$field] = $camera[$field] === null ? null : (int) $camera[$field];
    }
    if ($camera['configuration'] !== null) {
        $camera['configuration'] = json_decode($camera['configuration'], true, 16, JSON_THROW_ON_ERROR);
    }
}
unset($camera);
successResponse([
    'cameras' => $cameras,
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Camera metadata loaded.');
