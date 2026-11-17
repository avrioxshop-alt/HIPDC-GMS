<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';
$actor = requireOperationalPermission('vehicles.update');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($_GET['id'] ?? null);
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
    $update = $connection->prepare(
        'UPDATE vehicles SET company_id=:company_id,assigned_driver_id=:driver_id,
            plate_number=:plate,plate_normalized=:normalized,vehicle_type=:type,
            make=:make,model=:model,registration_number=:registration,
            registration_expiry=:expiry
         WHERE id=:id'
    );
    $update->execute([
        'company_id' => $companyId,
        'driver_id' => $driverId,
        'plate' => $plate,
        'normalized' => $normalizedPlate,
        'type' => $vehicleType,
        'make' => $make,
        'model' => $model,
        'registration' => $registrationNumber,
        'expiry' => $registrationExpiry,
        'id' => $id,
    ]);
    if ($update->rowCount() === 0 && vehicleRecord($connection, $id) === null) {
        $connection->rollBack();
        notFoundResponse('Vehicle not found.');
    }
    writeOperationalAudit($connection, $actor['id'], 'vehicle.update', 'vehicle', $id);
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
successResponse(['vehicle' => vehicleRecord($connection, $id)], 'Vehicle updated.');
