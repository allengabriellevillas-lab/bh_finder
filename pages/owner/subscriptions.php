<?php
require_once __DIR__ . '/../../includes/config.php';

requireOwner();
requireVerifiedOwner();

$uid = intval($_SESSION['user_id'] ?? 0);
$db = getDB();
ensurePaymentsSubscriptionIdColumn();
$pageTitle = 'Subscriptions';

ensureSubscriptionExpiringNotifications($uid);

$days = max(1, intval(getSetting('owner_subscription_days', '30') ?? '30'));
$basicMax = max(1, intval(getSetting('owner_subscription_basic_max_properties', '1') ?? '1'));
$basicPricing = ownerSubscriptionPricing('basic');
$proPricing = ownerSubscriptionPricing('pro');
$introActive = intval($basicPricing['is_intro'] ?? 0) === 1;
$defaultPlan = strtolower(trim((string)($_GET['plan'] ?? '')));
if (!in_array($defaultPlan, ['basic','pro'], true)) $defaultPlan = $activeSub ? strtolower((string)($activeSub['plan'] ?? 'basic')) : 'basic';
if (!in_array($defaultPlan, ['basic','pro'], true)) $defaultPlan = 'basic';

$activeSub = getActiveOwnerSubscription($uid);
$latestPending = null;
$payments = [];

try {
    $stmt = $db->prepare("SELECT * FROM owner_subscriptions WHERE owner_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$uid]);
    $latestPending = $stmt->fetch() ?: null;
} catch (Throwable $e) {
    $latestPending = null;
}

try {
    $p = $db->prepare("SELECT * FROM payments WHERE user_id = ? AND kind = 'owner_subscription' ORDER BY created_at DESC LIMIT 200");
    $p->execute([$uid]);
    $payments = $p->fetchAll() ?: [];
} catch (Throwable $e) {
    $payments = [];
}

// Handle new payment submission
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'submit_subscription_payment') {
        $plan = strtolower(trim((string)($_POST['plan'] ?? 'basic')));
        if (!in_array($plan, ['basic', 'pro'], true)) $plan = 'basic';

                $pricing = ownerSubscriptionPricing($plan);
        $amount = (float)($pricing['paid'] ?? 0);
        $originalAmount = (float)($pricing['original'] ?? $amount);
        $isIntro = intval($pricing['is_intro'] ?? 0);

        $proof = null;
        if (!empty($_FILES['proof']['name'] ?? '')) {
            $proof = uploadImage($_FILES['proof'], 'sub_' . $uid);
            if (!$proof) {
                $errors['proof'] = 'Failed to upload proof. Please upload a JPG/PNG/WebP file up to 5MB.';
            }
        } else {
        }

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                // Prefer extending an existing active subscription.
                $subId = intval($activeSub['id'] ?? 0);

                if ($subId <= 0) {
                    $insSub = $db->prepare("INSERT INTO owner_subscriptions (owner_id, plan, status) VALUES (?,?, 'pending')");
                    $insSub->execute([$uid, $plan]);
                    $subId = intval($db->lastInsertId());
                }

                $insPay = $db->prepare("INSERT INTO payments (user_id, subscription_id, kind, plan, plan_type, original_price, paid_price, is_intro, amount, method, proof_path, status)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?, 'pending')");
                $insPay->execute([
                    $uid,
                    $subId,
                    'owner_subscription',
                    $plan,
                    $plan,
                    $originalAmount,
                    $amount,
                    $isIntro,
                    $amount,
                    'proof_upload',
                    $proof,
                ]);

                $db->commit();
                setFlash('success', 'Payment submitted. An admin will review it shortly.');
                header('Location: subscriptions.php');
                exit;
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $errors['general'] = 'Unable to submit payment: ' . $e->getMessage();
            }
        }
    }
}

// Stats
$propertyCount = 0;
try {
    $stmt = $db->prepare('SELECT COUNT(*) FROM boarding_houses WHERE owner_id = ?');
    $stmt->execute([$uid]);
    $propertyCount = intval($stmt->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $propertyCount = 0;
}

$showNavbar = false;
require_once __DIR__ . '/../../includes/header.php';
?>

<?php $me = getCurrentUser(); ?>
<div class="dash-shell">
<?php $activeNav = 'subscriptions'; include __DIR__ . '/_partials/sidebar.php'; ?>

  <div class="dash-main">
<?php include __DIR__ . '/_partials/topbar.php'; ?>

    <div class="dash-content">
      <div class="dash-heading">
        <div>
          <h1 class="dash-title">Subscriptions</h1>
          <div class="dash-breadcrumb">
            <a href="dashboard.php">Dashboard</a>
            <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
            <span>Subscriptions</span>
          </div>
        </div>
      </div>

      <main>
        <div class="card">
          <div class="card-header">
            <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">Your Plan</h2>
            <div class="text-muted text-sm" style="margin-top:4px">Subscriptions are per property (rooms are free). You need an active subscription to add a new property.</div>
          </div>
          <div class="card-body">

            <?php if (!empty($errors['general'])): ?>
              <div class="flash flash-error mb-3"><i class="fas fa-exclamation-circle"></i><?= sanitize($errors['general']) ?></div>
            <?php endif; ?>

            <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px">
              <div class="card" style="margin:0">
                <div class="card-body">
                  <div class="text-muted text-xs">Status</div>
                  <?php if ($activeSub): ?>
                    <div style="font-weight:900;font-size:1.1rem;margin-top:4px">ACTIVE</div>
                    <div class="text-muted text-sm" style="margin-top:4px">Plan: <strong><?= strtoupper(sanitize((string)($activeSub['plan'] ?? 'basic'))) ?></strong></div>
                    <div class="text-muted text-sm">Expires: <strong><?= sanitize(date('M d, Y', strtotime((string)($activeSub['end_date'] ?? '')))) ?></strong></div>
                  <?php elseif ($latestPending): ?>
                    <div style="font-weight:900;font-size:1.1rem;margin-top:4px">PENDING</div>
                    <div class="text-muted text-sm" style="margin-top:4px">Waiting for admin approval.</div>
                  <?php else: ?>
                    <div style="font-weight:900;font-size:1.1rem;margin-top:4px">INACTIVE</div>
                    <div class="text-muted text-sm" style="margin-top:4px">Subscribe to start listing properties.</div>
                  <?php endif; ?>
                </div>
              </div>

              <div class="card" style="margin:0">
                <div class="card-body">
                  <div class="text-muted text-xs">Your Properties</div>
                  <div style="font-weight:900;font-size:1.1rem;margin-top:4px"><?= intval($propertyCount) ?></div>
                  <div class="text-muted text-sm" style="margin-top:4px">Basic plan limit: <strong><?= intval($basicMax) ?></strong> properties</div>
                  <div class="text-muted text-sm">Pro plan: <strong>Unlimited</strong></div>
                </div>
              </div>

              <div class="card" style="margin:0">
                <div class="card-body">
                  <div class="text-muted text-xs">Billing Cycle</div>
                  <div style="font-weight:900;font-size:1.1rem;margin-top:4px"><?= intval($days) ?> days</div>
                  <?php $introEnd = trim((string)(getSetting('intro_end_date', '2026-12-31') ?? '2026-12-31')); ?>
                  <?php if ($introActive): ?>
                    <div class="badge status-pending" style="display:inline-flex;margin-top:10px;gap:6px"><i class="fas fa-bolt"></i> Limited time offer</div>
                    <div class="text-muted text-xs" style="margin-top:6px">&#127881; Intro Offer! Early adopter discount<?= $introEnd !== '' ? (' &middot; Ends ' . sanitize($introEnd)) : '' ?></div>

                    <div class="text-muted text-sm" style="margin-top:10px">
                      Basic: <strong><?= formatPrice((float)($basicPricing['paid'] ?? 0)) ?></strong>
                      <span class="text-muted" style="margin-left:6px;text-decoration:line-through">Regular <?= formatPrice((float)($basicPricing['original'] ?? 0)) ?></span>
                    </div>
                    <div class="text-muted text-sm">
                      Pro: <strong><?= formatPrice((float)($proPricing['paid'] ?? 0)) ?></strong>
                      <span class="text-muted" style="margin-left:6px;text-decoration:line-through">Regular <?= formatPrice((float)($proPricing['original'] ?? 0)) ?></span>
                    </div>
                  <?php else: ?>
                    <div class="text-muted text-sm" style="margin-top:10px">Basic: <?= formatPrice((float)($basicPricing['original'] ?? 0)) ?></div>
                    <div class="text-muted text-sm">Pro: <?= formatPrice((float)($proPricing['original'] ?? 0)) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <hr style="border:none;border-top:1px solid var(--border);margin:18px 0">

            <h3 style="font-family:var(--font-display);font-size:1rem;margin:0 0 10px">Submit a payment</h3>

            <div class="be-subscribe-grid" style="margin-bottom:14px">
              <div class="be-subscribe-card">
                <h4 class="be-subscribe-title"><i class="fab fa-paypal"></i> Pay with PayPal</h4>
                <p class="text-muted text-sm" style="margin-top:6px">Instant activation after successful PayPal checkout.</p>
                <form method="POST" action="paypal_owner_sub_start.php" style="margin-top:12px">
                  <div class="form-row" style="margin-bottom:10px">
                    <div class="form-group" style="margin:0">
                      <label class="form-label">Plan</label>
                      <select name="plan" class="form-control">
                        <option value="basic" <?= $defaultPlan === 'basic' ? 'selected' : '' ?>>Basic (<?= formatPrice((float)($basicPricing['paid'] ?? 0)) ?><?= $introActive ? (' · Regular ' . formatPrice((float)($basicPricing['original'] ?? 0))) : '' ?> · up to <?= intval($basicMax) ?> properties)</option>
                        <option value="pro" <?= $defaultPlan === 'pro' ? 'selected' : '' ?>>Pro (<?= formatPrice((float)($proPricing['paid'] ?? 0)) ?><?= $introActive ? (' · Regular ' . formatPrice((float)($proPricing['original'] ?? 0))) : '' ?> · unlimited properties)</option>
                      </select>
                    </div>
                  </div>
                  <button class="btn btn-primary" type="submit" title="<?= paypalEnabled() ? "Pay with PayPal Sandbox" : "PayPal is not configured yet. Click to see setup instructions." ?>">
                    <i class="fab fa-paypal"></i> Pay with PayPal (Sandbox)
                  </button>
                </form>
              </div>

              <div class="be-subscribe-card">
                <h4 class="be-subscribe-title"><i class="fas fa-receipt"></i> Upload proof</h4>
                <p class="text-muted text-sm" style="margin-top:6px">If you paid outside PayPal, upload a receipt for admin approval.</p>

                <form method="POST" action="" enctype="multipart/form-data" style="margin-top:12px" data-validate>
                  <input type="hidden" name="action" value="submit_subscription_payment">
                  <input type="hidden" name="plan" id="proofPlan" value="<?= sanitize($defaultPlan) ?>">

                  <div class="form-group" style="margin:0 0 12px">
                    <label class="form-label">Receipt <span class="required">*</span></label>
                    <div class="be-file <?= isset($errors['proof']) ? 'is-error' : '' ?>">
                      <input id="proofFile" type="file" name="proof" accept="image/*" class="sr-only be-file-input" required>
                      <label class="be-file-btn" for="proofFile"><i class="fas fa-upload"></i> Choose file</label>
                      <span class="be-file-name" aria-live="polite"></span>
                    </div>
                    <?php if (!empty($errors['proof'])): ?><p class="form-error"><i class="fas fa-exclamation-circle"></i><?= sanitize($errors['proof']) ?></p><?php endif; ?>
                  </div>

                  <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Receipt</button>
                </form>
              </div>
            </div>
          </div>
        </div>

        <div class="card" style="margin-top:18px">
          <div class="card-header">
            <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">Payment History</h2>
            <div class="text-muted text-sm" style="margin-top:4px">Your most recent subscription payments.</div>
          </div>
          <div class="card-body">
            <?php if (empty($payments)): ?>
              <div class="empty-state compact">
                <i class="fas fa-receipt"></i>
                <h3>No payments yet</h3>
                <p>Submit a payment above to activate your subscription.</p>
              </div>
            <?php else: ?>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Plan</th>
                      <th>Amount</th>
                      <th>Method</th>
                      <th>Status</th>
                      <th style="width:180px">Date</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($payments as $p):
                      $st = strtolower((string)($p['status'] ?? 'pending'));
                      $badge = $st === 'approved' ? 'status-active' : ($st === 'rejected' ? 'status-full' : 'status-pending');
                      $created = (string)($p['created_at'] ?? '');
                    ?>
                      <tr>
                        <td class="font-bold"><?= strtoupper(sanitize((string)($p['plan'] ?? 'basic'))) ?></td>
                        <td><?= formatPrice((float)($p['amount'] ?? 0)) ?></td>
                        <td class="text-muted text-sm"><?= sanitize((string)($p['method'] ?? '')) ?></td>
                        <td><span class="badge <?= $badge ?>"><?= strtoupper($st) ?></span></td>
                        <td class="text-muted text-sm"><?= $created !== '' ? sanitize(date('M d, Y H:i', strtotime($created))) : '&mdash;' ?></td>
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






