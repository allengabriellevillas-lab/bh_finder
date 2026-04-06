<?php
require_once __DIR__ . '/_common.php';

$pageTitle = 'Payments';

$hasPayments = true;
try { $db->query("SELECT 1 FROM payments LIMIT 1"); } catch (Throwable $e) { $hasPayments = false; }

$hasOwnerSubs = true;
try { $db->query("SELECT 1 FROM owner_subscriptions LIMIT 1"); } catch (Throwable $e) { $hasOwnerSubs = false; }

$days = max(1, intval(getSetting('owner_subscription_days', '30') ?? '30'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasPayments) {
    $action = trim((string)($_POST['action'] ?? ''));
    $id = intval($_POST['id'] ?? 0);
    $note = trim((string)($_POST['admin_note'] ?? ''));

    if ($id > 0 && in_array($action, ['approve','reject'], true)) {
        try {
            $stmt = $db->prepare("SELECT p.*, u.full_name, u.email
              FROM payments p
              JOIN users u ON u.id = p.user_id
              WHERE p.id = ?");
            $stmt->execute([$id]);
            $pay = $stmt->fetch();
            if (!$pay) throw new RuntimeException('Payment not found.');
            if (($pay['status'] ?? '') !== 'pending') throw new RuntimeException('Payment is not pending.');

            $kind = (string)($pay['kind'] ?? 'owner_subscription');

            if ($action === 'approve') {
                $db->beginTransaction();

                $db->prepare("UPDATE payments
                    SET status='approved', admin_note=?, reviewed_by=?, reviewed_at=NOW()
                    WHERE id = ? AND status='pending'")
                    ->execute([$note !== '' ? $note : null, intval($_SESSION['user_id']), $id]);

                if ($kind === 'owner_subscription') {
                    if (!$hasOwnerSubs) throw new RuntimeException('Missing owner_subscriptions table. Run install.php.');

                    $subId = intval($pay['subscription_id'] ?? 0);
                    if ($subId <= 0) throw new RuntimeException('Missing subscription_id.');

                    $plan = strtolower((string)($pay['plan'] ?? ''));
                    if (!in_array($plan, ['basic','pro'], true)) $plan = 'basic';

                    // Activate/extend owner subscription
                    $upd = $db->prepare("UPDATE owner_subscriptions
                      SET plan = ?,
                          status='active',
                          start_date = IF(start_date IS NULL, CURDATE(), start_date),
                          end_date = IF(end_date IS NOT NULL AND end_date >= CURDATE(), DATE_ADD(end_date, INTERVAL ? DAY), DATE_ADD(CURDATE(), INTERVAL ? DAY))
                      WHERE id = ?");
                    $upd->execute([$plan, $days, $days, $subId]);

                    $subRow = null;
                    try {
                        $q = $db->prepare('SELECT * FROM owner_subscriptions WHERE id = ? LIMIT 1');
                        $q->execute([$subId]);
                        $subRow = $q->fetch() ?: null;
                    } catch (Throwable $e) {
                        $subRow = null;
                    }

                    if ($subRow) {
                        $ownerId = intval($subRow['owner_id'] ?? 0);
                        if ($ownerId > 0) {
                            // No longer controls listing visibility; keep linkage + ensure verified owners remain active.
                            syncOwnerPropertiesToSubscription($ownerId, $subRow);
                            if (notificationsEnabled()) {
                                createNotification(
                                    $ownerId,
                                    'subscription_approved',
                                    'Pro upgrade approved',
                                    'Your Pro upgrade payment was approved. Pro features are now active on your account.',
                                    SITE_URL . '/pages/owner/subscriptions.php'
                                );
                            }
                        }
                    }

                    adminLog($db, 'owner_subscription_payment_approved', 'payments', $id, ['subscription_id' => $subId, 'plan' => $plan]);
                    setFlash('success', 'Payment approved and Pro upgrade activated.');
                } elseif ($kind === 'listing_boost') {
                    ensureFeaturedListingColumns();

                    $ownerId = intval($pay['user_id'] ?? 0);
                    $listingId = intval($pay['listing_id'] ?? 0);
                    if ($listingId <= 0) throw new RuntimeException('Missing listing_id.');

                    $itemKey = trim((string)($pay['item_key'] ?? 'boost_7d'));
                    if (!in_array($itemKey, ['boost_7d', 'homepage_featured'], true)) $itemKey = 'boost_7d';
                    $boostDays = max(1, intval(getSetting('boost_days', '7') ?? '7'));

                    if ($itemKey === 'homepage_featured') {
                        $upd = $db->prepare("UPDATE boarding_houses
                          SET is_featured = 1,
                              featured_until = DATE_ADD(NOW(), INTERVAL ? DAY)
                          WHERE id = ?");
                        $upd->execute([$boostDays, $listingId]);
                    } else {
                        $upd = $db->prepare("UPDATE boarding_houses
                          SET is_featured = 1,
                              boost_until = DATE_ADD(NOW(), INTERVAL ? DAY)
                          WHERE id = ?");
                        $upd->execute([$boostDays, $listingId]);
                    }

                    if (notificationsEnabled() && $ownerId > 0) {
                        createNotification(
                            $ownerId,
                            'listing_boost_approved',
                            'Listing boost approved',
                            'Your listing boost payment was approved. Your listing will be prioritized in search.',
                            SITE_URL . '/pages/detail.php?id=' . $listingId
                        );
                    }

                    adminLog($db, 'listing_boost_payment_approved', 'payments', $id, ['listing_id' => $listingId, 'item_key' => $itemKey]);
                    setFlash('success', 'Payment approved and listing boosted.');
                } elseif ($kind === 'service_fee') {
                    $ownerId = intval($pay['user_id'] ?? 0);
                    if (notificationsEnabled() && $ownerId > 0) {
                        createNotification(
                            $ownerId,
                            'service_fee_approved',
                            'Service fee approved',
                            'Your service fee record was approved.',
                            SITE_URL . '/pages/owner/dashboard.php'
                        );
                    }
                    adminLog($db, 'service_fee_payment_approved', 'payments', $id);
                    setFlash('success', 'Payment approved.');
                } else {
                    adminLog($db, 'payment_approved', 'payments', $id, ['kind' => $kind]);
                    setFlash('success', 'Payment approved.');
                }
                $db->commit();
            } else {
                $db->prepare("UPDATE payments
                    SET status='rejected', admin_note=?, reviewed_by=?, reviewed_at=NOW()
                    WHERE id = ? AND status='pending'")
                    ->execute([$note !== '' ? $note : null, intval($_SESSION['user_id']), $id]);

                if ($kind === 'owner_subscription') {
                    $subId = intval($pay['subscription_id'] ?? 0);
                    if ($subId > 0 && $hasOwnerSubs) {
                        try {
                            $db->prepare("UPDATE owner_subscriptions
                              SET status = CASE WHEN status='pending' THEN 'rejected' ELSE status END
                              WHERE id = ?")
                              ->execute([$subId]);
                        } catch (Throwable $e) {
                            // ignore
                        }
                    }

                    if (notificationsEnabled()) {
                        $ownerId = intval($pay['user_id'] ?? 0);
                        createNotification(
                            $ownerId,
                            'subscription_rejected',
                            'Payment rejected',
                            'Your subscription payment was rejected. Please submit a new proof of payment.',
                            SITE_URL . '/pages/owner/subscriptions.php'
                        );
                    }

                    adminLog($db, 'owner_subscription_payment_rejected', 'payments', $id, ['subscription_id' => $subId]);
                    setFlash('success', 'Payment rejected.');
                } else {
                    adminLog($db, 'payment_rejected', 'payments', $id, ['kind' => $kind]);
                    setFlash('success', 'Payment rejected.');
                }
            }

            if ($db->inTransaction()) $db->commit();
            header('Location: payments.php');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            setFlash('error', $e->getMessage() ?: 'Unable to process payment.');
            header('Location: payments.php');
            exit;
        }
    }
}

$status = trim((string)($_GET['status'] ?? ''));
if (!in_array($status, ['', 'pending', 'approved', 'rejected'], true)) $status = '';

$kindFilter = trim((string)($_GET['kind'] ?? ''));
$allowedKinds = ['', 'owner_subscription', 'service_fee', 'listing_boost', 'room_subscription'];
if (!in_array($kindFilter, $allowedKinds, true)) $kindFilter = '';
unset($allowedKinds);
$rows = [];
if ($hasPayments) {
    $where = "WHERE 1=1";
    $params = [];
    if ($kindFilter !== '') { $where .= " AND p.kind = ?"; $params[] = $kindFilter; }
    if ($status !== '') { $where .= " AND p.status = ?"; $params[] = $status; }

    try {
        $stmt = $db->prepare("SELECT p.*, u.full_name, u.email
          FROM payments p
          JOIN users u ON u.id = p.user_id
          $where
          ORDER BY p.created_at DESC
          LIMIT 400");
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="dash-shell">
  <?php adminSidebar('payments'); ?>

  <div class="dash-main">
    <?php adminTopbar(); ?>

    <div class="dash-content">
      <div class="dash-heading">
        <div>
          <h1 class="dash-title">Payments</h1>
          <div class="dash-breadcrumb">
            <a href="dashboard.php">Admin</a>
            <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
            <span>Payments</span>
          </div>
        </div>
      </div>

      <main>
        <div class="card">
          <div class="card-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div>
              <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">Payments</h2>
              <div class="text-muted text-sm" style="margin-top:4px">Approve and track all payment types (Pro upgrades, boosts, service fees).</div>
            </div>

            <form method="GET" action="" class="card-filters">
                            <select name="kind" class="form-control">
                <option value="" <?= $kindFilter===''?'selected':'' ?>>All kinds</option>
                <option value="owner_subscription" <?= $kindFilter==='owner_subscription'?'selected':'' ?>>Pro upgrade</option>
                <option value="listing_boost" <?= $kindFilter==='listing_boost'?'selected':'' ?>>Listing boost</option>
                <option value="service_fee" <?= $kindFilter==='service_fee'?'selected':'' ?>>Service fee</option>
                <option value="room_subscription" <?= $kindFilter==='room_subscription'?'selected':'' ?>>Room subscription</option>
              </select>              <select name="status" class="form-control">
                <option value="" <?= $status===''?'selected':'' ?>>All</option>
                <option value="pending" <?= $status==='pending'?'selected':'' ?>>Pending</option>
                <option value="approved" <?= $status==='approved'?'selected':'' ?>>Approved</option>
                <option value="rejected" <?= $status==='rejected'?'selected':'' ?>>Rejected</option>
              </select>
              <div class="filter-row">
                <button class="btn btn-ghost btn-sm" type="submit"><i class="fas fa-filter"></i> Filter</button>
                <a class="btn btn-ghost btn-sm" href="payments.php"><i class="fas fa-rotate-left"></i> Reset</a>
              </div>
            </form>
          </div>

          <div class="card-body">
            <?php if (!$hasPayments): ?>
              <div class="flash flash-error"><i class="fas fa-exclamation-circle"></i> Missing <code>payments</code> table. Run <code>install.php</code>.</div>
            <?php elseif (empty($rows)): ?>
              <div class="empty-state compact">
                <i class="fas fa-receipt"></i>
                <h3>No payments found</h3>
                <p class="text-muted">No payments match your filter.</p>
              </div>
            <?php else: ?>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>User</th>
                      <th>Kind / Proof</th>
                      <th>Amount</th>
                      <th>Method</th>
                      <th>Status</th>
                      <th>Submitted</th>
                      <th style="width:320px">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rows as $r):
                      $id = intval($r['id'] ?? 0);
                      $st = strtolower((string)($r['status'] ?? 'pending'));
                      $badge = $st === 'approved' ? 'status-active' : ($st === 'rejected' ? 'status-full' : 'status-pending');
                      $proof = (string)($r['proof_path'] ?? '');
                      $plan = strtoupper(sanitize((string)($r['plan'] ?? 'basic')));
                      $created = (string)($r['created_at'] ?? '');
                    ?>
                      <tr>
                        <td>
                          <div class="font-bold"><?= sanitize($r['full_name'] ?? '') ?></div>
                          <div class="text-muted text-xs"><?= sanitize($r['email'] ?? '') ?></div>
                        </td>
                        <td>
                          <div class="font-bold"><?= $plan ?></div>
                          <?php if ($proof !== ''): ?>
                            <div class="text-muted text-xs" style="margin-top:6px">
                              <a class="btn btn-ghost btn-sm btn-icon" href="<?= UPLOAD_URL . sanitize($proof) ?>" target="_blank" rel="noopener" title="View proof" aria-label="View proof"><i class="fas fa-image"></i></a>
                            </div>
                          <?php endif; ?>
                        </td>
                        <td><?= formatPrice((float)($r['amount'] ?? 0)) ?></td>
                        <td><span class="badge" style="background:var(--bg);border:1px solid var(--border)"><?= sanitize($r['method'] ?? '') ?></span></td>
                        <td><span class="badge <?= $badge ?>"><?= strtoupper($st) ?></span></td>
                        <td class="text-muted text-xs"><?= $created !== '' ? sanitize(date('M d, Y H:i', strtotime($created))) : '&mdash;' ?></td>
                        <td>
                          <?php if ($st !== 'pending'): ?>
                            <div class="text-muted text-sm">Reviewed</div>
                            <?php if (!empty($r['admin_note'])): ?>
                              <div class="text-muted text-xs" style="margin-top:6px">Note: <?= sanitize((string)$r['admin_note']) ?></div>
                            <?php endif; ?>
                          <?php else: ?>
                            <form method="POST" action="" style="display:flex;flex-direction:column;gap:8px">
                              <input type="hidden" name="id" value="<?= $id ?>">
                              <textarea name="admin_note" class="form-control" rows="2" placeholder="Optional admin note..."></textarea>
                              <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <button class="btn btn-primary btn-sm btn-icon" type="submit" name="action" value="approve" title="Approve" aria-label="Approve"><i class="fas fa-check"></i></button>
                                <button class="btn btn-ghost btn-sm btn-icon" type="submit" name="action" value="reject" title="Reject" aria-label="Reject"><i class="fas fa-xmark"></i></button>
                              </div>
                            </form>
                          <?php endif; ?>
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




