<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';
$actor = requireOperationalPermission('gates.update');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($_GET['id'] ?? null);
    $gateId = parsePositiveId($data['gate_id'] ?? null, 'gate_id');
    $code = validateOperationalText($data, 'code', 64, true);
    $name = validateOperationalText($data, 'name', 120, true);
    $direction = $data['direction'] ?? null;
    if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $code)) {
        throw new InvalidArgumentException('Lane code may contain letters, numbers, dots, underscores, or hyphens.');
    }
    if (!is_string($direction) || !in_array($direction, ['entry', 'exit', 'both'], true)) {
        throw new InvalidArgumentException('Lane direction must be entry, exit, or both.');
    }
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $gate = validateGateDefinition($connection, $gateId);
    if (
        ($direction === 'entry' && !(int) $gate['supports_entry'])
        || ($direction === 'exit' && !(int) $gate['supports_exit'])
        || ($direction === 'both' && (!(int) $gate['supports_entry'] || !(int) $gate['supports_exit']))
    ) {
        throw new InvalidArgumentException('The lane direction is not supported by its gate.');
    }
    $update = $connection->prepare(
        'UPDATE lanes SET gate_id=:gate_id,code=:code,name=:name,direction=:direction WHERE id=:id'
    );
    $update->execute([
        'gate_id' => $gateId,
        'code' => $code,
        'name' => $name,
        'direction' => $direction,
        'id' => $id,
    ]);
    if ($update->rowCount() === 0 && laneRecord($connection, $id) === null) {
        $connection->rollBack();
        notFoundResponse('Lane not found.');
    }
    writeOperationalAudit($connection, $actor['id'], 'lane.update', 'lane', $id);
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
    respondToOperationalConflict($exception, 'Lane code or name is already used at this gate.');
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse(['lane' => laneRecord($connection, $id)], 'Lane updated.');
