<?php
require_once __DIR__ . '/../../includes/config.php';
requireOwner();

$db = getDB();
$user = getCurrentUser() ?: [];
$me = $user;
$pageTitle = 'Dashboard';

$uid = intval($_SESSION['user_id'] ?? 0);

// Handle delete listing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $id = intval($_POST['id'] ?? 0);

    if ($action === 'delete' && $id > 0) {
        try {
            // Collect uploaded image filenames first so we can remove files after DB delete.
            $imgStmt = $db->prepare("SELECT pi.image_path
              FROM boarding_house_images pi
              JOIN boarding_houses bh ON bh.id = pi.boarding_house_id
              WHERE pi.boarding_house_id = ? AND bh.owner_id = ?");
            $imgStmt->execute([$id, $uid]);
            $images = $imgStmt->fetchAll() ?: [];

            $delStmt = $db->prepare('DELETE FROM boarding_houses WHERE id = ? AND owner_id = ?');
            $delStmt->execute([$id, $uid]);

            if (intval($delStmt->rowCount()) <= 0) {
                setFlash('error', 'Listing not found or you do not have permission to delete it.');
            } else {
                foreach ($images as $img) {
                    $path = (string)($img['image_path'] ?? '');
                    if ($path !== '') deleteUploadedFile($path);
                }
                setFlash('success', 'Listing deleted.');
            }
        } catch (Throwable $e) {
            setFlash('error', 'Failed to delete listing. Please try again.');
        }
    } else {
        setFlash('error', 'Invalid request.');
    }

    header('Location: dashboard.php');
    exit;
}

// Subscription
$activeSub = getActiveOwnerSubscription($uid);

maybeNotifyOwnerTrialLifecycle($uid);
$accessInfo = getOwnerAccessInfo($uid);
$planType = strtolower((string)($activeSub['plan'] ?? ''));
$hasPro = $activeSub && in_array($planType, ['pro','trial'], true);

$days = max(1, intval(getSetting('owner_subscription_days', '30') ?? '30'));
$basicPricing = ownerSubscriptionPricing('basic');
$proPricing = ownerSubscriptionPricing('pro');
$introActive = intval($proPricing['is_intro'] ?? 0) === 1;


// Listing stats
$statsStmt = $db->prepare("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active_count,
    SUM(CASE WHEN status='full' THEN 1 ELSE 0 END) AS full_count,
    SUM(CASE WHEN status='inactive' THEN 1 ELSE 0 END) AS inactive_count,
    SUM(total_rooms) AS total_rooms,
    SUM(available_rooms) AS available_rooms,
    SUM(views) AS total_views
  FROM boarding_houses
  WHERE owner_id = ?");
$statsStmt->execute([$uid]);
$stats = $statsStmt->fetch() ?: [];

$totalListings = intval($stats['total'] ?? 0);
$activeCount = intval($stats['active_count'] ?? 0);
$fullCount = intval($stats['full_count'] ?? 0);
$inactiveCount = intval($stats['inactive_count'] ?? 0);
$totalRooms = intval($stats['total_rooms'] ?? 0);
$availableRooms = intval($stats['available_rooms'] ?? 0);
$viewsTotal = intval($stats['total_views'] ?? 0);

// Pro analytics
$requestsTotal = 0;
$favoritesTotal = 0;

try {
    $stmt = $db->prepare("SELECT COUNT(*)
      FROM room_requests rr
      JOIN rooms r ON r.id = rr.room_id
      JOIN boarding_houses bh ON bh.id = r.boarding_house_id
      WHERE bh.owner_id = ?");
    $stmt->execute([$uid]);
    $requestsTotal = intval($stmt->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $requestsTotal = 0;
}

try {
    $stmt = $db->prepare('SELECT COUNT(*) FROM favorites f JOIN boarding_houses bh ON bh.id = f.boarding_house_id WHERE bh.owner_id = ?');
    $stmt->execute([$uid]);
    $favoritesTotal = intval($stmt->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $favoritesTotal = 0;
}

$listingsStmt = $db->prepare("SELECT
    bh.*,
    (SELECT pi.image_path FROM boarding_house_images pi
      WHERE pi.boarding_house_id = bh.id AND pi.is_cover = 1
      LIMIT 1) AS cover_image
    ,(SELECT MIN(r.price) FROM rooms r WHERE r.boarding_house_id = bh.id AND r.price > 0) AS room_price_min
    ,(SELECT MAX(r.price) FROM rooms r WHERE r.boarding_house_id = bh.id AND r.price > 0) AS room_price_max
    ,(SELECT COUNT(*) FROM favorites f WHERE f.boarding_house_id = bh.id) AS favorites_count
    ,(SELECT COUNT(*) FROM room_requests rr JOIN rooms r2 ON r2.id = rr.room_id WHERE r2.boarding_house_id = bh.id) AS requests_count
  FROM boarding_houses bh
  WHERE bh.owner_id = ?
  ORDER BY bh.created_at DESC");
$listingsStmt->execute([$uid]);
$listings = $listingsStmt->fetchAll() ?: [];

$showNavbar = false;
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="dash-shell">
<?php $activeNav = 'dashboard'; include __DIR__ . '/_partials/sidebar.php'; ?>

  <div class="dash-main">
<?php include __DIR__ . '/_partials/topbar.php'; ?>

    <div class="dash-content">
      <div class="dash-heading">
        <div>
          <h1 class="dash-title">Dashboard</h1>
          <div class="dash-breadcrumb">
            <a href="<?= SITE_URL ?>/index.php">Home</a>
            <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
            <span>Property Owner</span>
          </div>
        </div>
      </div>

      <main>
        <?php if (!isOwnerActive($uid)): ?>
          <div class="flash flash-error" style="margin-bottom:14px"><i class="fas fa-hourglass-end"></i> Your trial has ended. <a href="<?= SITE_URL ?>/pages/owner/subscriptions.php">Subscribe to continue receiving tenants</a>.</div>
        <?php elseif (($accessInfo['kind'] ?? '') === 'trial' && $accessInfo['days_left'] !== null): ?>
          <div class="flash flash-warning" style="margin-bottom:14px"><i class="fas fa-hourglass-half"></i> Trial ends in <strong><?= intval($accessInfo['days_left']) ?></strong> day<?= intval($accessInfo['days_left']) === 1 ? '' : 's' ?>. <a href="<?= SITE_URL ?>/pages/owner/subscriptions.php">Subscribe now</a>.</div>
        <?php endif; ?>
        <div class="stats-grid">
          <div class="stat-card">
            <div class="stat-icon stat-icon-primary"><i class="fas fa-building"></i></div>
            <div>
              <div class="stat-value" data-count="<?= $totalListings ?>">0</div>
              <div class="stat-name">Total Properties</div>
            </div>
          </div>

          <div class="stat-card">
            <div class="stat-icon stat-icon-secondary"><i class="fas fa-door-open"></i></div>
            <div>
              <div class="stat-value" data-count="<?= $totalRooms ?>">0</div>
              <div class="stat-name">Total Rooms</div>
            </div>
          </div>

          <div class="stat-card">
            <div class="stat-icon stat-icon-warning"><i class="fas fa-bed"></i></div>
            <div>
              <div class="stat-value" data-count="<?= $availableRooms ?>">0</div>
              <div class="stat-name">Rooms Available</div>
            </div>
          </div>

          <div class="stat-card">
            <div class="stat-icon <?= $activeSub ? 'stat-icon-success' : 'stat-icon-warning' ?>"><i class="fas fa-credit-card"></i></div>
            <div>
              <div class="stat-value" style="font-size:1.2rem">
                <?= $activeSub ? 'ACTIVE' : 'INACTIVE' ?>
              </div>
              <div class="stat-name">
                Plan: <?= $activeSub ? strtoupper(sanitize((string)($activeSub['plan'] ?? 'basic'))) : 'NONE' ?>
              </div>
            </div>
          </div>

          <div class="stat-card">
            <div class="stat-icon stat-icon-primary"><i class="fas fa-eye"></i></div>
            <div>
              <div class="stat-value" data-count="<?= $hasPro ? $viewsTotal : 0 ?>">0</div>
              <div class="stat-name">Views<?= $hasPro ? '' : ' (Pro feature)' ?></div>
            </div>
          </div>

          <div class="stat-card">
            <div class="stat-icon stat-icon-success"><i class="fas fa-handshake"></i></div>
            <div>
              <div class="stat-value" data-count="<?= $hasPro ? $requestsTotal : 0 ?>">0</div>
              <div class="stat-name">Requests<?= $hasPro ? '' : ' (Pro feature)' ?></div>
            </div>
          </div>

          <div class="stat-card">
            <div class="stat-icon stat-icon-warning"><i class="fas fa-heart"></i></div>
            <div>
              <div class="stat-value" data-count="<?= $hasPro ? $favoritesTotal : 0 ?>">0</div>
              <div class="stat-name">Favorites<?= $hasPro ? '' : ' (Pro feature)' ?></div>
            </div>
          </div>
        </div>

        <?php if (!$hasPro): ?>
          <div class="upgrade-banner" role="region" aria-label="Upgrade to Pro">
            <div class="upgrade-banner__left">
              <div class="upgrade-banner__icon"><i class="fas fa-bolt"></i></div>
              <div class="upgrade-banner__content">
                <div class="upgrade-banner__title">Upgrade to Pro</div>
                <div class="upgrade-banner__desc">Unlock analytics (views, requests, favorites) and featured listings.</div>
                <div class="upgrade-banner__meta">
                  <?php if ($introActive): ?>
                    <span class="upgrade-pill">Limited time offer</span>
                    <span class="upgrade-price"><strong><?= formatPrice((float)($proPricing['paid'] ?? 0)) ?></strong> <span class="upgrade-strike">Regular <?= formatPrice((float)($proPricing['original'] ?? 0)) ?></span></span>
                  <?php else: ?>
                    <span class="upgrade-price"><strong><?= formatPrice((float)($proPricing['original'] ?? 0)) ?></strong> / <?= intval($days) ?> days</span>
                  <?php endif; ?>
                </div>
                <ul class="upgrade-banner__list">
                  <li><i class="fas fa-star"></i> Featured listings (appear first)</li>
                  <li><i class="fas fa-chart-line"></i> Property analytics</li>
                  <li><i class="fas fa-infinity"></i> Unlimited properties</li>
                </ul>
              </div>
            </div>
            <div class="upgrade-banner__right">
              <a href="subscriptions.php?plan=pro" class="btn btn-primary"><i class="fas fa-arrow-up"></i> Upgrade</a>
            </div>
          </div>
        <?php endif; ?>

        <div class="card">
          <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
            <div>
              <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">My Listings</h2>
              <div class="text-muted text-sm" style="margin-top:4px">Manage your properties, status, and details.</div>
            </div>
            <a class="btn btn-primary" href="add_listing.php"><i class="fas fa-plus"></i> Add Listing</a>
          </div>

          <div class="card-body">
            <?php if (empty($listings)): ?>
              <div class="empty-state">
                <i class="fas fa-building"></i>
                <h3>No listings yet</h3>
                <p>Create your first listing to start receiving chats.</p>
                <a class="btn btn-primary" href="add_listing.php"><i class="fas fa-plus"></i> Add Listing</a>
              </div>
            <?php else: ?>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Listing</th>
                      <th>Status</th>
                      <th>Rooms</th>
                      <th>Price</th>
                      <th style="width:220px">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($listings as $l):
                      $status = $l['status'] ?? 'active';
                      $statusLabel = $status === 'full' ? 'Full' : ($status === 'inactive' ? 'Inactive' : 'Active');
                      $statusClass = $status === 'full' ? 'status-full' : ($status === 'inactive' ? 'status-inactive' : 'status-active');
                      $cover = $l['cover_image'] ?? null;
                      $coverUrl = $cover ? (UPLOAD_URL . sanitize($cover)) : null;
                      $priceMin = (float)($l['price_min'] ?? 0);
                      $priceMax = $l['price_max'] !== null ? (float)$l['price_max'] : null;
                    ?>
                    <tr>
                      <td>
                        <div class="flex items-center gap-3">
                          <?php if ($coverUrl): ?>
                            <img src="<?= $coverUrl ?>" alt="" style="width:56px;height:44px;object-fit:cover;border-radius:10px;border:1px solid var(--border)">
                          <?php else: ?>
                            <div style="width:56px;height:44px;border-radius:10px;border:1px solid var(--border);background:var(--bg);display:flex;align-items:center;justify-content:center;color:var(--text-light)">
                              <i class="fas fa-image"></i>
                            </div>
                          <?php endif; ?>

                          <div>
                            <div class="font-bold"><?= sanitize($l['name'] ?? '') ?></div>
                            <div class="text-muted text-xs"><i class="fas fa-location-dot" style="margin-right:6px"></i><?= sanitize($l['city'] ?? '') ?></div>
                          </div>
                        </div>
                      </td>
                      <td><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                      <td>
                        <span class="text-sm"><?= intval($l['available_rooms'] ?? 0) ?></span>
                        <span class="text-muted text-xs">/ <?= intval($l['total_rooms'] ?? 0) ?></span>
                      </td>
                      <td>
                        <div class="text-sm"><?= formatPrice($priceMin) ?></div>
                        <?php if ($priceMax !== null && $priceMax > $priceMin): ?>
                          <div class="text-muted text-xs">to <?= formatPrice($priceMax) ?></div>
                        <?php else: ?>
                          <div class="text-muted text-xs">per month</div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div class="flex flex-wrap gap-2">
                          <a class="btn btn-primary btn-sm btn-icon" href="<?= SITE_URL ?>/pages/detail.php?id=<?= intval($l['id'] ?? 0) ?>" title="View" aria-label="View"><i class="fas fa-eye"></i></a>
                          <a class="btn btn-ghost btn-sm btn-icon" href="edit_listing.php?id=<?= intval($l['id'] ?? 0) ?>" title="Edit" aria-label="Edit"><i class="fas fa-pen"></i></a>
                          <a class="btn btn-ghost btn-sm btn-icon" href="boost_listing.php?bh_id=<?= intval($l['id'] ?? 0) ?>" title="Boost" aria-label="Boost"><i class="fas fa-rocket"></i></a>
                          <form method="POST" action="dashboard.php" style="display:inline" onsubmit="return confirm('Delete this listing? This cannot be undone.');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= intval($l['id'] ?? 0) ?>">
                            <button type="submit" class="btn btn-danger btn-sm btn-icon" title="Delete" aria-label="Delete"><i class="fas fa-trash"></i></button>
                          </form>
                        </div>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>


              <?php if ($hasPro && !empty($listings)): ?>
                <div class="card mt-4" style="box-shadow:none;border:1px solid var(--border)">
                  <div class="card-header">
                    <h3 style="margin:0;font-family:var(--font-display);font-size:1.05rem;font-weight:800">Performance Feedback</h3>
                    <div class="text-muted text-sm" style="margin-top:4px">Quick tips to help you get more tenants.</div>
                  </div>
                  <div class="card-body">
                    <div class="table-wrap">
                      <table>
                        <thead>
                          <tr>
                            <th>Listing</th>
                            <th>Views</th>
                            <th>Favorites</th>
                            <th>Inquiries</th>
                            <th>Tip</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($listings as $l):
                            $views = intval($l['views'] ?? 0);
                            $fav = intval($l['favorites_count'] ?? 0);
                            $req = intval($l['requests_count'] ?? 0);
                            $hasImg = !empty($l['cover_image']);
                            $avail = intval($l['available_rooms'] ?? 0);

                            $tips = [];
                            if (!$hasImg) $tips[] = 'Add photos to build trust.';
                            if ($views < 10) $tips[] = 'Low views: try boosting your listing.';
                            if ($avail <= 0) $tips[] = 'No availability: update rooms or capacity.';
                            if (empty($tips)) $tips[] = 'Good job — keep responding fast to inquiries.';
                            $tipText = $tips[0];
                          ?>
                            <tr>
                              <td>
                                <div class="font-bold"><?= sanitize($l['name'] ?? '') ?></div>
                                <div class="text-muted text-xs"><?= sanitize($l['city'] ?? '') ?></div>
                              </td>
                              <td><?= number_format($views) ?></td>
                              <td><?= number_format($fav) ?></td>
                              <td><?= number_format($req) ?></td>
                              <td class="text-muted"><?= sanitize($tipText) ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              <?php elseif (!$hasPro): ?>
                <div class="card mt-4" style="box-shadow:none;border:1px solid var(--border)">
                  <div class="card-body">
                    <div class="text-muted">Upgrade to Pro to see performance analytics (views, favorites, inquiries) and tips.</div>
                  </div>
                </div>
              <?php endif; ?>
              <div class="text-muted text-xs mt-3">
                Status summary:
                <span class="badge status-active"><?= $activeCount ?> Active</span>
                <span class="badge status-full"><?= $fullCount ?> Full</span>
                <span class="badge status-inactive"><?= $inactiveCount ?> Inactive</span>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </main>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>



