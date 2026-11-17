<?php

function getDatabaseConfiguration(): array
{
    $host = getenv('HIPDC_DB_HOST');
    $port = getenv('HIPDC_DB_PORT');
    $name = getenv('HIPDC_DB_NAME');
    $username = getenv('HIPDC_DB_USER');
    $password = getenv('HIPDC_DB_PASSWORD');

    $configuration = [
        'host' => $host !== false && $host !== '' ? $host : 'localhost',
        'port' => $port !== false && $port !== '' ? $port : '3306',
        'name' => $name !== false && $name !== '' ? $name : 'hipdc_gms',
        'username' => $username !== false && $username !== '' ? $username : 'root',
        'password' => $password !== false ? $password : '',
    ];

    if (!ctype_digit($configuration['port'])) {
        throw new RuntimeException('HIPDC_DB_PORT must be a valid TCP port.');
    }

    $portNumber = (int) $configuration['port'];
    if ($portNumber < 1 || $portNumber > 65535) {
        throw new RuntimeException('HIPDC_DB_PORT must be between 1 and 65535.');
    }

    return $configuration;
}

function createDatabaseConnection(bool $selectDatabase): PDO
{
    $configuration = getDatabaseConfiguration();
    $databasePart = $selectDatabase
        ? ';dbname=' . $configuration['name']
        : '';
    $dsn = sprintf(
        'mysql:host=%s;port=%s%s;charset=utf8mb4',
        $configuration['host'],
        $configuration['port'],
        $databasePart
    );

    return new PDO(
        $dsn,
        $configuration['username'],
        $configuration['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function getDatabaseConnection(): PDO
{
    static $connection;

    if (!$connection instanceof PDO) {
        $connection = createDatabaseConnection(true);
    }

    return $connection;
}

function getDatabaseServerConnection(): PDO
{
    return createDatabaseConnection(false);
}
