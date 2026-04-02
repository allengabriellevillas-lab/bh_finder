<?php
$uid = intval($_SESSION['user_id'] ?? 0);
$me = (isset($me) && is_array($me)) ? $me : (getCurrentUser() ?: []);

$notifCount = notificationsEnabled() ? unreadNotificationCount($uid) : 0;
$notifs = [];
if (notificationsEnabled() && $uid > 0) {
    try {
        $db = isset($db) && $db instanceof PDO ? $db : getDB();
        $stmt = $db->prepare('SELECT id, title, body, link_url, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 8');
        $stmt->execute([$uid]);
        $notifs = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        $notifs = [];
    }
}
?>

<div class="dash-topbar">
  <div class="dash-top-actions">

    <div class="notif-wrap">
      <button class="dash-icon-btn" id="notifBtn" type="button" title="Notifications" aria-label="Notifications" style="position:relative">
        <i class="far fa-bell"></i>
        <?php if ($notifCount > 0): ?><span class="dash-icon-badge"><?= $notifCount > 99 ? '99+' : intval($notifCount) ?></span><?php endif; ?>
      </button>

      <div class="notif-dropdown" id="notifDropdown" aria-label="Notifications">
        <div class="notif-head">
          <strong>Notifications</strong>
          <button class="notif-markall" type="button" data-notif-markall="1">Mark all read</button>
        </div>

        <?php if (empty($notifs)): ?>
          <div class="notif-empty">No notifications yet.</div>
        <?php else: ?>
          <div class="notif-list">
            <?php foreach ($notifs as $n):
              $isNew = intval($n['is_read'] ?? 0) === 0;
              $link = trim((string)($n['link_url'] ?? ''));
              $when = trim((string)($n['created_at'] ?? ''));
            ?>
              <a class="notif-item <?= $isNew ? 'is-new' : '' ?>" href="<?= $link !== '' ? sanitize($link) : '#' ?>">
                <div class="notif-title"><?= sanitize((string)($n['title'] ?? '')) ?><?php if ($isNew): ?><span class="notif-newdot" aria-label="New"></span><?php endif; ?></div>
                <?php if (!empty($n['body'])): ?><div class="notif-body"><?= sanitize((string)($n['body'] ?? '')) ?></div><?php endif; ?>
                <div class="notif-time"><?= $when !== '' ? sanitize(date('M d, H:i', strtotime($when))) : '' ?></div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="nav-user">
      <button class="user-btn" id="userBtn" type="button">
        <span class="user-avatar"><?= strtoupper(substr(sanitize($me['full_name'] ?? 'U'), 0, 1)) ?></span>
        <span><?= sanitize($me['full_name'] ?? 'Property Owner') ?></span>
        <i class="fas fa-chevron-down" style="font-size:0.7rem;color:var(--text-light)"></i>
      </button>

      <div class="user-dropdown" id="userDropdown">
        <div class="dropdown-header">
          <strong><?= sanitize($me['full_name'] ?? '') ?></strong>
          <span><?= sanitize($me['email'] ?? '') ?></span>
          <span class="role-badge role-owner">Property Owner</span>
        </div>

        <a href="dashboard.php"><i class="fas fa-gauge"></i> Dashboard</a>
        <a href="rooms.php"><i class="fas fa-door-open"></i> Rooms</a>
        <a href="subscriptions.php"><i class="fas fa-credit-card"></i> Subscriptions</a>
        <a href="chats.php"><i class="fas fa-comments"></i> Chats</a>
        <a href="verification.php"><i class="fas fa-user-check"></i> Verification</a>
        <hr>

        <a class="logout-link" href="<?= SITE_URL ?>/logout.php"><i class="fas fa-right-from-bracket"></i> Logout</a>
      </div>
    </div>
  </div>
</div>