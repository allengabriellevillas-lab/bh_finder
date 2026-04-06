<?php
require_once __DIR__ . '/_common.php';

$pageTitle = 'Owner Verification';

$hasOwnerVerified = adminTableHasColumn($db, 'users', 'owner_verified');
$hasOwnerVStatus = adminTableHasColumn($db, 'users', 'owner_verification_status');
$hasOwnerIdDoc = adminTableHasColumn($db, 'users', 'owner_id_doc_path');
$hasOwnerVReason = adminTableHasColumn($db, 'users', 'owner_verification_reason');
$hasUserActive = adminTableHasColumn($db, 'users', 'is_active');

if (!$hasOwnerVerified && !$hasOwnerVStatus) {
    setFlash('error', 'Your database schema does not support owner verification yet. Run install.php to update schema.');
}

$totalOwners = 0;
$verifiedOwners = 0;
$pendingCount = 0;
$inactiveOwners = 0;
try {
    $totalOwners = intval($db->query("SELECT COUNT(*) FROM users WHERE role='owner'")->fetchColumn() ?: 0);

    if ($hasOwnerVStatus) {
        $verifiedOwners = intval($db->query("SELECT COUNT(*) FROM users WHERE role='owner' AND owner_verification_status = 'verified'")->fetchColumn() ?: 0);
        $pendingCount = intval($db->query("SELECT COUNT(*) FROM users WHERE role='owner' AND (owner_verification_status IS NULL OR owner_verification_status = 'pending')")->fetchColumn() ?: 0);
    } elseif ($hasOwnerVerified) {
        $verifiedOwners = intval($db->query("SELECT COUNT(*) FROM users WHERE role='owner' AND owner_verified = 1")->fetchColumn() ?: 0);
        $pendingCount = intval($db->query("SELECT COUNT(*) FROM users WHERE role='owner' AND owner_verified = 0")->fetchColumn() ?: 0);
    }

    if ($hasUserActive) {
        $inactiveOwners = intval($db->query("SELECT COUNT(*) FROM users WHERE role='owner' AND is_active = 0")->fetchColumn() ?: 0);
    }
} catch (Throwable $e) {
    // ignore
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($hasOwnerVerified || $hasOwnerVStatus)) {
    $action = $_POST['action'] ?? '';
    $id = intval($_POST['id'] ?? 0);

    if ($id > 0) {
        if ($action === 'approve') {
            if ($hasOwnerVStatus) {
                $stmt = $db->prepare("UPDATE users
                  SET owner_verification_status = 'verified',
                      owner_verification_reason = NULL,
                      owner_verified = 1,
                      owner_verified_at = NOW()
                  WHERE id = ? AND role = 'owner'");
                $stmt->execute([$id]);
            } else {
                $stmt = $db->prepare("UPDATE users SET owner_verified = 1, owner_verified_at = NOW() WHERE id = ? AND role = 'owner'");
                $stmt->execute([$id]);
            }
            adminLog($db, 'owner_verified', 'users', $id);
            // Auto-start owner trial after approval (best-effort)
            $trialStarted = false;
            try { $trialStarted = startOwnerTrialIfMissing($id, 14); } catch (Throwable $e) { $trialStarted = false; }

            // Notification (best-effort)
            try {
                if (notificationsEnabled()) {
                    $body = $trialStarted
                        ? 'Your owner verification has been approved. Your 14-day trial is now active.'
                        : 'Your owner verification has been approved.';
                    createNotification(
                        $id,
                        'owner_verified',
                        'Verification approved',
                        $body,
                        SITE_URL . '/pages/owner/dashboard.php'
                    );
                }
            } catch (Throwable $e) {
                // ignore
            }
            setFlash('success', 'Owner approved.');
        }

        if ($action === 'reject') {
            $reason = trim((string)($_POST['reason'] ?? ''));

            if ($hasOwnerVStatus) {
                $stmt = $db->prepare("UPDATE users
                  SET owner_verification_status = 'rejected',
                      owner_verification_reason = ?,
                      owner_verified = 0,
                      owner_verified_at = NULL
                  WHERE id = ? AND role = 'owner'");
                $stmt->execute([$reason !== '' ? $reason : null, $id]);
                adminLog($db, 'owner_rejected', 'users', $id);
                // Notification (best-effort)
                try {
                    if (notificationsEnabled()) {
                        $msg = 'Your owner verification was rejected.';
                        if ($reason !== '') $msg .= ' Reason: ' . $reason;
                        createNotification(
                            $id,
                            'owner_verification_rejected',
                            'Verification rejected',
                            $msg,
                            SITE_URL . '/pages/owner/verification.php'
                        );
                    }
                } catch (Throwable $e) {
                    // ignore
                }
                setFlash('success', 'Owner rejected.');
            } else {
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
    }

    header('Location: owners.php');
    exit;
}

$pendingOwners = [];
if ($hasOwnerVStatus || $hasOwnerVerified) {
    $cols = 'id, full_name, email, phone, created_at';
    if ($hasUserActive) $cols .= ', is_active';
    if ($hasOwnerIdDoc) $cols .= ', owner_id_doc_path';
    if ($hasOwnerVStatus) $cols .= ', owner_verification_status, owner_verification_reason';

    if ($hasOwnerVStatus) {
        $pendingOwners = $db->query("SELECT $cols FROM users WHERE role='owner' AND (owner_verification_status IS NULL OR owner_verification_status = 'pending') ORDER BY created_at DESC")->fetchAll() ?: [];
    } else {
        $pendingOwners = $db->query("SELECT $cols FROM users WHERE role='owner' AND owner_verified = 0 ORDER BY created_at DESC")->fetchAll() ?: [];
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="dash-shell">
  <?php adminSidebar('owners'); ?>
  <div class="dash-main">
    <?php adminTopbar(); ?>
    <div class="dash-content">
      <div class="dash-heading">
        <div>
          <h1 class="dash-title">Owner Verification</h1>
          <div class="dash-breadcrumb">
            <a href="dashboard.php">Admin</a>
            <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
            <span>Owners</span>
          </div>
        </div>
      </div>

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
            <?php if (!$hasOwnerVerified && !$hasOwnerVStatus): ?>
              <div class="flash flash-error"><i class="fas fa-exclamation-circle"></i> Owner verification columns not found. Run <code>install.php</code>.</div>
            <?php elseif (empty($pendingOwners)): ?>
              <div class="empty-state compact">
                <i class="fas fa-user-check"></i>
                <h3>No pending owners</h3>
                <p class="text-muted">All owners are verified.</p>
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
                      <th style="width:280px">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($pendingOwners as $o):
                      $id = intval($o['id'] ?? 0);
                      $isActive = $hasUserActive ? intval($o['is_active'] ?? 1) : 1;
                    ?>
                      <tr>
                        <td>
                          <div class="font-bold"><?= sanitize($o['full_name'] ?? '') ?></div>
                          <div class="text-muted text-xs"><?= sanitize($o['email'] ?? '') ?></div>
                          <?php if ($hasOwnerIdDoc && !empty($o['owner_id_doc_path'])): ?>
                            <div class="text-muted text-xs" style="margin-top:6px">
                              <a href="<?= UPLOAD_URL . sanitize((string)$o['owner_id_doc_path']) ?>" target="_blank" rel="noopener" title="View ID" aria-label="View ID">
                                <i class="fas fa-id-card"></i><span class="sr-only">View ID</span>
                              </a>
                            </div>
                          <?php endif; ?>
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
                              <button class="btn btn-primary btn-sm btn-icon" type="submit" title="Approve" aria-label="Approve"><i class="fas fa-check"></i></button>
                            </form>

                            <form method="POST" action="" style="display:inline">
                              <input type="hidden" name="action" value="reject">
                              <input type="hidden" name="id" value="<?= $id ?>">
                              <?php if ($hasOwnerVStatus): ?>
                                <input type="hidden" name="reason" value="Invalid / insufficient ID">
                                <button class="btn btn-ghost btn-sm btn-icon" type="submit" title="Reject" aria-label="Reject" data-confirm="Reject this owner?"><i class="fas fa-ban"></i></button>
                              <?php else: ?>
                                <button class="btn btn-ghost btn-sm btn-icon" type="submit" title="Reject" aria-label="Reject" data-confirm="Reject this owner? (Will deactivate the account)"><i class="fas fa-ban"></i></button>
                              <?php endif; ?>
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
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>






