<?php
require_once __DIR__ . '/_bootstrap.php';

requireMethod('POST');

if (!isLoggedIn()) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$payload = $_POST;
if (empty($payload)) {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) $payload = $json;
    }
}

$threadId = intval($payload['thread_id'] ?? 0);
$message = trim((string)($payload['message'] ?? ''));

if ($threadId <= 0) {
    jsonResponse(['error' => 'Invalid thread_id'], 400);
}
if ($message === '') {
    jsonResponse(['error' => 'Message is required'], 400);
}
if (mb_strlen($message) > 2000) {
    jsonResponse(['error' => 'Message too long'], 400);
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

try {
    $ins = $db->prepare('INSERT INTO chat_messages (thread_id, sender_id, message) VALUES (?,?,?)');
    $ins->execute([$threadId, $uid, $message]);
    $mid = intval($db->lastInsertId() ?: 0);

    $db->prepare('UPDATE chat_threads SET last_message_at = NOW() WHERE id = ?')->execute([$threadId]);

    $stmt = $db->prepare('SELECT m.id, m.thread_id, m.sender_id, m.message, m.is_read, m.created_at, u.full_name
      FROM chat_messages m
      JOIN users u ON u.id = m.sender_id
      WHERE m.id = ? LIMIT 1');
    $stmt->execute([$mid]);
    $msgRow = $stmt->fetch() ?: null;

    jsonResponse(['ok' => true, 'message' => $msgRow]);
} catch (Throwable $e) {
    jsonResponse(['error' => 'Failed to send message'], 500);
}
