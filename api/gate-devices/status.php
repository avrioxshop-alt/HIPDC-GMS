<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/gate_devices.php';

$actor = requireAdministrator('gate_devices.manage');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($_GET['id'] ?? null);
    $status = $data['status'] ?? null;
    if (!is_string($status) || !in_array($status, ['active', 'inactive'], true)) {
        throw new InvalidArgumentException('Device status must be active or inactive.');
    }
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $lock = $connection->prepare(
        'SELECT gate_id,direction,status FROM gate_devices WHERE id=:id FOR UPDATE'
    );
    $lock->execute(['id' => $id]);
    $device = $lock->fetch();
    if (!$device) {
        $connection->rollBack();
        notFoundResponse('Gate device not found.');
    }
    requireGateDeviceScope($connection, $actor, (int) $device['gate_id'], $device['direction']);
    if ($status === 'active') {
        $gate = $connection->prepare(
            'SELECT status,supports_entry,supports_exit
             FROM gates WHERE id=:id FOR UPDATE'
        );
        $gate->execute(['id' => $device['gate_id']]);
        $gateRecord = $gate->fetch();
        $lane = $connection->prepare(
            'SELECT id,status,gate_id,direction FROM lanes WHERE id=(
                SELECT lane_id FROM gate_devices WHERE id=:id
            ) FOR UPDATE'
        );
        $lane->execute(['id' => $id]);
        $location = $lane->fetch();
        if (
            !$gateRecord
            || $gateRecord['status'] !== 'active'
            || ($device['direction'] === 'entry' && (int) $gateRecord['supports_entry'] !== 1)
            || ($device['direction'] === 'exit' && (int) $gateRecord['supports_exit'] !== 1)
            || !$location
            || $location['status'] !== 'active'
            || (int) $location['gate_id'] !== (int) $device['gate_id']
            || ($location['direction'] !== 'both' && $location['direction'] !== $device['direction'])
        ) {
            throw new InvalidArgumentException('An active compatible gate and lane are required.');
        }
    }
    $update = $connection->prepare(
        'UPDATE gate_devices SET status=:status WHERE id=:id'
    );
    $update->execute(['status' => $status, 'id' => $id]);
    writeOperationalAudit(
        $connection,
        $actor['id'],
        'gate_device.' . $status,
        'gate_device',
        $id
    );
    $connection->commit();
} catch (InvalidArgumentException $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    handleOperationalValidation($exception);
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}

successResponse(['device' => gateDeviceRecord($connection, $id)], 'Gate device status updated.');
