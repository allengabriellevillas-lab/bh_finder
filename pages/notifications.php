<?php
require_once __DIR__ . '/../includes/config.php';

requireLogin();

function wantsJson(): bool {
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (str_contains($accept, 'application/json')) return true;
    $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    return $xrw === 'xmlhttprequest';
}

function jsonOut(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

$pageTitle = 'Notifications';
$db = getDB();
$uid = intval($_SESSION['user_id']);

$hasNotifications = notificationsEnabled();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasNotifications) {
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'mark_read') {
            $id = intval($_POST['id'] ?? 0);
            if ($id > 0) {
                $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")
                  ->execute([$id, $uid]);
            }
        }

        if ($action === 'mark_all') {
            $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")
              ->execute([$uid]);
        }
    } catch (Throwable $e) {
        // ignore
    }

    if (wantsJson()) {
        $unread = 0;
        try { $unread = unreadNotificationCount($uid); } catch (Throwable $e) { $unread = 0; }
        jsonOut(['ok' => true, 'unread' => intval($unread)]);
    }

    header('Location: ' . SITE_URL . '/pages/notifications.php');
    exit;
}

$rows = [];
if ($hasNotifications) {
    try {
        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 200");
        $stmt->execute([$uid]);
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <nav class="page-breadcrumb">
      <a href="<?= SITE_URL ?>/index.php">Home</a>
      <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
      <span>Notifications</span>
    </nav>
    <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap">
      <div>
        <h1 class="section-title" style="margin:10px 0 4px">Notifications</h1>
        <div class="section-subtitle">Updates about your chats, rooms, and subscriptions.</div>
      </div>
      <?php if ($hasNotifications): ?>
        <form method="POST" action="" style="display:inline">
          <input type="hidden" name="action" value="mark_all">
          <button class="btn btn-ghost" type="submit"><i class="fas fa-check-double"></i> Mark all read</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="container" style="padding:24px 0 60px;max-width:980px">
  <?php if (!$hasNotifications): ?>
    <div class="flash flash-error"><i class="fas fa-exclamation-circle"></i> Notifications are not available yet. Run <code>install.php</code>.</div>
  <?php elseif (empty($rows)): ?>
    <div class="empty-state">
      <i class="far fa-bell"></i>
      <h3>No notifications</h3>
      <p>You’re all caught up.</p>
    </div>
  <?php else: ?>
    <div class="card">
      <div class="card-body">
        <div class="chat-thread-list">
          <?php foreach ($rows as $n):
            $id = intval($n['id'] ?? 0);
            $isRead = intval($n['is_read'] ?? 0) === 1;
            $link = trim((string)($n['link_url'] ?? ''));
          ?>
            <div class="chat-thread" style="align-items:flex-start;gap:12px;<?= $isRead ? 'opacity:.75' : '' ?>" data-notif-row="<?= $id ?>">
              <div class="chat-thread-main">
                <div class="chat-thread-title">
                  <strong><?= sanitize($n['title'] ?? '') ?></strong>
                  <span class="text-muted" style="font-size:.85rem">· <?= sanitize(date('M d, H:i', strtotime((string)($n['created_at'] ?? 'now')))) ?></span>
                </div>
                <?php if (!empty($n['body'])): ?>
                  <div class="chat-thread-meta"><span class="text-muted text-sm"><?= sanitize($n['body'] ?? '') ?></span></div>
                <?php endif; ?>
                <?php if ($link !== ''): ?>
                  <div style="margin-top:10px">
                    <a class="btn btn-primary btn-sm" href="<?= sanitize($link) ?>"><i class="fas fa-arrow-right"></i> Open</a>
                  </div>
                <?php endif; ?>
              </div>
              <div class="chat-thread-side">
                <?php if (!$isRead): ?><span class="chat-badge" style="background:var(--primary)" data-notif-new="<?= $id ?>">New</span><?php endif; ?>
                <form method="POST" action="" style="margin-top:8px" data-notif-form>
                  <input type="hidden" name="action" value="mark_read">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <button class="btn btn-ghost btn-sm" type="submit"><i class="fas fa-check"></i> Read</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>