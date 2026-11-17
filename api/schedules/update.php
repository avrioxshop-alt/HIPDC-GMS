<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';
$actor = requireOperationalPermission('schedules.update');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($_GET['id'] ?? null);
    $companyId = validateOptionalId($data, 'company_id');
    $vehicleId = validateOptionalId($data, 'vehicle_id');
    $driverId = validateOptionalId($data, 'driver_id');
    if ($vehicleId === null || $driverId === null) {
        throw new InvalidArgumentException('Vehicle and driver are required.');
    }
    $gateId = validateOptionalId($data, 'gate_id');
    $laneId = validateOptionalId($data, 'lane_id');
    $direction = $data['direction'] ?? null;
    if (!is_string($direction) || !in_array($direction, ['entry', 'exit'], true)) {
        throw new InvalidArgumentException('Direction must be entry or exit.');
    }
    $scheduledAt = validateOperationalDateTime($data['scheduled_at'] ?? null);
    $purpose = validateOperationalText($data, 'purpose', 120, true);
    $cargoSummary = validateOperationalText($data, 'cargo_summary', 500);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $lock = $connection->prepare('SELECT status FROM schedules WHERE id=:id FOR UPDATE');
    $lock->execute(['id' => $id]);
    $lockedScheduleStatus = $lock->fetchColumn();
    if ($lockedScheduleStatus === false) {
        $connection->rollBack();
        notFoundResponse('Schedule not found.');
    }
    if ($lockedScheduleStatus !== 'scheduled') {
        throw new InvalidArgumentException('Only scheduled records can be updated.');
    }
    $current = scheduleRecord($connection, $id);
    if ($current['gate_id'] !== null) {
        requireAssignedGateAccess($actor, $current['gate_id'], $current['direction']);
    }
    $vehicle = validateVehicleReference($connection, $vehicleId, $companyId);
    $driver = validateDriverReference($connection, $driverId, $companyId);
    if ($companyId === null) {
        $vehicleCompanyId = $vehicle['company_id'] === null ? null : (int) $vehicle['company_id'];
        $driverCompanyId = $driver['company_id'] === null ? null : (int) $driver['company_id'];
        if ($vehicleCompanyId !== null && $driverCompanyId !== null && $vehicleCompanyId !== $driverCompanyId) {
            throw new InvalidArgumentException('Vehicle and driver belong to different companies.');
        }
        $companyId = $vehicleCompanyId ?? $driverCompanyId;
    }
    validateCompanyReference($connection, $companyId);
    $lane = validateLaneReference($connection, $laneId, $gateId, $direction);
    if ($gateId !== null) {
        validateGateDirection($connection, $gateId, $direction);
        requireAssignedGateAccess($actor, $gateId, $direction);
        if ($lane !== null && $lane['status'] !== 'active') {
            throw new InvalidArgumentException('The selected lane is inactive.');
        }
    }
    $update = $connection->prepare(
        'UPDATE schedules SET company_id=:company_id,vehicle_id=:vehicle_id,driver_id=:driver_id,
            gate_id=:gate_id,lane_id=:lane_id,direction=:direction,scheduled_at=:scheduled_at,
            purpose=:purpose,cargo_summary=:cargo_summary WHERE id=:id'
    );
    $update->execute([
        'company_id' => $companyId,
        'vehicle_id' => $vehicleId,
        'driver_id' => $driverId,
        'gate_id' => $gateId,
        'lane_id' => $laneId,
        'direction' => $direction,
        'scheduled_at' => $scheduledAt,
        'purpose' => $purpose,
        'cargo_summary' => $cargoSummary,
        'id' => $id,
    ]);
    writeOperationalAudit($connection, $actor['id'], 'schedule.update', 'schedule', $id);
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
    respondToOperationalConflict($exception, 'Schedule references conflict with existing records.');
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse(['schedule' => scheduleRecord($connection, $id)], 'Schedule updated.');
