<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supporting.php';

requireRequestMethod('GET');
$actor = requireOperationalPermission('dashboard.view');
$connection = getDatabaseConnection();
$eventScope = accessEventScopeSql();
$eventParameters = [
    'scope_role_id' => $actor['role']['id'],
    'scope_user_id' => $actor['id'],
];

$activity = $connection->prepare(
    'SELECT COUNT(*) AS total,
            SUM(CASE WHEN e.event_type=\'entry\' THEN 1 ELSE 0 END) AS entries,
            SUM(CASE WHEN e.event_type=\'exit\' THEN 1 ELSE 0 END) AS exits,
            SUM(CASE WHEN e.access_result=\'granted\' THEN 1 ELSE 0 END) AS granted,
            SUM(CASE WHEN e.access_result=\'denied\' THEN 1 ELSE 0 END) AS denied
     FROM access_events e
     WHERE ' . $eventScope . ' AND e.event_at>=DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 24 HOUR)'
);
$activity->execute($eventParameters);
$eventTotals = $activity->fetch();
foreach (['total', 'entries', 'exits', 'granted', 'denied'] as $field) {
    $eventTotals[$field] = (int) ($eventTotals[$field] ?? 0);
}

$vehiclePresence = $connection->prepare(
    'SELECT COUNT(*)
     FROM access_events e
     WHERE ' . $eventScope . '
       AND e.subject_type=\'vehicle\' AND e.access_result=\'granted\'
       AND e.event_type=\'entry\' AND e.vehicle_id IS NOT NULL
       AND e.id=(
           SELECT latest.id FROM access_events latest
           WHERE latest.vehicle_id=e.vehicle_id
             AND latest.subject_type=\'vehicle\' AND latest.access_result=\'granted\'
           ORDER BY latest.event_at DESC,latest.id DESC LIMIT 1
       )'
);
$vehiclePresence->execute($eventParameters);
$vehiclePresenceCount = (int) $vehiclePresence->fetchColumn();

$visitorPresence = $connection->prepare(
    'SELECT COUNT(*)
     FROM access_events e
     WHERE ' . $eventScope . '
       AND e.subject_type=\'visitor\' AND e.access_result=\'granted\'
       AND e.event_type=\'entry\' AND e.visitor_id IS NOT NULL
       AND e.id=(
           SELECT latest.id FROM access_events latest
           WHERE latest.visitor_id=e.visitor_id
             AND latest.subject_type=\'visitor\' AND latest.access_result=\'granted\'
           ORDER BY latest.event_at DESC,latest.id DESC LIMIT 1
       )'
);
$visitorPresence->execute($eventParameters);
$visitorPresenceCount = (int) $visitorPresence->fetchColumn();

$visitorActivity = $connection->prepare(
    'SELECT
        SUM(CASE WHEN e.event_type=\'entry\' THEN 1 ELSE 0 END) AS entries,
        SUM(CASE WHEN e.event_type=\'exit\' THEN 1 ELSE 0 END) AS exits
     FROM access_events e
     WHERE ' . $eventScope . '
       AND e.subject_type=\'visitor\' AND e.access_result=\'granted\'
       AND e.event_at>=DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 24 HOUR)'
);
$visitorActivity->execute($eventParameters);
$visitorTotals = $visitorActivity->fetch();
$visitorTotals = [
    'entries' => (int) ($visitorTotals['entries'] ?? 0),
    'exits' => (int) ($visitorTotals['exits'] ?? 0),
];

$vehicleActivity = $connection->prepare(
    'SELECT
        SUM(CASE WHEN e.event_type=\'entry\' THEN 1 ELSE 0 END) AS entries,
        SUM(CASE WHEN e.event_type=\'exit\' THEN 1 ELSE 0 END) AS exits
     FROM access_events e
     WHERE ' . $eventScope . '
       AND e.subject_type=\'vehicle\' AND e.access_result=\'granted\'
       AND e.event_at>=DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 24 HOUR)'
);
$vehicleActivity->execute($eventParameters);
$vehicleTotals = $vehicleActivity->fetch();
$vehicleTotals = [
    'entries' => (int) ($vehicleTotals['entries'] ?? 0),
    'exits' => (int) ($vehicleTotals['exits'] ?? 0),
];

$approvalWhere = '(a.gate_id IS NULL OR EXISTS (
    SELECT 1 FROM user_gate_assignments uga
    JOIN gates assigned_gate ON assigned_gate.id=uga.gate_id
    JOIN role_permissions rp ON rp.role_id=:approval_role
    JOIN permissions p ON p.id=rp.permission_id
    WHERE uga.user_id=:approval_user AND uga.gate_id=a.gate_id
      AND uga.direction IN (a.direction,\'both\')
      AND p.code=CONCAT(\'gate.\',a.direction)
      AND assigned_gate.status=\'active\'
      AND ((a.direction=\'entry\' AND assigned_gate.supports_entry=1)
        OR (a.direction=\'exit\' AND assigned_gate.supports_exit=1))
))';
if ($actor['role']['code'] !== 'admin') {
    $approvalWhere .= ' AND a.submitted_by=:approval_submitter';
}
$pendingApprovals = $connection->prepare(
    'SELECT COUNT(*) FROM approval_requests a
     WHERE a.status=\'pending\' AND ' . $approvalWhere
);
$pendingParameters = [
    'approval_role' => $actor['role']['id'],
    'approval_user' => $actor['id'],
];
if ($actor['role']['code'] !== 'admin') {
    $pendingParameters['approval_submitter'] = $actor['id'];
}
$pendingApprovals->execute($pendingParameters);

$expectedWhere = $actor['role']['code'] === 'admin'
    ? 's.status=\'scheduled\' AND DATE(s.scheduled_at)=CURRENT_DATE'
    : 's.status=\'scheduled\' AND DATE(s.scheduled_at)=CURRENT_DATE
       AND EXISTS (
           SELECT 1 FROM user_gate_assignments uga
           JOIN role_permissions rp ON rp.role_id=:schedule_role
           JOIN permissions p ON p.id=rp.permission_id
           WHERE uga.user_id=:schedule_user AND uga.gate_id=s.gate_id
             AND uga.direction IN (s.direction,\'both\')
             AND p.code=CONCAT(\'gate.\',s.direction)
       )';
$expected = $connection->prepare('SELECT COUNT(*) FROM schedules s WHERE ' . $expectedWhere);
if ($actor['role']['code'] === 'admin') {
    $expected->execute();
} else {
    $expected->execute([
        'schedule_role' => $actor['role']['id'],
        'schedule_user' => $actor['id'],
    ]);
}

$shiftScope = $actor['role']['code'] === 'admin' ? '' : ' AND s.officer_id=:officer_id';
$shiftParameters = [];
if ($actor['role']['code'] !== 'admin') {
    $shiftParameters['officer_id'] = $actor['id'];
}
$currentShifts = $connection->prepare(
    'SELECT s.id,s.reference,s.direction,s.starts_at,s.ends_at,
            g.id AS gate_id,g.name AS gate_name,l.id AS lane_id,l.name AS lane_name,
            u.id AS officer_id,u.full_name AS officer_name
     FROM officer_shifts s
     JOIN gates g ON g.id=s.gate_id
     LEFT JOIN lanes l ON l.id=s.lane_id
     JOIN users u ON u.id=s.officer_id
     WHERE s.status=\'scheduled\' AND s.starts_at<=CURRENT_TIMESTAMP
       AND s.ends_at>CURRENT_TIMESTAMP'
    . $shiftScope . ' ORDER BY s.starts_at,s.id LIMIT 25'
);
$currentShifts->execute($shiftParameters);
$shiftRows = $currentShifts->fetchAll();
foreach ($shiftRows as &$shift) {
    foreach (['id', 'gate_id', 'lane_id', 'officer_id'] as $field) {
        $shift[$field] = $shift[$field] === null ? null : (int) $shift[$field];
    }
}
unset($shift);

$cameraScope = $actor['role']['code'] === 'admin'
    ? ''
    : ' WHERE EXISTS (
        SELECT 1 FROM user_gate_assignments uga
        JOIN role_permissions rp ON rp.role_id=:camera_role
        JOIN permissions p ON p.id=rp.permission_id
        WHERE uga.user_id=:camera_user AND uga.gate_id=c.gate_id
          AND ((uga.direction IN (\'entry\',\'both\') AND p.code=\'gate.entry\')
            OR (uga.direction IN (\'exit\',\'both\') AND p.code=\'gate.exit\'))
    )';
$cameraCounts = $connection->prepare(
    'SELECT COUNT(*) AS total,
            SUM(CASE WHEN c.status=\'active\' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN c.monitoring_status=\'offline\' THEN 1 ELSE 0 END) AS offline
     FROM cameras c' . $cameraScope
);
if ($actor['role']['code'] === 'admin') {
    $cameraCounts->execute();
} else {
    $cameraCounts->execute([
        'camera_role' => $actor['role']['id'],
        'camera_user' => $actor['id'],
    ]);
}
$cameraTotals = $cameraCounts->fetch();
foreach (['total', 'active', 'offline'] as $field) {
    $cameraTotals[$field] = (int) ($cameraTotals[$field] ?? 0);
}

successResponse([
    'role' => $actor['role']['code'],
    'period' => 'last_24_hours',
    'vehicle_activity' => $vehicleTotals + ['current_presence' => $vehiclePresenceCount],
    'visitor_activity' => $visitorTotals + ['current_presence' => $visitorPresenceCount],
    'pending_approvals' => (int) $pendingApprovals->fetchColumn(),
    'expected_schedules_today' => (int) $expected->fetchColumn(),
    'gate_activity' => $eventTotals,
    'current_shifts' => $shiftRows,
    'camera_inventory' => $cameraTotals,
], 'Dashboard summary loaded.');
