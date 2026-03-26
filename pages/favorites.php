<?php
require_once __DIR__ . '/../includes/config.php';

requireLogin();

$pageTitle = 'My Favorites';
$db = getDB();
$uid = intval($_SESSION['user_id']);

$listings = [];
try {
    try {
        $stmt = $db->prepare("
            SELECT
                bh.*,
                u.full_name AS owner_name,
                (SELECT pi.image_path FROM boarding_house_images pi WHERE pi.boarding_house_id = bh.id AND pi.is_cover = 1 LIMIT 1) AS primary_image,
                (SELECT pi2.image_path FROM boarding_house_images pi2 WHERE pi2.boarding_house_id = bh.id LIMIT 1) AS first_image,
                rev.avg_rating,
                rev.review_count,
                f.created_at AS saved_at
            FROM favorites f
            JOIN boarding_houses bh ON bh.id = f.boarding_house_id
            JOIN users u ON u.id = bh.owner_id
            LEFT JOIN (
                SELECT boarding_house_id, AVG(rating) AS avg_rating, COUNT(*) AS review_count
                FROM reviews
                WHERE is_hidden = 0
                GROUP BY boarding_house_id
            ) rev ON rev.boarding_house_id = bh.id
            WHERE f.user_id = ?
            ORDER BY f.created_at DESC
        ");
        $stmt->execute([$uid]);
        $listings = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        // Fallback for installs without the reviews table yet.
        $stmt = $db->prepare("
            SELECT
                bh.*,
                u.full_name AS owner_name,
                (SELECT pi.image_path FROM boarding_house_images pi WHERE pi.boarding_house_id = bh.id AND pi.is_cover = 1 LIMIT 1) AS primary_image,
                (SELECT pi2.image_path FROM boarding_house_images pi2 WHERE pi2.boarding_house_id = bh.id LIMIT 1) AS first_image,
                f.created_at AS saved_at
            FROM favorites f
            JOIN boarding_houses bh ON bh.id = f.boarding_house_id
            JOIN users u ON u.id = bh.owner_id
            WHERE f.user_id = ?
            ORDER BY f.created_at DESC
        ");
        $stmt->execute([$uid]);
        $listings = $stmt->fetchAll() ?: [];

        foreach ($listings as &$row) {
            if (!array_key_exists('avg_rating', $row)) $row['avg_rating'] = null;
            if (!array_key_exists('review_count', $row)) $row['review_count'] = 0;
        }
        unset($row);
    }
} catch (Throwable $e) {
    $listings = [];
    setFlash('error', 'Favorites are not available yet. Please run install.php or import the updated schema.sql.');
}

$typeLabels = ['solo_room'=>'Solo Room','shared_room'=>'Shared Room','bedspace'=>'Bedspace','studio'=>'Studio','apartment'=>'Apartment','entire_unit'=>'Entire Unit'];
$typeClasses = ['solo_room'=>'badge-solo','shared_room'=>'badge-shared','bedspace'=>'badge-shared','studio'=>'badge-studio','apartment'=>'badge-apartment','entire_unit'=>'badge-apartment'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <nav class="page-breadcrumb">
      <a href="<?= SITE_URL ?>/index.php">Home</a>
      <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
      <span>Favorites</span>
    </nav>
    <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap">
      <div>
        <h1 class="section-title" style="margin:10px 0 4px">My Favorites</h1>
        <div class="section-subtitle">Saved listings for quick access.</div>
      </div>
      <a class="btn btn-ghost" href="<?= SITE_URL ?>/index.php#listings"><i class="fas fa-search"></i> Browse</a>
    </div>
  </div>
</div>

<div class="container" style="padding:24px 0 60px">
  <?php if (empty($listings)): ?>
    <div class="empty-state">
      <i class="fas fa-heart"></i>
      <h3>No favorites yet</h3>
      <p>Browse listings and tap the heart icon to save them.</p>
      <a class="btn btn-primary" href="<?= SITE_URL ?>/index.php#listings"><i class="fas fa-search"></i> Browse Listings</a>
    </div>
  <?php else: ?>
    <div class="property-grid">
      <?php foreach ($listings as $l):
          $imgPath = $l['primary_image'] ?? $l['first_image'] ?? null;
          $imgUrl  = $imgPath ? UPLOAD_URL . sanitize($imgPath) : null;
          $tk = $l['accommodation_type'] ?? '';
          $status = $l['status'] ?? 'active';
          $availableRooms = intval($l['available_rooms'] ?? 0);
          $totalRooms = intval($l['total_rooms'] ?? 0);
          $locationText = trim((string)($l['location'] ?? ($l['address'] ?? '')));
          $cityText = trim((string)($l['city'] ?? ''));
          $fullLocation = trim($locationText . ($cityText !== '' ? ', ' . $cityText : ''));
          $avg = $l['avg_rating'] !== null ? (float)$l['avg_rating'] : 0.0;
          $cnt = intval($l['review_count'] ?? 0);
      ?>
      <article class="property-card">
        <div class="property-image">
          <?php if ($imgUrl): ?>
            <img src="<?= $imgUrl ?>" alt="<?= sanitize($l['name'] ?? '') ?>" loading="lazy">
          <?php else: ?>
            <div class="property-image-placeholder"><i class="fas fa-building"></i><span>No Photo</span></div>
          <?php endif; ?>

          <span class="property-badge <?= $typeClasses[$tk] ?? '' ?>"><?= sanitize($typeLabels[$tk] ?? ($tk ? ucfirst($tk) : 'Listing')) ?></span>
          <span class="property-status status-<?= sanitize($status) ?>"><?= $status === 'full' ? 'Full' : ($status === 'active' ? 'Available' : 'Inactive') ?></span>

          <form method="POST" action="<?= SITE_URL ?>/pages/favorite_toggle.php" class="fav-form">
            <input type="hidden" name="bh_id" value="<?= intval($l['id'] ?? 0) ?>">
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="redirect" value="<?= sanitize($_SERVER['REQUEST_URI'] ?? '') ?>">
            <button type="submit" class="fav-btn is-active" title="Remove from favorites" aria-label="Remove from favorites">
              <i class="fas fa-heart"></i>
            </button>
          </form>
        </div>

        <div class="property-body">
          <h2 class="property-name"><?= sanitize($l['name'] ?? '') ?></h2>
          <div class="property-location"><i class="fas fa-map-marker-alt"></i><?= sanitize($fullLocation) ?></div>

          <div class="rating-summary">
            <i class="fas fa-star"></i>
            <span><?= $cnt > 0 ? sanitize(number_format($avg, 1)) : '—' ?></span>
            <small>(<?= number_format($cnt) ?>)</small>
          </div>

          <div class="property-price">
            <?= formatPrice((float)($l['price_min'] ?? 0)) ?>
            <?php if (!empty($l['price_max']) && (float)$l['price_max'] > (float)($l['price_min'] ?? 0)): ?> – <?= formatPrice((float)$l['price_max']) ?><?php endif; ?>
            <small>/month</small>
          </div>
        </div>

        <div class="property-footer">
          <div class="property-rooms"><strong><?= $availableRooms ?></strong> / <?= $totalRooms ?> rooms</div>
          <a class="btn btn-primary btn-sm" href="<?= SITE_URL ?>/pages/detail.php?id=<?= intval($l['id'] ?? 0) ?>"><i class="fas fa-eye"></i> View</a>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

