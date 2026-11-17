<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/visitor_goods.php';

$actor = requireOperationalPermission('visitors.update');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $fields = validateVisitorFields($connection, $data);
    $lock = $connection->prepare('SELECT id FROM visitors WHERE id=:id FOR UPDATE');
    $lock->execute(['id' => $id]);
    if ($lock->fetchColumn() === false) {
        $connection->rollBack();
        notFoundResponse('Visitor not found.');
    }
    $update = $connection->prepare(
        'UPDATE visitors
         SET full_name=:full_name,document_reference=:document_reference,phone=:phone,
             email=:email,organization=:organization,company_id=:company_id,host_person=:host_person,
             purpose=:purpose,expected_arrival_at=:arrival,expected_departure_at=:departure,
             schedule_id=:schedule_id
         WHERE id=:id'
    );
    $update->execute([
        'full_name' => $fields['full_name'],
        'document_reference' => $fields['document_reference'],
        'phone' => $fields['phone'],
        'email' => $fields['email'],
        'organization' => $fields['organization'],
        'company_id' => $fields['company_id'],
        'host_person' => $fields['host_person'],
        'purpose' => $fields['purpose'],
        'arrival' => $fields['expected_arrival_at'],
        'departure' => $fields['expected_departure_at'],
        'schedule_id' => $fields['schedule_id'],
        'id' => $id,
    ]);
    writeOperationalAudit($connection, $actor['id'], 'visitor.update', 'visitor', $id);
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
    respondToOperationalConflict($exception, 'Visitor references conflict with existing records.');
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse(['visitor' => visitorRecord($connection, $id)], 'Visitor updated.');
