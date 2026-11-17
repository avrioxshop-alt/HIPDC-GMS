<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';

$actor = requireOperationalPermission('approvals.submit');
$data = requireJsonPostWithCsrf();
try {
    $requestType = $data['request_type'] ?? null;
    $types = ['access', 'exit_clearance', 'vehicle_registration', 'schedule_change'];
    if (!is_string($requestType) || !in_array($requestType, $types, true)) {
        throw new InvalidArgumentException('Request type is invalid.');
    }
    $title = validateOperationalText($data, 'title', 200, true);
    $note = validateOperationalText($data, 'note', 4000);
    $companyId = validateOptionalId($data, 'company_id');
    $driverId = validateOptionalId($data, 'driver_id');
    $vehicleId = validateOptionalId($data, 'vehicle_id');
    $scheduleId = validateOptionalId($data, 'schedule_id');
    $gateId = validateOptionalId($data, 'gate_id');
    $laneId = validateOptionalId($data, 'lane_id');
    $direction = $data['direction'] ?? null;
    if ($direction !== null && (!is_string($direction) || !in_array($direction, ['entry', 'exit'], true))) {
        throw new InvalidArgumentException('Direction must be entry or exit.');
    }
    $details = $data['details'] ?? null;
    if ($details !== null && (!is_array($details) || strlen(json_encode($details)) > 16384)) {
        throw new InvalidArgumentException('Details must be a JSON object no larger than 16 KB.');
    }
    if ($details !== null && $details !== [] && array_is_list($details)) {
        throw new InvalidArgumentException('Details must be a JSON object.');
    }
    if ($requestType === 'access' && ($gateId === null || $direction === null)) {
        throw new InvalidArgumentException('Access requests require a gate and direction.');
    }
    if ($requestType === 'exit_clearance' && ($gateId === null || $direction !== 'exit')) {
        throw new InvalidArgumentException('Exit clearance requests require an exit gate.');
    }
    if ($requestType === 'schedule_change' && $scheduleId === null) {
        throw new InvalidArgumentException('Schedule change requests require a schedule.');
    }
    if ($requestType === 'vehicle_registration' && $companyId === null) {
        throw new InvalidArgumentException('Vehicle registration requests require a company.');
    }
    if ($gateId === null && $direction !== null) {
        throw new InvalidArgumentException('A gate is required when a direction is specified.');
    }
    if (
        $companyId === null && $driverId === null && $vehicleId === null
        && $scheduleId === null && $gateId === null
    ) {
        throw new InvalidArgumentException('At least one related record or gate is required.');
    }
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    if ($companyId !== null) {
        requireEntity($connection, 'companies', $companyId, '');
    }
    if ($driverId !== null) {
        $driver = requireEntity($connection, 'drivers', $driverId, '');
        if ($companyId !== null && $driver['company_id'] !== null && (int) $driver['company_id'] !== $companyId) {
            throw new InvalidArgumentException('The driver is not associated with the selected company.');
        }
    }
    if ($vehicleId !== null) {
        $vehicle = requireEntity($connection, 'vehicles', $vehicleId, '');
        if ($companyId !== null && $vehicle['company_id'] !== null && (int) $vehicle['company_id'] !== $companyId) {
            throw new InvalidArgumentException('The vehicle is not associated with the selected company.');
        }
        if ($driverId !== null && $vehicle['assigned_driver_id'] !== null
            && (int) $vehicle['assigned_driver_id'] !== $driverId) {
            throw new InvalidArgumentException('The vehicle is assigned to a different driver.');
        }
    }
    if ($scheduleId !== null) {
        $schedule = scheduleRecord($connection, $scheduleId);
        if ($schedule === null) {
            throw new InvalidArgumentException('The selected schedule does not exist.');
        }
        if ($requestType === 'schedule_change' && $schedule['status'] !== 'scheduled') {
            throw new InvalidArgumentException('Only a scheduled record can be changed by approval.');
        }
        if ($companyId !== null && $schedule['company_id'] !== null && $schedule['company_id'] !== $companyId) {
            throw new InvalidArgumentException('The schedule is not associated with the selected company.');
        }
        if ($vehicleId !== null && $schedule['vehicle_id'] !== $vehicleId) {
            throw new InvalidArgumentException('The schedule is not associated with the selected vehicle.');
        }
        if ($driverId !== null && $schedule['driver_id'] !== $driverId) {
            throw new InvalidArgumentException('The schedule is not associated with the selected driver.');
        }
        if (
            $gateId !== null
            && $schedule['gate_id'] !== null
            && $gateId !== $schedule['gate_id']
        ) {
            throw new InvalidArgumentException('The selected gate does not match the schedule.');
        }
        if (
            $direction !== null
            && $schedule['direction'] !== $direction
        ) {
            throw new InvalidArgumentException('The selected direction does not match the schedule.');
        }
        if ($gateId === null && $schedule['gate_id'] !== null) {
            $gateId = $schedule['gate_id'];
            $direction = $direction ?? $schedule['direction'];
        }
    }
    $lane = validateLaneReference($connection, $laneId, $gateId, $direction);
    if ($gateId !== null) {
        validateGateDirection($connection, $gateId, (string) $direction);
        requireAssignedGateAccess($actor, $gateId, $direction);
        if ($lane !== null && $lane['status'] !== 'active') {
            throw new InvalidArgumentException('The selected lane is inactive.');
        }
    }
    if ($requestType === 'exit_clearance' && $direction !== 'exit') {
        throw new InvalidArgumentException('Exit clearance requests must use the exit direction.');
    }
    $reference = makeReference('APR');
    $insert = $connection->prepare(
        'INSERT INTO approval_requests
            (reference,request_type,title,note,details,company_id,driver_id,vehicle_id,
             schedule_id,gate_id,lane_id,direction,submitted_by,status)
         VALUES
            (:reference,:type,:title,:note,:details,:company,:driver,:vehicle,
             :schedule,:gate,:lane,:direction,:submitter,\'pending\')'
    );
    $insert->execute([
        'reference' => $reference,
        'type' => $requestType,
        'title' => $title,
        'note' => $note,
        'details' => $details === null
            ? null
            : json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'company' => $companyId,
        'driver' => $driverId,
        'vehicle' => $vehicleId,
        'schedule' => $scheduleId,
        'gate' => $gateId,
        'lane' => $laneId,
        'direction' => $direction,
        'submitter' => $actor['id'],
    ]);
    $id = (int) $connection->lastInsertId();
    writeOperationalAudit($connection, $actor['id'], 'approval.submit', 'approval_request', $id);
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
    respondToOperationalConflict($exception, 'Approval request references conflict with existing records.');
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
successResponse(['request' => approvalRecord($connection, $id)], 'Approval request submitted for review.', 201);
