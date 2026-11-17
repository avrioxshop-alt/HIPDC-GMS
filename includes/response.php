<?php

function jsonResponse(
    bool $success,
    string $message,
    array $data = [],
    int $statusCode = 200
): void {
    $responseConfiguration = $GLOBALS['applicationConfiguration']['response'] ?? [];

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($statusCode);
    header(
        'Content-Type: '
        . ($responseConfiguration['content_type'] ?? 'application/json; charset=utf-8')
    );
    header(
        'Cache-Control: '
        . ($responseConfiguration['cache_control'] ?? 'no-store, no-cache, must-revalidate, max-age=0')
    );
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    $payload = [
        'success' => $success,
        'message' => $message,
    ];

    if ($data !== []) {
        $payload['data'] = $data;
    }

    try {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $exception) {
        error_log('JSON response encoding failed: ' . $exception->getMessage());
        http_response_code(500);
        $body = '{"success":false,"message":"An internal server error occurred."}';
    }

    echo $body;
    exit;
}

function successResponse(
    array $data = [],
    string $message = 'Request completed successfully.',
    int $statusCode = 200
): void {
    jsonResponse(true, $message, $data, $statusCode);
}

function validationError(
    string $message = 'The submitted data is invalid.',
    array $errors = []
): void {
    jsonResponse(false, $message, $errors === [] ? [] : ['errors' => $errors], 422);
}

function unauthorizedResponse(
    string $message = 'Authentication is required.'
): void {
    jsonResponse(false, $message, [], 401);
}

function forbiddenResponse(
    string $message = 'You do not have permission to perform this action.'
): void {
    jsonResponse(false, $message, [], 403);
}

function notFoundResponse(
    string $message = 'The requested resource was not found.'
): void {
    jsonResponse(false, $message, [], 404);
}

function internalServerErrorResponse(): void
{
    jsonResponse(false, 'An internal server error occurred.', [], 500);
}

function serviceUnavailableResponse(
    string $message,
    array $data = []
): void {
    jsonResponse(false, $message, $data, 503);
}
