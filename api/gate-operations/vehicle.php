<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/gate_operations.php';

requireRequestMethod('POST');
handleManualGateAccessRequest('vehicle');
