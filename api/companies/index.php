<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'POST') {
    $actor = requireOperationalPermission('companies.create');
    $data = requireJsonPostWithCsrf();

    try {
        $name = validateOperationalText($data, 'name', 160, true);
        $registrationNumber = validateOperationalText($data, 'registration_number', 80);
        $contactPerson = validateOperationalText($data, 'contact_person', 120);
        $phone = validateOptionalPhone($data);
        $email = validateOptionalEmail($data);
        $address = validateOperationalText($data, 'address', 255);
    } catch (InvalidArgumentException $exception) {
        handleOperationalValidation($exception);
    }

    $connection = getDatabaseConnection();
    try {
        $connection->beginTransaction();
        $statement = $connection->prepare(
            'INSERT INTO companies
                (name, registration_number, contact_person, phone, email, address, created_by)
             VALUES
                (:name, :registration_number, :contact_person, :phone, :email, :address, :created_by)'
        );
        $statement->execute([
            'name' => $name,
            'registration_number' => $registrationNumber,
            'contact_person' => $contactPerson,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'created_by' => $actor['id'],
        ]);
        $id = (int) $connection->lastInsertId();
        writeOperationalAudit($connection, $actor['id'], 'company.create', 'company', $id);
        $connection->commit();
    } catch (PDOException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        respondToOperationalConflict($exception, 'Company registration number is already in use.');
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }

    successResponse(['company' => companyRecord($connection, $id)], 'Company created.', 201);
}

if ($method !== 'GET') {
    header('Allow: GET, POST');
    jsonResponse(false, 'The request method is not allowed.', [], 405);
}

$actor = requireOperationalPermission('companies.view');
$pagination = getPaginationParameters();
$search = getSearchParameter();
$status = getOperationalStatusFilter(['active', 'inactive']);
$where = [];
$parameters = [];

if ($status !== null) {
    $where[] = 'c.status = :status';
    $parameters['status'] = $status;
}
if ($search !== '') {
    $where[] = '(c.name LIKE :name OR c.registration_number LIKE :registration_number)';
    $parameters['name'] = '%' . addcslashes($search, '\\_%') . '%';
    $parameters['registration_number'] = '%' . addcslashes($search, '\\_%') . '%';
}

$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$connection = getDatabaseConnection();
$count = $connection->prepare('SELECT COUNT(*) FROM companies c' . $whereSql);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT c.id, c.name, c.registration_number, c.contact_person, c.phone, c.email,
            c.address, c.status, c.created_at, c.updated_at
     FROM companies c' . $whereSql . ' ORDER BY c.name, c.id LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $list->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$list->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$list->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$list->execute();

successResponse([
    'companies' => $list->fetchAll(),
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Companies loaded.');
