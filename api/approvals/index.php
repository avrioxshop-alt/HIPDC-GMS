<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/operations.php';

requireRequestMethod('GET');
$actor = requireOperationalPermission('approvals.view');
$pagination = getPaginationParameters();
$search = getSearchParameter();
$status = getOperationalStatusFilter(['pending', 'approved', 'denied', 'cancelled']);
$where = [];
$parameters = [];
if ($status !== null) {
    $where[] = 'a.status=:status';
    $parameters['status'] = $status;
}
if ($search !== '') {
    $where[] = '(a.reference LIKE :reference OR a.title LIKE :title OR v.plate_number LIKE :plate
        OR c.name LIKE :company OR d.full_name LIKE :driver OR visitor.full_name LIKE :visitor
        OR vp.reference LIKE :pass_reference OR goods.reference LIKE :goods_reference)';
    $like = '%' . addcslashes($search, '\\_%') . '%';
    foreach (
        ['reference', 'title', 'plate', 'company', 'driver', 'visitor', 'pass_reference', 'goods_reference']
        as $field
    ) {
        $parameters[$field] = $like;
    }
}
if ($actor['role']['code'] !== 'admin') {
    $where[] = 'a.submitted_by=:scope_user';
    $parameters['scope_user'] = $actor['id'];
}
$where[] = '(a.gate_id IS NULL OR EXISTS (
    SELECT 1 FROM user_gate_assignments uga
    JOIN gates assigned_gate ON assigned_gate.id=uga.gate_id
    JOIN role_permissions rp ON rp.role_id=:scope_role
    JOIN permissions permission ON permission.id=rp.permission_id
    WHERE uga.user_id=:scope_user_gate AND uga.gate_id=a.gate_id
      AND uga.direction IN (a.direction,\'both\')
      AND permission.code=CONCAT(\'gate.\',a.direction)
      AND assigned_gate.status=\'active\'
      AND ((a.direction=\'entry\' AND assigned_gate.supports_entry=1)
        OR (a.direction=\'exit\' AND assigned_gate.supports_exit=1))
))';
$parameters['scope_role'] = $actor['role']['id'];
$parameters['scope_user_gate'] = $actor['id'];
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$from = ' FROM approval_requests a
          LEFT JOIN companies c ON c.id=a.company_id
          LEFT JOIN drivers d ON d.id=a.driver_id
          LEFT JOIN vehicles v ON v.id=a.vehicle_id
          LEFT JOIN visitor_passes vp ON vp.id=a.visitor_pass_id
          LEFT JOIN visitors visitor ON visitor.id=vp.visitor_id
          LEFT JOIN goods_declarations goods ON goods.id=a.goods_declaration_id';
$connection = getDatabaseConnection();
$count = $connection->prepare('SELECT COUNT(*)' . $from . $whereSql);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT a.id,a.reference,a.request_type,a.title,a.note,a.company_id,c.name AS company_name,
            a.driver_id,d.full_name AS driver_name,a.vehicle_id,v.plate_number,
            a.schedule_id,a.visitor_pass_id,vp.reference AS visitor_pass_reference,
            vp.visitor_id,visitor.full_name AS visitor_name,
            a.goods_declaration_id,goods.reference AS goods_declaration_reference,
            a.gate_id,a.direction,a.submitted_by,
            a.assigned_reviewer_id,a.status,a.decision_reason,a.decided_at,
            a.created_at,a.updated_at'
    . $from . $whereSql . ' ORDER BY a.created_at DESC,a.id DESC LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$list->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$list->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$list->execute();
$requests = $list->fetchAll();
foreach ($requests as &$request) {
    foreach (
        [
            'id','company_id','driver_id','vehicle_id','schedule_id','visitor_pass_id','visitor_id',
            'goods_declaration_id','gate_id','submitted_by','assigned_reviewer_id'
        ]
        as $field
    ) {
        $request[$field] = $request[$field] === null ? null : (int) $request[$field];
    }
}
unset($request);
successResponse([
    'requests' => $requests,
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Approval requests loaded.');
