<?php
require_once __DIR__ . '/../../includes/config.php';
requireOwner();

$db = getDB();
$uid = intval($_SESSION['user_id']);

$threadId = intval($_GET['thread_id'] ?? 0);
if ($threadId <= 0) {
    header('Location: chats.php');
    exit;
}

$thread = null;
$error = null;

try {
    $stmt = $db->prepare("SELECT t.*, bh.name AS bh_name, bh.city AS bh_city, u.full_name AS tenant_name
        FROM chat_threads t
        JOIN boarding_houses bh ON bh.id = t.boarding_house_id
        JOIN users u ON u.id = t.tenant_id
        WHERE t.id = ? AND t.owner_id = ?");
    $stmt->execute([$threadId, $uid]);
    $thread = $stmt->fetch() ?: null;
} catch (Throwable $e) {
    $thread = null;
    $error = 'Chat is not available yet. Please run install.php or import the updated schema.sql.';
}

if (!$thread) {
    setFlash('error', $error ?: 'Chat not found.');
    header('Location: chats.php');
    exit;
}

$pageTitle = 'Chat · ' . sanitize($thread['bh_name'] ?? 'Listing');

// Send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $msg = trim((string)($_POST['message'] ?? ''));
    if ($msg !== '') {
        try {
            $dedupe = $db->prepare("SELECT id
              FROM chat_messages
              WHERE thread_id = ? AND sender_id = ? AND message = ?
                AND created_at >= (NOW() - INTERVAL 5 SECOND)
              ORDER BY id DESC
              LIMIT 1");
            $dedupe->execute([intval($thread['id']), $uid, $msg]);
            $existingId = intval($dedupe->fetchColumn() ?: 0);
            if ($existingId <= 0) {
                $db->prepare("INSERT INTO chat_messages (thread_id, sender_id, message) VALUES (?,?,?)")
                   ->execute([intval($thread['id']), $uid, $msg]);
            }
            $db->prepare("UPDATE chat_threads SET last_message_at = NOW() WHERE id = ?")
               ->execute([intval($thread['id'])]);
        } catch (Throwable $e) {
            setFlash('error', 'Failed to send message.');
        }
    }
    header('Location: chat.php?thread_id=' . intval($thread['id']));
    exit;
}

// Mark incoming messages as read
try {
    $db->prepare("UPDATE chat_messages SET is_read = 1 WHERE thread_id = ? AND sender_id <> ?")
       ->execute([intval($thread['id']), $uid]);
} catch (Throwable $e) {
    // ignore
}

$messages = [];
try {
    $stmt = $db->prepare("SELECT m.*, u.full_name
      FROM chat_messages m
      JOIN users u ON u.id = m.sender_id
      WHERE m.thread_id = ?
      ORDER BY m.id ASC
      LIMIT 300");
    $stmt->execute([intval($thread['id'])]);
    $messages = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    $messages = [];
}

// Unread count badge for sidebar
$unreadCount = 0;
try {
    $unreadStmt = $db->prepare("SELECT COUNT(*)
        FROM chat_messages m
        JOIN chat_threads t ON t.id = m.thread_id
        WHERE t.owner_id = ? AND m.is_read = 0 AND m.sender_id <> ?");
    $unreadStmt->execute([$uid, $uid]);
    $unreadCount = intval($unreadStmt->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $unreadCount = 0;
}

$showNavbar = false;
require_once __DIR__ . '/../../includes/header.php';
?>

<?php $me = getCurrentUser(); ?>
<div class="dash-shell">
  <aside class="dash-sidebar">
    <a class="dash-brand" href="dashboard.php" aria-label="<?= sanitize(SITE_NAME) ?>">
      <span class="dash-logo-wrap"><img class="dash-logo" src="<?= SITE_URL ?>/boardease-logo.png" alt="<?= sanitize(SITE_NAME) ?> logo"></span>
      <span class="sr-only"><?= sanitize(SITE_NAME) ?></span>
    </a>

    <a class="dash-action" href="add_listing.php" title="Create a new listing">
      <span>Add Listing</span>
      <i class="fas fa-plus"></i>
    </a>

    <nav class="dash-nav">
      <a href="dashboard.php"><i class="fas fa-gauge"></i> Overview</a>
      <a href="rooms.php"><i class="fas fa-door-open"></i> Rooms</a>
      <a class="active" href="chats.php"><i class="fas fa-comments"></i> Chats <?php if ($unreadCount > 0): ?><span class="sidebar-badge"><?= $unreadCount ?></span><?php endif; ?></a>
      <a href="<?= SITE_URL ?>/index.php"><i class="fas fa-house"></i> Browse</a>
    </nav>

  </aside>

  <div class="dash-main">
    <div class="dash-topbar">
      <div class="dash-search" aria-label="Search">
        <i class="fas fa-comments"></i>
        <input type="search" value="Chat" disabled>
      </div>

      <div class="dash-top-actions">
        <a class="btn btn-ghost btn-sm" href="chats.php"><i class="fas fa-arrow-left"></i> Back</a>
      </div>
    </div>

    <div class="dash-content" style="padding-top:16px">
      <div class="chat-header" style="margin-bottom:16px">
        <div style="min-width:0">
          <div class="chat-title"><?= sanitize($thread['bh_name'] ?? 'Listing') ?></div>
          <div class="text-muted text-sm">Chatting with <?= sanitize($thread['tenant_name'] ?? 'Tenant') ?></div>
        </div>
        <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/pages/detail.php?id=<?= intval($thread['boarding_house_id'] ?? 0) ?>"><i class="fas fa-eye"></i> View Listing</a>
      </div>

      <div class="chat-box" id="chatBox" data-thread-id="<?= intval($thread['id']) ?>" data-user-id="<?= intval($uid) ?>" data-messages-url="<?= SITE_URL ?>/api/chat_messages.php" data-send-url="<?= SITE_URL ?>/api/chat_send.php">
        <div class="chat-messages">
          <?php if (empty($messages)): ?>
            <div class="empty-state" style="padding:40px 0">
              <i class="fas fa-comment-dots"></i>
              <h3>No messages yet</h3>
              <p>Wait for the tenant to send a message.</p>
            </div>
          <?php else: ?>
            <?php foreach ($messages as $m):
              $mine = intval($m['sender_id'] ?? 0) === $uid;
              $time = $m['created_at'] ? date('M d, H:i', strtotime((string)$m['created_at'])) : '';
            ?>
              <div class="chat-msg <?= $mine ? 'mine' : 'theirs' ?>" data-mid="<?= intval($m['id'] ?? 0) ?>">
                <div class="chat-bubble">
                  <div class="chat-text"><?= nl2br(sanitize($m['message'] ?? '')) ?></div>
                  <div class="chat-time"><?= sanitize($time) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <form class="chat-compose" method="POST" action="">
          <input type="hidden" name="send_message" value="1">
          <input class="form-control" type="text" name="message" placeholder="Type a message..." autocomplete="off" required>
          <button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i></button>
        </form>
        <div class="chat-compose-status" aria-live="polite"></div>
      </div>

    </div>

  </div>
</div>


<?php require_once __DIR__ . '/../../includes/footer.php'; ?>





