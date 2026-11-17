<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define(
    'HIPDC_ADMIN_INTERACTIVE',
    function_exists('stream_isatty') && @stream_isatty(STDIN)
);

require_once __DIR__ . '/../includes/user_management.php';

function readRequiredLine(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $line = fgets(STDIN);

    if ($line === false) {
        throw new RuntimeException('Input ended before all required values were entered.');
    }

    return trim($line);
}

function readAdministratorPassword(): string
{
    $interactive = HIPDC_ADMIN_INTERACTIVE;

    if (PHP_OS_FAMILY === 'Windows' && $interactive) {
        fwrite(STDOUT, 'Administrator password (input hidden): ');
        $command = 'powershell.exe -NoLogo -NoProfile -Command '
            . '"$secure = Read-Host -AsSecureString; '
            . '$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure); '
            . 'try { [Console]::Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)) } '
            . 'finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }"';
        $password = shell_exec($command);

        if (!is_string($password)) {
            throw new RuntimeException('The hidden password prompt could not be started.');
        }

        return rtrim($password, "\r\n");
    }

    if ($interactive && PHP_OS_FAMILY !== 'Windows') {
        fwrite(STDOUT, 'Administrator password (input hidden): ');
        shell_exec('stty -echo');

        try {
            $password = fgets(STDIN);
        } finally {
            shell_exec('stty echo');
            fwrite(STDOUT, PHP_EOL);
        }

        if ($password === false) {
            throw new RuntimeException('The password could not be read.');
        }

        return rtrim($password, "\r\n");
    }

    fwrite(STDOUT, 'Administrator password: ');
    $password = fgets(STDIN);

    if ($password === false) {
        throw new RuntimeException('The password could not be read.');
    }

    return rtrim($password, "\r\n");
}

try {
    $profile = normalizeUserProfile([
        'username' => readRequiredLine('Username: '),
        'name' => readRequiredLine('Full name: '),
        'email' => readRequiredLine('Email: '),
    ]);
    $password = validateAccountPassword(readAdministratorPassword());
    $connection = getDatabaseConnection();
    $connection->beginTransaction();
    $adminRoleId = lockAdministratorRole($connection);

    if (countActiveAdministrators($connection) > 0) {
        $connection->rollBack();
        fwrite(STDERR, "An active administrator already exists; no account was created.\n");
        exit(1);
    }

    $statement = $connection->prepare(
        'INSERT INTO users (role_id, username, full_name, email, password_hash, status)
         VALUES (:role_id, :username, :full_name, :email, :password_hash, \'active\')'
    );
    $statement->execute([
        'role_id' => $adminRoleId,
        'username' => $profile['username'],
        'full_name' => $profile['name'],
        'email' => $profile['email'],
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $userId = (int) $connection->lastInsertId();
    $connection->commit();
    unset($password);

    fwrite(
        STDOUT,
        'Initial administrator created (user ID ' . $userId . ').' . PHP_EOL
    );
} catch (InvalidArgumentException $exception) {
    if (isset($connection) && $connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }

    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
} catch (PDOException $exception) {
    if (isset($connection) && $connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }

    if ($exception->getCode() === '23000') {
        fwrite(STDERR, "Username or email is already in use.\n");
        exit(1);
    }

    error_log('Initial administrator creation failed: ' . $exception->getMessage());
    fwrite(STDERR, "Administrator creation failed; check the PHP error log.\n");
    exit(1);
} catch (Throwable $exception) {
    if (isset($connection) && $connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }

    error_log('Initial administrator creation failed: ' . $exception->getMessage());
    fwrite(STDERR, "Administrator creation failed; check the PHP error log.\n");
    exit(1);
}
