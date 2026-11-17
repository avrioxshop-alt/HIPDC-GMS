<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

requireRequestMethod('GET');
$user = requireAuthenticatedUser();
$user['gate_assignments'] = getUserGateAssignments(
    getDatabaseConnection(),
    $user['id']
);
unset($user['auth_version']);

successResponse(['user' => $user], 'Authenticated user loaded.');
