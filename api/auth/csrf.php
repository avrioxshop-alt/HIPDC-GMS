<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
requireRequestMethod('GET');

successResponse(
    ['token' => getCsrfToken()],
    'CSRF token issued.'
);
