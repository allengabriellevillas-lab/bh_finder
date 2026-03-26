<?php
require_once __DIR__ . '/config.php';

$pageTitle = $pageTitle ?? SITE_NAME;
$flash = getFlash();
$showNavbar = $showNavbar ?? true;
$currentUser = $currentUser ?? (isLoggedIn() ? getCurrentUser() : null);
$scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
$isHome = ($scriptName === '' || $scriptName === 'index.php');
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
<body class="<?= $showNavbar ? 'has-navbar' : 'no-navbar' ?>"><div class="page-root"><?php
if ($flash): ?>
  <div class="flash-container">
    <div class="flash flash-<?= sanitize($flash['type']) ?>">
      <?= sanitize($flash['message']) ?>
      <button type="button" class="flash-close" onclick="this.closest('.flash-container')?.remove()">&times;</button>
    </div>
  </div>
<?php endif;

if ($showNavbar): ?>
  <nav class="navbar">
    <div class="container navbar-inner">
      <a class="brand" href="<?= SITE_URL ?>/index.php" aria-label="<?= sanitize(SITE_NAME) ?>">
        <span class="brand-icon">
          <img class="site-logo nav-logo" src="<?= SITE_URL ?>/boardease-logo.png" alt="<?= sanitize(SITE_NAME) ?> logo">
        </span>
        <span class="sr-only"><?= sanitize(SITE_NAME) ?></span>
      </a>

      <button class="nav-toggle" id="navToggle" type="button" aria-label="Toggle navigation">
        <span></span><span></span><span></span>
      </button>

      <div class="nav-links" id="navLinks">
        <a class="nav-link <?= $isHome ? 'active' : '' ?>" href="<?= SITE_URL ?>/index.php">Home</a>
        <a class="nav-link" href="<?= SITE_URL ?>/index.php#listings">Browse</a>

        <?php if (!isLoggedIn()): ?>
          <a class="nav-link btn-outline-sm" href="<?= SITE_URL ?>/login.php"><i class="fas fa-right-to-bracket"></i> Login</a>
          <a class="nav-link btn-primary-sm" href="<?= SITE_URL ?>/register.php?role=owner"><i class="fas fa-plus"></i> List Property</a>
        <?php else: ?>
          <div class="nav-user">
            <button class="user-btn" id="userBtn" type="button">
              <span class="user-avatar"><?= strtoupper(substr(sanitize($currentUser['full_name'] ?? 'U'), 0, 1)) ?></span>
              <span><?= sanitize($currentUser['full_name'] ?? 'Account') ?></span>
              <i class="fas fa-chevron-down" style="font-size:0.7rem;color:var(--text-light)"></i>
            </button>

            <div class="user-dropdown" id="userDropdown">
              <div class="dropdown-header">
                <strong><?= sanitize($currentUser['full_name'] ?? '') ?></strong>
                <span><?= sanitize($currentUser['email'] ?? '') ?></span>
                <span class="role-badge <?= isAdmin() ? 'role-admin' : (isOwner() ? 'role-owner' : 'role-tenant') ?>"><?= sanitize($currentUser['role'] ?? '') ?></span>
              </div>

              <?php if (isAdmin()): ?>
                <a href="<?= SITE_URL ?>/pages/admin/dashboard.php"><i class="fas fa-shield-halved"></i> Admin Panel</a>
                <hr>
              <?php endif; ?>


                <?php if (isTenant()): ?>
                  <a href="<?= SITE_URL ?>/pages/favorites.php"><i class="fas fa-heart"></i> Favorites</a>
                  <a href="<?= SITE_URL ?>/pages/chats.php"><i class="fas fa-comments"></i> Messages</a>
                  <hr>
                <?php endif; ?>
              <?php if (isOwner()): ?>
                <a href="<?= SITE_URL ?>/pages/owner/dashboard.php"><i class="fas fa-gauge"></i> Owner Dashboard</a>
                <a href="<?= SITE_URL ?>/pages/owner/add_listing.php"><i class="fas fa-plus"></i> Add Listing</a>
                <hr>
              <?php endif; ?>

              <a class="logout-link" href="<?= SITE_URL ?>/logout.php"><i class="fas fa-right-from-bracket"></i> Logout</a>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </nav>
<?php endif;

