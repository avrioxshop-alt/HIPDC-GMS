<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

requireRequestMethod('POST');
requireValidCsrfToken();

if (!destroyApplicationSession()) {
    internalServerErrorResponse();
}

successResponse([], 'Logout successful.');
