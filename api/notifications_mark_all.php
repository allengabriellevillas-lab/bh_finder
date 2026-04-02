<?php
require_once __DIR__ . '/_bootstrap.php';
requireMethod('POST');
requireLogin();

if (!notificationsEnabled()) {
    jsonResponse(['ok' => true, 'unread' => 0]);
}

$uid = intval($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    jsonResponse(['ok' => false, 'error' => 'Not logged in'], 401);
}

try {
    $db = getDB();
    $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$uid]);
    $unread = unreadNotificationCount($uid);
    jsonResponse(['ok' => true, 'unread' => $unread]);
} catch (Throwable $e) {
    jsonResponse(['ok' => false, 'error' => 'Failed'], 500);
}