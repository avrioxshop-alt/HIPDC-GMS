<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/visitor_goods.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    $actor = requireOperationalPermission('visitors.create');
    $data = requireJsonPostWithCsrf();
    $connection = getDatabaseConnection();
    try {
        $connection->beginTransaction();
        $fields = validateVisitorFields($connection, $data);
        $insert = $connection->prepare(
            'INSERT INTO visitors
                (reference,full_name,document_reference,phone,email,organization,company_id,
                 host_person,purpose,expected_arrival_at,expected_departure_at,schedule_id,created_by)
             VALUES
                (:reference,:full_name,:document_reference,:phone,:email,:organization,:company_id,
                 :host_person,:purpose,:arrival,:departure,:schedule_id,:created_by)'
        );
        $insert->execute([
            'reference' => makeReference('VIS'),
            'full_name' => $fields['full_name'],
            'document_reference' => $fields['document_reference'],
            'phone' => $fields['phone'],
            'email' => $fields['email'],
            'organization' => $fields['organization'],
            'company_id' => $fields['company_id'],
            'host_person' => $fields['host_person'],
            'purpose' => $fields['purpose'],
            'arrival' => $fields['expected_arrival_at'],
            'departure' => $fields['expected_departure_at'],
            'schedule_id' => $fields['schedule_id'],
            'created_by' => $actor['id'],
        ]);
        $id = (int) $connection->lastInsertId();
        writeOperationalAudit($connection, $actor['id'], 'visitor.create', 'visitor', $id);
        $connection->commit();
    } catch (InvalidArgumentException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        handleOperationalValidation($exception);
    } catch (PDOException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        respondToOperationalConflict($exception, 'Visitor reference conflicts with an existing record.');
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
    successResponse(['visitor' => visitorRecord($connection, $id)], 'Visitor registered.', 201);
}

if ($method !== 'GET') {
    header('Allow: GET, POST');
    jsonResponse(false, 'The request method is not allowed.', [], 405);
}

$actor = requireOperationalPermission('visitors.view');
$pagination = getPaginationParameters();
$search = getSearchParameter();
$status = getOperationalStatusFilter(['active', 'inactive']);
$where = [];
$parameters = [];
if ($status !== null) {
    $where[] = 'v.status=:status';
    $parameters['status'] = $status;
}
if ($search !== '') {
    $where[] = '(v.reference LIKE :reference OR v.full_name LIKE :name
        OR v.document_reference LIKE :document OR v.phone LIKE :phone
        OR v.email LIKE :email OR v.organization LIKE :organization
        OR v.host_person LIKE :host)';
    $like = '%' . addcslashes($search, '\\_%') . '%';
    foreach (['reference', 'name', 'document', 'phone', 'email', 'organization', 'host'] as $field) {
        $parameters[$field] = $like;
    }
}
if ($actor['role']['code'] !== 'admin') {
    $where[] = 'v.created_by=:scope_user';
    $parameters['scope_user'] = $actor['id'];
}
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$from = ' FROM visitors v LEFT JOIN companies c ON c.id=v.company_id';
$connection = getDatabaseConnection();
$count = $connection->prepare('SELECT COUNT(*)' . $from . $whereSql);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT v.id,v.reference,v.full_name,v.document_reference,v.phone,v.email,v.organization,
            v.company_id,c.name AS company_name,v.host_person,v.purpose,v.expected_arrival_at,
            v.expected_departure_at,v.schedule_id,v.status,v.created_at,v.updated_at'
    . $from . $whereSql . ' ORDER BY v.expected_arrival_at DESC,v.id DESC LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$list->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$list->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$list->execute();
$visitors = $list->fetchAll();
foreach ($visitors as &$visitor) {
    foreach (['id', 'company_id', 'schedule_id'] as $field) {
        $visitor[$field] = $visitor[$field] === null ? null : (int) $visitor[$field];
    }
}
unset($visitor);
successResponse([
    'visitors' => $visitors,
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Visitors loaded.');
