<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';

$actor = requireOperationalPermission('companies.update');
$data = requireJsonPostWithCsrf();

try {
    $id = parsePositiveId($_GET['id'] ?? null);
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
        'UPDATE companies SET name=:name, registration_number=:registration_number,
            contact_person=:contact_person, phone=:phone, email=:email, address=:address
         WHERE id=:id'
    );
    $statement->execute([
        'name' => $name,
        'registration_number' => $registrationNumber,
        'contact_person' => $contactPerson,
        'phone' => $phone,
        'email' => $email,
        'address' => $address,
        'id' => $id,
    ]);
    if ($statement->rowCount() === 0 && companyRecord($connection, $id) === null) {
        $connection->rollBack();
        notFoundResponse('Company not found.');
    }
    writeOperationalAudit($connection, $actor['id'], 'company.update', 'company', $id);
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
successResponse(['company' => companyRecord($connection, $id)], 'Company updated.');
