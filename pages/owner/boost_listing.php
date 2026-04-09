<?php
require_once __DIR__ . '/../../includes/config.php';
requireOwner();
requireVerifiedOwner();

$db = getDB();
ensurePaymentsSubscriptionIdColumn();
ensureFeaturedListingColumns();

$uid = intval($_SESSION['user_id'] ?? 0);
$bhId = intval($_GET['bh_id'] ?? ($_GET['id'] ?? 0));
if ($bhId <= 0) {
    setFlash('error', 'Missing listing id.');
    header('Location: ' . SITE_URL . '/pages/owner/dashboard.php');
    exit;
}

$listing = null;
try {
    $stmt = $db->prepare("SELECT bh.*, (SELECT pi.image_path FROM boarding_house_images pi WHERE pi.boarding_house_id = bh.id AND pi.is_cover = 1 LIMIT 1) AS cover_image
      FROM boarding_houses bh
      WHERE bh.id = ? AND bh.owner_id = ?
      LIMIT 1");
    $stmt->execute([$bhId, $uid]);
    $listing = $stmt->fetch() ?: null;
} catch (Throwable $e) {
    $listing = null;
}

if (!$listing) {
    setFlash('error', 'Listing not found.');
    header('Location: ' . SITE_URL . '/pages/owner/dashboard.php');
    exit;
}

$pageTitle = 'Boost Listing';
$errors = [];

$amount7d = (float)(getSetting('boost_amount_7d', '150') ?? '150');
$amountFeatured = (float)(getSetting('boost_amount_featured', '300') ?? '300');
$days = max(1, intval(getSetting('boost_days', '7') ?? '7'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'submit_boost') {
        $itemKey = trim((string)($_POST['item_key'] ?? ''));
        if (!in_array($itemKey, ['boost_7d', 'homepage_featured'], true)) {
            $errors['item_key'] = 'Invalid boost option.';
        }

        $amount = $itemKey === 'homepage_featured' ? $amountFeatured : $amount7d;
        if ($amount <= 0) {
            $errors['amount'] = 'Boost pricing is not configured.';
        }

        $proof = null;
        if (!empty($_FILES['proof']['name'] ?? '')) {
            $proof = uploadImage($_FILES['proof'], 'boost_' . $uid . '_' . $bhId);
            if (!$proof) {
                $errors['proof'] = 'Failed to upload proof. Please upload a JPG/PNG/WebP file up to 5MB.';
            }
        } else {
            $errors['proof'] = 'Proof of payment is required.';
        }

        if (empty($errors)) {
            try {
                $note = $itemKey === 'homepage_featured'
                    ? ('Homepage featured boost (' . $days . ' days)')
                    : ('Priority boost (' . $days . ' days)');

                $ins = $db->prepare("INSERT INTO payments (user_id, listing_id, kind, item_key, amount, method, proof_path, status, admin_note)
                  VALUES (?,?,?,?,?,?,?,?,?)");
                $ins->execute([
                    $uid,
                    $bhId,
                    'listing_boost',
                    $itemKey,
                    $amount,
                    'proof_upload',
                    $proof,
                    'pending',
                    $note,
                ]);

                setFlash('success', 'Boost payment submitted. An admin will review it shortly.');
                header('Location: ' . SITE_URL . '/pages/owner/dashboard.php');
                exit;
            } catch (Throwable $e) {
                if ($proof) deleteUploadedFile($proof);
                $errors['general'] = 'Unable to submit boost payment: ' . ($e->getMessage() ?: 'Unknown error');
            }
        }
    }
}

$showNavbar = false;
require_once __DIR__ . '/../../includes/header.php';
?>

<?php $me = getCurrentUser(); ?>
<div class="dash-shell">
<?php $activeNav = 'dashboard'; include __DIR__ . '/_partials/sidebar.php'; ?>

  <div class="dash-main">
<?php include __DIR__ . '/_partials/topbar.php'; ?>

    <div class="dash-content">
      <div class="dash-heading">
        <div>
          <h1 class="dash-title">Boost Listing</h1>
          <div class="dash-breadcrumb">
            <a href="dashboard.php">Dashboard</a>
            <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
            <span>Boost</span>
          </div>
        </div>
      </div>

      <main>
        <?php if (!empty($errors['general'])): ?>
          <div class="flash flash-error"><i class="fas fa-exclamation-circle"></i><?= sanitize($errors['general']) ?></div>
        <?php endif; ?>

        <div class="card">
          <div class="card-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div>
              <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800"><?= sanitize($listing['name'] ?? 'Listing') ?></h2>
              <div class="text-muted text-sm" style="margin-top:4px">Boost your visibility in search and on the homepage.</div>
            </div>
            <a class="btn btn-ghost btn-sm" href="dashboard.php"><i class="fas fa-arrow-left"></i> Back</a>
          </div>

          <div class="card-body">
            <form method="POST" action="" enctype="multipart/form-data" data-validate>
              <input type="hidden" name="action" value="submit_boost">

              <div class="form-group">
                <label class="form-label">Boost Option <span class="required">*</span></label>
                <select class="form-control <?= isset($errors['item_key']) ? 'error' : '' ?>" name="item_key" required>
                  <option value="boost_7d">₱<?= number_format($amount7d, 0) ?> · Boost for <?= intval($days) ?> days (appears higher)</option>
                  <option value="homepage_featured">₱<?= number_format($amountFeatured, 0) ?> · Homepage featured for <?= intval($days) ?> days</option>
                </select>
                <?php if (isset($errors['item_key'])): ?><p class="form-error"><i class="fas fa-exclamation-circle"></i><?= sanitize($errors['item_key']) ?></p><?php endif; ?>
              </div>

              <div class="form-group">
                <label class="form-label">Proof of Payment (JPG/PNG/WebP, max 5MB) <span class="required">*</span></label>
                <div class="file-upload file-upload-compact <?= isset($errors['proof']) ? 'is-error' : '' ?>" aria-label="Upload proof of payment">
  <input id="boostProof" type="file" name="proof" accept="image/*" required>
  <div class="file-upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
  <p class="file-upload-text"><strong>Click to upload</strong> or drag & drop</p>
  <p class="file-upload-text file-upload-name" style="font-size:.76rem;margin-top:4px"></p>
</div>
                <?php if (isset($errors['proof'])): ?><p class="form-error"><i class="fas fa-exclamation-circle"></i><?= sanitize($errors['proof']) ?></p><?php endif; ?>
                <?php if (isset($errors['amount'])): ?><p class="form-error"><i class="fas fa-exclamation-circle"></i><?= sanitize($errors['amount']) ?></p><?php endif; ?>
              </div>

              <button class="btn btn-primary" type="submit"><i class="fas fa-rocket"></i> Submit Boost Payment</button>
            </form>

            <div class="text-muted text-xs" style="margin-top:12px">
              After admin approval, boosted listings appear earlier in search. Pro owners also get priority ranking.
            </div>
          </div>
        </div>
      </main>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

