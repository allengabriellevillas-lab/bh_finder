<?php
require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Find Your Boarding House';

// ── Search & Filter Params ──
$search   = trim($_GET['search'] ?? '');
$city     = trim($_GET['city'] ?? '');
$minPrice = intval($_GET['min_price'] ?? 0);
$maxPrice = intval($_GET['max_price'] ?? 0);
$type     = trim($_GET['type'] ?? '');
$page     = max(1, intval($_GET['page'] ?? 1));
$perPage  = 9;
$offset   = ($page - 1) * $perPage;

// ── Build Query ──
$db = getDB();

// Search & filter monitoring (best-effort)
try {
    if (($search !== '') || ($city !== '') || ($minPrice > 0) || ($maxPrice > 0) || ($type !== '')) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $uid = isLoggedIn() ? intval($_SESSION['user_id']) : null;
        $ins = $db->prepare("INSERT INTO search_logs (user_id, ip, channel, search, city, min_price, max_price, accommodation_type)
          VALUES (?,?,?,?,?,?,?,?)");
        $ins->execute([
            $uid,
            $ip,
            'web',
            $search !== '' ? $search : null,
            $city !== '' ? $city : null,
            $minPrice > 0 ? $minPrice : null,
            $maxPrice > 0 ? $maxPrice : null,
            $type !== '' ? $type : null,
        ]);
    }
} catch (Throwable $e) {
    // ignore if table doesn't exist
}

$bhCols = $db->query('SHOW COLUMNS FROM boarding_houses')->fetchAll() ?: [];
$bhFields = array_map(fn($r) => (string)($r['Field'] ?? ''), $bhCols);
$addressCol = in_array('location', $bhFields, true) ? 'location' : (in_array('address', $bhFields, true) ? 'address' : 'location');
$hasApprovalStatus = in_array('approval_status', $bhFields, true);

$conditions = ["bh.status != 'inactive'"];
if ($hasApprovalStatus) $conditions[] = "bh.approval_status = 'approved'";
$params = [];

if ($search !== '') {
    $conditions[] = "(bh.name LIKE ? OR bh.$addressCol LIKE ? OR bh.city LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($city !== '') {
    $conditions[] = "bh.city LIKE ?";
    $params[] = "%$city%";
}
if ($minPrice > 0) { $conditions[] = "bh.price_min >= ?"; $params[] = $minPrice; }
if ($maxPrice > 0) { $conditions[] = "bh.price_min <= ?"; $params[] = $maxPrice; }
if ($type !== '') { $conditions[] = "bh.accommodation_type = ?"; $params[] = $type; }

$whereClause = 'WHERE ' . implode(' AND ', $conditions);

$countStmt = $db->prepare("SELECT COUNT(*) FROM boarding_houses bh $whereClause");
$countStmt->execute($params);
$totalCount = intval($countStmt->fetchColumn() ?: 0);
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$listingsStmt = $db->prepare("
    SELECT bh.*, u.full_name AS owner_name,
           (SELECT pi.image_path FROM boarding_house_images pi WHERE pi.boarding_house_id = bh.id AND pi.is_cover = 1 LIMIT 1) AS primary_image,
           (SELECT pi2.image_path FROM boarding_house_images pi2 WHERE pi2.boarding_house_id = bh.id LIMIT 1) AS first_image,
           GROUP_CONCAT(a.name ORDER BY a.name SEPARATOR '||') AS amenity_names
    FROM boarding_houses bh
    JOIN users u ON u.id = bh.owner_id
    LEFT JOIN boarding_house_amenities bha ON bha.boarding_house_id = bh.id
    LEFT JOIN amenities a ON a.id = bha.amenity_id
    $whereClause
    GROUP BY bh.id
    ORDER BY bh.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$listingsStmt->execute($params);
$listings = $listingsStmt->fetchAll();

$citiesWhere = "status != 'inactive'";
if ($hasApprovalStatus) $citiesWhere .= " AND approval_status = 'approved'";
$citiesStmt = $db->query("SELECT DISTINCT city FROM boarding_houses WHERE $citiesWhere ORDER BY city");
$cities = $citiesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

// Quick stats
$activeWhere = "status != 'inactive'";
if ($hasApprovalStatus) $activeWhere .= " AND approval_status = 'approved'";
$activeListings = intval($db->query("SELECT COUNT(*) FROM boarding_houses WHERE $activeWhere")->fetchColumn() ?: 0);
$ownerCount = intval($db->query("SELECT COUNT(*) FROM users WHERE role = 'owner'")->fetchColumn() ?: 0);
$tenantCount = intval($db->query("SELECT COUNT(*) FROM users WHERE role = 'tenant'")->fetchColumn() ?: 0);

$typeLabels = ['solo_room'=>'Solo Room','shared_room'=>'Shared Room','bedspace'=>'Bedspace','studio'=>'Studio','apartment'=>'Apartment','entire_unit'=>'Entire Unit'];
$typeClasses = ['solo_room'=>'badge-solo','shared_room'=>'badge-shared','bedspace'=>'badge-shared','studio'=>'badge-studio','apartment'=>'badge-apartment','entire_unit'=>'badge-apartment'];

// Announcements (best-effort)
$announcement = null;
try {
    $announcement = $db->query("SELECT title, body FROM announcements WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1")->fetch() ?: null;
} catch (Throwable $e) {
    $announcement = null;
}

function buildQS(array $overrides = []): string {
    $p = array_merge($_GET, $overrides);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($p);
}

$currentUser = isLoggedIn() ? getCurrentUser() : null;
require_once __DIR__ . '/includes/header.php';
?>


<div class="main-content">
  <?php if ($announcement): ?>
    <section class="section" style="padding:22px 0 0 0">
      <div class="container" style="max-width:980px">
        <div class="card" style="border-left:6px solid var(--primary)">
          <div class="card-body">
            <div class="flex items-center gap-2" style="margin-bottom:8px;color:var(--secondary)">
              <i class="fas fa-bullhorn"></i>
              <strong><?= sanitize($announcement['title'] ?? 'Announcement') ?></strong>
            </div>
            <?php if (!empty($announcement['body'])): ?>
              <div class="text-muted" style="white-space:pre-wrap;line-height:1.8"><?= sanitize($announcement['body']) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>
  <?php endif; ?>
  <section class="hero">
    <div class="container">
      <div class="hero-content">
        <div class="hero-eyebrow"><i class="fas fa-map-marker-alt"></i> Philippines' #1 Boarding House Finder</div>
        <h1>Find Your Perfect<br><span>Boarding House</span></h1>
        <p>Browse hundreds of affordable, quality boarding houses near your school or workplace.</p>

        <form method="GET" action="#listings">
          <div class="search-bar">
            <div class="search-field">
              <i class="fas fa-search"></i>
              <input type="text" name="search" placeholder="Search by name or location..." value="<?= sanitize($search) ?>">
            </div>

            <div class="search-field">
              <i class="fas fa-location-dot"></i>
              <select name="city">
                <option value="">All Cities</option>
                <?php foreach ($cities as $c): ?>
                  <option value="<?= sanitize($c) ?>" <?= $city === $c ? 'selected' : '' ?>><?= sanitize($c) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="search-field">
              <i class="fas fa-house"></i>
              <select name="type">
                <option value="">Any Type</option>
                <?php foreach ($typeLabels as $k => $label): ?>
                  <option value="<?= sanitize($k) ?>" <?= $type === $k ? 'selected' : '' ?>><?= sanitize($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <button class="btn btn-primary" type="submit"><i class="fas fa-magnifying-glass"></i> Search</button>
          </div>
        </form>

        <div class="stats-row">
          <div class="stat-item"><div class="stat-number" data-count="<?= $activeListings ?>">0</div><div class="stat-label">Active Listings</div></div>
          <div class="stat-item"><div class="stat-number" data-count="<?= $ownerCount ?>">0</div><div class="stat-label">Property Owners</div></div>
          <div class="stat-item"><div class="stat-number" data-count="<?= $tenantCount ?>">0</div><div class="stat-label">Happy Tenants</div></div>
        </div>
      </div>
    </div>
  </section>

  <section class="section" id="listings">
    <div class="container">
      <div class="section-header">
        <h2 class="section-title">Latest Listings</h2>
        <div class="section-subtitle">Showing <?= number_format($totalCount) ?> result<?= $totalCount === 1 ? '' : 's' ?>.</div>
      </div>

      <form class="filter-bar" method="GET" action="#listings">
        <input type="hidden" name="search" value="<?= sanitize($search) ?>">
        <input type="hidden" name="city" value="<?= sanitize($city) ?>">
        <input type="hidden" name="type" value="<?= sanitize($type) ?>">

        <div class="filter-group">
          <label class="filter-label" for="minPrice">Min Price (₱)</label>
          <input class="form-control" id="minPrice" name="min_price" type="number" min="0" step="1" value="<?= sanitize($minPrice ?: '') ?>" placeholder="0">
        </div>

        <div class="filter-group">
          <label class="filter-label" for="maxPrice">Max Price (₱)</label>
          <input class="form-control" id="maxPrice" name="max_price" type="number" min="0" step="1" value="<?= sanitize($maxPrice ?: '') ?>" placeholder="Any">
        </div>

        <button class="btn btn-primary" type="submit"><i class="fas fa-filter"></i> Apply Filters</button>
        <a href="<?= SITE_URL ?>/index.php#listings" class="btn btn-ghost"><i class="fas fa-rotate-left"></i> Clear</a>
      </form>

      <?php if (empty($listings)): ?>
        <div class="empty-state">
          <i class="fas fa-magnifying-glass"></i>
          <h3>No listings found</h3>
          <p>Try adjusting your search or <a href="<?= SITE_URL ?>/index.php#listings">clear all filters</a>.</p>
        </div>
      <?php else: ?>
        <div class="property-grid">
          <?php foreach ($listings as $l):
              $imgPath = $l['primary_image'] ?? $l['first_image'] ?? null;
              $imgUrl  = $imgPath ? UPLOAD_URL . sanitize($imgPath) : null;
              $amenities = !empty($l['amenity_names']) ? explode('||', $l['amenity_names']) : [];
              $display = array_slice($amenities, 0, 4);
              $extra   = max(0, count($amenities) - 4);
              $tk = $l['accommodation_type'] ?? '';
              $status = $l['status'] ?? 'active';
              $availableRooms = intval($l['available_rooms'] ?? 0);
              $totalRooms = intval($l['total_rooms'] ?? 0);
              $locationText = trim((string)($l['location'] ?? ($l['address'] ?? '')));
              $cityText = trim((string)($l['city'] ?? ''));
              $fullLocation = trim($locationText . ($cityText !== '' ? ', ' . $cityText : ''));
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
            </div>

            <div class="property-body">
              <h2 class="property-name"><?= sanitize($l['name'] ?? '') ?></h2>
              <div class="property-location"><i class="fas fa-map-marker-alt"></i><?= sanitize($fullLocation) ?></div>
              <div class="property-price">
                <?= formatPrice((float)($l['price_min'] ?? 0)) ?>
                <?php if (!empty($l['price_max']) && (float)$l['price_max'] > (float)($l['price_min'] ?? 0)): ?> – <?= formatPrice((float)$l['price_max']) ?><?php endif; ?>
                <small>/month</small>
              </div>

              <?php if (!empty($display)): ?>
                <div class="property-amenities">
                  <?php foreach ($display as $am): ?>
                    <span class="amenity-chip"><i class="fas fa-check"></i><?= sanitize($am) ?></span>
                  <?php endforeach; ?>
                  <?php if ($extra > 0): ?><span class="amenity-chip">+<?= $extra ?> more</span><?php endif; ?>
                </div>
              <?php endif; ?>
            </div>

            <div class="property-footer">
              <div class="property-rooms">
                Rooms: <strong><?= $availableRooms ?></strong> / <?= $totalRooms ?> available
              </div>
              <a href="<?= SITE_URL ?>/pages/detail.php?id=<?= intval($l['id'] ?? 0) ?>" class="btn btn-primary btn-sm">View Details</a>
            </div>
          </article>
          <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
          <div class="pagination">
            <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= buildQS(['page' => max(1, $page - 1)]) ?>#listings">&laquo;</a>

            <?php
              $start = max(1, $page - 2);
              $end = min($totalPages, $page + 2);
              if ($start > 1) {
                echo '<a class="page-btn" href="' . buildQS(['page' => 1]) . '#listings">1</a>';
                if ($start > 2) echo '<span class="page-btn disabled">…</span>';
              }
              for ($i = $start; $i <= $end; $i++) {
                $cls = $i === $page ? 'page-btn active' : 'page-btn';
                echo '<a class="' . $cls . '" href="' . buildQS(['page' => $i]) . '#listings">' . $i . '</a>';
              }
              if ($end < $totalPages) {
                if ($end < $totalPages - 1) echo '<span class="page-btn disabled">…</span>';
                echo '<a class="page-btn" href="' . buildQS(['page' => $totalPages]) . '#listings">' . $totalPages . '</a>';
              }
            ?>

            <a class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= buildQS(['page' => min($totalPages, $page + 1)]) ?>#listings">&raquo;</a>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

