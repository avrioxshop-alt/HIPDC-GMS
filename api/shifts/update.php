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
    $currentStatement = $connection->prepare(
        'SELECT officer_id,gate_id,status FROM officer_shifts WHERE id=:id FOR UPDATE'
    );
    $currentStatement->execute(['id' => $id]);
    $current = $currentStatement->fetch();
    if (!$current) {
        $connection->rollBack();
        notFoundResponse('Officer shift not found.');
    }
    if ($current['status'] !== 'scheduled') {
        throw new DomainException('Only scheduled shifts can be updated.');
    }
    $shift = validateShiftFields($connection, $data);
    lockShiftResources($connection, $shift['officer_id'], $shift['gate_id']);
    $shift = validateShiftFields($connection, $data);
    assertNoShiftConflict($connection, $shift, $id);
    $update = $connection->prepare(
        'UPDATE officer_shifts
         SET officer_id=:officer_id,gate_id=:gate_id,lane_id=:lane_id,direction=:direction,
             starts_at=:starts_at,ends_at=:ends_at,notes=:notes
         WHERE id=:id'
    );
    $update->execute([
        'officer_id' => $shift['officer_id'],
        'gate_id' => $shift['gate_id'],
        'lane_id' => $shift['lane_id'],
        'direction' => $shift['direction'],
        'starts_at' => $shift['starts_at'],
        'ends_at' => $shift['ends_at'],
        'notes' => $shift['notes'],
        'id' => $id,
    ]);
    writeOperationalAudit($connection, $actor['id'], 'officer_shift.update', 'officer_shift', $id);
    createUserNotification(
        $connection,
        $shift['officer_id'],
        'shift',
        'Officer shift updated',
        'An assigned gate shift has been updated.',
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
} catch (InvalidArgumentException $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    handleOperationalValidation($exception);
} catch (PDOException $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    respondToOperationalConflict($exception, 'The Officer shift conflicts with an existing record.');
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse(['shift' => officerShiftRecord($connection, $id)], 'Officer shift updated.');
