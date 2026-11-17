<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supporting.php';

$actor = requireOperationalPermission('notifications.read');
requireJsonPostWithCsrf();
$connection = getDatabaseConnection();
$update = $connection->prepare(
    'UPDATE notifications SET read_at=CURRENT_TIMESTAMP
     WHERE user_id=:user_id AND read_at IS NULL'
);
$update->execute(['user_id' => $actor['id']]);
successResponse(['marked_read' => $update->rowCount()], 'Unread notifications marked as read.');
