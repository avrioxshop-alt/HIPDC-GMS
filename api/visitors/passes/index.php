<?php

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/visitor_goods.php';

requireRequestMethod('GET');
$actor = requireOperationalPermission('visitors.passes.view');
$pagination = getPaginationParameters();
$search = getSearchParameter();
$status = getOperationalStatusFilter([
    'pending_approval',
    'active',
    'denied',
    'cancelled',
    'revoked',
    'expired',
]);
$visitorId = null;
if (isset($_GET['visitor_id']) && $_GET['visitor_id'] !== '') {
    try {
        $visitorId = parsePositiveId($_GET['visitor_id'], 'visitor_id');
    } catch (InvalidArgumentException $exception) {
        handleOperationalValidation($exception);
    }
}
$where = [];
$parameters = [];
if ($status !== null && $status !== 'expired') {
    if ($status === 'active') {
        $where[] = 'p.status=:status AND p.expires_at>CURRENT_TIMESTAMP';
    } else {
        $where[] = 'p.status=:status';
    }
    $parameters['status'] = $status;
} elseif ($status === 'expired') {
    $where[] = 'p.status=\'active\' AND p.expires_at<=CURRENT_TIMESTAMP';
}
if ($visitorId !== null) {
    $where[] = 'p.visitor_id=:visitor_id';
    $parameters['visitor_id'] = $visitorId;
}
if ($search !== '') {
    $where[] = '(p.reference LIKE :reference OR v.reference LIKE :visitor_reference
        OR v.full_name LIKE :visitor_name)';
    $like = '%' . addcslashes($search, '\\_%') . '%';
    $parameters['reference'] = $like;
    $parameters['visitor_reference'] = $like;
    $parameters['visitor_name'] = $like;
}
if ($actor['role']['code'] !== 'admin') {
    $where[] = 'p.submitted_by=:scope_user';
    $parameters['scope_user'] = $actor['id'];
}
$where[] = 'EXISTS (
    SELECT 1 FROM user_gate_assignments uga
    JOIN gates scope_gate ON scope_gate.id=uga.gate_id
    JOIN role_permissions rp ON rp.role_id=:scope_role
    JOIN permissions permission ON permission.id=rp.permission_id
    WHERE uga.user_id=:scope_gate_user AND uga.gate_id=p.gate_id
      AND uga.direction IN (p.direction,\'both\')
      AND permission.code=CONCAT(\'gate.\',p.direction)
      AND scope_gate.status=\'active\'
      AND ((p.direction=\'entry\' AND scope_gate.supports_entry=1)
        OR (p.direction=\'exit\' AND scope_gate.supports_exit=1))
)';
$parameters['scope_role'] = $actor['role']['id'];
$parameters['scope_gate_user'] = $actor['id'];
$whereSql = ' WHERE ' . implode(' AND ', $where);
$from = ' FROM visitor_passes p
          JOIN visitors v ON v.id=p.visitor_id
          JOIN gates g ON g.id=p.gate_id';
$connection = getDatabaseConnection();
$count = $connection->prepare('SELECT COUNT(*)' . $from . $whereSql);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT p.id,p.reference,p.visitor_id,v.reference AS visitor_reference,
            v.full_name AS visitor_name,v.company_id,p.gate_id,g.code AS gate_code,
            g.name AS gate_name,p.direction,p.valid_from,p.expires_at,
            CASE WHEN p.status=\'active\' AND p.expires_at<=CURRENT_TIMESTAMP
                 THEN \'expired\' ELSE p.status END AS status,
            p.submitted_by,p.issued_by,p.issued_at,p.revoked_by,p.revoked_at,
            p.revocation_reason,p.created_at,p.updated_at'
    . $from . $whereSql . ' ORDER BY p.created_at DESC,p.id DESC LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$list->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$list->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$list->execute();
$passes = $list->fetchAll();
foreach ($passes as &$pass) {
    foreach (['id', 'visitor_id', 'company_id', 'gate_id', 'submitted_by', 'issued_by', 'revoked_by'] as $field) {
        $pass[$field] = $pass[$field] === null ? null : (int) $pass[$field];
    }
}
unset($pass);
successResponse([
    'passes' => $passes,
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Visitor passes loaded.');
