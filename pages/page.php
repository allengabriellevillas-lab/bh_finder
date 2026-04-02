<?php
require_once __DIR__ . '/../includes/config.php';

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') {
    header('Location: ' . SITE_URL . '/index.php');
    exit;
}

$db = getDB();

try {
    $stmt = $db->prepare("SELECT title, body, is_active FROM content_pages WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $page = $stmt->fetch();
} catch (Throwable $e) {
    $page = null;
}

if (!$page || intval($page['is_active'] ?? 0) !== 1) {
    setFlash('error', 'Page not found.');
    header('Location: ' . SITE_URL . '/index.php');
    exit;
}

$pageTitle = (string)($page['title'] ?? 'Page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <h1 class="page-title"><?= sanitize($pageTitle) ?></h1>
    <nav class="page-breadcrumb">
      <a href="<?= SITE_URL ?>/index.php">Home</a>
      <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
      <span><?= sanitize($slug) ?></span>
    </nav>
  </div>
</div>

<div class="container" style="padding: 0 0 60px 0;max-width:920px">
  <div class="card">
    <div class="card-body" style="white-space:pre-wrap;line-height:1.85;color:var(--text-muted)">
      <?= nl2br(sanitize((string)($page['body'] ?? ''))) ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
