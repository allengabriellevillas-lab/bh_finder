<?php
require_once __DIR__ . '/_common.php';

$pageTitle = 'Owner Verification';

$hasOwnerVerified = adminTableHasColumn($db, 'users', 'owner_verified');
$hasUserActive = adminTableHasColumn($db, 'users', 'is_active');

if (!$hasOwnerVerified) {
    setFlash('error', 'Your database schema does not support owner verification yet. Run install.php to update schema.');
}

$totalOwners = 0;
$verifiedOwners = 0;
$pendingCount = 0;
$inactiveOwners = 0;
try {
    $totalOwners = intval($db->query("SELECT COUNT(*) FROM users WHERE role='owner'")->fetchColumn() ?: 0);
    if ($hasOwnerVerified) {
        $verifiedOwners = intval($db->query("SELECT COUNT(*) FROM users WHERE role='owner' AND owner_verified = 1")->fetchColumn() ?: 0);
        $pendingCount = intval($db->query("SELECT COUNT(*) FROM users WHERE role='owner' AND owner_verified = 0")->fetchColumn() ?: 0);
    }
    if ($hasUserActive) {
        $inactiveOwners = intval($db->query("SELECT COUNT(*) FROM users WHERE role='owner' AND is_active = 0")->fetchColumn() ?: 0);
    }
} catch (Throwable $e) {
    // ignore
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasOwnerVerified) {
    $action = $_POST['action'] ?? '';
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        if ($action === 'approve') {
            $stmt = $db->prepare("UPDATE users SET owner_verified = 1, owner_verified_at = NOW() WHERE id = ? AND role = 'owner'");
            $stmt->execute([$id]);
            adminLog($db, 'owner_verified', 'users', $id);
            setFlash('success', 'Owner approved.');
        } elseif ($action === 'reject') {
            if ($hasUserActive) {
                $stmt = $db->prepare("UPDATE users SET is_active = 0 WHERE id = ? AND role = 'owner'");
                $stmt->execute([$id]);
                adminLog($db, 'owner_rejected_deactivated', 'users', $id);
                setFlash('success', 'Owner rejected (account deactivated).');
            } else {
                setFlash('error', 'Cannot reject owner because users.is_active column is missing.');
            }
        }
    }
    header('Location: owners.php');
    exit;
}

$pendingOwners = [];
if ($hasOwnerVerified) {
    $cols = 'id, full_name, email, phone, created_at';
    if ($hasUserActive) $cols .= ', is_active';
    $pendingOwners = $db->query("SELECT $cols FROM users WHERE role='owner' AND owner_verified = 0 ORDER BY created_at DESC")->fetchAll() ?: [];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <h1 class="page-title">Owner Verification</h1>
    <nav class="page-breadcrumb">
      <a href="dashboard.php">Admin</a>
      <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
      <span>Owners</span>
    </nav>
  </div>
</div>

<div class="container" style="padding-bottom:60px">
  <div class="dashboard-layout">
    <?php adminSidebar('owners'); ?>

    <main>
      <div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr))">
        <div class="stat-card">
          <div class="stat-icon stat-icon-primary"><i class="fas fa-user-tie"></i></div>
          <div>
            <div class="stat-value" data-count="<?= $totalOwners ?>">0</div>
            <div class="stat-name">Total Owners</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon stat-icon-warning"><i class="fas fa-user-check"></i></div>
          <div>
            <div class="stat-value" data-count="<?= $pendingCount ?>">0</div>
            <div class="stat-name">Pending Verification</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon stat-icon-success"><i class="fas fa-shield"></i></div>
          <div>
            <div class="stat-value" data-count="<?= $verifiedOwners ?>">0</div>
            <div class="stat-name">Verified Owners</div>
          </div>
        </div>

        <?php if ($hasUserActive): ?>
        <div class="stat-card">
          <div class="stat-icon stat-icon-error"><i class="fas fa-user-slash"></i></div>
          <div>
            <div class="stat-value" data-count="<?= $inactiveOwners ?>">0</div>
            <div class="stat-name">Inactive Owners</div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
          <div>
            <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">Pending Owners</h2>
            <div class="text-muted text-sm" style="margin-top:4px">Approve owners before they can manage listings.</div>
          </div>
          <div class="flex flex-wrap gap-2">
            <a class="btn btn-ghost btn-sm" href="users.php?role=owner"><i class="fas fa-users"></i> View Owners</a>
            <a class="btn btn-ghost btn-sm" href="owners.php"><i class="fas fa-rotate-right"></i> Refresh</a>
          </div>
        </div>

        <div class="card-body">
          <?php if (!$hasOwnerVerified): ?>
            <div class="flash flash-error"><i class="fas fa-exclamation-circle"></i> Owner verification columns not found. Run <code>install.php</code>.</div>
          <?php elseif (empty($pendingOwners)): ?>
            <div class="empty-state compact">
              <i class="fas fa-user-check"></i>
              <h3>No pending owners</h3>
              <p class="text-muted">All owners are verified.</p>
              <div class="flex flex-wrap gap-2" style="justify-content:center;margin-top:16px">
                <a class="btn btn-primary btn-sm" href="users.php?role=owner"><i class="fas fa-users"></i> Manage Owners</a>
                <?php if ($hasUserActive): ?>
                  <a class="btn btn-ghost btn-sm" href="users.php?role=owner&inactive=1"><i class="fas fa-user-slash"></i> View Inactive</a>
                <?php endif; ?>
              </div>
            </div>
          <?php else: ?>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Owner</th>
                    <th>Phone</th>
                    <th>Joined</th>
                    <?php if ($hasUserActive): ?><th>Status</th><?php endif; ?>
                    <th style="width:260px">Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingOwners as $o): $id=intval($o['id']??0); $isActive=$hasUserActive?intval($o['is_active']??1):1; ?>
                  <tr>
                    <td>
                      <div class="font-bold"><?= sanitize($o['full_name'] ?? '') ?></div>
                      <div class="text-muted text-xs"><?= sanitize($o['email'] ?? '') ?></div>
                    </td>
                    <td><?= sanitize($o['phone'] ?? '') ?></td>
                    <td class="text-muted text-sm"><?= sanitize(date('M d, Y', strtotime((string)($o['created_at'] ?? '')))) ?></td>
                    <?php if ($hasUserActive): ?>
                      <td>
                        <span class="badge" style="<?= $isActive ? 'background:rgba(27,122,74,0.12);color:var(--success)' : 'background:var(--error-bg);color:var(--error)' ?>">
                          <?= $isActive ? 'Active' : 'Inactive' ?>
                        </span>
                      </td>
                    <?php endif; ?>
                    <td>
                      <div class="flex flex-wrap gap-2">
                        <form method="POST" action="" style="display:inline">
                          <input type="hidden" name="action" value="approve">
                          <input type="hidden" name="id" value="<?= $id ?>">
                          <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-check"></i> Approve</button>
                        </form>
                        <form method="POST" action="" style="display:inline">
                          <input type="hidden" name="action" value="reject">
                          <input type="hidden" name="id" value="<?= $id ?>">
                          <button class="btn btn-ghost btn-sm" type="submit" data-confirm="Reject this owner? (Will deactivate the account)"><i class="fas fa-ban"></i> Reject</button>
                        </form>
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
