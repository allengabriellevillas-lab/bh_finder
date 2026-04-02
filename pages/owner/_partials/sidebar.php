<?php
$activeNav = strtolower(trim((string)($activeNav ?? '')));
$links = [
  'dashboard' => ['href' => 'dashboard.php', 'icon' => 'fas fa-gauge', 'label' => 'Overview'],
  'rooms' => ['href' => 'rooms.php', 'icon' => 'fas fa-door-open', 'label' => 'Rooms'],
  'subscriptions' => ['href' => 'subscriptions.php', 'icon' => 'fas fa-credit-card', 'label' => 'Subscriptions'],
  'chats' => ['href' => 'chats.php', 'icon' => 'fas fa-comments', 'label' => 'Chats'],
  'verification' => ['href' => 'verification.php', 'icon' => 'fas fa-user-check', 'label' => 'Verification'],
  'browse' => ['href' => SITE_URL . '/index.php', 'icon' => 'fas fa-house', 'label' => 'Browse'],
];
?>

<aside class="dash-sidebar">
  <a class="dash-brand" href="dashboard.php" aria-label="<?= sanitize(SITE_NAME) ?>">
    <span class="dash-logo-wrap"><img class="dash-logo" src="<?= SITE_URL ?>/boardease-logo.png" alt="<?= sanitize(SITE_NAME) ?> logo"></span>
    <span class="sr-only"><?= sanitize(SITE_NAME) ?></span>
  </a>

  <a class="dash-action" href="add_listing.php" title="Create a new listing">
    <span>Add Listing</span>
    <i class="fas fa-plus"></i>
  </a>

  <nav class="dash-nav">
    <?php foreach ($links as $key => $l):
      $isActive = ($activeNav === $key);
      $href = (string)($l['href'] ?? '#');
    ?>
      <a class="<?= $isActive ? 'active' : '' ?>" href="<?= sanitize($href) ?>"><i class="<?= sanitize((string)($l['icon'] ?? '')) ?>"></i> <?= sanitize((string)($l['label'] ?? '')) ?></a>
    <?php endforeach; ?>
  </nav>
</aside>