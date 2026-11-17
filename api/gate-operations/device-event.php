<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/gate_devices.php';

requireRequestMethod('POST');
$device = authenticateGateDevice();
$data = readJsonRequestBody();
try {
    $sequence = requireDeviceSequence();
    $idempotencyKey = requireIdempotencyKey();
    if ($device['device_type'] === 'qr_scanner') {
        if (array_diff(array_keys($data), ['qr_token']) !== []) {
            throw new InvalidArgumentException('QR scanner payload contains unsupported fields.');
        }
        $input = validateAccessRequestFields(
            array_merge($data, [
                'gate_id' => $device['gate_id'],
                'lane_id' => $device['lane_id'],
                'direction' => $device['direction'],
            ]),
            'visitor'
        );
        $subjectType = 'visitor';
        $method = 'qr';
    } elseif ($device['device_type'] === 'anpr') {
        if (array_diff(array_keys($data), ['plate_number']) !== []) {
            throw new InvalidArgumentException('ANPR payload contains unsupported fields.');
        }
        $input = validateAccessRequestFields(
            array_merge($data, [
                'gate_id' => $device['gate_id'],
                'lane_id' => $device['lane_id'],
                'direction' => $device['direction'],
            ]),
            'vehicle'
        );
        $subjectType = 'vehicle';
        $method = 'anpr';
    } else {
        forbiddenResponse('This device type cannot submit access events.');
    }
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

$device['sequence'] = $sequence;
$requestHash = makeAccessRequestHash(
    $subjectType,
    $method,
    null,
    $device['id'],
    ['sequence' => $sequence, 'input' => $input]
);
$connection = getDatabaseConnection();
try {
    $result = processGateAccessAttempt(
        $connection,
        null,
        $device,
        $subjectType,
        $method,
        $idempotencyKey,
        $requestHash,
        $input
    );
} catch (GateOperationException $exception) {
    jsonResponse(false, $exception->getMessage(), [], $exception->httpStatus);
}

$event = $result['event'];
$message = $event['access_result'] === 'granted'
    ? 'Device access event was granted and recorded.'
    : 'Device access event was denied and recorded.';
successResponse(
    ['event' => $event, 'replayed' => $result['replayed']],
    $message,
    $result['replayed'] ? 200 : 201
);
