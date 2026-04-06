<?php
require_once __DIR__ . '/../../includes/config.php';
requireOwner();

$db = getDB();
$pageTitle = 'Chats';
$uid = intval($_SESSION['user_id']);

$threads = [];
$unreadCount = 0;

try {
    $stmt = $db->prepare("
        SELECT
            t.id,
            t.boarding_house_id,
            t.tenant_id,
            t.created_at,
            t.last_message_at,
            bh.name AS bh_name,
            bh.city AS bh_city,
            u.full_name AS tenant_name,
            (SELECT message FROM chat_messages m WHERE m.thread_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_message,
            (SELECT created_at FROM chat_messages m2 WHERE m2.thread_id = t.id ORDER BY m2.id DESC LIMIT 1) AS last_message_time,
            (SELECT COUNT(*) FROM chat_messages mu WHERE mu.thread_id = t.id AND mu.is_read = 0 AND mu.sender_id <> ?) AS unread_count
        FROM chat_threads t
        JOIN boarding_houses bh ON bh.id = t.boarding_house_id
        JOIN users u ON u.id = t.tenant_id
        WHERE t.owner_id = ?
        ORDER BY COALESCE(t.last_message_at, t.created_at) DESC
    ");
    $stmt->execute([$uid, $uid]);
    $threads = $stmt->fetchAll() ?: [];

    $unreadStmt = $db->prepare("SELECT COUNT(*)
        FROM chat_messages m
        JOIN chat_threads t ON t.id = m.thread_id
        WHERE t.owner_id = ? AND m.is_read = 0 AND m.sender_id <> ?");
    $unreadStmt->execute([$uid, $uid]);
    $unreadCount = intval($unreadStmt->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $threads = [];
    $unreadCount = 0;
    setFlash('error', 'Chat is not available yet. Please run install.php or import the updated schema.sql.');
}

$showNavbar = false;
require_once __DIR__ . '/../../includes/header.php';
?>

<?php $me = getCurrentUser(); ?>
<div class="dash-shell">
<?php $activeNav = 'chats'; include __DIR__ . '/_partials/sidebar.php'; ?>

  <div class="dash-main">
<?php include __DIR__ . '/_partials/topbar.php'; ?>

    <div class="dash-content">
      <div class="dash-heading">
        <div>
          <h1 class="dash-title">Chats</h1>
          <div class="dash-breadcrumb">
            <a href="dashboard.php">Dashboard</a>
            <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
            <span>Chats</span>
          </div>
        </div>
      </div>

      <main>
      <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
          <div>
            <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">Conversations</h2>
            <div class="text-muted text-sm" style="margin-top:4px">In-app messages from tenants.</div>
          </div>
        </div>

        <div class="card-body">
          <?php if (empty($threads)): ?>
            <div class="empty-state">
              <i class="fas fa-comments"></i>
              <h3>No chats yet</h3>
              <p class="text-muted">When a tenant starts a chat from your listing, it will show up here.</p>
            </div>
          <?php else: ?>
            <div class="chat-thread-list">
              <?php foreach ($threads as $t):
                $unread = intval($t['unread_count'] ?? 0);
                $snippet = trim((string)($t['last_message'] ?? ''));
                if ($snippet !== '' && textLength($snippet) > 90) $snippet = textSlice($snippet, 0, 90) . '...';
                $when = $t['last_message_time'] ? date('M d, H:i', strtotime((string)$t['last_message_time'])) : '';
              ?>
                <a class="chat-thread" href="chat.php?thread_id=<?= intval($t['id']) ?>">
                  <div class="chat-thread-main">
                    <div class="chat-thread-title">
                      <strong><?= sanitize($t['bh_name'] ?? 'Listing') ?></strong>
                      <span class="text-muted" style="font-size:.85rem">&middot; <?= sanitize($t['tenant_name'] ?? 'Tenant') ?></span>
                    </div>
                    <div class="chat-thread-meta">
                      <span class="text-muted text-sm"><?= sanitize($snippet !== '' ? $snippet : 'No messages yet') ?></span>
                    </div>
                  </div>
                  <div class="chat-thread-side">
                    <?php if ($when): ?><div class="text-muted text-xs" style="text-align:right"><?= sanitize($when) ?></div><?php endif; ?>
                    <?php if ($unread > 0): ?><span class="chat-badge"><?= $unread ?></span><?php endif; ?>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </main>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>





