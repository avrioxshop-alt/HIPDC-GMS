<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/supporting.php';

$actor = requireOperationalPermission('notifications.read');
$data = requireJsonPostWithCsrf();
try {
    $id = parsePositiveId($data['id'] ?? null, 'notification id');
} catch (InvalidArgumentException $exception) {
    handleOperationalValidation($exception);
}
$connection = getDatabaseConnection();
$update = $connection->prepare(
    'UPDATE notifications SET read_at=COALESCE(read_at,CURRENT_TIMESTAMP)
     WHERE id=:id AND user_id=:user_id'
);
$update->execute(['id' => $id, 'user_id' => $actor['id']]);
$notification = notificationRecord($connection, $id, $actor['id']);
if ($notification === null) {
    notFoundResponse('Notification not found.');
}
successResponse(['notification' => $notification], 'Notification marked as read.');
