<?php
require_once __DIR__ . '/_bootstrap.php';

requireMethod('GET');

if (!isLoggedIn()) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$threadId = intval($_GET['thread_id'] ?? 0);
$sinceId = intval($_GET['since_id'] ?? 0);

if ($threadId <= 0) {
    jsonResponse(['error' => 'Invalid thread_id'], 400);
}

$db = getDB();
$uid = intval($_SESSION['user_id']);

// Verify participant
$thread = null;
try {
    $stmt = $db->prepare('SELECT id, tenant_id, owner_id FROM chat_threads WHERE id = ? LIMIT 1');
    $stmt->execute([$threadId]);
    $thread = $stmt->fetch() ?: null;
} catch (Throwable $e) {
    jsonResponse(['error' => 'Chat not available'], 500);
}

if (!$thread) {
    jsonResponse(['error' => 'Thread not found'], 404);
}

$isTenantParticipant = intval($thread['tenant_id'] ?? 0) === $uid;
$isOwnerParticipant = intval($thread['owner_id'] ?? 0) === $uid;

if (!$isTenantParticipant && !$isOwnerParticipant) {
    jsonResponse(['error' => 'Forbidden'], 403);
}

// Mark as read (incoming only)
try {
    $db->prepare('UPDATE chat_messages SET is_read = 1 WHERE thread_id = ? AND sender_id <> ? AND is_read = 0')
       ->execute([$threadId, $uid]);
} catch (Throwable $e) {
    // ignore
}

$messages = [];
try {
    $stmt = $db->prepare('SELECT m.id, m.thread_id, m.sender_id, m.message, m.is_read, m.created_at, u.full_name
      FROM chat_messages m
      JOIN users u ON u.id = m.sender_id
      WHERE m.thread_id = ? AND m.id > ?
      ORDER BY m.id ASC
      LIMIT 200');
    $stmt->execute([$threadId, $sinceId]);
    $messages = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    jsonResponse(['error' => 'Failed to load messages'], 500);
}

$lastId = $sinceId;
foreach ($messages as $m) {
    $mid = intval($m['id'] ?? 0);
    if ($mid > $lastId) $lastId = $mid;
}

jsonResponse([
    'thread_id' => $threadId,
    'since_id' => $sinceId,
    'last_id' => $lastId,
    'messages' => $messages,
]);
