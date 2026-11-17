<?php

require_once __DIR__ . '/visitor_goods.php';

final class GateOperationException extends RuntimeException
{
    public function __construct(string $message, int $httpStatus)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }

    public int $httpStatus;
}

function normalizeGatePlate(mixed $value): array
{
    if (!is_string($value)) {
        throw new InvalidArgumentException('Plate number is required.');
    }

    $plate = strtoupper(preg_replace('/\s+/', ' ', trim($value)));
    if (!preg_match('/^[A-Z0-9][A-Z0-9 -]{1,39}$/', $plate)) {
        throw new InvalidArgumentException('Plate number must contain letters, numbers, spaces, or hyphens.');
    }
    $normalized = preg_replace('/[^A-Z0-9]/', '', $plate);
    if (strlen($normalized) < 3 || strlen($normalized) > 40) {
        throw new InvalidArgumentException('Plate number must contain 3-40 letters or digits.');
    }

    return [$plate, $normalized];
}

function requireIdempotencyKey(): string
{
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    if (
        !is_string($key)
        || strlen($key) < 16
        || strlen($key) > 128
        || !preg_match('/^[A-Za-z0-9._:-]+$/', $key)
    ) {
        throw new InvalidArgumentException(
            'Idempotency-Key must contain 16-128 letters, digits, periods, underscores, colons, or hyphens.'
        );
    }

    return $key;
}

function makeAccessRequestHash(
    string $subjectType,
    string $accessMethod,
    ?int $actorId,
    ?int $deviceId,
    array $input
): string {
    $sort = static function (array $value) use (&$sort): array {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = $sort($child);
            }
        }
        return $value;
    };

    $encoded = json_encode(
        [
            'subject_type' => $subjectType,
            'access_method' => $accessMethod,
            'actor_id' => $actorId,
            'device_id' => $deviceId,
            'input' => $sort($input),
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    return hash('sha256', $encoded, true);
}

function accessEventRecord(PDO $connection, int $id): ?array
{
    $statement = $connection->prepare(
        'SELECT e.id,e.reference,e.subject_type,e.event_type,e.gate_id,g.code AS gate_code,
                g.name AS gate_name,e.lane_id,l.code AS lane_code,l.name AS lane_name,
                e.vehicle_id,v.plate_number,e.vehicle_plate_observed,e.driver_id,d.full_name AS driver_name,
                e.visitor_id,vis.reference AS visitor_reference,vis.full_name AS visitor_name,
                e.visitor_pass_id,vp.reference AS visitor_pass_reference,
                e.schedule_id,s.reference AS schedule_reference,
                e.goods_declaration_id,gd.reference AS goods_reference,
                e.approval_request_id,ar.reference AS approval_reference,
                e.company_id,c.name AS company_name,e.actor_id,u.full_name AS actor_name,
                e.device_id,dev.name AS device_name,e.access_result,e.denial_reason,
                e.access_method,e.override_reason,e.metadata,e.event_at,e.created_at
         FROM access_events e
         JOIN gates g ON g.id=e.gate_id
         LEFT JOIN lanes l ON l.id=e.lane_id
         LEFT JOIN vehicles v ON v.id=e.vehicle_id
         LEFT JOIN drivers d ON d.id=e.driver_id
         LEFT JOIN visitors vis ON vis.id=e.visitor_id
         LEFT JOIN visitor_passes vp ON vp.id=e.visitor_pass_id
         LEFT JOIN schedules s ON s.id=e.schedule_id
         LEFT JOIN goods_declarations gd ON gd.id=e.goods_declaration_id
         LEFT JOIN approval_requests ar ON ar.id=e.approval_request_id
         LEFT JOIN companies c ON c.id=e.company_id
         LEFT JOIN users u ON u.id=e.actor_id
         LEFT JOIN gate_devices dev ON dev.id=e.device_id
         WHERE e.id=:id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $event = $statement->fetch();
    if (!$event) {
        return null;
    }

    foreach (
        [
            'id',
            'gate_id',
            'lane_id',
            'vehicle_id',
            'driver_id',
            'visitor_id',
            'visitor_pass_id',
            'schedule_id',
            'goods_declaration_id',
            'approval_request_id',
            'company_id',
            'actor_id',
            'device_id',
        ] as $field
    ) {
        $event[$field] = $event[$field] === null ? null : (int) $event[$field];
    }
    if ($event['metadata'] !== null) {
        $event['metadata'] = json_decode($event['metadata'], true, 16, JSON_THROW_ON_ERROR);
    }

    return $event;
}

function latestVehicleMovement(PDO $connection, int $vehicleId): ?string
{
    $statement = $connection->prepare(
        'SELECT event_type
         FROM access_events
         WHERE vehicle_id=:vehicle_id
           AND subject_type=\'vehicle\'
           AND access_result=\'granted\'
         ORDER BY event_at DESC,id DESC
         LIMIT 1'
        . ' FOR UPDATE'
    );
    $statement->execute(['vehicle_id' => $vehicleId]);
    $movement = $statement->fetchColumn();

    return $movement === false ? null : (string) $movement;
}

function latestVisitorMovement(PDO $connection, int $visitorId): ?string
{
    $statement = $connection->prepare(
        'SELECT event_type
         FROM access_events
         WHERE visitor_id=:visitor_id
           AND subject_type=\'visitor\'
           AND access_result=\'granted\'
         ORDER BY event_at DESC,id DESC
         LIMIT 1'
        . ' FOR UPDATE'
    );
    $statement->execute(['visitor_id' => $visitorId]);
    $movement = $statement->fetchColumn();

    return $movement === false ? null : (string) $movement;
}

function accessEventScopeSql(string $alias = 'e'): string
{
    return 'EXISTS (
        SELECT 1
        FROM user_gate_assignments uga
        JOIN role_permissions rp ON rp.role_id=:scope_role_id
        JOIN permissions p ON p.id=rp.permission_id
        WHERE uga.user_id=:scope_user_id
          AND uga.gate_id=' . $alias . '.gate_id
          AND uga.direction IN (' . $alias . '.event_type,\'both\')
          AND p.code=CONCAT(\'gate.\',' . $alias . '.event_type)
    )';
}

function userCanViewGateEvent(
    PDO $connection,
    int $userId,
    int $gateId,
    string $direction
): bool {
    if (!in_array($direction, ['entry', 'exit'], true)) {
        return false;
    }

    $statement = $connection->prepare(
        'SELECT 1
         FROM users u
         JOIN user_gate_assignments uga ON uga.user_id=u.id
         JOIN role_permissions rp ON rp.role_id=u.role_id
         JOIN permissions p ON p.id=rp.permission_id
         WHERE u.id=:user_id AND u.status=\'active\'
           AND uga.gate_id=:gate_id
           AND uga.direction IN (:direction,\'both\')
           AND p.code=CONCAT(\'gate.\',:permission_direction)
         LIMIT 1'
    );
    $statement->execute([
        'user_id' => $userId,
        'gate_id' => $gateId,
        'direction' => $direction,
        'permission_direction' => $direction,
    ]);

    return $statement->fetchColumn() !== false;
}

function userHasGateOperationAssignment(
    PDO $connection,
    int $userId,
    int $gateId,
    string $direction
): bool {
    if (!in_array($direction, ['entry', 'exit'], true)) {
        return false;
    }

    $statement = $connection->prepare(
        'SELECT 1
         FROM users u
         JOIN user_gate_assignments uga ON uga.user_id=u.id
         JOIN role_permissions rp ON rp.role_id=u.role_id
         JOIN permissions p ON p.id=rp.permission_id
         WHERE u.id=:user_id AND u.status=\'active\'
           AND uga.gate_id=:gate_id
           AND uga.direction IN (:direction,\'both\')
           AND p.code=CONCAT(\'gate.\',:permission_direction)
         LIMIT 1'
    );
    $statement->execute([
        'user_id' => $userId,
        'gate_id' => $gateId,
        'direction' => $direction,
        'permission_direction' => $direction,
    ]);

    return $statement->fetchColumn() !== false;
}

function requireAnyGateOperationAssignment(PDO $connection, array $actor): void
{
    $statement = $connection->prepare(
        'SELECT 1
         FROM user_gate_assignments uga
         JOIN gates g ON g.id=uga.gate_id AND g.status=\'active\'
         JOIN role_permissions rp ON rp.role_id=:role_id
         JOIN permissions p ON p.id=rp.permission_id
         WHERE uga.user_id=:user_id
           AND (
                (uga.direction IN (\'entry\',\'both\') AND g.supports_entry=1 AND p.code=\'gate.entry\')
                OR (uga.direction IN (\'exit\',\'both\') AND g.supports_exit=1 AND p.code=\'gate.exit\')
           )
         LIMIT 1'
    );
    $statement->execute([
        'role_id' => $actor['role']['id'],
        'user_id' => $actor['id'],
    ]);
    if ($statement->fetchColumn() === false) {
        forbiddenResponse('An active gate assignment is required.');
    }
}

function accessLocationDenial(
    PDO $connection,
    ?array $actor,
    ?array $device,
    array $location
): ?array {
    $gate = $location['gate'];
    $lane = $location['lane'];
    $direction = $location['direction'];

    if ($gate['status'] !== 'active') {
        return ['gate_inactive', 'The selected gate is inactive.'];
    }
    if (
        ($direction === 'entry' && (int) $gate['supports_entry'] !== 1)
        || ($direction === 'exit' && (int) $gate['supports_exit'] !== 1)
    ) {
        return ['gate_direction_unsupported', 'The selected gate does not support this direction.'];
    }
    if ($lane !== null && $lane['status'] !== 'active') {
        return ['lane_inactive', 'The selected lane is inactive.'];
    }
    if (
        $lane !== null
        && (int) $lane['gate_id'] !== (int) $gate['id']
    ) {
        return ['lane_gate_mismatch', 'The selected lane does not belong to this gate.'];
    }
    if (
        $lane !== null
        && $lane['direction'] !== 'both'
        && $lane['direction'] !== $direction
    ) {
        return ['lane_direction_unsupported', 'The selected lane does not support this direction.'];
    }

    if ($device !== null) {
        if (
            $device['status'] !== 'active'
            || (int) $device['gate_id'] !== (int) $gate['id']
            || (int) $device['lane_id'] !== (int) $lane['id']
            || $device['direction'] !== $direction
        ) {
            return ['device_assignment_invalid', 'The device is inactive or is not assigned to this gate direction.'];
        }
        return null;
    }

    if ($actor === null) {
        return ['actor_missing', 'An active officer session is required.'];
    }
    if (!userCanAccessGate($connection, $actor['id'], (int) $gate['id'], $direction)) {
        return ['gate_assignment_required', 'The officer is not assigned to this gate direction.'];
    }

    return null;
}

function currentAccessResponseForKey(
    PDO $connection,
    string $key,
    string $requestHash
): ?array {
    $statement = $connection->prepare(
        'SELECT id,request_hash FROM access_events WHERE idempotency_key=:key LIMIT 1'
    );
    $statement->bindValue(':key', $key, PDO::PARAM_STR);
    $statement->execute();
    $existing = $statement->fetch();
    if (!$existing) {
        return null;
    }
    if (!hash_equals($existing['request_hash'], $requestHash)) {
        throw new GateOperationException(
            'This Idempotency-Key was already used for a different request.',
            409
        );
    }

    return [
        'event' => accessEventRecord($connection, (int) $existing['id']),
        'replayed' => true,
    ];
}

function processGateAccessAttempt(
    PDO $connection,
    ?array $actor,
    ?array $device,
    string $subjectType,
    string $accessMethod,
    string $idempotencyKey,
    string $requestHash,
    array $input
): array {
    try {
        $connection->beginTransaction();
        $existing = currentAccessResponseForKey($connection, $idempotencyKey, $requestHash);
        if ($existing !== null) {
            $connection->commit();
            return $existing;
        }

        if ($device !== null) {
            $deviceLock = $connection->prepare(
                'SELECT id,name,device_type,gate_id,lane_id,direction,status,last_sequence
                 FROM gate_devices WHERE id=:id FOR UPDATE'
            );
            $deviceLock->execute(['id' => $device['id']]);
            $lockedDevice = $deviceLock->fetch();
            if (!$lockedDevice || $lockedDevice['status'] !== 'active') {
                throw new GateOperationException('The device is not active.', 401);
            }
            if (
                (int) $lockedDevice['last_sequence'] >= $device['sequence']
                || $device['sequence'] < 1
            ) {
                throw new GateOperationException('The device sequence is stale or has already been processed.', 409);
            }
            $device = array_merge($lockedDevice, $device);
        }

        $gateLock = $connection->prepare(
            'SELECT id,code,name,status,supports_entry,supports_exit
             FROM gates WHERE id=:id FOR UPDATE'
        );
        $gateLock->execute(['id' => $input['gate_id']]);
        $gate = $gateLock->fetch();
        if (!$gate) {
            throw new GateOperationException('The selected gate does not exist.', 422);
        }
        $gate['id'] = (int) $gate['id'];

        $lane = null;
        if ($input['lane_id'] !== null) {
            $laneLock = $connection->prepare(
                'SELECT id,gate_id,code,name,direction,status
                 FROM lanes WHERE id=:id FOR UPDATE'
            );
            $laneLock->execute(['id' => $input['lane_id']]);
            $lane = $laneLock->fetch();
            if (!$lane) {
                throw new GateOperationException('The selected lane does not exist.', 422);
            }
            $lane['id'] = (int) $lane['id'];
            $lane['gate_id'] = (int) $lane['gate_id'];
        }

        if ($device !== null) {
            $sequenceUpdate = $connection->prepare(
                'UPDATE gate_devices SET last_sequence=:sequence WHERE id=:id'
            );
            $sequenceUpdate->execute([
                'sequence' => $device['sequence'],
                'id' => $device['id'],
            ]);
        }

        $location = [
            'gate' => $gate,
            'lane' => $lane,
            'direction' => $input['direction'],
        ];
        $denial = accessLocationDenial($connection, $actor, $device, $location);
        $fields = [
            'vehicle_id' => null,
            'driver_id' => null,
            'visitor_id' => null,
            'visitor_pass_id' => null,
            'schedule_id' => null,
            'goods_declaration_id' => null,
            'approval_request_id' => null,
            'company_id' => null,
            'vehicle_plate_observed' => $input['vehicle_plate_observed'] ?? null,
            'override_reason' => null,
            'metadata' => [],
        ];

        if ($denial === null) {
            if ($subjectType === 'vehicle') {
                $denial = processVehiclePolicy($connection, $actor, $input, $fields);
            } elseif ($subjectType === 'visitor') {
                $denial = processVisitorPolicy($connection, $input, $fields);
            } elseif ($subjectType === 'goods') {
                $denial = processGoodsPolicy($connection, $actor, $input, $fields);
            } else {
                throw new LogicException('Unsupported gate-operation subject type.');
            }
        }

        $accessResult = $denial === null ? 'granted' : 'denied';
        $denialCode = $denial[0] ?? null;
        $denialReason = $denial[1] ?? null;
        $metadata = $fields['metadata'] === [] ? null : json_encode(
            $fields['metadata'],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $insert = $connection->prepare(
            'INSERT INTO access_events
                (reference,subject_type,event_type,gate_id,lane_id,vehicle_id,driver_id,
                 visitor_id,visitor_pass_id,schedule_id,goods_declaration_id,
                 approval_request_id,company_id,actor_id,device_id,device_sequence,
                 idempotency_key,request_hash,access_result,denial_reason,access_method,
                 vehicle_plate_observed,override_reason,metadata)
             VALUES
                (:reference,:subject_type,:event_type,:gate_id,:lane_id,:vehicle_id,:driver_id,
                 :visitor_id,:visitor_pass_id,:schedule_id,:goods_declaration_id,
                 :approval_request_id,:company_id,:actor_id,:device_id,:device_sequence,
                 :idempotency_key,:request_hash,:access_result,:denial_reason,:access_method,
                 :vehicle_plate_observed,:override_reason,:metadata)'
        );
        $insert->bindValue(':reference', makeReference('EVT'), PDO::PARAM_STR);
        $insert->bindValue(':subject_type', $subjectType, PDO::PARAM_STR);
        $insert->bindValue(':event_type', $input['direction'], PDO::PARAM_STR);
        $insert->bindValue(':gate_id', $gate['id'], PDO::PARAM_INT);
        $insert->bindValue(':lane_id', $lane['id'] ?? null, $lane === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        foreach (
            [
                'vehicle_id',
                'driver_id',
                'visitor_id',
                'visitor_pass_id',
                'schedule_id',
                'goods_declaration_id',
                'approval_request_id',
                'company_id',
            ] as $field
        ) {
            $insert->bindValue(
                ':' . $field,
                $fields[$field],
                $fields[$field] === null ? PDO::PARAM_NULL : PDO::PARAM_INT
            );
        }
        $insert->bindValue(':actor_id', $actor['id'] ?? null, $actor === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $insert->bindValue(':device_id', $device['id'] ?? null, $device === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $insert->bindValue(':device_sequence', $device['sequence'] ?? null, $device === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $insert->bindValue(':idempotency_key', $idempotencyKey, PDO::PARAM_STR);
        $insert->bindValue(':request_hash', $requestHash, PDO::PARAM_LOB);
        $insert->bindValue(':access_result', $accessResult, PDO::PARAM_STR);
        $insert->bindValue(':denial_reason', $denialReason, $denialReason === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $insert->bindValue(':access_method', $accessMethod, PDO::PARAM_STR);
        $insert->bindValue(
            ':vehicle_plate_observed',
            $fields['vehicle_plate_observed'],
            $fields['vehicle_plate_observed'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $insert->bindValue(
            ':override_reason',
            $fields['override_reason'],
            $fields['override_reason'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $insert->bindValue(':metadata', $metadata, $metadata === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $insert->execute();
        $eventId = (int) $connection->lastInsertId();

        writeOperationalAudit(
            $connection,
            $actor['id'] ?? null,
            'gate.access.' . $accessResult,
            'access_event',
            $eventId,
            [
                'subject_type' => $subjectType,
                'direction' => $input['direction'],
                'gate_id' => $gate['id'],
                'reason' => $denialCode,
                'device_id' => $device['id'] ?? null,
            ]
        );
        $connection->commit();

        return [
            'event' => accessEventRecord($connection, $eventId),
            'replayed' => false,
        ];
    } catch (PDOException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        if ($exception->getCode() === '23000') {
            $replayed = currentAccessResponseForKey($connection, $idempotencyKey, $requestHash);
            if ($replayed !== null) {
                return $replayed;
            }
            throw new GateOperationException(
                'The access event conflicts with an already processed request or movement.',
                409
            );
        }
        throw $exception;
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

function processVehiclePolicy(
    PDO $connection,
    ?array $actor,
    array $input,
    array &$fields
): ?array {
    $vehicleLookup = $connection->prepare(
        'SELECT v.id,v.plate_number,v.company_id,v.assigned_driver_id,v.status,
                c.status AS company_status
         FROM vehicles v
         LEFT JOIN companies c ON c.id=v.company_id
         WHERE v.plate_normalized=:normalized
         LIMIT 1 FOR UPDATE'
    );
    $vehicleLookup->execute(['normalized' => $input['plate_normalized']]);
    $vehicle = $vehicleLookup->fetch();
    if (!$vehicle) {
        return ['vehicle_not_registered', 'No registered vehicle matches the observed plate.'];
    }
    $fields['vehicle_id'] = (int) $vehicle['id'];
    $fields['company_id'] = $vehicle['company_id'] === null ? null : (int) $vehicle['company_id'];
    if ($vehicle['status'] !== 'active') {
        return ['vehicle_inactive', 'The vehicle is not active.'];
    }
    if ($vehicle['company_id'] !== null && $vehicle['company_status'] !== 'active') {
        return ['company_inactive', 'The vehicle company is not active.'];
    }

    $driverId = $input['driver_id'] ?? (
        $vehicle['assigned_driver_id'] === null ? null : (int) $vehicle['assigned_driver_id']
    );
    if ($driverId !== null) {
        $driverLock = $connection->prepare(
            'SELECT id,company_id,status FROM drivers WHERE id=:id FOR UPDATE'
        );
        $driverLock->execute(['id' => $driverId]);
        $driver = $driverLock->fetch();
        if (!$driver || $driver['status'] !== 'active') {
            return ['driver_inactive_or_unknown', 'The driver does not exist or is not active.'];
        }
        if (
            $driver['company_id'] !== null
            && $fields['company_id'] !== null
            && (int) $driver['company_id'] !== $fields['company_id']
        ) {
            return ['driver_company_mismatch', 'The driver is not associated with the vehicle company.'];
        }
        if (
            $vehicle['assigned_driver_id'] !== null
            && (int) $vehicle['assigned_driver_id'] !== $driverId
        ) {
            return ['driver_vehicle_mismatch', 'The driver does not match the vehicle assignment.'];
        }
        $fields['driver_id'] = (int) $driver['id'];
    }

    $scheduleId = $input['schedule_id'] ?? null;
    $schedule = null;
    if ($scheduleId !== null) {
        $scheduleLock = $connection->prepare(
            'SELECT id,reference,company_id,vehicle_id,driver_id,gate_id,lane_id,
                    direction,scheduled_at,status
             FROM schedules WHERE id=:id FOR UPDATE'
        );
        $scheduleLock->execute(['id' => $scheduleId]);
        $schedule = $scheduleLock->fetch();
        if (
            !$schedule
            || $schedule['status'] !== 'scheduled'
            || $schedule['direction'] !== $input['direction']
            || (int) $schedule['vehicle_id'] !== (int) $vehicle['id']
            || (int) $schedule['driver_id'] !== (int) ($fields['driver_id'] ?? 0)
            || ($schedule['company_id'] !== null
                && (int) $schedule['company_id'] !== (int) ($fields['company_id'] ?? 0))
            || ($schedule['gate_id'] !== null && (int) $schedule['gate_id'] !== $input['gate_id'])
            || ($schedule['lane_id'] !== null && (int) $schedule['lane_id'] !== (int) ($input['lane_id'] ?? 0))
            || substr($schedule['scheduled_at'], 0, 10)
                !== (string) $connection->query('SELECT CURRENT_DATE')->fetchColumn()
        ) {
            return ['schedule_invalid_or_outside_window', 'The schedule is not valid for this vehicle, gate, direction, or local date.'];
        }
        $fields['schedule_id'] = (int) $schedule['id'];
    }

    $approval = null;
    if ($input['direction'] === 'entry') {
        $approvalQuery = $connection->prepare(
            'SELECT a.id,a.reference,a.schedule_id,a.company_id,a.driver_id,a.lane_id
             FROM approval_requests a
             LEFT JOIN schedules s ON s.id=a.schedule_id
             WHERE a.request_type=\'access\'
               AND a.status=\'approved\'
               AND a.vehicle_id=:vehicle_id
               AND a.gate_id=:gate_id
               AND a.direction=\'entry\'
               AND (a.company_id IS NULL OR a.company_id=:company_id)
               AND (a.driver_id IS NULL OR a.driver_id=:driver_id)
               AND (a.lane_id IS NULL OR a.lane_id=:lane_id)
               AND (a.schedule_id IS NULL OR a.schedule_id=:schedule_id)
               AND (
                    (a.schedule_id IS NOT NULL AND s.status=\'scheduled\'
                     AND DATE(s.scheduled_at)=CURRENT_DATE)
                    OR (a.schedule_id IS NULL AND DATE(a.created_at)=CURRENT_DATE)
               )
               AND NOT EXISTS (
                    SELECT 1 FROM access_events used
                    WHERE used.approval_request_id=a.id AND used.access_result=\'granted\'
               )
             ORDER BY a.decided_at DESC,a.id DESC
             LIMIT 1 FOR UPDATE'
        );
        $approvalQuery->execute([
            'vehicle_id' => $vehicle['id'],
            'gate_id' => $input['gate_id'],
            'company_id' => $fields['company_id'],
            'driver_id' => $fields['driver_id'],
            'lane_id' => $input['lane_id'],
            'schedule_id' => $scheduleId,
        ]);
        $approval = $approvalQuery->fetch();
        if (!$approval) {
            return ['approved_access_request_required', 'An approved, unused access request is required for vehicle entry.'];
        }
        if (
            $approval['schedule_id'] !== null
            && $scheduleId !== null
            && (int) $approval['schedule_id'] !== $scheduleId
        ) {
            return ['approval_schedule_mismatch', 'The approved access request does not match the selected schedule.'];
        }
        if ($approval['schedule_id'] !== null) {
            $fields['schedule_id'] = (int) $approval['schedule_id'];
        }
        if ($approval['driver_id'] !== null && $fields['driver_id'] === null) {
            return ['approved_driver_required', 'The approved access request requires a verified driver.'];
        }
        if (
            $approval['driver_id'] !== null
            && (int) $approval['driver_id'] !== (int) $fields['driver_id']
        ) {
            return ['approval_driver_mismatch', 'The verified driver does not match the approved access request.'];
        }
        $fields['approval_request_id'] = (int) $approval['id'];
    }

    $lastMovement = latestVehicleMovement($connection, (int) $vehicle['id']);
    if ($input['direction'] === 'entry' && $lastMovement === 'entry') {
        return ['vehicle_already_inside', 'The vehicle already has an unresolved entry event.'];
    }
    if ($input['direction'] === 'exit' && $lastMovement !== 'entry') {
        $reason = $input['override_reason'] ?? null;
        if ($reason === null) {
            return ['vehicle_entry_not_found', 'No matching active vehicle entry was found; an authorized override is required.'];
        }
        if (
            $actor === null
            || $actor['role']['code'] !== 'admin'
            || !in_array('gate_operations.override', $actor['permissions'], true)
        ) {
            return ['override_not_permitted', 'Only an authorized administrator may override an unmatched vehicle exit.'];
        }
        $fields['override_reason'] = $reason;
    } elseif (($input['override_reason'] ?? null) !== null) {
        return ['unnecessary_override', 'An override reason is not accepted when an entry record exists.'];
    }

    $fields['metadata'] = [
        'approval_reference' => $approval['reference'] ?? null,
        'schedule_reference' => $schedule['reference'] ?? null,
    ];

    return null;
}

function processVisitorPolicy(PDO $connection, array $input, array &$fields): ?array
{
    $tokenHash = hash('sha256', hex2bin($input['qr_token']), true);
    $passLookup = $connection->prepare(
        'SELECT p.id,p.reference,p.visitor_id,p.gate_id,p.direction,p.valid_from,p.expires_at,
                p.qr_token_hash,p.status,v.reference AS visitor_reference,v.full_name,
                v.company_id,v.schedule_id,v.expected_arrival_at,v.expected_departure_at,
                v.status AS visitor_status,c.status AS company_status,
                a.id AS approval_request_id,a.status AS approval_status
         FROM visitor_passes p
         JOIN visitors v ON v.id=p.visitor_id
         LEFT JOIN companies c ON c.id=v.company_id
         LEFT JOIN approval_requests a ON a.visitor_pass_id=p.id
         WHERE p.qr_token_hash=:token_hash
         LIMIT 1 FOR UPDATE'
    );
    $passLookup->bindValue(':token_hash', $tokenHash, PDO::PARAM_LOB);
    $passLookup->execute();
    $pass = $passLookup->fetch();
    if (!$pass) {
        return ['visitor_pass_unknown', 'No visitor pass matches the submitted QR token.'];
    }
    $fields['visitor_id'] = (int) $pass['visitor_id'];
    $fields['visitor_pass_id'] = (int) $pass['id'];
    $fields['company_id'] = $pass['company_id'] === null ? null : (int) $pass['company_id'];
    $fields['schedule_id'] = $pass['schedule_id'] === null ? null : (int) $pass['schedule_id'];

    if (!hash_equals($pass['qr_token_hash'], $tokenHash)) {
        return ['visitor_pass_invalid', 'The visitor pass is not valid.'];
    }
    if ($pass['status'] !== 'active' || $pass['approval_status'] !== 'approved') {
        return ['visitor_pass_not_approved', 'The visitor pass is not active and approved.'];
    }
    if (
        $pass['visitor_status'] !== 'active'
        || ($pass['company_status'] !== null && $pass['company_status'] !== 'active')
    ) {
        return ['visitor_or_company_inactive', 'The visitor or host company is inactive.'];
    }
    if (
        (int) $pass['gate_id'] !== $input['gate_id']
        || $pass['direction'] !== $input['direction']
    ) {
        return ['visitor_pass_scope_mismatch', 'The visitor pass is not valid for this gate and direction.'];
    }
    $now = (string) $connection->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
    if (
        $pass['valid_from'] > $now
        || $pass['expires_at'] <= $now
        || $pass['expected_arrival_at'] > $now
        || ($pass['expected_departure_at'] !== null && $pass['expected_departure_at'] < $now)
    ) {
        return ['visitor_pass_outside_window', 'The visitor pass or registered visit is outside its validity window.'];
    }
    if ($fields['schedule_id'] !== null) {
        $schedule = $connection->prepare(
            'SELECT status,scheduled_at FROM schedules WHERE id=:id FOR UPDATE'
        );
        $schedule->execute(['id' => $fields['schedule_id']]);
        $scheduleRecord = $schedule->fetch();
        if (
            !$scheduleRecord
            || $scheduleRecord['status'] !== 'scheduled'
            || substr($scheduleRecord['scheduled_at'], 0, 10)
                !== (string) $connection->query('SELECT CURRENT_DATE')->fetchColumn()
        ) {
            return ['visitor_schedule_invalid', 'The linked visitor schedule is unavailable for the current local date.'];
        }
    }

    $lastMovement = latestVisitorMovement($connection, (int) $pass['visitor_id']);
    if ($input['direction'] === 'entry' && $lastMovement === 'entry') {
        return ['visitor_already_inside', 'The visitor already has an unresolved entry event.'];
    }
    if ($input['direction'] === 'exit' && $lastMovement !== 'entry') {
        return ['visitor_entry_not_found', 'No matching active visitor entry was found.'];
    }

    $fields['approval_request_id'] = (int) $pass['approval_request_id'];
    $fields['metadata'] = [
        'visitor_reference' => $pass['visitor_reference'],
        'pass_reference' => $pass['reference'],
    ];

    return null;
}

function processGoodsPolicy(
    PDO $connection,
    ?array $actor,
    array $input,
    array &$fields
): ?array {
    $fields['metadata'] = ['goods_reference_observed' => $input['goods_reference']];
    $declarationQuery = $connection->prepare(
        'SELECT gd.id,gd.reference,gd.direction,gd.company_id,gd.vehicle_id,gd.driver_id,
                gd.schedule_id,gd.gate_id,gd.lane_id,gd.status,c.status AS company_status,
                v.status AS vehicle_status,v.plate_number,v.assigned_driver_id,
                d.status AS driver_status,ar.id AS approval_request_id,ar.status AS approval_status
         FROM goods_declarations gd
         JOIN companies c ON c.id=gd.company_id
         LEFT JOIN vehicles v ON v.id=gd.vehicle_id
         LEFT JOIN drivers d ON d.id=gd.driver_id
         LEFT JOIN approval_requests ar ON ar.goods_declaration_id=gd.id
         WHERE gd.reference=:reference
         LIMIT 1 FOR UPDATE'
    );
    $declarationQuery->execute(['reference' => $input['goods_reference']]);
    $declaration = $declarationQuery->fetch();
    if (!$declaration) {
        return ['goods_declaration_unknown', 'The goods declaration reference was not found.'];
    }

    $fields['goods_declaration_id'] = (int) $declaration['id'];
    $fields['company_id'] = (int) $declaration['company_id'];
    $fields['vehicle_id'] = $declaration['vehicle_id'] === null ? null : (int) $declaration['vehicle_id'];
    $fields['driver_id'] = $declaration['driver_id'] === null ? null : (int) $declaration['driver_id'];
    $fields['approval_request_id'] = $declaration['approval_request_id'] === null
        ? null
        : (int) $declaration['approval_request_id'];
    $fields['schedule_id'] = $declaration['schedule_id'] === null ? null : (int) $declaration['schedule_id'];
    $fields['vehicle_plate_observed'] = $declaration['plate_number'];

    if ($declaration['status'] !== 'approved' || $declaration['approval_status'] !== 'approved') {
        return ['goods_declaration_not_approved', 'The goods declaration is not approved.'];
    }
    if ($declaration['direction'] !== $input['direction']) {
        return ['goods_direction_mismatch', 'The goods declaration direction does not match this movement.'];
    }
    if ($declaration['company_status'] !== 'active') {
        return ['goods_company_inactive', 'The declaring company is not active.'];
    }
    if (
        $declaration['gate_id'] !== null
        && (int) $declaration['gate_id'] !== $input['gate_id']
    ) {
        return ['goods_gate_mismatch', 'The goods declaration is restricted to a different gate.'];
    }
    if (
        $declaration['lane_id'] !== null
        && (int) $declaration['lane_id'] !== (int) ($input['lane_id'] ?? 0)
    ) {
        return ['goods_lane_mismatch', 'The goods declaration is restricted to a different lane.'];
    }
    if ($declaration['vehicle_id'] !== null && $declaration['vehicle_status'] !== 'active') {
        return ['goods_vehicle_inactive', 'The declaration vehicle is not active.'];
    }
    if ($declaration['driver_id'] !== null && $declaration['driver_status'] !== 'active') {
        return ['goods_driver_inactive', 'The declaration driver is not active.'];
    }
    if (
        $declaration['vehicle_id'] !== null
        && $declaration['driver_id'] !== null
        && $declaration['assigned_driver_id'] !== null
        && (int) $declaration['assigned_driver_id'] !== (int) $declaration['driver_id']
    ) {
        return ['goods_driver_vehicle_mismatch', 'The declaration driver does not match the vehicle assignment.'];
    }
    if ($fields['schedule_id'] !== null) {
        $schedule = $connection->prepare(
            'SELECT status,scheduled_at,vehicle_id,driver_id,company_id,gate_id,lane_id,direction
             FROM schedules WHERE id=:id FOR UPDATE'
        );
        $schedule->execute(['id' => $fields['schedule_id']]);
        $scheduled = $schedule->fetch();
        if (
            !$scheduled
            || $scheduled['status'] !== 'scheduled'
            || $scheduled['direction'] !== $input['direction']
            || ($scheduled['vehicle_id'] !== null
                && (int) $scheduled['vehicle_id'] !== (int) ($fields['vehicle_id'] ?? 0))
            || ($scheduled['driver_id'] !== null
                && (int) $scheduled['driver_id'] !== (int) ($fields['driver_id'] ?? 0))
            || ($scheduled['company_id'] !== null
                && (int) $scheduled['company_id'] !== (int) $fields['company_id'])
            || ($scheduled['gate_id'] !== null
                && (int) $scheduled['gate_id'] !== $input['gate_id'])
            || ($scheduled['lane_id'] !== null
                && (int) $scheduled['lane_id'] !== (int) ($input['lane_id'] ?? 0))
            || substr($scheduled['scheduled_at'], 0, 10)
                !== (string) $connection->query('SELECT CURRENT_DATE')->fetchColumn()
        ) {
            return ['goods_schedule_invalid', 'The linked schedule is not valid for this movement.'];
        }
    }

    $alreadyMoved = $connection->prepare(
        'SELECT id FROM access_events
         WHERE goods_declaration_id=:declaration_id AND access_result=\'granted\'
         LIMIT 1 FOR UPDATE'
    );
    $alreadyMoved->execute(['declaration_id' => $declaration['id']]);
    if ($alreadyMoved->fetchColumn() !== false) {
        return ['goods_already_moved', 'This approved goods declaration has already been physically completed.'];
    }

    if ($fields['vehicle_id'] !== null) {
        $vehicleLock = $connection->prepare(
            'SELECT id,company_id,assigned_driver_id,status
             FROM vehicles WHERE id=:id FOR UPDATE'
        );
        $vehicleLock->execute(['id' => $fields['vehicle_id']]);
        $vehicle = $vehicleLock->fetch();
        if (!$vehicle || $vehicle['status'] !== 'active') {
            return ['goods_vehicle_inactive', 'The declaration vehicle is not active.'];
        }
        $lastMovement = latestVehicleMovement($connection, (int) $fields['vehicle_id']);
        if ($input['direction'] === 'entry' && $lastMovement === 'entry') {
            return ['vehicle_already_inside', 'The vehicle already has an unresolved entry event.'];
        }
        if ($input['direction'] === 'exit' && $lastMovement !== 'entry') {
            $reason = $input['override_reason'] ?? null;
            if ($reason === null) {
                return ['vehicle_entry_not_found', 'No matching active vehicle entry was found; an authorized override is required.'];
            }
            if (
                $actor === null
                || $actor['role']['code'] !== 'admin'
                || !in_array('gate_operations.override', $actor['permissions'], true)
            ) {
                return ['override_not_permitted', 'Only an authorized administrator may override an unmatched vehicle exit.'];
            }
            $fields['override_reason'] = $reason;
        } elseif (($input['override_reason'] ?? null) !== null) {
            return ['unnecessary_override', 'An override reason is not accepted when an entry record exists.'];
        }
    } elseif (($input['override_reason'] ?? null) !== null) {
        return ['override_without_vehicle', 'A vehicle exit override requires a registered vehicle.'];
    }

    $fields['metadata'] = ['goods_reference' => $declaration['reference']];

    return null;
}

function validateAccessRequestFields(array $data, string $subjectType): array
{
    $direction = $data['direction'] ?? null;
    if (!is_string($direction) || !in_array($direction, ['entry', 'exit'], true)) {
        throw new InvalidArgumentException('Direction must be entry or exit.');
    }
    $gateId = validateOptionalId($data, 'gate_id');
    if ($gateId === null) {
        throw new InvalidArgumentException('Gate is required.');
    }
    $laneId = validateOptionalId($data, 'lane_id');
    $overrideReason = validateOperationalText($data, 'override_reason', 1000);

    $input = [
        'gate_id' => $gateId,
        'lane_id' => $laneId,
        'direction' => $direction,
        'override_reason' => $overrideReason,
    ];
    if ($subjectType === 'vehicle') {
        [$plate, $normalized] = normalizeGatePlate($data['plate_number'] ?? null);
        $input['vehicle_plate_observed'] = $plate;
        $input['plate_normalized'] = $normalized;
        $input['driver_id'] = validateOptionalId($data, 'driver_id');
        $input['schedule_id'] = validateOptionalId($data, 'schedule_id');
    } elseif ($subjectType === 'visitor') {
        $token = validateOperationalText($data, 'qr_token', 64, true);
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            throw new InvalidArgumentException('QR token format is invalid.');
        }
        $input['qr_token'] = strtolower($token);
    } elseif ($subjectType === 'goods') {
        $reference = validateOperationalText($data, 'goods_reference', 40, true);
        $input['goods_reference'] = $reference;
    } else {
        throw new LogicException('Unsupported gate-operation subject type.');
    }

    return $input;
}

function handleManualGateAccessRequest(string $subjectType): void
{
    $actor = requireOperationalPermission('gate_operations.process');
    $data = requireJsonPostWithCsrf();
    try {
        $input = validateAccessRequestFields($data, $subjectType);
        $idempotencyKey = requireIdempotencyKey();
    } catch (InvalidArgumentException $exception) {
        handleOperationalValidation($exception);
    }

    $connection = getDatabaseConnection();
    $requestHash = makeAccessRequestHash(
        $subjectType,
        'manual',
        $actor['id'],
        null,
        $input
    );
    if (!userHasGateOperationAssignment(
        $connection,
        $actor['id'],
        $input['gate_id'],
        $input['direction']
    )) {
        forbiddenResponse('You are not assigned to this gate direction.');
    }
    try {
        $result = processGateAccessAttempt(
            $connection,
            $actor,
            null,
            $subjectType,
            'manual',
            $idempotencyKey,
            $requestHash,
            $input
        );
    } catch (GateOperationException $exception) {
        jsonResponse(false, $exception->getMessage(), [], $exception->httpStatus);
    }

    $event = $result['event'];
    $message = $event['access_result'] === 'granted'
        ? 'Gate access was granted and recorded.'
        : 'Gate access was denied and recorded.';
    successResponse(
        ['event' => $event, 'replayed' => $result['replayed']],
        $message,
        $result['replayed'] ? 200 : 201
    );
}
