<?php
require_once __DIR__ . '/../../includes/config.php';
requireOwner();

$db = getDB();
$user = getCurrentUser();
$pageTitle = 'Owner Dashboard';

// Stats
$statsStmt = $db->prepare("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active_count,
    SUM(CASE WHEN status='full' THEN 1 ELSE 0 END) AS full_count,
    SUM(CASE WHEN status='inactive' THEN 1 ELSE 0 END) AS inactive_count,
    SUM(total_rooms) AS total_rooms,
    SUM(available_rooms) AS available_rooms
  FROM boarding_houses
  WHERE owner_id = ?");
$statsStmt->execute([$_SESSION['user_id']]);
$stats = $statsStmt->fetch() ?: [];

$totalListings = intval($stats['total'] ?? 0);
$activeCount = intval($stats['active_count'] ?? 0);
$fullCount = intval($stats['full_count'] ?? 0);
$inactiveCount = intval($stats['inactive_count'] ?? 0);
$totalRooms = intval($stats['total_rooms'] ?? 0);
$availableRooms = intval($stats['available_rooms'] ?? 0);

$msgStmt = $db->prepare("SELECT COUNT(*)
  FROM contact_messages cm
  JOIN boarding_houses bh ON bh.id = cm.boarding_house_id
  WHERE bh.owner_id = ?");
$msgStmt->execute([$_SESSION['user_id']]);
$messageCount = intval($msgStmt->fetchColumn() ?: 0);

$unreadMessageCount = 0;
try {
    $unreadStmt = $db->prepare("SELECT COUNT(*)
      FROM contact_messages cm
      JOIN boarding_houses bh ON bh.id = cm.boarding_house_id
      WHERE bh.owner_id = ? AND cm.is_read = 0");
    $unreadStmt->execute([$_SESSION['user_id']]);
    $unreadMessageCount = intval($unreadStmt->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $unreadMessageCount = 0;
}

$listingsStmt = $db->prepare("SELECT
    bh.*,
    (SELECT pi.image_path FROM boarding_house_images pi
      WHERE pi.boarding_house_id = bh.id AND pi.is_cover = 1
      LIMIT 1) AS cover_image,
    (SELECT COUNT(*) FROM contact_messages cm WHERE cm.boarding_house_id = bh.id) AS message_count
  FROM boarding_houses bh
  WHERE bh.owner_id = ?
  ORDER BY bh.created_at DESC");
$listingsStmt->execute([$_SESSION['user_id']]);
$listings = $listingsStmt->fetchAll() ?: [];

$showNavbar = false;
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <h1 class="page-title">Owner Dashboard</h1>
    <nav class="page-breadcrumb">
      <a href="<?= SITE_URL ?>/index.php">Home</a>
      <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
      <span>Dashboard</span>
    </nav>
  </div>
</div>

<div class="container">
  <div class="dashboard-layout">

    <aside class="sidebar">
      <div class="sidebar-user">
        <div class="sidebar-avatar"><?= strtoupper(substr(sanitize($user['full_name'] ?? 'U'), 0, 1)) ?></div>
        <div class="sidebar-name"><?= sanitize($user['full_name'] ?? 'Owner') ?></div>
        <div class="sidebar-email"><?= sanitize($user['email'] ?? '') ?></div>
      </div>

      <nav class="sidebar-nav">
        <a class="active" href="dashboard.php"><i class="fas fa-gauge"></i> Dashboard</a>
        <a href="add_listing.php"><i class="fas fa-plus"></i> Add Listing</a>
        <a href="inquiries.php"><i class="fas fa-envelope"></i> Inquiries <?php if ($unreadMessageCount > 0): ?><span class="sidebar-badge"><?= $unreadMessageCount ?></span><?php endif; ?></a>
        <a href="<?= SITE_URL ?>/index.php"><i class="fas fa-house"></i> Browse</a>
        <a href="<?= SITE_URL ?>/logout.php" class="logout-link"><i class="fas fa-right-from-bracket"></i> Logout</a>
      </nav>
    </aside>

    <main>
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon stat-icon-primary"><i class="fas fa-building"></i></div>
          <div>
            <div class="stat-value" data-count="<?= $totalListings ?>">0</div>
            <div class="stat-name">Total Listings</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon stat-icon-success"><i class="fas fa-circle-check"></i></div>
          <div>
            <div class="stat-value" data-count="<?= $activeCount ?>">0</div>
            <div class="stat-name">Active</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon stat-icon-warning"><i class="fas fa-bed"></i></div>
          <div>
            <div class="stat-value" data-count="<?= $availableRooms ?>">0</div>
            <div class="stat-name">Rooms Available (<?= $totalRooms ?> total)</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon stat-icon-secondary"><i class="fas fa-envelope"></i></div>
          <div>
            <div class="stat-value" data-count="<?= $messageCount ?>">0</div>
            <div class="stat-name">Inquiries</div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
          <div>
            <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">My Listings</h2>
            <div class="text-muted text-sm" style="margin-top:4px">Manage your properties, status, and details.</div>
          </div>
          <a class="btn btn-primary" href="add_listing.php"><i class="fas fa-plus"></i> Add Listing</a>
        </div>

        <div class="card-body">
          <?php if (empty($listings)): ?>
            <div class="empty-state">
              <i class="fas fa-building"></i>
              <h3>No listings yet</h3>
              <p>Create your first listing to start receiving inquiries.</p>
              <a class="btn btn-primary" href="add_listing.php"><i class="fas fa-plus"></i> Add Listing</a>
            </div>
          <?php else: ?>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Listing</th>
                    <th>Status</th>
                    <th>Rooms</th>
                    <th>Price</th>
                    <th>Inquiries</th>
                    <th style="width:220px">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($listings as $l):
                    $status = $l['status'] ?? 'active';
                    $statusLabel = $status === 'full' ? 'Full' : ($status === 'inactive' ? 'Inactive' : 'Active');
                    $statusClass = $status === 'full' ? 'status-full' : ($status === 'inactive' ? 'status-inactive' : 'status-active');
                    $cover = $l['cover_image'] ?? null;
                    $coverUrl = $cover ? (UPLOAD_URL . sanitize($cover)) : null;
                    $messages = intval($l['message_count'] ?? 0);
                    $priceMin = (float)($l['price_min'] ?? 0);
                    $priceMax = $l['price_max'] !== null ? (float)$l['price_max'] : null;
                  ?>
                  <tr>
                    <td>
                      <div class="flex items-center gap-3">
                        <?php if ($coverUrl): ?>
                          <img src="<?= $coverUrl ?>" alt="" style="width:56px;height:44px;object-fit:cover;border-radius:10px;border:1px solid var(--border)">
                        <?php else: ?>
                          <div style="width:56px;height:44px;border-radius:10px;border:1px solid var(--border);background:var(--bg);display:flex;align-items:center;justify-content:center;color:var(--text-light)">
                            <i class="fas fa-image"></i>
                          </div>
                        <?php endif; ?>

                        <div>
                          <div class="font-bold"><?= sanitize($l['name'] ?? '') ?></div>
                          <div class="text-muted text-xs"><i class="fas fa-location-dot" style="margin-right:6px"></i><?= sanitize($l['city'] ?? '') ?></div>
                        </div>
                      </div>
                    </td>
                    <td><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                    <td>
                      <span class="text-sm"><?= intval($l['available_rooms'] ?? 0) ?></span>
                      <span class="text-muted text-xs">/ <?= intval($l['total_rooms'] ?? 0) ?></span>
                    </td>
                    <td>
                      <div class="text-sm"><?= formatPrice($priceMin) ?></div>
                      <?php if ($priceMax !== null && $priceMax > $priceMin): ?>
                        <div class="text-muted text-xs">to <?= formatPrice($priceMax) ?></div>
                      <?php else: ?>
                        <div class="text-muted text-xs">per month</div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($messages > 0): ?>
                        <a href="inquiries.php?listing_id=<?= intval($l['id'] ?? 0) ?>" class="badge" style="background:var(--info-bg);color:var(--info)"><?= $messages ?></a>
                      <?php else: ?>
                        <span class="text-muted text-sm">0</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="flex flex-wrap gap-2">
                        <a class="btn btn-primary btn-sm" href="<?= SITE_URL ?>/pages/detail.php?id=<?= intval($l['id'] ?? 0) ?>"><i class="fas fa-eye"></i> View</a>
                        <a class="btn btn-ghost btn-sm" href="edit_listing.php?id=<?= intval($l['id'] ?? 0) ?>"><i class="fas fa-pen"></i> Edit</a>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="text-muted text-xs mt-3">
              Status summary: <span class="badge status-active"><?= $activeCount ?> Active</span>
              <span class="badge status-full"><?= $fullCount ?> Full</span>
              <span class="badge status-inactive"><?= $inactiveCount ?> Inactive</span>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </main>

  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>


