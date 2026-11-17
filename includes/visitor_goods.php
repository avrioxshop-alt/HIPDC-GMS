<?php

require_once __DIR__ . '/operations.php';

function validateVisitorFields(PDO $connection, array $data): array
{
    $fullName = validateOperationalText($data, 'full_name', 160, true);
    $documentReference = validateOperationalText($data, 'document_reference', 120);
    $phone = validateOptionalPhone($data);
    $email = validateOptionalEmail($data);
    $organization = validateOperationalText($data, 'organization', 160);
    $companyId = validateOptionalId($data, 'company_id');
    $hostPerson = validateOperationalText($data, 'host_person', 120);
    $purpose = validateOperationalText($data, 'purpose', 255, true);
    $arrival = validateOperationalDateTime($data['expected_arrival_at'] ?? null, 'expected_arrival_at');
    $departure = isset($data['expected_departure_at']) && $data['expected_departure_at'] !== ''
        ? validateOperationalDateTime($data['expected_departure_at'], 'expected_departure_at')
        : null;
    $scheduleId = validateOptionalId($data, 'schedule_id');

    if ($departure !== null && $departure <= $arrival) {
        throw new InvalidArgumentException('Expected departure must be later than expected arrival.');
    }

    validateCompanyReference($connection, $companyId);
    if ($scheduleId !== null) {
        $schedule = scheduleRecord($connection, $scheduleId);
        if ($schedule === null || $schedule['status'] !== 'scheduled') {
            throw new InvalidArgumentException('The selected schedule is unavailable.');
        }
        if (
            $companyId !== null
            && $schedule['company_id'] !== null
            && $companyId !== $schedule['company_id']
        ) {
            throw new InvalidArgumentException('The schedule is not associated with the selected company.');
        }
    }

    return [
        'full_name' => $fullName,
        'document_reference' => $documentReference,
        'phone' => $phone,
        'email' => $email,
        'organization' => $organization,
        'company_id' => $companyId,
        'host_person' => $hostPerson,
        'purpose' => $purpose,
        'expected_arrival_at' => $arrival,
        'expected_departure_at' => $departure,
        'schedule_id' => $scheduleId,
    ];
}

function validateGoodsReferences(
    PDO $connection,
    array $actor,
    int $companyId,
    ?int $vehicleId,
    ?int $driverId,
    ?int $scheduleId,
    ?int $gateId,
    ?int $laneId,
    string $direction
): void {
    validateCompanyReference($connection, $companyId);

    if ($vehicleId !== null) {
        validateVehicleReference($connection, $vehicleId, $companyId);
    }
    if ($driverId !== null) {
        validateDriverReference($connection, $driverId, $companyId);
    }

    if ($scheduleId !== null) {
        $schedule = scheduleRecord($connection, $scheduleId);
        if ($schedule === null || $schedule['status'] !== 'scheduled') {
            throw new InvalidArgumentException('The selected schedule is unavailable.');
        }
        if ($schedule['company_id'] !== null && $schedule['company_id'] !== $companyId) {
            throw new InvalidArgumentException('The schedule is not associated with the selected company.');
        }
        if ($vehicleId !== null && $schedule['vehicle_id'] !== $vehicleId) {
            throw new InvalidArgumentException('The schedule is not associated with the selected vehicle.');
        }
        if ($driverId !== null && $schedule['driver_id'] !== $driverId) {
            throw new InvalidArgumentException('The schedule is not associated with the selected driver.');
        }
        if ($schedule['direction'] !== $direction) {
            throw new InvalidArgumentException('The declaration direction does not match the selected schedule.');
        }
        if ($schedule['gate_id'] !== null && $schedule['gate_id'] !== $gateId) {
            throw new InvalidArgumentException('The declaration gate must match the selected schedule.');
        }
        if ($schedule['lane_id'] !== null && $schedule['lane_id'] !== $laneId) {
            throw new InvalidArgumentException('The declaration lane must match the selected schedule.');
        }
    }

    $lane = validateLaneReference($connection, $laneId, $gateId, $direction);
    if ($gateId !== null) {
        validateGateDirection($connection, $gateId, $direction);
        requireAssignedGateAccess($actor, $gateId, $direction);
        if ($lane !== null && $lane['status'] !== 'active') {
            throw new InvalidArgumentException('The selected lane is inactive.');
        }
    }
}

function validateGoodsItems(mixed $items): array
{
    if (!is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > 100) {
        throw new InvalidArgumentException('Items must be a list containing between 1 and 100 goods items.');
    }

    $validated = [];
    foreach ($items as $item) {
        if (!is_array($item) || $item === []) {
            throw new InvalidArgumentException('Each goods item must be an object.');
        }

        $description = validateOperationalText($item, 'description', 255, true);
        $itemCode = validateOperationalText($item, 'item_code', 80);
        $quantityValue = $item['quantity'] ?? null;
        if (!is_string($quantityValue) && !is_int($quantityValue) && !is_float($quantityValue)) {
            throw new InvalidArgumentException('Item quantity must be a positive number with up to 3 decimal places.');
        }
        $quantity = (string) $quantityValue;
        if (
            !preg_match('/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,3})?$/', $quantity)
            || (float) $quantity <= 0
        ) {
            throw new InvalidArgumentException('Item quantity must be a positive number with up to 3 decimal places.');
        }
        $unit = validateOperationalText($item, 'unit', 32, true);
        $serialNumber = validateOperationalText($item, 'serial_number', 120);
        $remarks = validateOperationalText($item, 'remarks', 1000);
        $validated[] = [
            'description' => $description,
            'item_code' => $itemCode,
            'quantity' => $quantity,
            'unit' => $unit,
            'serial_number' => $serialNumber,
            'remarks' => $remarks,
        ];
    }

    return $validated;
}

function insertGoodsItems(PDO $connection, int $declarationId, array $items): void
{
    $insert = $connection->prepare(
        'INSERT INTO goods_items
            (declaration_id,description,item_code,quantity,unit,serial_number,remarks)
         VALUES
            (:declaration_id,:description,:item_code,:quantity,:unit,:serial_number,:remarks)'
    );
    foreach ($items as $item) {
        $insert->execute([
            'declaration_id' => $declarationId,
            'description' => $item['description'],
            'item_code' => $item['item_code'],
            'quantity' => $item['quantity'],
            'unit' => $item['unit'],
            'serial_number' => $item['serial_number'],
            'remarks' => $item['remarks'],
        ]);
    }
}

function visitorRecord(PDO $connection, int $id): ?array
{
    $statement = $connection->prepare(
        'SELECT v.id,v.reference,v.full_name,v.document_reference,v.phone,v.email,
                v.organization,v.company_id,c.name AS company_name,v.host_person,v.purpose,
                v.expected_arrival_at,v.expected_departure_at,v.schedule_id,
                s.reference AS schedule_reference,v.status,v.created_by,
                v.created_at,v.updated_at
         FROM visitors v
         LEFT JOIN companies c ON c.id=v.company_id
         LEFT JOIN schedules s ON s.id=v.schedule_id
         WHERE v.id=:id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $record = $statement->fetch();
    if (!$record) {
        return null;
    }
    foreach (['id', 'company_id', 'schedule_id', 'created_by'] as $field) {
        $record[$field] = $record[$field] === null ? null : (int) $record[$field];
    }
    return $record;
}

function visitorPassRecord(PDO $connection, int $id): ?array
{
    $statement = $connection->prepare(
        'SELECT p.id,p.reference,p.visitor_id,v.reference AS visitor_reference,
                v.full_name AS visitor_name,v.company_id,c.name AS company_name,
                p.gate_id,g.code AS gate_code,g.name AS gate_name,p.direction,
                p.valid_from,p.expires_at,
                CASE WHEN p.status=\'active\' AND p.expires_at<=CURRENT_TIMESTAMP
                     THEN \'expired\' ELSE p.status END AS status,
                p.submitted_by,p.issued_by,p.issued_at,p.revoked_by,p.revoked_at,
                p.revocation_reason,p.created_at,p.updated_at,
                a.id AS approval_request_id,a.reference AS approval_reference,
                a.status AS approval_status
         FROM visitor_passes p
         JOIN visitors v ON v.id=p.visitor_id
         LEFT JOIN companies c ON c.id=v.company_id
         JOIN gates g ON g.id=p.gate_id
         LEFT JOIN approval_requests a ON a.visitor_pass_id=p.id
         WHERE p.id=:id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $record = $statement->fetch();
    if (!$record) {
        return null;
    }
    foreach (
        ['id', 'visitor_id', 'company_id', 'gate_id', 'submitted_by', 'issued_by', 'revoked_by', 'approval_request_id']
        as $field
    ) {
        $record[$field] = $record[$field] === null ? null : (int) $record[$field];
    }
    return $record;
}

function visitorPassApprovalRecord(PDO $connection, int $passId): ?array
{
    $statement = $connection->prepare(
        'SELECT id FROM approval_requests WHERE visitor_pass_id=:pass_id LIMIT 1'
    );
    $statement->execute(['pass_id' => $passId]);
    $approvalId = $statement->fetchColumn();
    return $approvalId === false ? null : approvalRecord($connection, (int) $approvalId);
}

function goodsDeclarationRecord(PDO $connection, int $id, bool $includeItems = true): ?array
{
    $statement = $connection->prepare(
        'SELECT d.id,d.reference,d.direction,d.company_id,c.name AS company_name,
                d.vehicle_id,v.plate_number,d.driver_id,dr.full_name AS driver_name,
                d.schedule_id,s.reference AS schedule_reference,d.gate_id,g.code AS gate_code,
                g.name AS gate_name,d.lane_id,l.code AS lane_code,l.name AS lane_name,
                d.purpose,d.submitted_by,d.submitted_at,d.status,d.created_at,d.updated_at
         FROM goods_declarations d
         JOIN companies c ON c.id=d.company_id
         LEFT JOIN vehicles v ON v.id=d.vehicle_id
         LEFT JOIN drivers dr ON dr.id=d.driver_id
         LEFT JOIN schedules s ON s.id=d.schedule_id
         LEFT JOIN gates g ON g.id=d.gate_id
         LEFT JOIN lanes l ON l.id=d.lane_id
         WHERE d.id=:id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $record = $statement->fetch();
    if (!$record) {
        return null;
    }
    foreach (
        ['id', 'company_id', 'vehicle_id', 'driver_id', 'schedule_id', 'gate_id', 'lane_id', 'submitted_by']
        as $field
    ) {
        $record[$field] = $record[$field] === null ? null : (int) $record[$field];
    }
    if ($includeItems) {
        $items = $connection->prepare(
            'SELECT id,description,item_code,quantity,unit,serial_number,remarks,created_at,updated_at
             FROM goods_items WHERE declaration_id=:id ORDER BY id'
        );
        $items->execute(['id' => $id]);
        $record['items'] = $items->fetchAll();
        foreach ($record['items'] as &$item) {
            $item['id'] = (int) $item['id'];
        }
        unset($item);
    }
    return $record;
}

function goodsItemRecord(PDO $connection, int $id): ?array
{
    $statement = $connection->prepare(
        'SELECT id,declaration_id,description,item_code,quantity,unit,serial_number,
                remarks,created_at,updated_at
         FROM goods_items WHERE id=:id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $record = $statement->fetch();
    if (!$record) {
        return null;
    }
    $record['id'] = (int) $record['id'];
    $record['declaration_id'] = (int) $record['declaration_id'];
    return $record;
}

function assertVisitorScope(array $actor, int $createdBy): void
{
    if ($actor['role']['code'] !== 'admin' && $actor['id'] !== $createdBy) {
        forbiddenResponse();
    }
}

function assertGoodsDeclarationScope(array $actor, int $submittedBy): void
{
    if ($actor['role']['code'] !== 'admin' && $actor['id'] !== $submittedBy) {
        forbiddenResponse();
    }
}

function visitorHistory(PDO $connection, int $visitorId): array
{
    $statement = $connection->prepare(
        'SELECT e.id,e.action,e.entity_type,e.entity_id,e.metadata,e.created_at,
                u.username AS actor_username
         FROM audit_events e
         LEFT JOIN users u ON u.id=e.actor_id
         WHERE (e.entity_type=\'visitor\' AND e.entity_id=:visitor_id)
            OR (e.entity_type=\'visitor_pass\' AND e.entity_id IN
                (SELECT p.id FROM visitor_passes p WHERE p.visitor_id=:pass_visitor_id))
            OR (e.entity_type=\'approval_request\' AND e.entity_id IN
                (SELECT a.id FROM approval_requests a
                 JOIN visitor_passes p ON p.id=a.visitor_pass_id
                 WHERE p.visitor_id=:approval_visitor_id))
         ORDER BY e.created_at,e.id'
    );
    $statement->execute([
        'visitor_id' => $visitorId,
        'pass_visitor_id' => $visitorId,
        'approval_visitor_id' => $visitorId,
    ]);
    $events = $statement->fetchAll();
    foreach ($events as &$event) {
        $event['id'] = (int) $event['id'];
        $event['entity_id'] = (int) $event['entity_id'];
        $event['metadata'] = $event['metadata'] === null
            ? null
            : json_decode($event['metadata'], true, 16, JSON_THROW_ON_ERROR);
    }
    unset($event);
    return $events;
}

function goodsDeclarationHistory(PDO $connection, int $declarationId): array
{
    $statement = $connection->prepare(
        'SELECT e.id,e.action,e.entity_type,e.entity_id,e.metadata,e.created_at,
                u.username AS actor_username
         FROM audit_events e
         LEFT JOIN users u ON u.id=e.actor_id
         WHERE (e.entity_type=\'goods_declaration\' AND e.entity_id=:declaration_id)
            OR (e.entity_type=\'approval_request\' AND e.entity_id IN
                (SELECT a.id FROM approval_requests a
                 WHERE a.goods_declaration_id=:approval_declaration_id))
         ORDER BY e.created_at,e.id'
    );
    $statement->execute([
        'declaration_id' => $declarationId,
        'approval_declaration_id' => $declarationId,
    ]);
    $events = $statement->fetchAll();
    foreach ($events as &$event) {
        $event['id'] = (int) $event['id'];
        $event['entity_id'] = (int) $event['entity_id'];
        $event['metadata'] = $event['metadata'] === null
            ? null
            : json_decode($event['metadata'], true, 16, JSON_THROW_ON_ERROR);
    }
    unset($event);
    return $events;
}
