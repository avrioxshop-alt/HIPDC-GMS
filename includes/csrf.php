<?php

function getCsrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('An active session is required for a CSRF token.');
    }

    if (
        !isset($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
        || !preg_match('/^[a-f0-9]{64}$/', $_SESSION['csrf_token'])
    ) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validateCsrfToken(?string $submittedToken = null): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    if ($submittedToken === null) {
        $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    }

    $sessionToken = $_SESSION['csrf_token'] ?? null;

    return is_string($submittedToken)
        && is_string($sessionToken)
        && $sessionToken !== ''
        && hash_equals($sessionToken, $submittedToken);
}

function requireValidCsrfToken(?string $submittedToken = null): void
{
    if (!validateCsrfToken($submittedToken)) {
        forbiddenResponse('The request could not be verified. Refresh and try again.');
    }
}
