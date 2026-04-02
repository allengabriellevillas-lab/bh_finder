<?php
require_once __DIR__ . '/../includes/config.php';

requireTenant();

$pageTitle = 'Messages';
$db = getDB();
$uid = intval($_SESSION['user_id']);

$threads = [];
try {
    $stmt = $db->prepare("
        SELECT
            t.id,
            t.boarding_house_id,
            t.owner_id,
            t.created_at,
            t.last_message_at,
            bh.name AS bh_name,
            bh.city AS bh_city,
            u.full_name AS owner_name,
            (SELECT message FROM chat_messages m WHERE m.thread_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_message,
            (SELECT created_at FROM chat_messages m2 WHERE m2.thread_id = t.id ORDER BY m2.id DESC LIMIT 1) AS last_message_time,
            (SELECT COUNT(*) FROM chat_messages mu WHERE mu.thread_id = t.id AND mu.is_read = 0 AND mu.sender_id <> ?) AS unread_count
        FROM chat_threads t
        JOIN boarding_houses bh ON bh.id = t.boarding_house_id
        JOIN users u ON u.id = t.owner_id
        WHERE t.tenant_id = ?
        ORDER BY COALESCE(t.last_message_at, t.created_at) DESC
    ");
    $stmt->execute([$uid, $uid]);
    $threads = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    $threads = [];
    setFlash('error', 'Chat is not available yet. Please run install.php or import the updated schema.sql.');
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <nav class="page-breadcrumb">
      <a href="<?= SITE_URL ?>/index.php">Home</a>
      <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
      <span>Messages</span>
    </nav>
    <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap">
      <div>
        <h1 class="section-title" style="margin:10px 0 4px">Messages</h1>
        <div class="section-subtitle">Chats with property owners.</div>
      </div>
      <a class="btn btn-ghost" href="<?= SITE_URL ?>/index.php#listings"><i class="fas fa-magnifying-glass"></i> Browse</a>
    </div>
  </div>
</div>

<div class="container" style="padding:24px 0 60px;max-width:980px">
  <div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div>
        <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">Your Chats</h2>
        <div class="text-muted text-sm" style="margin-top:4px">Open a listing and tap “Chat with owner” to start a conversation.</div>
      </div>
      <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/pages/favorites.php"><i class="fas fa-heart"></i> Favorites</a>
    </div>

    <div class="card-body">
      <?php if (empty($threads)): ?>
        <div class="empty-state">
          <i class="fas fa-comments"></i>
          <h3>No messages yet</h3>
          <p>Start a chat from any listing page.</p>
          <a class="btn btn-primary" href="<?= SITE_URL ?>/index.php#listings"><i class="fas fa-search"></i> Browse Property Listings</a>
        </div>
      <?php else: ?>
        <div class="chat-thread-list">
          <?php foreach ($threads as $t):
            $unread = intval($t['unread_count'] ?? 0);
            $snippet = trim((string)($t['last_message'] ?? ''));
            if ($snippet !== '' && textLength($snippet) > 90) $snippet = textSlice($snippet, 0, 90) . '…';
            $when = $t['last_message_time'] ? date('M d, H:i', strtotime((string)$t['last_message_time'])) : '';
          ?>
            <a class="chat-thread" href="<?= SITE_URL ?>/pages/chat.php?thread_id=<?= intval($t['id']) ?>">
              <div class="chat-thread-main">
                <div class="chat-thread-title">
                  <strong><?= sanitize($t['bh_name'] ?? 'Listing') ?></strong>
                  <span class="text-muted" style="font-size:.85rem">· <?= sanitize($t['owner_name'] ?? 'Property Owner') ?></span>
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
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

