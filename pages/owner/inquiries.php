<?php
require_once __DIR__ . '/../../includes/config.php';
requireOwner();

$db = getDB();
$pageTitle = 'Inquiries';

// Column detection for compatibility
$cmCols = $db->query("SHOW COLUMNS FROM contact_messages")->fetchAll() ?: [];
$cmFields = array_map(fn($r) => (string)($r['Field'] ?? ''), $cmCols);
$cmHas = fn(string $c): bool => in_array($c, $cmFields, true);

$sentCol = $cmHas('sent_at') ? 'sent_at' : ($cmHas('created_at') ? 'created_at' : 'sent_at');
$hasIsRead = $cmHas('is_read');
$hasOwnerReply = $cmHas('owner_reply');
$hasRepliedAt = $cmHas('replied_at');

$listingId = intval($_GET['listing_id'] ?? 0);
$onlyUnread = isset($_GET['unread']) && $_GET['unread'] === '1';

// Owner listings (for filter)
$ownerListings = $db->prepare("SELECT id, name FROM boarding_houses WHERE owner_id = ? ORDER BY created_at DESC");
$ownerListings->execute([$_SESSION['user_id']]);
$ownerListings = $ownerListings->fetchAll() ?: [];

$where = ["bh.owner_id = ?"]; 
$params = [$_SESSION['user_id']];
if ($listingId > 0) { $where[] = "bh.id = ?"; $params[] = $listingId; }
if ($onlyUnread && $hasIsRead) { $where[] = "cm.is_read = 0"; }

$sql = "SELECT cm.*, bh.id AS bh_id, bh.name AS bh_name, bh.city AS bh_city
  FROM contact_messages cm
  JOIN boarding_houses bh ON bh.id = cm.boarding_house_id
  WHERE " . implode(' AND ', $where) . "
  ORDER BY cm.$sentCol DESC";

$msgStmt = $db->prepare($sql);
$msgStmt->execute($params);
$messages = $msgStmt->fetchAll() ?: [];

$unreadCount = 0;
if ($hasIsRead) {
    $unreadStmt = $db->prepare("SELECT COUNT(*)
      FROM contact_messages cm
      JOIN boarding_houses bh ON bh.id = cm.boarding_house_id
      WHERE bh.owner_id = ? AND cm.is_read = 0");
    $unreadStmt->execute([$_SESSION['user_id']]);
    $unreadCount = intval($unreadStmt->fetchColumn() ?: 0);
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <h1 class="page-title">Inquiries</h1>
    <nav class="page-breadcrumb">
      <a href="dashboard.php">Dashboard</a>
      <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
      <span>Inquiries</span>
    </nav>
  </div>
</div>

<div class="container" style="padding-bottom:60px">
  <div class="dashboard-layout">

    <aside class="sidebar">
      <?php $me = getCurrentUser(); ?>
      <div class="sidebar-user">
        <div class="sidebar-avatar"><?= strtoupper(substr(sanitize($me['full_name'] ?? 'U'), 0, 1)) ?></div>
        <div class="sidebar-name"><?= sanitize($me['full_name'] ?? 'Owner') ?></div>
        <div class="sidebar-email"><?= sanitize($me['email'] ?? '') ?></div>
      </div>

      <nav class="sidebar-nav">
        <a href="dashboard.php"><i class="fas fa-gauge"></i> Dashboard</a>
        <a href="add_listing.php"><i class="fas fa-plus"></i> Add Listing</a>
        <a class="active" href="inquiries.php"><i class="fas fa-envelope"></i> Inquiries <?php if ($unreadCount > 0): ?><span class="sidebar-badge"><?= $unreadCount ?></span><?php endif; ?></a>
        <a href="<?= SITE_URL ?>/index.php"><i class="fas fa-house"></i> Browse</a>
        <a href="<?= SITE_URL ?>/logout.php" class="logout-link"><i class="fas fa-right-from-bracket"></i> Logout</a>
      </nav>
    </aside>

    <main>
      <div class="card">
        <div class="card-header" style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">
          <div>
            <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">Messages</h2>
            <div class="text-muted text-sm" style="margin-top:4px">Tenant inquiries sent from listing pages.</div>
          </div>

          <form method="GET" action="" class="flex items-center gap-2" style="flex-wrap:wrap">
            <select name="listing_id" class="form-control" style="min-width:220px">
              <option value="0">All Listings</option>
              <?php foreach ($ownerListings as $l): ?>
                <option value="<?= intval($l['id']) ?>" <?= $listingId === intval($l['id']) ? 'selected' : '' ?>><?= sanitize($l['name']) ?></option>
              <?php endforeach; ?>
            </select>

            <label class="flex items-center gap-2 text-sm" style="user-select:none">
              <input type="checkbox" name="unread" value="1" <?= $onlyUnread ? 'checked' : '' ?> <?= $hasIsRead ? '' : 'disabled' ?>>
              Unread only
            </label>

            <button class="btn btn-ghost btn-sm" type="submit"><i class="fas fa-filter"></i> Filter</button>
            <a class="btn btn-ghost btn-sm" href="inquiries.php"><i class="fas fa-rotate-left"></i> Reset</a>
          </form>
        </div>

        <div class="card-body">
          <?php if (empty($messages)): ?>
            <div class="empty-state">
              <i class="fas fa-envelope"></i>
              <h3>No inquiries found</h3>
              <p class="text-muted">When tenants contact you from a listing, messages will appear here.</p>
            </div>
          <?php else: ?>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>From</th>
                    <th>Listing</th>
                    <th>Message</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th style="width:180px">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($messages as $m):
                    $isRead = $hasIsRead ? (intval($m['is_read'] ?? 0) === 1) : true;
                    $hasReply = $hasOwnerReply ? (trim((string)($m['owner_reply'] ?? '')) !== '') : false;
                    $sentAt = $m[$sentCol] ?? null;
                    $dateText = $sentAt ? date('M d, Y H:i', strtotime((string)$sentAt)) : '';
                    $snippet = trim((string)($m['message'] ?? ''));
                    if (mb_strlen($snippet) > 80) $snippet = mb_substr($snippet, 0, 80) . '…';
                    $badgeStyle = $isRead ? 'background:var(--bg);color:var(--text-muted);border:1px solid var(--border)' : 'background:var(--info-bg);color:var(--info)';
                  ?>
                  <tr>
                    <td>
                      <div class="font-bold"><?= sanitize($m['sender_name'] ?? '') ?></div>
                      <div class="text-muted text-xs"><?= sanitize($m['sender_email'] ?? '') ?></div>
                    </td>
                    <td>
                      <div class="font-bold"><?= sanitize($m['bh_name'] ?? '') ?></div>
                      <div class="text-muted text-xs"><?= sanitize($m['bh_city'] ?? '') ?></div>
                    </td>
                    <td class="text-sm"><?= sanitize($snippet) ?></td>
                    <td class="text-muted text-sm"><?= sanitize($dateText) ?></td>
                    <td>
                      <span class="badge" style="<?= $badgeStyle ?>"><?= $isRead ? 'Read' : 'Unread' ?></span>
                      <?php if ($hasReply && $hasRepliedAt): ?>
                        <span class="badge" style="background:rgba(27,122,74,0.12);color:var(--success)">Replied</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="flex flex-wrap gap-2">
                        <a class="btn btn-primary btn-sm" href="inquiry.php?id=<?= intval($m['id']) ?>"><i class="fas fa-eye"></i> View</a>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </main>

  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
