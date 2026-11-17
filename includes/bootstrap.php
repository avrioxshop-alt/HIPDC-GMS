<?php

$applicationConfiguration = require __DIR__ . '/../config/app.php';
$GLOBALS['applicationConfiguration'] = $applicationConfiguration;

ob_start();
ini_set('display_errors', '0');
$errorConfiguration = $applicationConfiguration['errors'] ?? [];
ini_set('log_errors', !empty($errorConfiguration['log']) ? '1' : '0');
error_reporting(E_ALL);

require_once __DIR__ . '/response.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';

set_error_handler(
    static function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }
);

set_exception_handler(
    static function (Throwable $exception): void {
        error_log(
            'Unhandled HIPDC API exception: '
            . get_class($exception)
            . ': '
            . $exception->getMessage()
        );

        if (!headers_sent()) {
            internalServerErrorResponse();
        }
    }
);

register_shutdown_function(
    static function (): void {
        $error = error_get_last();

        if (
            $error !== null
            && in_array(
                $error['type'],
                [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
                true
            )
        ) {
            error_log(
                'Fatal HIPDC API error: '
                . $error['message']
                . ' in '
                . $error['file']
                . ':'
                . $error['line']
            );

            if (!headers_sent()) {
                internalServerErrorResponse();
            }
        }
    }
);

$environment = strtolower((string) $applicationConfiguration['environment']);

if (!in_array($environment, ['development', 'production'], true)) {
    throw new RuntimeException('HIPDC_APP_ENV must be development or production.');
}

startApplicationSession($applicationConfiguration['session']);
