<?php
require_once __DIR__ . '/../includes/config.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . SITE_URL . '/index.php');
    exit;
}

$db = getDB();

$bhCols = $db->query("SHOW COLUMNS FROM boarding_houses")->fetchAll() ?: [];
$bhFields = array_map(fn($r) => (string)($r['Field'] ?? ''), $bhCols);
$hasApprovalStatus = in_array('approval_status', $bhFields, true);
$approvalWhere = "";
if ($hasApprovalStatus) {
    if (isAdmin()) {
        $approvalWhere = "";
    } elseif (isLoggedIn()) {
        $approvalWhere = " AND (bh.approval_status = 'approved' OR bh.owner_id = " . intval($_SESSION['user_id']) . ")";
    } else {
        $approvalWhere = " AND bh.approval_status = 'approved'";
    }
}
$hasViews = in_array('views', $bhFields, true);

$stmt = $db->prepare("
    SELECT
        bh.*,
        u.full_name AS owner_name,
        u.phone AS owner_phone,
        u.email AS owner_email,
        u.created_at AS owner_since
    FROM boarding_houses bh
    JOIN users u ON u.id = bh.owner_id
    WHERE bh.id = ? AND bh.status != 'inactive'$approvalWhere
");
$stmt->execute([$id]);
$bh = $stmt->fetch();
if (!$bh) {
    setFlash('error', 'Listing not found.');
    header('Location: ' . SITE_URL . '/index.php');
    exit;
}


// Count page views (best-effort)
if ($hasViews) {
    try {
        $db->prepare("UPDATE boarding_houses SET views = views + 1 WHERE id = ?")->execute([$id]);
        if (isset($bh['views'])) $bh['views'] = intval($bh['views']) + 1;
    } catch (Throwable $e) {
        // ignore
    }
}

$currentUser = isLoggedIn() ? getCurrentUser() : null;

$imagesStmt = $db->prepare("SELECT * FROM boarding_house_images WHERE boarding_house_id = ? ORDER BY is_cover DESC, uploaded_at DESC");
$imagesStmt->execute([$id]);
$images = $imagesStmt->fetchAll() ?: [];

$amenitiesStmt = $db->prepare("SELECT a.* FROM amenities a JOIN boarding_house_amenities bha ON bha.amenity_id = a.id WHERE bha.boarding_house_id = ?");
$amenitiesStmt->execute([$id]);
$amenities = $amenitiesStmt->fetchAll() ?: [];

$typeLabels = ['solo_room' => 'Solo Room', 'shared_room' => 'Shared Room', 'studio' => 'Studio', 'apartment' => 'Apartment'];

$bhName = (string)($bh['name'] ?? 'Listing');
$bhLocation = trim((string)($bh['location'] ?? ($bh['address'] ?? '')));
$bhCity = trim((string)($bh['city'] ?? ''));
$bhFullLocation = trim($bhLocation . (($bhLocation !== '' && $bhCity !== '') ? ', ' : '') . $bhCity);
$bhType = (string)($bh['accommodation_type'] ?? '');
$bhStatus = (string)($bh['status'] ?? 'active');
$bhAvailableRooms = intval($bh['available_rooms'] ?? 0);
$bhTotalRooms = intval($bh['total_rooms'] ?? 0);
$bhPriceMin = (float)($bh['price_min'] ?? 0);
$bhPriceMax = $bh['price_max'] ?? null;
$bhContactPhone = trim((string)($bh['contact_phone'] ?? ($bh['owner_phone'] ?? '')));
$bhContactEmail = trim((string)($bh['contact_email'] ?? ($bh['owner_email'] ?? '')));
$ownerName = (string)($bh['owner_name'] ?? 'Owner');
$ownerSince = (string)($bh['owner_since'] ?? '');
$bhMapQuery = $bhFullLocation !== '' ? rawurlencode($bhFullLocation) : '';
$bhMapEmbedUrl = $bhMapQuery !== '' ? ("https://www.google.com/maps?q={$bhMapQuery}&output=embed") : '';
$bhMapLinkUrl = $bhMapQuery !== '' ? ("https://www.google.com/maps?q={$bhMapQuery}") : '';

// Handle contact form
$contactSuccess = false;
$contactErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
    $cName = trim($_POST['sender_name'] ?? '');
    $cEmail = trim($_POST['sender_email'] ?? '');
    $cPhone = trim($_POST['sender_phone'] ?? '');
    $cMessage = trim($_POST['message'] ?? '');

    if ($cName === '') $contactErrors['name'] = 'Name is required.';
    if ($cEmail === '' || !filter_var($cEmail, FILTER_VALIDATE_EMAIL)) $contactErrors['email'] = 'Valid email required.';
    if ($cMessage === '') $contactErrors['message'] = 'Message is required.';

    if (empty($contactErrors)) {
        $senderId = isLoggedIn() ? $_SESSION['user_id'] : null;
        $ins = $db->prepare("INSERT INTO contact_messages (boarding_house_id,sender_id,sender_name,sender_email,sender_phone,message) VALUES(?,?,?,?,?,?)");
        $ins->execute([$id, $senderId, $cName, $cEmail, $cPhone, $cMessage]);
        $contactSuccess = true;
    }
}


// Handle report form
$reportSuccess = false;
$reportErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_submit'])) {
    $rReason = trim((string)($_POST['reason'] ?? ''));
    $rDetails = trim((string)($_POST['details'] ?? ''));

    if ($rReason === '') $reportErrors['reason'] = 'Reason is required.';

    if (empty($reportErrors)) {
        try {
            $reporterId = isLoggedIn() ? intval($_SESSION['user_id']) : null;
            $ins = $db->prepare("INSERT INTO reports (boarding_house_id, reporter_id, reason, details) VALUES (?,?,?,?)");
            $ins->execute([$id, $reporterId, $rReason, $rDetails !== '' ? $rDetails : null]);
            $reportSuccess = true;
        } catch (Throwable $e) {
            $reportErrors['general'] = 'Reporting is currently unavailable.';
        }
    }
}

$pageTitle = sanitize($bhName);
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <nav class="page-breadcrumb">
      <a href="<?= SITE_URL ?>/index.php">Home</a>
      <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
      <a href="<?= SITE_URL ?>/index.php">Listings</a>
      <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
      <span><?= sanitize($bhName) ?></span>
    </nav>
  </div>
</div>

<div class="container">
  <div class="detail-grid">

    <!-- Left Column -->
    <div>
      <!-- Gallery -->
      <?php if (!empty($images)): ?>
        <div class="detail-gallery">
          <div class="gallery-main">
            <img src="<?= UPLOAD_URL . sanitize($images[0]['image_path'] ?? '') ?>" alt="<?= sanitize($bhName) ?>" id="mainImage">
          </div>
          <?php if (count($images) > 1): ?>
            <div class="gallery-thumbs">
              <?php foreach (array_slice($images, 0, 4) as $img): ?>
                <div class="gallery-thumb" data-src="<?= UPLOAD_URL . sanitize($img['image_path'] ?? '') ?>">
                  <img src="<?= UPLOAD_URL . sanitize($img['image_path'] ?? '') ?>" alt="">
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div style="height:300px;background:linear-gradient(135deg,rgba(var(--primary-rgb),0.14),rgba(var(--primary-rgb),0.04));border-radius:var(--radius-lg);display:flex;align-items:center;justify-content:center;flex-direction:column;color:var(--text-light);gap:12px;margin-bottom:24px">
          <i class="fas fa-building" style="font-size:4rem"></i>
          <span>No photos available</span>
        </div>
      <?php endif; ?>

      <!-- Info -->
      <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:8px">
        <div>
          <h1 class="detail-title"><?= sanitize($bhName) ?></h1>
          <div class="detail-location"><i class="fas fa-map-marker-alt" style="color:var(--primary)"></i><?= sanitize($bhFullLocation) ?></div>
        </div>
        <span class="property-badge <?= $bhType ? ('badge-' . str_replace('_', '-', $bhType)) : '' ?>" style="position:static;font-size:.85rem;padding:6px 16px">
          <?= sanitize($typeLabels[$bhType] ?? ($bhType ? ucfirst($bhType) : 'Listing')) ?>
        </span>
      </div>

      <div class="detail-price">
        <?= formatPrice($bhPriceMin) ?>
        <?php if ($bhPriceMax !== null && (float)$bhPriceMax > $bhPriceMin): ?> &ndash; <?= formatPrice((float)$bhPriceMax) ?><?php endif; ?>
        <small>/month</small>
      </div>

      <div class="flex gap-3 mb-4" style="flex-wrap:wrap">
        <div class="amenity-chip"><i class="fas fa-door-open"></i> <?= $bhAvailableRooms ?> / <?= $bhTotalRooms ?> rooms available</div>
        <span class="property-status status-<?= sanitize($bhStatus) ?>" style="position:static;padding:6px 14px;border-radius:50px">
          <?= $bhStatus === 'full' ? 'Full' : ($bhStatus === 'active' ? '&bull; Available' : 'Inactive') ?>
        </span>
      </div>

      <!-- Description -->
      <?php if (!empty($bh['description'])): ?>
        <div class="detail-section">
          <h2 class="detail-section-title"><i class="fas fa-info-circle" style="color:var(--primary)"></i> About this place</h2>
          <p style="color:var(--text-muted);line-height:1.8"><?= nl2br(sanitize($bh['description'])) ?></p>
        </div>
      <?php endif; ?>

      <!-- Map -->
      <?php if ($bhMapEmbedUrl !== ''): ?>
        <div class="detail-section">
          <div class="detail-section-title" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
            <span><i class="fas fa-map" style="color:var(--primary)"></i> Location</span>
            <a class="btn btn-outline btn-sm" href="<?= sanitize($bhMapLinkUrl) ?>" target="_blank" rel="noopener noreferrer">
              <i class="fas fa-external-link-alt"></i> Open in Maps
            </a>
          </div>
          <div class="map-embed">
            <iframe
              title="Map for <?= sanitize($bhName) ?>"
              src="<?= sanitize($bhMapEmbedUrl) ?>"
              loading="lazy"
              referrerpolicy="no-referrer-when-downgrade"
              allowfullscreen
            ></iframe>
          </div>
          <p class="text-sm text-muted mt-2"><?= sanitize($bhFullLocation) ?></p>
        </div>
      <?php endif; ?>

      <!-- Amenities -->
      <?php if (!empty($amenities)): ?>
        <div class="detail-section">
          <h2 class="detail-section-title"><i class="fas fa-star" style="color:var(--primary)"></i> Amenities</h2>
          <div class="amenities-list">
            <?php foreach ($amenities as $am): ?>
              <div class="amenity-item"><i class="fas fa-check" style="color:var(--primary)"></i><?= sanitize($am['name'] ?? '') ?></div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Rules -->
      <?php if (!empty($bh['rules'])): ?>
        <div class="detail-section">
          <h2 class="detail-section-title"><i class="fas fa-clipboard-list" style="color:var(--primary)"></i> House Rules</h2>
          <ul style="color:var(--text-muted);padding-left:20px;line-height:2">
            <?php foreach (explode("\n", (string)$bh['rules']) as $rule): $rule = trim($rule); if ($rule !== ''): ?>
              <li><?= sanitize($rule) ?></li>
            <?php endif; endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>

    <!-- Right Column - Contact Card -->
    <div>
      <div class="contact-card">
        <div class="contact-owner">
          <div class="contact-owner-avatar"><?= strtoupper(substr(sanitize($ownerName), 0, 1)) ?></div>
          <div class="contact-owner-info">
            <strong><?= sanitize($ownerName) ?></strong>
            <span>Property Owner &middot; Member since <?= $ownerSince ? date('Y', strtotime($ownerSince)) : '' ?></span>
          </div>
        </div>

        <?php if ($bhContactPhone !== '' || $bhContactEmail !== ''): ?>
          <div class="mb-4" style="background:var(--bg);border-radius:var(--radius-sm);padding:14px">
            <?php if ($bhContactPhone !== ''): ?>
              <div class="flex items-center gap-2 mb-2" style="font-size:.875rem">
                <i class="fas fa-phone" style="color:var(--primary);width:20px"></i>
                <a href="tel:<?= sanitize($bhContactPhone) ?>"><?= sanitize($bhContactPhone) ?></a>
              </div>
            <?php endif; ?>
            <?php if ($bhContactEmail !== ''): ?>
              <div class="flex items-center gap-2" style="font-size:.875rem">
                <i class="fas fa-envelope" style="color:var(--primary);width:20px"></i>
                <a href="mailto:<?= sanitize($bhContactEmail) ?>"><?= sanitize($bhContactEmail) ?></a>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($contactSuccess): ?>
          <div class="flash flash-success mb-4"><i class="fas fa-check-circle"></i> Message sent! The owner will contact you soon.</div>
        <?php else: ?>
          <h3 style="font-family:var(--font-display);font-size:1rem;margin-bottom:16px">Send a Message</h3>
          <form method="POST" action="" data-validate>
            <div class="form-group">
              <label class="form-label">Your Name <span class="required">*</span></label>
              <input type="text" name="sender_name" class="form-control <?= isset($contactErrors['name']) ? 'error' : '' ?>"
                     placeholder="Juan Dela Cruz" value="<?= $currentUser ? sanitize($currentUser['full_name'] ?? '') : '' ?>" required>
              <?php if (isset($contactErrors['name'])): ?><p class="form-error"><i class="fas fa-exclamation-circle"></i><?= sanitize($contactErrors['name']) ?></p><?php endif; ?>
            </div>

            <div class="form-group">
              <label class="form-label">Email <span class="required">*</span></label>
              <input type="email" name="sender_email" class="form-control <?= isset($contactErrors['email']) ? 'error' : '' ?>"
                     placeholder="you@email.com" value="<?= $currentUser ? sanitize($currentUser['email'] ?? '') : '' ?>" required>
              <?php if (isset($contactErrors['email'])): ?><p class="form-error"><i class="fas fa-exclamation-circle"></i><?= sanitize($contactErrors['email']) ?></p><?php endif; ?>
            </div>

            <div class="form-group">
              <label class="form-label">Phone (optional)</label>
              <input type="tel" name="sender_phone" class="form-control" placeholder="09171234567" value="<?= $currentUser ? sanitize($currentUser['phone'] ?? '') : '' ?>">
            </div>

            <div class="form-group">
              <label class="form-label">Message <span class="required">*</span></label>
              <textarea name="message" class="form-control <?= isset($contactErrors['message']) ? 'error' : '' ?>"
                        placeholder="Hi, I'm interested in renting a room. Is it still available?" required><?= sanitize($_POST['message'] ?? '') ?></textarea>
              <?php if (isset($contactErrors['message'])): ?><p class="form-error"><i class="fas fa-exclamation-circle"></i><?= sanitize($contactErrors['message']) ?></p><?php endif; ?>
            </div>

            <input type="hidden" name="contact_submit" value="1">
            <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-paper-plane"></i> Send Message</button>
          </form>
        <?php endif; ?>

        <hr style="border:none;border-top:1px solid var(--border);margin:18px 0">

        <h3 style="font-family:var(--font-display);font-size:1rem;margin-bottom:12px">Report this listing</h3>

        <?php if ($reportSuccess): ?>
          <div class="flash flash-success mb-3"><i class="fas fa-check-circle"></i> Report submitted. Thank you.</div>
        <?php else: ?>
          <?php if (!empty($reportErrors['general'])): ?>
            <div class="flash flash-error mb-3"><i class="fas fa-exclamation-circle"></i><?= sanitize($reportErrors['general']) ?></div>
          <?php endif; ?>

          <form method="POST" action="" data-validate>
            <div class="form-group">
              <label class="form-label">Reason <span class="required">*</span></label>
              <select name="reason" class="form-control <?= isset($reportErrors['reason']) ? 'error' : '' ?>" required>
                <?php $sel = sanitize($_POST['reason'] ?? ''); ?>
                <option value="">Select a reason</option>
                <option value="Fake listing" <?= $sel==='Fake listing'?'selected':'' ?>>Fake listing</option>
                <option value="Misleading information" <?= $sel==='Misleading information'?'selected':'' ?>>Misleading information</option>
                <option value="Inappropriate content" <?= $sel==='Inappropriate content'?'selected':'' ?>>Inappropriate content</option>
                <option value="Other" <?= $sel==='Other'?'selected':'' ?>>Other</option>
              </select>
              <?php if (isset($reportErrors['reason'])): ?><p class="form-error"><i class="fas fa-exclamation-circle"></i><?= sanitize($reportErrors['reason']) ?></p><?php endif; ?>
            </div>
            <div class="form-group">
              <label class="form-label">Details (optional)</label>
              <textarea name="details" class="form-control" rows="3" placeholder="Add any helpful details..."><?= sanitize($_POST['details'] ?? '') ?></textarea>
            </div>
            <input type="hidden" name="report_submit" value="1">
            <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-flag"></i> Submit Report</button>
          </form>
        <?php endif; ?>

      </div>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


