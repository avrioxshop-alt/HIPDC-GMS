<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

$checks = [
    'php' => ['status' => 'ok'],
    'database_server' => ['status' => 'failed'],
    'database' => ['status' => 'failed'],
];

try {
    $serverConnection = getDatabaseServerConnection();
    $serverConnection->query('SELECT 1');
    $checks['database_server']['status'] = 'ok';
} catch (Throwable $exception) {
    error_log(
        'HIPDC health check database-server probe failed: '
        . get_class($exception)
        . ': '
        . $exception->getMessage()
    );
}

try {
    $databaseConnection = getDatabaseConnection();
    $selectedDatabase = $databaseConnection->query('SELECT DATABASE()')->fetchColumn();

    if ($selectedDatabase === false || $selectedDatabase === null) {
        throw new RuntimeException('The configured database was not selected.');
    }
    $checks['database']['status'] = 'ok';
    $checks['database_server']['status'] = 'ok';
} catch (Throwable $exception) {
    error_log(
        'HIPDC health check database probe failed: '
        . get_class($exception)
        . ': '
        . $exception->getMessage()
    );
}

$healthy = $checks['database_server']['status'] === 'ok'
    && $checks['database']['status'] === 'ok';

if (!$healthy) {
    serviceUnavailableResponse(
        'One or more backend dependencies are unavailable.',
        [
            'status' => 'degraded',
            'checks' => $checks,
        ]
    );
}

successResponse(
    [
        'status' => 'ok',
        'checks' => $checks,
    ],
    'Backend health check passed.'
);
