<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supporting.php';

$actor = requireAdministrator('shifts.manage');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($data['id'] ?? null, 'shift id');
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $select = $connection->prepare(
        'SELECT officer_id,status FROM officer_shifts WHERE id=:id FOR UPDATE'
    );
    $select->execute(['id' => $id]);
    $shift = $select->fetch();
    if (!$shift) {
        $connection->rollBack();
        notFoundResponse('Officer shift not found.');
    }
    if ($shift['status'] !== 'scheduled') {
        throw new DomainException('Only scheduled shifts can be cancelled.');
    }
    $update = $connection->prepare(
        'UPDATE officer_shifts SET status=\'cancelled\' WHERE id=:id'
    );
    $update->execute(['id' => $id]);
    writeOperationalAudit($connection, $actor['id'], 'officer_shift.cancel', 'officer_shift', $id);
    createUserNotification(
        $connection,
        (int) $shift['officer_id'],
        'shift',
        'Officer shift cancelled',
        'An assigned gate shift has been cancelled.',
        'officer_shift',
        $id,
        $actor['id']
    );
    $connection->commit();
} catch (DomainException $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    jsonResponse(false, $exception->getMessage(), [], 409);
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse(['shift' => officerShiftRecord($connection, $id)], 'Officer shift cancelled.');
