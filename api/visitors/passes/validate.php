<?php

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/visitor_goods.php';

$actor = requireOperationalPermission('visitors.passes.validate');
$data = requireJsonPostWithCsrf();
try {
    $token = validateOperationalText($data, 'qr_token', 64, true);
    $gateId = validateOptionalId($data, 'gate_id');
    $direction = $data['direction'] ?? null;
    if (
        $gateId === null
        || !is_string($direction)
        || !in_array($direction, ['entry', 'exit'], true)
    ) {
        throw new InvalidArgumentException('An assigned gate and entry or exit direction are required.');
    }
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        throw new InvalidArgumentException('QR token format is invalid.');
    }
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

requireAssignedGateAccess($actor, $gateId, $direction);
$tokenHash = hash('sha256', hex2bin($token), true);
$connection = getDatabaseConnection();
$lookup = $connection->prepare(
    'SELECT p.id,p.reference,p.qr_token_hash,p.visitor_id,p.gate_id,p.direction,
            p.valid_from,p.expires_at,p.status,v.full_name,v.reference AS visitor_reference,
            v.purpose,v.status AS visitor_status,c.status AS company_status,g.status AS gate_status,
            CASE WHEN p.expires_at<=CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS is_expired,
            a.status AS approval_status
     FROM visitor_passes p
     JOIN visitors v ON v.id=p.visitor_id
     LEFT JOIN companies c ON c.id=v.company_id
     JOIN gates g ON g.id=p.gate_id
     LEFT JOIN approval_requests a ON a.visitor_pass_id=p.id
     WHERE p.qr_token_hash=:token_hash LIMIT 1'
);
$lookup->bindValue(':token_hash', $tokenHash, PDO::PARAM_LOB);
$lookup->execute();
$pass = $lookup->fetch();
$valid = $pass !== false
    && hash_equals($pass['qr_token_hash'], $tokenHash)
    && $pass['status'] === 'active'
    && $pass['approval_status'] === 'approved'
    && $pass['visitor_status'] === 'active'
    && ($pass['company_status'] === null || $pass['company_status'] === 'active')
    && $pass['gate_status'] === 'active'
    && (int) $pass['is_expired'] === 0
    && $pass['valid_from'] <= (string) $connection->query('SELECT CURRENT_TIMESTAMP')->fetchColumn()
    && (int) $pass['gate_id'] === $gateId
    && $pass['direction'] === $direction;

if (!$valid) {
    successResponse(['valid' => false], 'Pass is not valid for this gate, direction, or time.');
}

successResponse([
    'valid' => true,
    'pass' => [
        'reference' => $pass['reference'],
        'visitor_reference' => $pass['visitor_reference'],
        'visitor_name' => $pass['full_name'],
        'purpose' => $pass['purpose'],
        'valid_from' => $pass['valid_from'],
        'expires_at' => $pass['expires_at'],
        'gate_id' => (int) $pass['gate_id'],
        'direction' => $pass['direction'],
    ],
], 'Visitor pass is valid. This validation is not a physical entry or exit event.');
