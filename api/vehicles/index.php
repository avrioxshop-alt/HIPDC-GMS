<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'POST') {
    $actor = requireOperationalPermission('vehicles.create');
    $data = requireJsonPostWithCsrf();

    try {
        $plate = validateOperationalText($data, 'plate_number', 40, true);
        $plate = strtoupper(preg_replace('/\s+/', ' ', $plate));
        if (!preg_match('/^[A-Z0-9][A-Z0-9 -]{1,39}$/', $plate)) {
            throw new InvalidArgumentException('Plate number must contain letters, numbers, spaces, or hyphens.');
        }
        $normalizedPlate = preg_replace('/[^A-Z0-9]/', '', $plate);
        if (strlen($normalizedPlate) < 3 || strlen($normalizedPlate) > 40) {
            throw new InvalidArgumentException('Plate number must contain 3-40 letters or digits.');
        }
        $vehicleType = $data['vehicle_type'] ?? null;
        if (!is_string($vehicleType) || !in_array(
            $vehicleType,
            ['car', 'truck', 'bus', 'motorcycle', 'other'],
            true
        )) {
            throw new InvalidArgumentException('Vehicle type must be car, truck, bus, motorcycle, or other.');
        }
        $make = validateOperationalText($data, 'make', 80);
        $model = validateOperationalText($data, 'model', 100);
        $registrationNumber = validateOperationalText($data, 'registration_number', 80);
        $registrationExpiry = validateOptionalDate(
            $data['registration_expiry'] ?? null,
            'Registration expiry'
        );
        $companyId = validateOptionalId($data, 'company_id');
        $driverId = validateOptionalId($data, 'assigned_driver_id');
    } catch (InvalidArgumentException $exception) {
        handleOperationalValidation($exception);
    }

    $connection = getDatabaseConnection();
    try {
        $connection->beginTransaction();
        validateCompanyReference($connection, $companyId);
        $driver = validateDriverReference($connection, $driverId, $companyId);
        if ($companyId === null && $driver !== null && $driver['company_id'] !== null) {
            $companyId = (int) $driver['company_id'];
        }
        if ($driverId !== null) {
            validateVehicleCompanyDriverPair($connection, $companyId, $driverId);
        }
        $insert = $connection->prepare(
            'INSERT INTO vehicles
                (company_id,assigned_driver_id,plate_number,plate_normalized,vehicle_type,
                 make,model,registration_number,registration_expiry,created_by)
             VALUES
                (:company_id,:driver_id,:plate,:normalized,:type,:make,:model,:registration,
                 :expiry,:created_by)'
        );
        $insert->execute([
            'company_id' => $companyId,
            'driver_id' => $driverId,
            'plate' => $plate,
            'normalized' => $normalizedPlate,
            'type' => $vehicleType,
            'make' => $make,
            'model' => $model,
            'registration' => $registrationNumber,
            'expiry' => $registrationExpiry,
            'created_by' => $actor['id'],
        ]);
        $id = (int) $connection->lastInsertId();
        writeOperationalAudit($connection, $actor['id'], 'vehicle.create', 'vehicle', $id);
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
        respondToOperationalConflict($exception, 'Vehicle plate or registration number is already in use.');
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }

    successResponse(['vehicle' => vehicleRecord($connection, $id)], 'Vehicle created.', 201);
}

if ($method !== 'GET') {
    header('Allow: GET, POST');
    jsonResponse(false, 'The request method is not allowed.', [], 405);
}

requireOperationalPermission('vehicles.view');
$pagination = getPaginationParameters();
$search = getSearchParameter();
$status = getOperationalStatusFilter(['active', 'inactive', 'blocked']);
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
    $where[] = 'v.status=:status';
    $parameters['status'] = $status;
}
if ($companyId !== null) {
    $where[] = 'v.company_id=:company_id';
    $parameters['company_id'] = $companyId;
}
if ($search !== '') {
    $where[] = '(v.plate_number LIKE :plate OR v.model LIKE :model OR c.name LIKE :company OR d.full_name LIKE :driver)';
    $like = '%' . addcslashes($search, '\\_%') . '%';
    $parameters['plate'] = $like;
    $parameters['model'] = $like;
    $parameters['company'] = $like;
    $parameters['driver'] = $like;
}
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$connection = getDatabaseConnection();
$from = ' FROM vehicles v LEFT JOIN companies c ON c.id=v.company_id
          LEFT JOIN drivers d ON d.id=v.assigned_driver_id';
$count = $connection->prepare('SELECT COUNT(*)' . $from . $whereSql);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT v.id,v.company_id,c.name AS company_name,v.assigned_driver_id,
            d.full_name AS driver_name,v.plate_number,v.vehicle_type,v.make,v.model,
            v.registration_number,v.registration_expiry,v.status,v.created_at,v.updated_at'
    . $from . $whereSql . ' ORDER BY v.plate_normalized,v.id LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$list->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$list->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$list->execute();
successResponse([
    'vehicles' => $list->fetchAll(),
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Vehicles loaded.');
