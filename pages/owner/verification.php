<?php
require_once __DIR__ . '/../../includes/config.php';
requireOwner();

$db = getDB();
$uid = intval($_SESSION['user_id'] ?? 0);
if (function_exists('ensureOwnerVerificationColumns')) { ensureOwnerVerificationColumns(); }
$me = getCurrentUser();
$pageTitle = 'Owner Verification';
$showNavbar = false;

$hasCols = [
    'owner_verification_status' => false,
    'owner_id_doc_path' => false,
    'owner_verification_reason' => false,
];

// Check actual DB columns (don't rely on cached user arrays).
try {
    $cols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $names = array_map(fn($r) => (string)($r["Field"] ?? ""), $cols);
    $has = fn(string $c): bool => in_array($c, $names, true);
    $hasCols['owner_verification_status'] = $has('owner_verification_status');
    $hasCols['owner_id_doc_path'] = $has('owner_id_doc_path');
    $hasCols['owner_verification_reason'] = $has('owner_verification_reason');
} catch (Throwable $e) {
    // Fallback: infer from loaded user array.
    $hasCols['owner_verification_status'] = array_key_exists('owner_verification_status', (array)$me);
    $hasCols['owner_id_doc_path'] = array_key_exists('owner_id_doc_path', (array)$me);
    $hasCols['owner_verification_reason'] = array_key_exists('owner_verification_reason', (array)$me);
}

// Ensure we have the latest values for these fields (best-effort).
if ($uid > 0 && ($hasCols['owner_verification_status'] || $hasCols['owner_id_doc_path'] || $hasCols['owner_verification_reason'])) {
    try {
        $q = $db->prepare("SELECT owner_verification_status, owner_id_doc_path, owner_verification_reason, owner_verified, owner_verified_at FROM users WHERE id = ? LIMIT 1");
        $q->execute([$uid]);
        $extra = $q->fetch() ?: [];
        if ($extra) $me = array_merge((array)$me, $extra);
    } catch (Throwable $e) {
        // ignore
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'upload_id') {
        // If already verified, don't allow re-upload (prevents accidental status reset).
        $alreadyVerified = false;
        if (!empty($hasCols['owner_verification_status'])) {
            $alreadyVerified = strtolower((string)($me['owner_verification_status'] ?? '')) === 'verified';
        }
        if (!$alreadyVerified) {
            $alreadyVerified = intval($me['owner_verified'] ?? 0) === 1;
        }
        if ($alreadyVerified) {
            setFlash('error', 'You are already verified. If you need to change your ID, please contact an admin.');
            header('Location: verification.php');
            exit;
        }

        if (!$hasCols['owner_id_doc_path'] || !$hasCols['owner_verification_status']) {
            setFlash('error', 'Verification fields are missing. Please run install.php or import the updated schema.sql.');
            header('Location: verification.php');
            exit;
        }

        $file = $_FILES['id_file'] ?? null;
        if (!is_array($file)) {
            setFlash('error', 'Please choose a valid ID image.');
            header('Location: verification.php');
            exit;
        }

        $stored = uploadImage($file, 'owner_id_' . intval($_SESSION['user_id']));
        if (!$stored) {
            setFlash('error', 'Failed to upload. Please upload a JPG/PNG/WebP file up to 5MB.');
            header('Location: verification.php');
            exit;
        }

        try {
            $prev = (string)($me['owner_id_doc_path'] ?? '');
            $stmt = $db->prepare("UPDATE users
              SET owner_id_doc_path = ?,
                  owner_verification_status = 'pending',
                  owner_verification_reason = NULL,
                  owner_verified = 0,
                  owner_verified_at = NULL
              WHERE id = ? AND role = 'owner'");
            $stmt->execute([$stored, intval($_SESSION['user_id'])]);

            if ($prev !== '' && $prev !== $stored) {
                deleteUploadedFile($prev);
            }

            setFlash('success', 'ID uploaded. Your verification is now pending admin approval.');
        } catch (Throwable $e) {
            deleteUploadedFile($stored);
            setFlash('error', 'Unable to save your verification request. Please try again.');
        }

        header('Location: verification.php');
        exit;
    }
}

 $status = "";
 if (array_key_exists('owner_verification_status', (array)$me)) {
     $status = (string)($me['owner_verification_status'] ?? "");
     if ($status === "") $status = 'pending';
 } else {
     $status = intval($me['owner_verified'] ?? 0) === 1 ? 'verified' : 'pending';
 }

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="dash-shell">
<?php $activeNav = 'verification'; include __DIR__ . '/_partials/sidebar.php'; ?>

  <div class="dash-main">
<?php include __DIR__ . '/_partials/topbar.php'; ?>

    <div class="dash-content">
      <div class="dash-heading">
        <div>
          <h1 class="dash-title">Owner Verification</h1>
          <div class="dash-breadcrumb">
            <a href="dashboard.php">Dashboard</a>
            <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
            <span>Verification</span>
          </div>
        </div>
      </div>

      <main>
        <div class="card">
          <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div>
              <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">Upload Valid ID</h2>
              <div class="text-muted text-sm" style="margin-top:4px">Required before you can post listings (admin approval).</div>
            </div>
            <span class="badge" style="background:var(--bg);border:1px solid var(--border)">
              Status: <?= sanitize(ucfirst($status)) ?>
            </span>
          </div>

          <div class="card-body">
            <?php if (!$hasCols['owner_id_doc_path'] || !$hasCols['owner_verification_status']): ?>
              <div class="flash flash-error"><i class="fas fa-exclamation-circle"></i> Missing verification fields. Run <code>install.php</code>.</div>
            <?php else: ?>
              <?php if ($status === 'rejected' && !empty($me['owner_verification_reason'])): ?>
                <div class="flash flash-error"><i class="fas fa-triangle-exclamation"></i> Rejected: <?= sanitize((string)$me['owner_verification_reason']) ?></div>
              <?php elseif ($status === 'pending'): ?>
                <div class="flash flash-warning"><i class="fas fa-clock"></i> Your verification request is pending review.</div>
              <?php elseif ($status === 'verified'): ?>
                <div class="flash flash-success"><i class="fas fa-circle-check"></i> You are verified. You can now post listings and rooms.</div>
              <?php endif; ?>

              <?php if (!empty($me['owner_id_doc_path'])): ?>
                <div class="mb-3 text-muted text-sm">Current uploaded ID:</div>
                <button class="btn btn-ghost btn-sm" type="button" data-open-id-modal data-id-src="<?= UPLOAD_URL . sanitize((string)$me['owner_id_doc_path']) ?>">
                  <i class="fas fa-id-card"></i> View Uploaded ID
                </button>
                <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">
              <?php endif; ?>

              <?php if ($status !== 'verified'): ?>
              <form method="POST" enctype="multipart/form-data" data-validate>
                <input type="hidden" name="action" value="upload_id">
                <div class="form-group">
                  <label class="form-label">Valid ID (JPG/PNG/WebP, max 5MB)</label>
                  <div class="file-upload file-upload-compact">
                    <input type="file" name="id_file" accept="image/jpeg,image/png,image/webp" required>
                    <div class="file-upload-icon"><i class="fas fa-id-card"></i></div>
                    <p class="file-upload-text"><strong>Click to upload</strong> or drag & drop</p>
                    <p class="file-upload-text" style="font-size:.8rem;margin-top:4px">Make sure details are clear and readable.</p>
                  </div>
                  <div class="file-preview"></div>
                </div>

                <button class="btn btn-primary" type="submit"><i class="fas fa-upload"></i> Upload for Review</button>
              </form>
              <?php else: ?>
                <div class="text-muted text-sm" style="margin-top:10px">Your account is verified. If you need to update your ID, please contact an admin.</div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </main>
    </div>
  </div>
</div>


<style>
  .id-modal { position:fixed; inset:0; display:none; align-items:center; justify-content:center; padding:18px; z-index:9999; }
  .id-modal.open { display:flex; }
  .id-modal__backdrop { position:absolute; inset:0; background:rgba(0,0,0,.55); }
  .id-modal__dialog { position:relative; width:min(920px, 100%); max-height:calc(100vh - 36px); background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow-xl); overflow:hidden; display:flex; flex-direction:column; }
  .id-modal__header { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:14px 16px; border-bottom:1px solid var(--border); }
  .id-modal__title { margin:0; font-family:var(--font-display); font-size:1.05rem; font-weight:800; }
  .id-modal__body { padding:12px 16px; overflow:auto; background:var(--bg); }
  .id-modal__img { width:100%; height:auto; border-radius:var(--radius-sm); border:1px solid var(--border); background:#fff; }
  .id-modal__footer { display:flex; justify-content:flex-end; gap:10px; padding:12px 16px; border-top:1px solid var(--border); }
</style>

<div class="id-modal" id="idModal" aria-hidden="true">
  <div class="id-modal__backdrop" data-close-id-modal></div>
  <div class="id-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="idModalTitle">
    <div class="id-modal__header">
      <h3 class="id-modal__title" id="idModalTitle">Uploaded ID</h3>
      <button class="btn btn-ghost btn-sm" type="button" data-close-id-modal><i class="fas fa-xmark"></i> Close</button>
    </div>
    <div class="id-modal__body">
      <img class="id-modal__img" id="idModalImg" src="" alt="Uploaded valid ID">
    </div>
    <div class="id-modal__footer">
      <a class="btn btn-ghost btn-sm" id="idModalOpen" href="#" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i> Open in new tab</a>
    </div>
  </div>
</div>

<script>
(function(){
  const modal = document.getElementById('idModal');
  const img = document.getElementById('idModalImg');
  const openLink = document.getElementById('idModalOpen');
  if (!modal || !img || !openLink) return;

  function openModal(src){
    img.src = src;
    openLink.href = src;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden','false');
    document.body.style.overflow = 'hidden';
  }
  function closeModal(){
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden','true');
    document.body.style.overflow = '';
    img.src = '';
    openLink.href = '#';
  }

  document.querySelectorAll('[data-open-id-modal]').forEach(btn => {
    btn.addEventListener('click', () => {
      const src = btn.getAttribute('data-id-src') || '';
      if (src) openModal(src);
    });
  });

  modal.querySelectorAll('[data-close-id-modal]').forEach(el => el.addEventListener('click', closeModal));
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
  });
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>




