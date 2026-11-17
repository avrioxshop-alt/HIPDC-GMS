<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/user_management.php';

$actor = requireAdministrator('users.assign_gates');
$connection = getDatabaseConnection();

try {
    $userId = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleUserInputException($exception);
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
    $user = getManagedUser($connection, $userId);

    if ($user === null) {
        notFoundResponse('User not found.');
    }

    successResponse(
        ['gate_assignments' => getUserGateAssignments($connection, $userId)],
        'Gate assignments loaded.'
    );
}

requireRequestMethod('POST');
requireValidCsrfToken();

if ($userId === $actor['id']) {
    forbiddenResponse('You cannot change your own gate assignments.');
}

$user = getManagedUser($connection, $userId);

if ($user === null) {
    notFoundResponse('User not found.');
}

$data = readJsonRequestBody();
$assignments = $data['assignments'] ?? null;

if (!is_array($assignments) || !array_is_list($assignments)) {
    validationError('Assignments must be a list.');
}

$normalizedAssignments = [];
$seenGateIds = [];

foreach ($assignments as $assignment) {
    if (!is_array($assignment)) {
        validationError('Each gate assignment must be an object.');
    }

    try {
        $gateId = parsePositiveId($assignment['gate_id'] ?? null, 'gate_id');
    } catch (InvalidArgumentException $exception) {
        handleUserInputException($exception);
    }

    $direction = $assignment['direction'] ?? null;

    if (!is_string($direction) || !in_array($direction, ['entry', 'exit', 'both'], true)) {
        validationError('Gate direction must be entry, exit, or both.');
    }

    if (isset($seenGateIds[$gateId])) {
        validationError('A gate can only be assigned once per user.');
    }

    $seenGateIds[$gateId] = true;
    $normalizedAssignments[] = ['gate_id' => $gateId, 'direction' => $direction];
}

$connection->beginTransaction();

try {
    $connection->prepare('DELETE FROM user_gate_assignments WHERE user_id = :user_id')
        ->execute(['user_id' => $userId]);
    $insert = $connection->prepare(
        'INSERT INTO user_gate_assignments (user_id, gate_id, direction, assigned_by)
         SELECT :user_id, g.id, :direction, :assigned_by
         FROM gates AS g
         WHERE g.id = :gate_id AND g.status = \'active\''
    );

    foreach ($normalizedAssignments as $assignment) {
        $insert->execute([
            'user_id' => $userId,
            'direction' => $assignment['direction'],
            'assigned_by' => $actor['id'],
            'gate_id' => $assignment['gate_id'],
        ]);

        if ($insert->rowCount() !== 1) {
            throw new InvalidArgumentException(
                'Gate ' . $assignment['gate_id'] . ' does not exist or is inactive.'
            );
        }
    }

    $connection->commit();
} catch (InvalidArgumentException $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }

    validationError($exception->getMessage());
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }

    throw $exception;
}

logAdministrativeAction($actor['id'], 'user.gates.assign', $userId);
successResponse(
    ['gate_assignments' => getUserGateAssignments($connection, $userId)],
    'Gate assignments updated.'
);
