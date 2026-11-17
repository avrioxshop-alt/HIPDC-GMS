<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supporting.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    $actor = requireAdministrator('notifications.manage');
    $data = requireJsonPostWithCsrf();
    try {
        $userId = validateOptionalId($data, 'user_id');
        if ($userId === null) {
            throw new InvalidArgumentException('A target user id is required.');
        }
        $type = $data['notification_type'] ?? null;
        if (
            !is_string($type)
            || !in_array($type, ['approval', 'shift', 'camera', 'administrative', 'system'], true)
        ) {
            throw new InvalidArgumentException('Notification type is invalid.');
        }
        $title = validateOperationalText($data, 'title', 160, true);
        $message = validateOperationalText($data, 'message', 1000, true);
        $entityType = validateOperationalText($data, 'entity_type', 48);
        $entityId = validateOptionalId($data, 'entity_id');
        if (($entityType === null) !== ($entityId === null)) {
            throw new InvalidArgumentException('Entity type and entity id must be provided together.');
        }
    } catch (InvalidArgumentException $exception) {
        handleOperationalValidation($exception);
    }

    $connection = getDatabaseConnection();
    try {
        $connection->beginTransaction();
        $target = $connection->prepare('SELECT id FROM users WHERE id=:id AND status=\'active\' FOR UPDATE');
        $target->execute(['id' => $userId]);
        if ($target->fetchColumn() === false) {
            $connection->rollBack();
            notFoundResponse('Active target user not found.');
        }
        $id = createUserNotification(
            $connection,
            $userId,
            $type,
            $title,
            $message,
            $entityType,
            $entityId,
            $actor['id']
        );
        writeOperationalAudit(
            $connection,
            $actor['id'],
            'notification.create',
            'notification',
            $id,
            ['target_user_id' => $userId, 'notification_type' => $type]
        );
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
    successResponse(['notification' => notificationRecord($connection, $id, $userId)], 'Notification created.', 201);
}

if ($method !== 'GET') {
    header('Allow: GET, POST');
    jsonResponse(false, 'The request method is not allowed.', [], 405);
}

$actor = requireOperationalPermission('notifications.view');
$pagination = getPaginationParameters();
$read = $_GET['read'] ?? '';
if (!is_string($read) || ($read !== '' && !in_array($read, ['true', 'false'], true))) {
    validationError('Read filter must be true or false.');
}
$where = ['user_id=:user_id'];
$parameters = ['user_id' => $actor['id']];
if ($read === 'true') {
    $where[] = 'read_at IS NOT NULL';
} elseif ($read === 'false') {
    $where[] = 'read_at IS NULL';
}
$whereSql = ' WHERE ' . implode(' AND ', $where);
$connection = getDatabaseConnection();
$count = $connection->prepare('SELECT COUNT(*) FROM notifications' . $whereSql);
$count->execute($parameters);
$list = $connection->prepare(
    'SELECT id,user_id,notification_type,title,message,entity_type,entity_id,created_by,read_at,created_at
     FROM notifications' . $whereSql . ' ORDER BY created_at DESC,id DESC LIMIT :limit OFFSET :offset'
);
$list->bindValue(':user_id', $actor['id'], PDO::PARAM_INT);
$list->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$list->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$list->execute();
$notifications = $list->fetchAll();
foreach ($notifications as &$notification) {
    foreach (['id', 'user_id', 'entity_id', 'created_by'] as $field) {
        $notification[$field] = $notification[$field] === null ? null : (int) $notification[$field];
    }
}
unset($notification);
successResponse([
    'notifications' => $notifications,
    'pagination' => paginationResult($pagination, (int) $count->fetchColumn()),
], 'Notifications loaded.');
