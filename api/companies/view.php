<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';

requireRequestMethod('GET');
requireOperationalPermission('companies.view');

try {
    $id = parsePositiveId($_GET['id'] ?? null);
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

$company = companyRecord(getDatabaseConnection(), $id);
if ($company === null) {
    notFoundResponse('Company not found.');
}
successResponse(['company' => $company], 'Company loaded.');
