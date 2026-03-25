<?php
require_once __DIR__ . '/_common.php';

$pageTitle = 'Admin Dashboard';

$hasUserActive = adminTableHasColumn($db, 'users', 'is_active');
$hasOwnerVerified = adminTableHasColumn($db, 'users', 'owner_verified');
$hasApproval = adminTableHasColumn($db, 'boarding_houses', 'approval_status');
$hasViews = adminTableHasColumn($db, 'boarding_houses', 'views');

$totalUsers = intval($db->query("SELECT COUNT(*) FROM users")->fetchColumn() ?: 0);
$totalListings = intval($db->query("SELECT COUNT(*) FROM boarding_houses")->fetchColumn() ?: 0);
$activeListings = intval($db->query("SELECT COUNT(*) FROM boarding_houses WHERE status != 'inactive'")->fetchColumn() ?: 0);
$inactiveListings = intval($db->query("SELECT COUNT(*) FROM boarding_houses WHERE status = 'inactive'")->fetchColumn() ?: 0);

$pendingOwners = 0;
if ($hasOwnerVerified) {
    $pendingOwners = intval($db->query("SELECT COUNT(*) FROM users WHERE role='owner' AND owner_verified = 0")->fetchColumn() ?: 0);
}

$pendingListings = 0;
if ($hasApproval) {
    $pendingListings = intval($db->query("SELECT COUNT(*) FROM boarding_houses WHERE approval_status = 'pending'")->fetchColumn() ?: 0);
}

$openReports = 0;
try {
    $openReports = intval($db->query("SELECT COUNT(*) FROM reports WHERE status = 'open'")->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $openReports = 0;
}

$recentUsers = $db->query("SELECT id, full_name, email, role, created_at" . ($hasUserActive ? ", is_active" : "") . " FROM users ORDER BY created_at DESC LIMIT 10")->fetchAll() ?: [];

$topViewed = [];
if ($hasViews) {
    $topViewed = $db->query("SELECT id, name, city, views FROM boarding_houses ORDER BY views DESC, created_at DESC LIMIT 10")->fetchAll() ?: [];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <h1 class="page-title">Admin Dashboard</h1>
    <nav class="page-breadcrumb">
      <a href="<?= SITE_URL ?>/index.php">Home</a>
      <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
      <span>Admin</span>
    </nav>
  </div>
</div>

<div class="container" style="padding-bottom:60px">
  <div class="dashboard-layout">
    <?php adminSidebar('dashboard'); ?>

    <main>
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon stat-icon-primary"><i class="fas fa-users"></i></div>
          <div>
            <div class="stat-value" data-count="<?= $totalUsers ?>">0</div>
            <div class="stat-name">Total Users</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon stat-icon-secondary"><i class="fas fa-building"></i></div>
          <div>
            <div class="stat-value" data-count="<?= $totalListings ?>">0</div>
            <div class="stat-name">Total Listings</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon stat-icon-success"><i class="fas fa-circle-check"></i></div>
          <div>
            <div class="stat-value" data-count="<?= $activeListings ?>">0</div>
            <div class="stat-name">Active Listings</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon stat-icon-warning"><i class="fas fa-eye-slash"></i></div>
          <div>
            <div class="stat-value" data-count="<?= $inactiveListings ?>">0</div>
            <div class="stat-name">Inactive Listings</div>
          </div>
        </div>

        <?php if ($hasOwnerVerified): ?>
        <div class="stat-card">
          <div class="stat-icon stat-icon-warning"><i class="fas fa-user-check"></i></div>
          <div>
            <div class="stat-value" data-count="<?= $pendingOwners ?>">0</div>
            <div class="stat-name">Owners Pending</div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($hasApproval): ?>
        <div class="stat-card">
          <div class="stat-icon stat-icon-warning"><i class="fas fa-clipboard-check"></i></div>
          <div>
            <div class="stat-value" data-count="<?= $pendingListings ?>">0</div>
            <div class="stat-name">Listings Pending</div>
          </div>
        </div>
        <?php endif; ?>

        <div class="stat-card">
          <div class="stat-icon stat-icon-error"><i class="fas fa-flag"></i></div>
          <div>
            <div class="stat-value" data-count="<?= $openReports ?>">0</div>
            <div class="stat-name">Open Reports</div>
          </div>
        </div>
      </div>

      <div class="card mt-4">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
          <div>
            <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">Recent Registrations</h2>
            <div class="text-muted text-sm" style="margin-top:4px">Last 10 users created.</div>
          </div>
          <a class="btn btn-ghost btn-sm" href="users.php"><i class="fas fa-users"></i> Manage Users</a>
        </div>
        <div class="card-body">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Date</th><?= $hasUserActive ? '<th>Status</th>' : '' ?></tr></thead>
              <tbody>
              <?php foreach ($recentUsers as $u): ?>
                <tr>
                  <td class="font-bold"><?= sanitize($u['full_name'] ?? '') ?></td>
                  <td><?= sanitize($u['email'] ?? '') ?></td>
                  <td><span class="badge" style="background:var(--bg);border:1px solid var(--border)"><?= sanitize($u['role'] ?? '') ?></span></td>
                  <td class="text-muted text-sm"><?= sanitize(date('M d, Y', strtotime((string)($u['created_at'] ?? '')))) ?></td>
                  <?php if ($hasUserActive): ?>
                    <td>
                      <span class="badge" style="<?= intval($u['is_active'] ?? 1) ? 'background:rgba(27,122,74,0.12);color:var(--success)' : 'background:var(--error-bg);color:var(--error)' ?>">
                        <?= intval($u['is_active'] ?? 1) ? 'Active' : 'Inactive' ?>
                      </span>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <?php if ($hasViews): ?>
      <div class="card mt-4">
        <div class="card-header">
          <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">Most Viewed Listings</h2>
        </div>
        <div class="card-body">
          <?php if (empty($topViewed)): ?>
            <div class="text-muted">No view data yet.</div>
          <?php else: ?>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Listing</th><th>City</th><th>Views</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($topViewed as $r): ?>
                  <tr>
                    <td class="font-bold"><?= sanitize($r['name'] ?? '') ?></td>
                    <td><?= sanitize($r['city'] ?? '') ?></td>
                    <td><?= intval($r['views'] ?? 0) ?></td>
                    <td><a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/pages/detail.php?id=<?= intval($r['id'] ?? 0) ?>"><i class="fas fa-eye"></i> View</a></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </main>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
