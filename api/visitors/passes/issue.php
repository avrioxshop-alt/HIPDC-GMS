<?php

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/visitor_goods.php';

$actor = requireOperationalPermission('visitors.passes.issue');
$data = requireJsonPostWithCsrf();
try {
    $visitorId = validateOptionalId($data, 'visitor_id');
    $gateId = validateOptionalId($data, 'gate_id');
    if ($visitorId === null || $gateId === null) {
        throw new InvalidArgumentException('Visitor and gate are required.');
    }
    $direction = $data['direction'] ?? null;
    if (!is_string($direction) || !in_array($direction, ['entry', 'exit'], true)) {
        throw new InvalidArgumentException('Direction must be entry or exit.');
    }
    $validFrom = validateOperationalDateTime($data['valid_from'] ?? null, 'valid_from');
    $expiresAt = validateOperationalDateTime($data['expires_at'] ?? null, 'expires_at');
    if ($expiresAt <= $validFrom) {
        throw new InvalidArgumentException('Pass expiration must be later than its valid-from time.');
    }
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $visitor = $connection->prepare(
        'SELECT id,reference,full_name,company_id,schedule_id,status
         FROM visitors WHERE id=:id FOR UPDATE'
    );
    $visitor->execute(['id' => $visitorId]);
    $visitorRecord = $visitor->fetch();
    if (!$visitorRecord) {
        $connection->rollBack();
        notFoundResponse('Visitor not found.');
    }
    if ($visitorRecord['status'] !== 'active') {
        throw new InvalidArgumentException('An inactive visitor cannot receive a pass.');
    }
    if ($visitorRecord['company_id'] !== null) {
        requireEntity($connection, 'companies', (int) $visitorRecord['company_id']);
    }
    if ($visitorRecord['schedule_id'] !== null) {
        $schedule = scheduleRecord($connection, (int) $visitorRecord['schedule_id']);
        if ($schedule === null || $schedule['status'] !== 'scheduled') {
            throw new InvalidArgumentException('The visitor schedule is unavailable.');
        }
        if (
            $schedule['gate_id'] !== null
            && (int) $schedule['gate_id'] !== $gateId
        ) {
            throw new InvalidArgumentException('Pass gate must match the visitor schedule gate.');
        }
        if ($schedule['direction'] !== $direction) {
            throw new InvalidArgumentException('Pass direction must match the visitor schedule direction.');
        }
    }
    validateGateDirection($connection, $gateId, $direction);
    requireAssignedGateAccess($actor, $gateId, $direction);
    $now = (string) $connection->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
    if ($validFrom < $now) {
        throw new InvalidArgumentException('Pass valid-from time cannot be in the past.');
    }

    $passReference = makeReference('VP');
    $insertPass = $connection->prepare(
        'INSERT INTO visitor_passes
            (reference,visitor_id,gate_id,direction,valid_from,expires_at,status,submitted_by)
         VALUES
            (:reference,:visitor_id,:gate_id,:direction,:valid_from,:expires_at,
             \'pending_approval\',:submitted_by)'
    );
    $insertPass->execute([
        'reference' => $passReference,
        'visitor_id' => $visitorId,
        'gate_id' => $gateId,
        'direction' => $direction,
        'valid_from' => $validFrom,
        'expires_at' => $expiresAt,
        'submitted_by' => $actor['id'],
    ]);
    $passId = (int) $connection->lastInsertId();

    $requestReference = makeReference('APR');
    $insertApproval = $connection->prepare(
        'INSERT INTO approval_requests
            (reference,request_type,title,note,details,visitor_pass_id,gate_id,direction,
             submitted_by,status)
         VALUES
            (:reference,\'visitor_pass\',:title,:note,:details,:pass_id,:gate_id,:direction,
             :submitted_by,\'pending\')'
    );
    $insertApproval->execute([
        'reference' => $requestReference,
        'title' => 'Visitor pass: ' . $visitorRecord['full_name'],
        'note' => validateOperationalText($data, 'note', 2000),
        'details' => json_encode(
            ['pass_reference' => $passReference],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ),
        'pass_id' => $passId,
        'gate_id' => $gateId,
        'direction' => $direction,
        'submitted_by' => $actor['id'],
    ]);
    $approvalId = (int) $connection->lastInsertId();
    writeOperationalAudit($connection, $actor['id'], 'visitor.pass.submit', 'visitor_pass', $passId);
    writeOperationalAudit($connection, $actor['id'], 'approval.submit', 'approval_request', $approvalId);
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
    respondToOperationalConflict($exception, 'Visitor pass request conflicts with existing records.');
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}

successResponse([
    'pass' => visitorPassRecord($connection, $passId),
    'approval' => approvalRecord($connection, $approvalId),
], 'Visitor pass submitted for approval. No QR token is issued until approval.', 201);
