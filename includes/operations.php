<?php

require_once __DIR__ . '/user_management.php';

function requireOperationalPermission(string $permission): array
{
    $user = requireAuthenticatedUser();

    if (!in_array($permission, $user['permissions'], true)) {
        forbiddenResponse();
    }

    return $user;
}

function validateOperationalText(
    array $data,
    string $field,
    int $maximum,
    bool $required = false
): ?string {
    $value = $data[$field] ?? null;

    if ($value === null && !$required) {
        return null;
    }

    if (!is_string($value)) {
        throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $field)) . ' must be text.');
    }

    $value = trim($value);
    $length = preg_match_all('/./us', $value);

    if (
        $length === false
        || ($required && $length < 1)
        || $length > $maximum
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) !== 0
    ) {
        throw new InvalidArgumentException(
            ucfirst(str_replace('_', ' ', $field))
            . ($required ? ' is required and' : '')
            . ' must be at most ' . $maximum . ' valid characters.'
        );
    }

    return $value === '' ? null : $value;
}

function validateOptionalEmail(array $data, string $field = 'email'): ?string
{
    $email = validateOperationalText($data, $field, 254);

    if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('A valid email address is required.');
    }

    return $email === null ? null : strtolower($email);
}

function validateOptionalPhone(array $data, string $field = 'phone'): ?string
{
    $phone = validateOperationalText($data, $field, 32);

    if ($phone !== null && !preg_match('/^\+?[0-9 ()-]{6,32}$/', $phone)) {
        throw new InvalidArgumentException('Phone must contain 6-32 digits or telephone separators.');
    }

    return $phone;
}

function validateOptionalId(array $data, string $field): ?int
{
    if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
        return null;
    }

    try {
        return parsePositiveId($data[$field], $field);
    } catch (InvalidArgumentException $exception) {
        throw $exception;
    }
}

function validateOperationalDateTime(mixed $value, string $field = 'scheduled_at'): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $field)) . ' is required.');
    }

    $normalized = str_replace('T', ' ', trim($value));
    $format = strlen($normalized) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s';
    $date = DateTimeImmutable::createFromFormat('!' . $format, $normalized);
    $errors = DateTimeImmutable::getLastErrors();

    if (
        $date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format($format) !== $normalized
    ) {
        throw new InvalidArgumentException(
            ucfirst(str_replace('_', ' ', $field)) . ' must be a valid local date and time.'
        );
    }

    return $date->format('Y-m-d H:i:s');
}

function validateOptionalDate(mixed $value, string $field): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_string($value)) {
        throw new InvalidArgumentException($field . ' must be a valid date.');
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    if (
        $date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value
    ) {
        throw new InvalidArgumentException($field . ' must use YYYY-MM-DD format.');
    }

    return $value;
}

function requireEntity(
    PDO $connection,
    string $table,
    int $id,
    string $status = 'active'
): array {
    $allowed = [
        'companies' => ['name', 'status'],
        'drivers' => ['full_name', 'company_id', 'status'],
        'vehicles' => ['plate_number', 'company_id', 'assigned_driver_id', 'status'],
        'gates' => ['name', 'status', 'supports_entry', 'supports_exit'],
        'lanes' => ['gate_id', 'name', 'direction', 'status'],
        'schedules' => ['id', 'status'],
    ];

    if (!isset($allowed[$table])) {
        throw new LogicException('Unsupported operational entity.');
    }

    $fields = implode(', ', $allowed[$table]);
    $statement = $connection->prepare(
        'SELECT ' . $fields . ' FROM ' . $table . ' WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $record = $statement->fetch();

    if (!$record || ($status !== '' && isset($record['status']) && $record['status'] !== $status)) {
        throw new InvalidArgumentException(
            'The selected ' . rtrim($table, 's') . ' does not exist or is unavailable.'
        );
    }

    return $record;
}

function validateCompanyReference(PDO $connection, ?int $companyId): void
{
    if ($companyId !== null) {
        requireEntity($connection, 'companies', $companyId);
    }
}

function validateDriverReference(
    PDO $connection,
    ?int $driverId,
    ?int $companyId,
    bool $requireActive = true
): ?array {
    if ($driverId === null) {
        return null;
    }

    $driver = requireEntity(
        $connection,
        'drivers',
        $driverId,
        $requireActive ? 'active' : ''
    );

    if (
        $companyId !== null
        && $driver['company_id'] !== null
        && (int) $driver['company_id'] !== $companyId
    ) {
        throw new InvalidArgumentException('The driver is not associated with the selected company.');
    }

    return $driver;
}

function validateVehicleReference(
    PDO $connection,
    int $vehicleId,
    ?int $companyId
): array {
    $vehicle = requireEntity($connection, 'vehicles', $vehicleId);

    if (
        $companyId !== null
        && $vehicle['company_id'] !== null
        && (int) $vehicle['company_id'] !== $companyId
    ) {
        throw new InvalidArgumentException('The vehicle is not associated with the selected company.');
    }

    return $vehicle;
}

function validateVehicleCompanyDriverPair(
    PDO $connection,
    ?int $companyId,
    int $driverId
): void {
    validateDriverReference($connection, $driverId, $companyId);
}

function validateGateDefinition(PDO $connection, int $gateId): array
{
    return requireEntity($connection, 'gates', $gateId);
}

function validateBooleanField(array $data, string $field, bool $default): bool
{
    if (!array_key_exists($field, $data)) {
        return $default;
    }

    if (!is_bool($data[$field])) {
        throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $field)) . ' must be true or false.');
    }

    return $data[$field];
}

function validateGateDirection(PDO $connection, int $gateId, string $direction): array
{
    if (!in_array($direction, ['entry', 'exit'], true)) {
        throw new InvalidArgumentException('Direction must be entry or exit.');
    }

    $gate = requireEntity($connection, 'gates', $gateId);

    if (
        ($direction === 'entry' && (int) $gate['supports_entry'] !== 1)
        || ($direction === 'exit' && (int) $gate['supports_exit'] !== 1)
    ) {
        throw new InvalidArgumentException('The selected gate does not support this direction.');
    }

    return $gate;
}

function validateLaneReference(
    PDO $connection,
    ?int $laneId,
    ?int $gateId,
    ?string $direction
): ?array {
    if ($laneId === null) {
        return null;
    }

    if ($gateId === null) {
        throw new InvalidArgumentException('A gate is required when a lane is selected.');
    }

    $lane = requireEntity($connection, 'lanes', $laneId);

    if ((int) $lane['gate_id'] !== $gateId) {
        throw new InvalidArgumentException('The selected lane does not belong to the selected gate.');
    }

    if (
        $direction !== null
        && $lane['direction'] !== 'both'
        && $lane['direction'] !== $direction
    ) {
        throw new InvalidArgumentException('The selected lane does not support this direction.');
    }

    return $lane;
}

function requireAssignedGateAccess(array $user, ?int $gateId, ?string $direction): void
{
    if ($gateId === null) {
        if ($direction !== null) {
            throw new InvalidArgumentException('A gate is required when a direction is specified.');
        }

        return;
    }

    if ($direction === null || !userCanAccessGate(
        getDatabaseConnection(),
        $user['id'],
        $gateId,
        $direction
    )) {
        forbiddenResponse('You are not assigned to this gate and direction.');
    }
}

function writeOperationalAudit(
    PDO $connection,
    ?int $actorId,
    string $action,
    string $entityType,
    int $entityId,
    array $metadata = []
): void {
    $statement = $connection->prepare(
        'INSERT INTO audit_events (actor_id, action, entity_type, entity_id, metadata)
         VALUES (:actor_id, :action, :entity_type, :entity_id, :metadata)'
    );
    $statement->execute([
        'actor_id' => $actorId,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'metadata' => $metadata === []
            ? null
            : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ]);
}

function respondToOperationalConflict(PDOException $exception, string $message): void
{
    if ($exception->getCode() === '23000') {
        jsonResponse(false, $message, [], 409);
    }

    throw $exception;
}

function handleOperationalValidation(InvalidArgumentException $exception): void
{
    validationError($exception->getMessage());
}

function getPaginationParameters(): array
{
    try {
        $page = parsePositiveId($_GET['page'] ?? 1, 'page');
        $perPage = parsePositiveId($_GET['per_page'] ?? 25, 'per_page');
    } catch (InvalidArgumentException $exception) {
        handleOperationalValidation($exception);
    }

    if ($perPage > 100 || $page > intdiv(PHP_INT_MAX, $perPage)) {
        validationError('Pagination values are outside the supported range.');
    }

    return [
        'page' => $page,
        'per_page' => $perPage,
        'offset' => ($page - 1) * $perPage,
    ];
}

function getSearchParameter(): string
{
    $search = $_GET['q'] ?? '';

    if (!is_string($search) || strlen($search) > 254) {
        validationError('Search text must be 254 characters or fewer.');
    }

    return trim($search);
}

function getOperationalStatusFilter(array $allowed): ?string
{
    $status = $_GET['status'] ?? '';

    if (!is_string($status) || ($status !== '' && !in_array($status, $allowed, true))) {
        validationError('The requested status filter is invalid.');
    }

    return $status === '' ? null : $status;
}

function requireJsonPostWithCsrf(): array
{
    requireRequestMethod('POST');
    requireValidCsrfToken();

    return readJsonRequestBody();
}

function makeReference(string $prefix): string
{
    return $prefix . '-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
}

function paginationResult(array $pagination, int $total): array
{
    return [
        'page' => $pagination['page'],
        'per_page' => $pagination['per_page'],
        'total' => $total,
        'pages' => (int) ceil($total / $pagination['per_page']),
    ];
}

function companyRecord(PDO $connection, int $id): ?array
{
    $statement = $connection->prepare(
        'SELECT id,name,registration_number,contact_person,phone,email,address,status,
                created_at,updated_at
         FROM companies WHERE id=:id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $record = $statement->fetch();
    if (!$record) {
        return null;
    }
    $record['id'] = (int) $record['id'];
    return $record;
}

function driverRecord(PDO $connection, int $id): ?array
{
    $statement = $connection->prepare(
        'SELECT d.id,d.company_id,c.name AS company_name,d.full_name,d.identification_number,
                d.license_number,d.phone,d.status,d.created_at,d.updated_at
         FROM drivers d LEFT JOIN companies c ON c.id=d.company_id
         WHERE d.id=:id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $record = $statement->fetch();
    if (!$record) {
        return null;
    }
    $record['id'] = (int) $record['id'];
    $record['company_id'] = $record['company_id'] === null ? null : (int) $record['company_id'];
    return $record;
}

function vehicleRecord(PDO $connection, int $id): ?array
{
    $statement = $connection->prepare(
        'SELECT v.id,v.company_id,c.name AS company_name,v.assigned_driver_id,
                d.full_name AS driver_name,v.plate_number,v.vehicle_type,v.make,v.model,
                v.registration_number,v.registration_expiry,v.status,v.created_at,v.updated_at
         FROM vehicles v
         LEFT JOIN companies c ON c.id=v.company_id
         LEFT JOIN drivers d ON d.id=v.assigned_driver_id
         WHERE v.id=:id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $record = $statement->fetch();
    if (!$record) {
        return null;
    }
    $record['id'] = (int) $record['id'];
    $record['company_id'] = $record['company_id'] === null ? null : (int) $record['company_id'];
    $record['assigned_driver_id'] = $record['assigned_driver_id'] === null
        ? null
        : (int) $record['assigned_driver_id'];
    return $record;
}

function gateRecord(PDO $connection, int $id): ?array
{
    $statement = $connection->prepare(
        'SELECT id,code,name,description,location,supports_entry,supports_exit,status,
                created_at,updated_at
         FROM gates WHERE id=:id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $record = $statement->fetch();
    if (!$record) {
        return null;
    }
    $record['id'] = (int) $record['id'];
    $record['supports_entry'] = (bool) $record['supports_entry'];
    $record['supports_exit'] = (bool) $record['supports_exit'];
    return $record;
}

function laneRecord(PDO $connection, int $id): ?array
{
    $statement = $connection->prepare(
        'SELECT l.id,l.gate_id,g.code AS gate_code,g.name AS gate_name,l.code,l.name,
                l.direction,l.status,l.created_at,l.updated_at
         FROM lanes l JOIN gates g ON g.id=l.gate_id
         WHERE l.id=:id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $record = $statement->fetch();
    if (!$record) {
        return null;
    }
    $record['id'] = (int) $record['id'];
    $record['gate_id'] = (int) $record['gate_id'];
    return $record;
}

function scheduleRecord(PDO $connection, int $id): ?array
{
    $statement = $connection->prepare(
        'SELECT s.id,s.reference,s.company_id,c.name AS company_name,
                s.vehicle_id,v.plate_number,v.vehicle_type,v.make,v.model,
                s.driver_id,d.full_name AS driver_name,s.gate_id,g.code AS gate_code,
                g.name AS gate_name,s.lane_id,l.code AS lane_code,l.name AS lane_name,
                s.direction,s.scheduled_at,s.purpose,s.cargo_summary,s.status,
                s.created_by,s.created_at,s.updated_at
         FROM schedules s
         LEFT JOIN companies c ON c.id=s.company_id
         JOIN vehicles v ON v.id=s.vehicle_id
         JOIN drivers d ON d.id=s.driver_id
         LEFT JOIN gates g ON g.id=s.gate_id
         LEFT JOIN lanes l ON l.id=s.lane_id
         WHERE s.id=:id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $record = $statement->fetch();
    if (!$record) {
        return null;
    }
    foreach (['id','company_id','vehicle_id','driver_id','gate_id','lane_id','created_by'] as $field) {
        if (array_key_exists($field, $record)) {
            $record[$field] = $record[$field] === null ? null : (int) $record[$field];
        }
    }
    return $record;
}

function approvalRecord(PDO $connection, int $id, bool $includeHistory = true): ?array
{
    $statement = $connection->prepare(
        'SELECT a.id,a.reference,a.request_type,a.title,a.note,a.details,
                a.company_id,c.name AS company_name,a.driver_id,d.full_name AS driver_name,
                a.vehicle_id,v.plate_number,a.schedule_id,s.reference AS schedule_reference,
                a.visitor_pass_id,vp.reference AS visitor_pass_reference,
                vp.visitor_id,visitor.reference AS visitor_reference,
                visitor.full_name AS visitor_name,
                a.goods_declaration_id,goods.reference AS goods_declaration_reference,
                a.gate_id,g.code AS gate_code,g.name AS gate_name,
                a.lane_id,l.code AS lane_code,l.name AS lane_name,a.direction,
                a.submitted_by,submitter.username AS submitted_by_username,
                a.assigned_reviewer_id,reviewer.username AS assigned_reviewer_username,
                a.status,a.decision_reason,a.decided_by,decider.username AS decided_by_username,
                a.decided_at,a.created_at,a.updated_at
         FROM approval_requests a
         LEFT JOIN companies c ON c.id=a.company_id
         LEFT JOIN drivers d ON d.id=a.driver_id
         LEFT JOIN vehicles v ON v.id=a.vehicle_id
         LEFT JOIN schedules s ON s.id=a.schedule_id
         LEFT JOIN visitor_passes vp ON vp.id=a.visitor_pass_id
         LEFT JOIN visitors visitor ON visitor.id=vp.visitor_id
         LEFT JOIN goods_declarations goods ON goods.id=a.goods_declaration_id
         LEFT JOIN gates g ON g.id=a.gate_id
         LEFT JOIN lanes l ON l.id=a.lane_id
         JOIN users submitter ON submitter.id=a.submitted_by
         LEFT JOIN users reviewer ON reviewer.id=a.assigned_reviewer_id
         LEFT JOIN users decider ON decider.id=a.decided_by
         WHERE a.id=:id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $record = $statement->fetch();
    if (!$record) {
        return null;
    }
    foreach (
        [
            'id',
            'company_id',
            'driver_id',
            'vehicle_id',
            'schedule_id',
            'visitor_pass_id',
            'visitor_id',
            'goods_declaration_id',
            'gate_id',
            'lane_id',
            'submitted_by',
            'assigned_reviewer_id',
            'decided_by',
        ] as $field
    ) {
        $record[$field] = $record[$field] === null ? null : (int) $record[$field];
    }
    $record['details'] = $record['details'] === null
        ? null
        : json_decode($record['details'], true, 32, JSON_THROW_ON_ERROR);

    if ($includeHistory) {
        $history = $connection->prepare(
            'SELECT d.id,d.actor_id,u.username AS actor_username,d.previous_status,
                    d.new_status,d.reason,d.created_at
             FROM approval_decisions d JOIN users u ON u.id=d.actor_id
             WHERE d.approval_request_id=:request_id
             ORDER BY d.created_at,d.id'
        );
        $history->execute(['request_id' => $id]);
        $record['history'] = $history->fetchAll();
        foreach ($record['history'] as &$decision) {
            $decision['id'] = (int) $decision['id'];
            $decision['actor_id'] = (int) $decision['actor_id'];
        }
        unset($decision);
    }

    return $record;
}
