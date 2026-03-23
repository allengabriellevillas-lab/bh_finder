<?php
require_once __DIR__ . '/config.php';

$pageTitle = $pageTitle ?? SITE_NAME;
$flash = getFlash();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= sanitize($pageTitle) ?><?= $pageTitle !== SITE_NAME ? ' | ' . SITE_NAME : '' ?></title>
  <link rel="stylesheet" href="<?= SITE_URL ?>/style.css">
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
<?php if ($flash): ?>
  <div class="flash-container">
    <div class="flash flash-<?= sanitize($flash['type']) ?>">
      <?= sanitize($flash['message']) ?>
      <button type="button" class="flash-close" onclick="this.closest('.flash-container')?.remove()">&times;</button>
    </div>
  </div>
<?php endif; ?>
