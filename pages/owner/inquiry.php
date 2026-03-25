<?php
require_once __DIR__ . '/../../includes/config.php';
requireOwner();

$db = getDB();
$pageTitle = 'Inquiry';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: inquiries.php');
    exit;
}

// Column detection
$cmCols = $db->query("SHOW COLUMNS FROM contact_messages")->fetchAll() ?: [];
$cmFields = array_map(fn($r) => (string)($r['Field'] ?? ''), $cmCols);
$cmHas = fn(string $c): bool => in_array($c, $cmFields, true);
$sentCol = $cmHas('sent_at') ? 'sent_at' : ($cmHas('created_at') ? 'created_at' : 'sent_at');
$hasIsRead = $cmHas('is_read');
$hasOwnerReply = $cmHas('owner_reply');
$hasRepliedAt = $cmHas('replied_at');

// Load inquiry and verify ownership
$stmt = $db->prepare("SELECT cm.*, bh.id AS bh_id, bh.name AS bh_name, bh.city AS bh_city
  FROM contact_messages cm
  JOIN boarding_houses bh ON bh.id = cm.boarding_house_id
  WHERE cm.id = ? AND bh.owner_id = ?");
$stmt->execute([$id, $_SESSION['user_id']]);
$inq = $stmt->fetch();
if (!$inq) {
    setFlash('error', 'Inquiry not found.');
    header('Location: inquiries.php');
    exit;
}

// Mark read
if ($hasIsRead && intval($inq['is_read'] ?? 0) === 0) {
    $db->prepare("UPDATE contact_messages SET is_read = 1 WHERE id = ?")->execute([$id]);
    $inq['is_read'] = 1;
}

// Save reply draft (optional)
$saveOk = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_reply']) && $hasOwnerReply) {
    $reply = trim((string)($_POST['owner_reply'] ?? ''));
    if ($hasRepliedAt) {
        $upd = $db->prepare("UPDATE contact_messages SET owner_reply = ?, replied_at = NOW(), is_read = 1 WHERE id = ?");
        $upd->execute([$reply, $id]);
    } else {
        $upd = $db->prepare("UPDATE contact_messages SET owner_reply = ?, is_read = 1 WHERE id = ?");
        $upd->execute([$reply, $id]);
    }
    $saveOk = true;
    $inq['owner_reply'] = $reply;
}

$listingName = (string)($inq['bh_name'] ?? 'Listing');
$senderName = (string)($inq['sender_name'] ?? '');
$senderEmail = (string)($inq['sender_email'] ?? '');
$senderPhone = trim((string)($inq['sender_phone'] ?? ''));
$sentAt = $inq[$sentCol] ?? null;
$dateText = $sentAt ? date('M d, Y H:i', strtotime((string)$sentAt)) : '';

$subject = rawurlencode('Re: ' . $listingName . ' inquiry #' . $id);
$body = rawurlencode("Hi {$senderName},\n\nThanks for your inquiry about {$listingName}.\n\n(Write your reply here)\n\nRegards,\n" . (getCurrentUser()['full_name'] ?? 'Owner'));
$mailto = $senderEmail !== '' ? ('mailto:' . $senderEmail . '?subject=' . $subject . '&body=' . $body) : '#';

$showNavbar = false;
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <h1 class="page-title">Inquiry</h1>
    <nav class="page-breadcrumb">
      <a href="dashboard.php">Dashboard</a>
      <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
      <a href="inquiries.php">Inquiries</a>
      <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
      <span>#<?= intval($id) ?></span>
    </nav>
  </div>
</div>

<div class="container" style="max-width:980px;padding-bottom:60px">
  <?php if ($saveOk): ?>
    <div class="flash flash-success" style="margin-bottom:16px"><i class="fas fa-check-circle"></i> Saved.</div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div>
        <div class="text-muted text-xs">Listing</div>
        <div style="font-family:var(--font-display);font-size:1.15rem;font-weight:800">
          <?= sanitize($listingName) ?>
        </div>
        <div class="text-muted text-xs" style="margin-top:2px">
          From <?= sanitize($senderName) ?> &middot; <?= sanitize($dateText) ?>
        </div>
      </div>

      <div class="flex flex-wrap gap-2">
        <a class="btn btn-ghost btn-sm" href="inquiries.php"><i class="fas fa-arrow-left"></i> Back</a>
        <?php if ($senderEmail !== ''): ?>
          <a class="btn btn-primary btn-sm" href="<?= $mailto ?>"><i class="fas fa-reply"></i> Reply via Email</a>
        <?php endif; ?>
        <?php if ($senderPhone !== ''): ?>
          <a class="btn btn-ghost btn-sm" href="tel:<?= sanitize($senderPhone) ?>"><i class="fas fa-phone"></i> Call</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="card-body">
      <div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr));margin-bottom:18px">
        <div class="stat-card">
          <div class="stat-icon stat-icon-primary"><i class="fas fa-user"></i></div>
          <div>
            <div class="stat-value" style="font-size:1rem;font-weight:800"><?= sanitize($senderName) ?></div>
            <div class="stat-name">Sender</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon stat-icon-secondary"><i class="fas fa-envelope"></i></div>
          <div>
            <div class="stat-value" style="font-size:1rem;font-weight:800"><?= sanitize($senderEmail) ?></div>
            <div class="stat-name">Email</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon stat-icon-warning"><i class="fas fa-phone"></i></div>
          <div>
            <div class="stat-value" style="font-size:1rem;font-weight:800"><?= sanitize($senderPhone ?: '—') ?></div>
            <div class="stat-name">Phone</div>
          </div>
        </div>
      </div>

      <div class="detail-section" style="padding-top:0">
        <h2 class="detail-section-title"><i class="fas fa-message" style="color:var(--primary)"></i> Message</h2>
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:16px;white-space:pre-wrap;line-height:1.7">
          <?= sanitize($inq['message'] ?? '') ?>
        </div>
      </div>

      <?php if ($hasOwnerReply): ?>
        <div class="detail-section">
          <h2 class="detail-section-title"><i class="fas fa-pen" style="color:var(--primary)"></i> Reply Draft (saved in DB)</h2>
          <form method="POST" action="">
            <textarea name="owner_reply" class="form-control" rows="7" placeholder="Write your reply here..."><?= sanitize($inq['owner_reply'] ?? '') ?></textarea>
            <div class="flex items-center justify-between" style="margin-top:12px;gap:12px;flex-wrap:wrap">
              <div class="text-muted text-xs">
                Tip: Click “Reply via Email” to send using your email app.
              </div>
              <button class="btn btn-primary btn-sm" type="submit" name="save_reply" value="1"><i class="fas fa-save"></i> Save Draft</button>
            </div>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

