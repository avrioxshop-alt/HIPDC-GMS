<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';
$actor = requireOperationalPermission('gates.update');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($_GET['id'] ?? null);
    $code = validateOperationalText($data, 'code', 64, true);
    $name = validateOperationalText($data, 'name', 120, true);
    $description = validateOperationalText($data, 'description', 255);
    $location = validateOperationalText($data, 'location', 160);
    $supportsEntry = validateBooleanField($data, 'supports_entry', true);
    $supportsExit = validateBooleanField($data, 'supports_exit', true);
    if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $code)) {
        throw new InvalidArgumentException('Gate code may contain letters, numbers, dots, underscores, or hyphens.');
    }
    if (!$supportsEntry && !$supportsExit) {
        throw new InvalidArgumentException('A gate must support entry, exit, or both directions.');
    }
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $gateCheck = $connection->prepare(
        'SELECT id FROM gates WHERE id=:id FOR UPDATE'
    );
    $gateCheck->execute(['id' => $id]);
    if ($gateCheck->fetchColumn() === false) {
        $connection->rollBack();
        notFoundResponse('Gate not found.');
    }
    $laneCheck = $connection->prepare(
        'SELECT COUNT(*)
         FROM lanes
         WHERE gate_id=:id AND status=\'active\'
           AND (
                (direction=\'entry\' AND :entry_off=0)
                OR (direction=\'exit\' AND :exit_off=0)
                OR (direction=\'both\' AND (:both_entry_off=0 OR :both_exit_off=0))
           )'
    );
    $laneCheck->execute([
        'id' => $id,
        'entry_off' => (int) $supportsEntry,
        'exit_off' => (int) $supportsExit,
        'both_entry_off' => (int) $supportsEntry,
        'both_exit_off' => (int) $supportsExit,
    ]);
    if ((int) $laneCheck->fetchColumn() > 0) {
        throw new InvalidArgumentException(
            'Gate directions cannot exclude directions used by active lanes.'
        );
    }
    $update = $connection->prepare(
        'UPDATE gates SET code=:code,name=:name,description=:description,location=:location,
            supports_entry=:entry,supports_exit=:exit WHERE id=:id'
    );
    $update->execute([
        'code' => $code,
        'name' => $name,
        'description' => $description,
        'location' => $location,
        'entry' => (int) $supportsEntry,
        'exit' => (int) $supportsExit,
        'id' => $id,
    ]);
    writeOperationalAudit($connection, $actor['id'], 'gate.update', 'gate', $id);
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
    respondToOperationalConflict($exception, 'Gate code or name is already in use.');
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse(['gate' => gateRecord($connection, $id)], 'Gate updated.');
