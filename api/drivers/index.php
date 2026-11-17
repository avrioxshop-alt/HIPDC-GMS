<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'POST') {
    $actor = requireOperationalPermission('drivers.create');
    $data = requireJsonPostWithCsrf();
    try {
        $fullName = validateOperationalText($data, 'full_name', 120, true);
        $identificationNumber = validateOperationalText($data, 'identification_number', 80);
        $licenseNumber = validateOperationalText($data, 'license_number', 80);
        $phone = validateOptionalPhone($data);
        $companyId = validateOptionalId($data, 'company_id');
    } catch (InvalidArgumentException $exception) {
        handleOperationalValidation($exception);
    }
    $connection = getDatabaseConnection();
    try {
        $connection->beginTransaction();
        validateCompanyReference($connection, $companyId);
        $insert = $connection->prepare(
            'INSERT INTO drivers
                (company_id, full_name, identification_number, license_number, phone, created_by)
             VALUES (:company_id, :full_name, :identification_number, :license_number, :phone, :created_by)'
        );
        $insert->execute([
            'company_id' => $companyId,
            'full_name' => $fullName,
            'identification_number' => $identificationNumber,
            'license_number' => $licenseNumber,
            'phone' => $phone,
            'created_by' => $actor['id'],
        ]);
        $id = (int) $connection->lastInsertId();
        writeOperationalAudit($connection, $actor['id'], 'driver.create', 'driver', $id);
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
        respondToOperationalConflict($exception, 'Driver identification or license number is already in use.');
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
    successResponse(['driver' => driverRecord($connection, $id)], 'Driver created.', 201);
}

if ($method !== 'GET') {
    header('Allow: GET, POST');
    jsonResponse(false, 'The request method is not allowed.', [], 405);
}

requireOperationalPermission('drivers.view');
$pagination = getPaginationParameters();
$search = getSearchParameter();
$status = getOperationalStatusFilter(['active', 'inactive']);
$companyId = null;
if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
    try {
        $companyId = parsePositiveId($_GET['company_id'], 'company_id');
    } catch (InvalidArgumentException $exception) {
        handleOperationalValidation($exception);
    }
}
$where = [];
$parameters = [];
if ($status !== null) {
    $where[] = 'd.status=:status';
    $parameters['status'] = $status;
}
if ($companyId !== null) {
    $where[] = 'd.company_id=:company_id';
    $parameters['company_id'] = $companyId;
}
if ($search !== '') {
    $where[] = '(d.full_name LIKE :name OR d.identification_number LIKE :identification OR d.license_number LIKE :license)';
    $like = '%' . addcslashes($search, '\\_%') . '%';
    $parameters['name'] = $like;
    $parameters['identification'] = $like;
    $parameters['license'] = $like;
}
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$connection = getDatabaseConnection();
$count = $connection->prepare('SELECT COUNT(*) FROM drivers d' . $whereSql);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT d.id,d.company_id,c.name AS company_name,d.full_name,d.identification_number,
            d.license_number,d.phone,d.status,d.created_at,d.updated_at
     FROM drivers d LEFT JOIN companies c ON c.id=d.company_id'
     . $whereSql . ' ORDER BY d.full_name,d.id LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$list->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$list->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$list->execute();
successResponse([
    'drivers' => $list->fetchAll(),
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Drivers loaded.');
