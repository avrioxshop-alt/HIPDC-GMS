<?php

require_once __DIR__ . '/gate_operations.php';

function createUserNotification(
    PDO $connection,
    int $userId,
    string $type,
    string $title,
    string $message,
    ?string $entityType,
    ?int $entityId,
    ?int $createdBy
): int {
    if (!in_array($type, ['approval', 'shift', 'camera', 'administrative', 'system'], true)) {
        throw new InvalidArgumentException('Notification type is invalid.');
    }

    $insert = $connection->prepare(
        'INSERT INTO notifications
            (user_id,notification_type,title,message,entity_type,entity_id,created_by)
         VALUES
            (:user_id,:type,:title,:message,:entity_type,:entity_id,:created_by)'
    );
    $insert->execute([
        'user_id' => $userId,
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'created_by' => $createdBy,
    ]);

    return (int) $connection->lastInsertId();
}

function notificationRecord(PDO $connection, int $id, int $userId): ?array
{
    $statement = $connection->prepare(
        'SELECT id,user_id,notification_type,title,message,entity_type,entity_id,
                created_by,read_at,created_at
         FROM notifications
         WHERE id=:id AND user_id=:user_id
         LIMIT 1'
    );
    $statement->execute(['id' => $id, 'user_id' => $userId]);
    $record = $statement->fetch();
    if (!$record) {
        return null;
    }
    foreach (['id', 'user_id', 'entity_id', 'created_by'] as $field) {
        $record[$field] = $record[$field] === null ? null : (int) $record[$field];
    }

    return $record;
}

function validateCameraConfiguration(mixed $configuration): ?array
{
    if ($configuration === null || $configuration === []) {
        return null;
    }
    if (!is_array($configuration) || array_is_list($configuration)) {
        throw new InvalidArgumentException('Camera configuration must be a JSON object.');
    }
    $allowed = ['resolution', 'frame_rate', 'view_description'];
    if (array_diff(array_keys($configuration), $allowed) !== []) {
        throw new InvalidArgumentException(
            'Camera configuration supports only resolution, frame_rate, and view_description; stream URLs and credentials are not accepted.'
        );
    }

    $normalized = [];
    if (array_key_exists('resolution', $configuration)) {
        $value = validateOperationalText(['value' => $configuration['resolution']], 'value', 32);
        if ($value !== null && !preg_match('/^[0-9]{2,5}x[0-9]{2,5}$/i', $value)) {
            throw new InvalidArgumentException('Resolution must use WIDTHxHEIGHT format.');
        }
        $normalized['resolution'] = $value;
    }
    if (array_key_exists('frame_rate', $configuration)) {
        $rate = $configuration['frame_rate'];
        if (!is_int($rate) || $rate < 1 || $rate > 120) {
            throw new InvalidArgumentException('Frame rate must be an integer from 1 to 120.');
        }
        $normalized['frame_rate'] = $rate;
    }
    if (array_key_exists('view_description', $configuration)) {
        $normalized['view_description'] = validateOperationalText(
            ['value' => $configuration['view_description']],
            'value',
            160
        );
    }

    return $normalized === [] ? null : $normalized;
}

function cameraRecord(PDO $connection, int $id): ?array
{
    $statement = $connection->prepare(
        'SELECT c.id,c.reference,c.name,c.model,c.serial_reference,c.location,
                c.gate_id,g.code AS gate_code,g.name AS gate_name,
                c.lane_id,l.code AS lane_code,l.name AS lane_name,c.status,
                c.monitoring_status,c.monitoring_notes,c.last_monitored_at,
                c.configuration,c.created_by,creator.full_name AS created_by_name,
                c.updated_by,updater.full_name AS updated_by_name,c.created_at,c.updated_at
         FROM cameras c
         JOIN gates g ON g.id=c.gate_id
         LEFT JOIN lanes l ON l.id=c.lane_id
         JOIN users creator ON creator.id=c.created_by
         LEFT JOIN users updater ON updater.id=c.updated_by
         WHERE c.id=:id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $camera = $statement->fetch();
    if (!$camera) {
        return null;
    }
    foreach (['id', 'gate_id', 'lane_id', 'created_by', 'updated_by'] as $field) {
        $camera[$field] = $camera[$field] === null ? null : (int) $camera[$field];
    }
    if ($camera['configuration'] !== null) {
        $camera['configuration'] = json_decode($camera['configuration'], true, 16, JSON_THROW_ON_ERROR);
    }

    return $camera;
}

function validateCameraFields(PDO $connection, array $data): array
{
    $name = validateOperationalText($data, 'name', 120, true);
    $model = validateOperationalText($data, 'model', 120);
    $serial = validateOperationalText($data, 'serial_reference', 120);
    $location = validateOperationalText($data, 'location', 160);
    $gateId = validateOptionalId($data, 'gate_id');
    $laneId = validateOptionalId($data, 'lane_id');
    if ($gateId === null) {
        throw new InvalidArgumentException('A gate is required for a camera record.');
    }
    $configuration = validateCameraConfiguration($data['configuration'] ?? null);

    $gateStatement = $connection->prepare(
        'SELECT status FROM gates WHERE id=:id'
    );
    $gateStatement->execute(['id' => $gateId]);
    if ($gateStatement->fetchColumn() !== 'active') {
        throw new InvalidArgumentException('The selected gate does not exist or is inactive.');
    }
    if ($laneId !== null) {
        $laneStatement = $connection->prepare(
            'SELECT gate_id,status FROM lanes WHERE id=:id'
        );
        $laneStatement->execute(['id' => $laneId]);
        $lane = $laneStatement->fetch();
        if (
            !$lane
            || (int) $lane['gate_id'] !== $gateId
            || $lane['status'] !== 'active'
        ) {
            throw new InvalidArgumentException('The selected lane does not belong to the active gate.');
        }
    }

    return [
        'name' => $name,
        'model' => $model,
        'serial_reference' => $serial,
        'location' => $location,
        'gate_id' => $gateId,
        'lane_id' => $laneId,
        'configuration' => $configuration,
    ];
}

function validateShiftFields(PDO $connection, array $data): array
{
    $officerId = validateOptionalId($data, 'officer_id');
    $gateId = validateOptionalId($data, 'gate_id');
    $laneId = validateOptionalId($data, 'lane_id');
    $direction = $data['direction'] ?? null;
    if ($officerId === null || $gateId === null) {
        throw new InvalidArgumentException('Officer and gate are required.');
    }
    if (!is_string($direction) || !in_array($direction, ['entry', 'exit'], true)) {
        throw new InvalidArgumentException('Direction must be entry or exit.');
    }
    $startsAt = validateOperationalDateTime($data['starts_at'] ?? null, 'starts_at');
    $endsAt = validateOperationalDateTime($data['ends_at'] ?? null, 'ends_at');
    if ($endsAt <= $startsAt) {
        throw new InvalidArgumentException('Shift end must be later than its start.');
    }

    $officerStatement = $connection->prepare(
        'SELECT u.status,r.code AS role_code
         FROM users u JOIN roles r ON r.id=u.role_id
         WHERE u.id=:id'
    );
    $officerStatement->execute(['id' => $officerId]);
    $officer = $officerStatement->fetch();
    if (!$officer || $officer['status'] !== 'active' || $officer['role_code'] !== 'officer') {
        throw new InvalidArgumentException('The selected account must be an active Officer.');
    }

    $gate = validateGateDirection($connection, $gateId, $direction);
    $lane = validateLaneReference($connection, $laneId, $gateId, $direction);
    if ($lane !== null && $lane['status'] !== 'active') {
        throw new InvalidArgumentException('The selected lane is inactive.');
    }
    if (!userCanAccessGate($connection, $officerId, $gateId, $direction)) {
        throw new InvalidArgumentException('The Officer must already have an active assignment for this gate direction.');
    }

    return [
        'officer_id' => $officerId,
        'gate_id' => $gateId,
        'lane_id' => $laneId,
        'direction' => $direction,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'notes' => validateOperationalText($data, 'notes', 1000),
        'gate' => $gate,
    ];
}

function cameraGateScopeSql(string $alias = 'c'): string
{
    return 'EXISTS (
        SELECT 1 FROM user_gate_assignments uga
        JOIN role_permissions rp ON rp.role_id=:camera_scope_role
        JOIN permissions p ON p.id=rp.permission_id
        WHERE uga.user_id=:camera_scope_user AND uga.gate_id=' . $alias . '.gate_id
          AND ((uga.direction IN (\'entry\',\'both\') AND p.code=\'gate.entry\')
            OR (uga.direction IN (\'exit\',\'both\') AND p.code=\'gate.exit\'))
    )';
}

function requireCameraGateScope(PDO $connection, array $actor, int $gateId): void
{
    if ($actor['role']['code'] === 'admin') {
        return;
    }

    $statement = $connection->prepare(
        'SELECT 1 FROM user_gate_assignments uga
         JOIN role_permissions rp ON rp.role_id=:role_id
         JOIN permissions p ON p.id=rp.permission_id
         WHERE uga.user_id=:user_id AND uga.gate_id=:gate_id
           AND ((uga.direction IN (\'entry\',\'both\') AND p.code=\'gate.entry\')
             OR (uga.direction IN (\'exit\',\'both\') AND p.code=\'gate.exit\'))
         LIMIT 1'
    );
    $statement->execute([
        'role_id' => $actor['role']['id'],
        'user_id' => $actor['id'],
        'gate_id' => $gateId,
    ]);
    if ($statement->fetchColumn() === false) {
        forbiddenResponse('You are not assigned to this camera gate.');
    }
}

function lockShiftResources(PDO $connection, int $officerId, int $gateId): void
{
    $user = $connection->prepare('SELECT id FROM users WHERE id=:id FOR UPDATE');
    $user->execute(['id' => $officerId]);
    if ($user->fetchColumn() === false) {
        throw new InvalidArgumentException('The selected Officer does not exist.');
    }

    $gate = $connection->prepare('SELECT id FROM gates WHERE id=:id FOR UPDATE');
    $gate->execute(['id' => $gateId]);
    if ($gate->fetchColumn() === false) {
        throw new InvalidArgumentException('The selected gate does not exist.');
    }
}

function assertNoShiftConflict(
    PDO $connection,
    array $shift,
    ?int $excludeId = null
): void {
    $excludeSql = $excludeId === null ? '' : ' AND id<>:exclude_id';
    $officer = $connection->prepare(
        'SELECT id FROM officer_shifts
         WHERE officer_id=:officer_id AND status=\'scheduled\'
           AND starts_at<:ends_at AND ends_at>:starts_at'
        . $excludeSql . ' LIMIT 1 FOR UPDATE'
    );
    $parameters = [
        'officer_id' => $shift['officer_id'],
        'ends_at' => $shift['ends_at'],
        'starts_at' => $shift['starts_at'],
    ];
    if ($excludeId !== null) {
        $parameters['exclude_id'] = $excludeId;
    }
    $officer->execute($parameters);
    if ($officer->fetchColumn() !== false) {
        throw new DomainException('The Officer already has an overlapping scheduled shift.');
    }

    $laneCondition = $shift['lane_id'] === null ? 'lane_id IS NULL' : 'lane_id=:lane_id';
    $gate = $connection->prepare(
        'SELECT id FROM officer_shifts
         WHERE gate_id=:gate_id AND ' . $laneCondition . '
           AND direction=:direction AND status=\'scheduled\'
           AND starts_at<:ends_at AND ends_at>:starts_at'
        . $excludeSql . ' LIMIT 1 FOR UPDATE'
    );
    $parameters = [
        'gate_id' => $shift['gate_id'],
        'direction' => $shift['direction'],
        'ends_at' => $shift['ends_at'],
        'starts_at' => $shift['starts_at'],
    ];
    if ($shift['lane_id'] !== null) {
        $parameters['lane_id'] = $shift['lane_id'];
    }
    if ($excludeId !== null) {
        $parameters['exclude_id'] = $excludeId;
    }
    $gate->execute($parameters);
    if ($gate->fetchColumn() !== false) {
        throw new DomainException('Another Officer is already scheduled for this gate, lane, and direction.');
    }
}

function officerShiftRecord(PDO $connection, int $id): ?array
{
    $statement = $connection->prepare(
        'SELECT s.id,s.reference,s.officer_id,u.full_name AS officer_name,
                s.gate_id,g.code AS gate_code,g.name AS gate_name,s.lane_id,
                l.code AS lane_code,l.name AS lane_name,s.direction,s.starts_at,s.ends_at,
                s.status,s.notes,s.assigned_by,a.full_name AS assigned_by_name,
                s.created_at,s.updated_at
         FROM officer_shifts s
         JOIN users u ON u.id=s.officer_id
         JOIN gates g ON g.id=s.gate_id
         LEFT JOIN lanes l ON l.id=s.lane_id
         JOIN users a ON a.id=s.assigned_by
         WHERE s.id=:id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $shift = $statement->fetch();
    if (!$shift) {
        return null;
    }
    foreach (['id', 'officer_id', 'gate_id', 'lane_id', 'assigned_by'] as $field) {
        $shift[$field] = $shift[$field] === null ? null : (int) $shift[$field];
    }

    return $shift;
}
