<?php

function getSessionCookiePath(array $sessionConfiguration): string
{
    $configuredPath = $sessionConfiguration['path'] ?? null;

    if (is_string($configuredPath) && $configuredPath !== '') {
        if (
            $configuredPath[0] !== '/'
            || !preg_match('#^[A-Za-z0-9/._-]+$#', $configuredPath)
        ) {
            throw new RuntimeException('HIPDC_SESSION_PATH is invalid.');
        }

        return rtrim($configuredPath, '/') . '/';
    }

    $projectRoot = realpath(__DIR__ . '/..');
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));

    if ($projectRoot !== false && $documentRoot !== false) {
        $normalizedProjectRoot = str_replace('\\', '/', $projectRoot);
        $normalizedDocumentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');

        if (strcasecmp($normalizedProjectRoot, $normalizedDocumentRoot) === 0) {
            return '/';
        }

        $documentPrefix = $normalizedDocumentRoot . '/';

        if (stripos($normalizedProjectRoot, $documentPrefix) === 0) {
            $relativeProjectPath = substr($normalizedProjectRoot, strlen($documentPrefix));
            return '/' . trim($relativeProjectPath, '/') . '/';
        }
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
    $apiPosition = strpos($scriptName, '/api/');
    $projectPath = $apiPosition === false
        ? dirname(dirname($scriptName))
        : substr($scriptName, 0, $apiPosition);
    $projectPath = '/' . trim($projectPath, '/');

    return $projectPath === '/' ? '/' : $projectPath . '/';
}

function isHttpsRequest(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';

    return ($https !== '' && strtolower((string) $https) !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function startApplicationSession(array $sessionConfiguration): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (session_status() !== PHP_SESSION_NONE) {
        throw new RuntimeException('The PHP session cannot be started.');
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name($sessionConfiguration['name']);
    session_set_cookie_params([
        'lifetime' => (int) $sessionConfiguration['lifetime'],
        'path' => getSessionCookiePath($sessionConfiguration),
        'secure' => isHttpsRequest(),
        'httponly' => (bool) $sessionConfiguration['http_only'],
        'samesite' => $sessionConfiguration['same_site'],
    ]);

    if (!session_start()) {
        throw new RuntimeException('The PHP session could not be started.');
    }
}

function regenerateSessionId(bool $deleteOldSession = true): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('An active session is required to regenerate its ID.');
    }

    return session_regenerate_id($deleteOldSession);
}

function destroyApplicationSession(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return true;
    }

    $_SESSION = [];
    $cookieParameters = session_get_cookie_params();
    $cookieExpired = true;

    if (ini_get('session.use_cookies')) {
        $cookieExpired = setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $cookieParameters['path'],
                'domain' => $cookieParameters['domain'],
                'secure' => $cookieParameters['secure'],
                'httponly' => $cookieParameters['httponly'],
                'samesite' => $cookieParameters['samesite'] ?? 'Lax',
            ]
        );
    }

    $sessionDestroyed = session_destroy();

    if (!$cookieExpired || !$sessionDestroyed) {
        error_log('The HIPDC application session could not be fully destroyed.');
        return false;
    }

    return true;
}
