<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';
$actor = requireOperationalPermission('drivers.update');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($_GET['id'] ?? null);
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
    $update = $connection->prepare(
        'UPDATE drivers SET company_id=:company_id,full_name=:full_name,
            identification_number=:identification_number,license_number=:license_number,phone=:phone
         WHERE id=:id'
    );
    $update->execute([
        'company_id' => $companyId,
        'full_name' => $fullName,
        'identification_number' => $identificationNumber,
        'license_number' => $licenseNumber,
        'phone' => $phone,
        'id' => $id,
    ]);
    if ($update->rowCount() === 0 && driverRecord($connection, $id) === null) {
        $connection->rollBack();
        notFoundResponse('Driver not found.');
    }
    writeOperationalAudit($connection, $actor['id'], 'driver.update', 'driver', $id);
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
successResponse(['driver' => driverRecord($connection, $id)], 'Driver updated.');
