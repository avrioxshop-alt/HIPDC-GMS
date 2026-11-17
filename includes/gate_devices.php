<?php

require_once __DIR__ . '/gate_operations.php';

function gateDeviceRecord(PDO $connection, int $id): ?array
{
    $statement = $connection->prepare(
        'SELECT d.id,d.public_id,d.name,d.device_type,d.gate_id,g.code AS gate_code,
                g.name AS gate_name,d.lane_id,l.code AS lane_code,l.name AS lane_name,
                d.direction,d.status,d.last_sequence,d.created_by,u.full_name AS created_by_name,
                d.created_at,d.updated_at
         FROM gate_devices d
         JOIN gates g ON g.id=d.gate_id
         JOIN lanes l ON l.id=d.lane_id
         JOIN users u ON u.id=d.created_by
         WHERE d.id=:id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $record = $statement->fetch();
    if (!$record) {
        return null;
    }

    foreach (['id', 'gate_id', 'lane_id', 'last_sequence', 'created_by'] as $field) {
        $record[$field] = (int) $record[$field];
    }

    return $record;
}

function authenticateGateDevice(): array
{
    if (
        strtolower((string) ($GLOBALS['applicationConfiguration']['environment'] ?? 'development'))
            === 'production'
        && (empty($_SERVER['HTTPS']) || strtolower((string) $_SERVER['HTTPS']) === 'off')
    ) {
        serviceUnavailableResponse('Device callbacks require an HTTPS connection.');
    }

    $authorization = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    $deviceCredential = $_SERVER['HTTP_X_DEVICE_CREDENTIAL'] ?? '';
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0 && $authorization === '') {
                $authorization = $value;
            } elseif (
                strcasecmp((string) $name, 'X-Device-Credential') === 0
                && $deviceCredential === ''
            ) {
                $deviceCredential = $value;
            }
        }
    }
    $credential = null;
    if (
        is_string($authorization)
        && preg_match('/^Bearer ([a-f0-9]{32}\.[a-f0-9]{64})$/i', $authorization, $matches)
    ) {
        $credential = $matches[1];
    } elseif (
        is_string($deviceCredential)
        && preg_match('/^([a-f0-9]{32}\.[a-f0-9]{64})$/i', $deviceCredential, $matches)
    ) {
        $credential = $matches[1];
    }
    if (
        $credential === null
        || !preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/i', $credential, $matches)
    ) {
        unauthorizedResponse('A valid device credential is required.');
    }

    $connection = getDatabaseConnection();
    $statement = $connection->prepare(
        'SELECT id,public_id,name,device_type,gate_id,lane_id,direction,secret_hash,status
         FROM gate_devices WHERE public_id=:public_id LIMIT 1'
    );
    $statement->execute(['public_id' => strtolower($matches[1])]);
    $device = $statement->fetch();
    $candidateHash = hash('sha256', hex2bin($matches[2]), true);
    if (
        !$device
        || $device['status'] !== 'active'
        || !hash_equals($device['secret_hash'], $candidateHash)
    ) {
        unauthorizedResponse('A valid device credential is required.');
    }
    if (!in_array($device['device_type'], ['qr_scanner', 'anpr'], true)) {
        forbiddenResponse('This device type cannot submit access events.');
    }

    foreach (['id', 'gate_id', 'lane_id'] as $field) {
        $device[$field] = (int) $device[$field];
    }

    return $device;
}

function requireDeviceSequence(): int
{
    $sequence = $_SERVER['HTTP_X_DEVICE_SEQUENCE'] ?? null;
    if (
        !is_string($sequence)
        || !preg_match('/^[1-9][0-9]{0,18}$/', $sequence)
    ) {
        throw new InvalidArgumentException('X-Device-Sequence must be a positive increasing integer.');
    }
    $maximum = (string) PHP_INT_MAX;
    if (
        strlen($sequence) > strlen($maximum)
        || (strlen($sequence) === strlen($maximum) && strcmp($sequence, $maximum) > 0)
    ) {
        throw new InvalidArgumentException('X-Device-Sequence is outside the supported range.');
    }

    return (int) $sequence;
}

function userCanManageGateDeviceScope(
    PDO $connection,
    int $userId,
    int $gateId,
    string $direction
): bool {
    $statement = $connection->prepare(
        'SELECT 1
         FROM users u
         JOIN user_gate_assignments uga ON uga.user_id=u.id
         JOIN role_permissions rp ON rp.role_id=u.role_id
         JOIN permissions p ON p.id=rp.permission_id
         WHERE u.id=:user_id AND u.status=\'active\'
           AND uga.gate_id=:gate_id AND uga.direction IN (:direction,\'both\')
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

function requireGateDeviceScope(
    PDO $connection,
    array $actor,
    int $gateId,
    string $direction
): void {
    if (!userCanManageGateDeviceScope($connection, $actor['id'], $gateId, $direction)) {
        forbiddenResponse('You are not assigned to this gate direction.');
    }
}

function createGateDevice(
    PDO $connection,
    int $actorId,
    string $name,
    string $deviceType,
    int $gateId,
    int $laneId,
    string $direction
): array {
    $publicId = bin2hex(random_bytes(16));
    $secret = bin2hex(random_bytes(32));
    $insert = $connection->prepare(
        'INSERT INTO gate_devices
            (public_id,name,device_type,gate_id,lane_id,direction,secret_hash,created_by)
         VALUES
            (:public_id,:name,:device_type,:gate_id,:lane_id,:direction,:secret_hash,:created_by)'
    );
    $insert->bindValue(':public_id', $publicId, PDO::PARAM_STR);
    $insert->bindValue(':name', $name, PDO::PARAM_STR);
    $insert->bindValue(':device_type', $deviceType, PDO::PARAM_STR);
    $insert->bindValue(':gate_id', $gateId, PDO::PARAM_INT);
    $insert->bindValue(':lane_id', $laneId, PDO::PARAM_INT);
    $insert->bindValue(':direction', $direction, PDO::PARAM_STR);
    $insert->bindValue(':secret_hash', hash('sha256', hex2bin($secret), true), PDO::PARAM_LOB);
    $insert->bindValue(':created_by', $actorId, PDO::PARAM_INT);
    $insert->execute();
    $id = (int) $connection->lastInsertId();

    return [
        'device' => gateDeviceRecord($connection, $id),
        'device_token' => $publicId . '.' . $secret,
    ];
}
