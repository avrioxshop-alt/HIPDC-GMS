<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';

requireRequestMethod('GET');
$actor = requireOperationalPermission('gate_operations.view');
$connection = getDatabaseConnection();
$statement = $connection->prepare(
    '(SELECT DISTINCT g.id AS gate_id,g.code AS gate_code,g.name AS gate_name,
             g.location,g.supports_entry,g.supports_exit,l.id AS lane_id,
             l.code AS lane_code,l.name AS lane_name,l.direction AS lane_direction,
             \'entry\' AS operation_direction
      FROM user_gate_assignments uga
      JOIN gates g ON g.id=uga.gate_id
      JOIN role_permissions rp ON rp.role_id=:entry_role
      JOIN permissions p ON p.id=rp.permission_id AND p.code=\'gate.entry\'
      LEFT JOIN lanes l ON l.gate_id=g.id AND l.status=\'active\'
        AND l.direction IN (\'entry\',\'both\')
      WHERE uga.user_id=:entry_user AND g.status=\'active\'
        AND uga.direction IN (\'entry\',\'both\') AND g.supports_entry=1)
     UNION ALL
     (SELECT DISTINCT g.id AS gate_id,g.code AS gate_code,g.name AS gate_name,
             g.location,g.supports_entry,g.supports_exit,l.id AS lane_id,
             l.code AS lane_code,l.name AS lane_name,l.direction AS lane_direction,
             \'exit\' AS operation_direction
      FROM user_gate_assignments uga
      JOIN gates g ON g.id=uga.gate_id
      JOIN role_permissions rp ON rp.role_id=:exit_role
      JOIN permissions p ON p.id=rp.permission_id AND p.code=\'gate.exit\'
      LEFT JOIN lanes l ON l.gate_id=g.id AND l.status=\'active\'
        AND l.direction IN (\'exit\',\'both\')
      WHERE uga.user_id=:exit_user AND g.status=\'active\'
        AND uga.direction IN (\'exit\',\'both\') AND g.supports_exit=1)
     ORDER BY gate_name,lane_name,operation_direction'
);
$statement->execute([
    'entry_role' => $actor['role']['id'],
    'entry_user' => $actor['id'],
    'exit_role' => $actor['role']['id'],
    'exit_user' => $actor['id'],
]);
$locations = $statement->fetchAll();
foreach ($locations as &$location) {
    foreach (['gate_id', 'lane_id'] as $field) {
        $location[$field] = $location[$field] === null ? null : (int) $location[$field];
    }
    foreach (['supports_entry', 'supports_exit'] as $field) {
        $location[$field] = (bool) $location[$field];
    }
}
unset($location);
successResponse(['locations' => $locations], 'Assigned active gates and lanes loaded.');
