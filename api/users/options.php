<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/user_management.php';

requireRequestMethod('GET');
requireAdministrator('users.view');
$connection = getDatabaseConnection();

$roles = $connection->query(
    'SELECT id, code, name FROM roles ORDER BY id'
)->fetchAll();

$gates = $connection->query(
    'SELECT id, code, name FROM gates WHERE status = \'active\' ORDER BY name, id'
)->fetchAll();

$rolePermissions = $connection->query(
    'SELECT r.id AS role_id, p.code AS permission
     FROM roles AS r
     INNER JOIN role_permissions AS rp ON rp.role_id = r.id
     INNER JOIN permissions AS p ON p.id = rp.permission_id
     ORDER BY r.id, p.code'
)->fetchAll();

$permissionsByRole = [];
foreach ($rolePermissions as $row) {
    $roleId = (int) $row['role_id'];
    $permissionsByRole[$roleId][] = $row['permission'];
}

$formattedRoles = array_map(
    static function (array $role) use ($permissionsByRole): array {
        $roleId = (int) $role['id'];
        return [
            'id' => $roleId,
            'code' => $role['code'],
            'name' => $role['name'],
            'permissions' => $permissionsByRole[$roleId] ?? [],
        ];
    },
    $roles
);

$formattedGates = array_map(
    static function (array $gate): array {
        return [
            'id' => (int) $gate['id'],
            'code' => $gate['code'],
            'name' => $gate['name'],
        ];
    },
    $gates
);

successResponse(
    ['roles' => $formattedRoles, 'gates' => $formattedGates],
    'User-management options loaded.'
);
