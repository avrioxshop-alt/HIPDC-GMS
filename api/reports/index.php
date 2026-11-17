<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/gate_operations.php';
require_once __DIR__ . '/../../includes/operations.php';

requireRequestMethod('GET');
$actor = requireOperationalPermission('reports.view');
$report = $_GET['report'] ?? null;
$allowedReports = ['vehicle_movements', 'visitor_activity', 'goods_declarations', 'approvals', 'gate_events'];
if (!is_string($report) || !in_array($report, $allowedReports, true)) {
    validationError('Report must be one of the supported report types.');
}
$format = $_GET['format'] ?? 'json';
if (!is_string($format) || !in_array($format, ['json', 'csv'], true)) {
    validationError('Report format must be json or csv.');
}
$connection = getDatabaseConnection();
$where = [];
$parameters = [];
try {
    $fromDate = validateOptionalDate($_GET['from'] ?? null, 'From date');
    $toDate = validateOptionalDate($_GET['to'] ?? null, 'To date');
    if ($fromDate !== null && $toDate !== null) {
        if ($fromDate > $toDate) {
            throw new InvalidArgumentException('From date must not be later than to date.');
        }
        $from = new DateTimeImmutable($fromDate);
        $to = new DateTimeImmutable($toDate);
        if ((int) $from->diff($to)->format('%a') > 365) {
            throw new InvalidArgumentException('Report date ranges may not exceed 366 calendar days.');
        }
    }
    if (isset($_GET['gate_id']) && $_GET['gate_id'] !== '') {
        $parameters['gate_id'] = parsePositiveId($_GET['gate_id'], 'gate id');
        $where[] = 'REPORT_GATE_COLUMN=:gate_id';
    }
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $parameters['company_id'] = parsePositiveId($_GET['company_id'], 'company id');
        $where[] = 'REPORT_COMPANY_COLUMN=:company_id';
    }
    if (isset($_GET['direction']) && $_GET['direction'] !== '') {
        $direction = $_GET['direction'];
        if (!is_string($direction) || !in_array($direction, ['entry', 'exit'], true)) {
            throw new InvalidArgumentException('Direction filter is invalid.');
        }
        $parameters['direction'] = $direction;
        $where[] = 'REPORT_DIRECTION_COLUMN=:direction';
    }
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $status = $_GET['status'];
        $statuses = $report === 'approvals'
            ? ['pending', 'approved', 'denied', 'cancelled']
            : ($report === 'goods_declarations'
                ? ['draft', 'pending', 'approved', 'denied', 'cancelled']
                : []);
        if (!is_string($status) || !in_array($status, $statuses, true)) {
            throw new InvalidArgumentException('Status filter is not supported for this report.');
        }
        $parameters['status'] = $status;
        $where[] = 'REPORT_STATUS_COLUMN=:status';
    }
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}

$dateColumn = in_array($report, ['approvals', 'goods_declarations'], true)
    ? ($report === 'approvals' ? 'a.created_at' : 'gd.created_at')
    : 'e.event_at';
if ($fromDate !== null) {
    $where[] = $dateColumn . '>=:from_date';
    $parameters['from_date'] = $fromDate . ' 00:00:00';
}
if ($toDate !== null) {
    $where[] = $dateColumn . '<DATE_ADD(:to_date,INTERVAL 1 DAY)';
    $parameters['to_date'] = $toDate;
}

if (in_array($report, ['vehicle_movements', 'visitor_activity', 'gate_events'], true)) {
    $where[] = accessEventScopeSql('e');
    $parameters['scope_role_id'] = $actor['role']['id'];
    $parameters['scope_user_id'] = $actor['id'];
    if ($report === 'vehicle_movements') {
        $where[] = 'e.subject_type=\'vehicle\'';
    } elseif ($report === 'visitor_activity') {
        $where[] = 'e.subject_type=\'visitor\'';
    }
} elseif ($report === 'goods_declarations') {
    if ($actor['role']['code'] !== 'admin') {
        $where[] = 'gd.submitted_by=:report_actor';
        $parameters['report_actor'] = $actor['id'];
    }
    $where[] = '(gd.gate_id IS NULL OR EXISTS (
        SELECT 1 FROM user_gate_assignments uga
        JOIN gates assigned_gate ON assigned_gate.id=uga.gate_id
        JOIN role_permissions rp ON rp.role_id=:scope_role_id
        JOIN permissions p ON p.id=rp.permission_id
        WHERE uga.user_id=:scope_user_id AND uga.gate_id=gd.gate_id
          AND uga.direction IN (gd.direction,\'both\')
          AND p.code=CONCAT(\'gate.\',gd.direction)
          AND assigned_gate.status=\'active\'
          AND ((gd.direction=\'entry\' AND assigned_gate.supports_entry=1)
            OR (gd.direction=\'exit\' AND assigned_gate.supports_exit=1))
    ))';
    $parameters['scope_role_id'] = $actor['role']['id'];
    $parameters['scope_user_id'] = $actor['id'];
} elseif ($report === 'approvals') {
    if ($actor['role']['code'] !== 'admin') {
        $where[] = 'a.submitted_by=:report_actor';
        $parameters['report_actor'] = $actor['id'];
    }
    $where[] = '(a.gate_id IS NULL OR EXISTS (
        SELECT 1 FROM user_gate_assignments uga
        JOIN gates assigned_gate ON assigned_gate.id=uga.gate_id
        JOIN role_permissions rp ON rp.role_id=:scope_role_id
        JOIN permissions p ON p.id=rp.permission_id
        WHERE uga.user_id=:scope_user_id AND uga.gate_id=a.gate_id
          AND uga.direction IN (a.direction,\'both\')
          AND p.code=CONCAT(\'gate.\',a.direction)
          AND assigned_gate.status=\'active\'
          AND ((a.direction=\'entry\' AND assigned_gate.supports_entry=1)
            OR (a.direction=\'exit\' AND assigned_gate.supports_exit=1))
    ))';
    $parameters['scope_role_id'] = $actor['role']['id'];
    $parameters['scope_user_id'] = $actor['id'];
}

$gateColumn = in_array($report, ['approvals'], true) ? 'a.gate_id'
    : ($report === 'goods_declarations' ? 'gd.gate_id' : 'e.gate_id');
$companyColumn = in_array($report, ['approvals'], true) ? 'a.company_id'
    : ($report === 'goods_declarations' ? 'gd.company_id' : 'e.company_id');
$directionColumn = in_array($report, ['approvals'], true) ? 'a.direction'
    : ($report === 'goods_declarations' ? 'gd.direction' : 'e.event_type');
$statusColumn = $report === 'approvals' ? 'a.status' : 'gd.status';
$whereSql = str_replace(
    ['REPORT_GATE_COLUMN', 'REPORT_COMPANY_COLUMN', 'REPORT_DIRECTION_COLUMN', 'REPORT_STATUS_COLUMN'],
    [$gateColumn, $companyColumn, $directionColumn, $statusColumn],
    $where === [] ? '' : ' WHERE ' . implode(' AND ', $where)
);

$definitions = [
    'vehicle_movements' => [
        'columns' => [
            'event_reference', 'direction', 'access_result', 'plate_number', 'driver_name',
            'company_name', 'gate_name', 'lane_name', 'access_method', 'event_at',
        ],
        'from' => ' FROM access_events e
            LEFT JOIN gates g ON g.id=e.gate_id
            LEFT JOIN lanes l ON l.id=e.lane_id
            LEFT JOIN vehicles v ON v.id=e.vehicle_id
            LEFT JOIN drivers d ON d.id=e.driver_id
            LEFT JOIN companies c ON c.id=e.company_id',
        'select' => 'SELECT e.reference AS event_reference,e.event_type AS direction,e.access_result,
            COALESCE(e.vehicle_plate_observed,v.plate_number) AS plate_number,d.full_name AS driver_name,
            c.name AS company_name,g.name AS gate_name,l.name AS lane_name,e.access_method,e.event_at',
        'order' => ' ORDER BY e.event_at DESC,e.id DESC',
    ],
    'visitor_activity' => [
        'columns' => [
            'event_reference', 'direction', 'access_result', 'visitor_reference', 'visitor_name',
            'company_name', 'gate_name', 'lane_name', 'access_method', 'denial_reason', 'event_at',
        ],
        'from' => ' FROM access_events e
            LEFT JOIN gates g ON g.id=e.gate_id
            LEFT JOIN lanes l ON l.id=e.lane_id
            LEFT JOIN visitors v ON v.id=e.visitor_id
            LEFT JOIN companies c ON c.id=e.company_id',
        'select' => 'SELECT e.reference AS event_reference,e.event_type AS direction,e.access_result,
            v.reference AS visitor_reference,v.full_name AS visitor_name,c.name AS company_name,
            g.name AS gate_name,l.name AS lane_name,e.access_method,e.denial_reason,e.event_at',
        'order' => ' ORDER BY e.event_at DESC,e.id DESC',
    ],
    'gate_events' => [
        'columns' => [
            'event_reference', 'subject_type', 'direction', 'access_result', 'denial_reason',
            'gate_name', 'lane_name', 'subject', 'officer_name', 'access_method', 'event_at',
        ],
        'from' => ' FROM access_events e
            LEFT JOIN gates g ON g.id=e.gate_id
            LEFT JOIN lanes l ON l.id=e.lane_id
            LEFT JOIN vehicles v ON v.id=e.vehicle_id
            LEFT JOIN visitors vis ON vis.id=e.visitor_id
            LEFT JOIN users u ON u.id=e.actor_id',
        'select' => 'SELECT e.reference AS event_reference,e.subject_type,e.event_type AS direction,
            e.access_result,e.denial_reason,g.name AS gate_name,l.name AS lane_name,
            COALESCE(v.plate_number,vis.full_name) AS subject,u.full_name AS officer_name,
            e.access_method,e.event_at',
        'order' => ' ORDER BY e.event_at DESC,e.id DESC',
    ],
    'goods_declarations' => [
        'columns' => [
            'declaration_reference', 'direction', 'status', 'company_name', 'plate_number',
            'driver_name', 'gate_name', 'lane_name', 'purpose', 'submitted_at', 'created_at',
        ],
        'from' => ' FROM goods_declarations gd
            JOIN companies c ON c.id=gd.company_id
            LEFT JOIN vehicles v ON v.id=gd.vehicle_id
            LEFT JOIN drivers d ON d.id=gd.driver_id
            LEFT JOIN gates g ON g.id=gd.gate_id
            LEFT JOIN lanes l ON l.id=gd.lane_id',
        'select' => 'SELECT gd.reference AS declaration_reference,gd.direction,gd.status,c.name AS company_name,
            v.plate_number,d.full_name AS driver_name,g.name AS gate_name,l.name AS lane_name,
            gd.purpose,gd.submitted_at,gd.created_at',
        'order' => ' ORDER BY gd.created_at DESC,gd.id DESC',
    ],
    'approvals' => [
        'columns' => [
            'request_reference', 'request_type', 'title', 'status', 'decision_reason',
            'submitted_by', 'reviewer', 'company_name', 'gate_name', 'created_at', 'decided_at',
        ],
        'from' => ' FROM approval_requests a
            LEFT JOIN users submitter ON submitter.id=a.submitted_by
            LEFT JOIN users reviewer ON reviewer.id=a.assigned_reviewer_id
            LEFT JOIN companies c ON c.id=a.company_id
            LEFT JOIN gates g ON g.id=a.gate_id',
        'select' => 'SELECT a.reference AS request_reference,a.request_type,a.title,a.status,
            a.decision_reason,submitter.username AS submitted_by,reviewer.username AS reviewer,
            c.name AS company_name,g.name AS gate_name,a.created_at,a.decided_at',
        'order' => ' ORDER BY a.created_at DESC,a.id DESC',
    ],
];
$definition = $definitions[$report];
$count = $connection->prepare('SELECT COUNT(*)' . $definition['from'] . $whereSql);
$count->execute($parameters);
$total = (int) $count->fetchColumn();

if ($format === 'csv') {
    if ($total > 10000) {
        validationError('CSV exports are limited to 10,000 rows; narrow the filters.');
    }
    $statement = $connection->prepare(
        $definition['select'] . $definition['from'] . $whereSql . $definition['order'] . ' LIMIT 10000'
    );
    $statement->execute($parameters);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hipdc-' . $report . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Unable to open the CSV response stream.');
    }
    fputcsv($output, $definition['columns']);
    foreach ($rows as $row) {
        $values = [];
        foreach ($row as $value) {
            if (is_string($value) && preg_match('/^[\t\r\n ]*[=+\-@]/', $value)) {
                $value = "'" . $value;
            }
            $values[] = $value;
        }
        fputcsv($output, $values);
    }
    fclose($output);
    exit;
}

$pagination = getPaginationParameters();
$statement = $connection->prepare(
    $definition['select'] . $definition['from'] . $whereSql . $definition['order']
    . ' LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$statement->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$statement->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$statement->execute();
successResponse([
    'report' => $report,
    'rows' => $statement->fetchAll(),
    'pagination' => paginationResult($pagination, $total),
], 'Operational report loaded.');
