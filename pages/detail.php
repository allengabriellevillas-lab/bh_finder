<?php
require_once __DIR__ . '/../includes/config.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . SITE_URL . '/index.php');
    exit;
}
$db = getDB();
$serviceFeePct = getServiceFeePercentage();
$userCols = [];
try {
    $userCols = $db->query("SHOW COLUMNS FROM users")->fetchAll() ?: [];
} catch (Throwable $e) {
    $userCols = [];
}
$userFields = array_map(fn($r) => (string)($r['Field'] ?? ''), $userCols);
$hasOwnerVerified = in_array('owner_verified', $userFields, true);
$hasOwnerVStatus = in_array('owner_verification_status', $userFields, true);
$ownerSelectExtra = '';
if ($hasOwnerVerified) $ownerSelectExtra .= ', u.owner_verified';
if ($hasOwnerVStatus) $ownerSelectExtra .= ', u.owner_verification_status';

$bhCols = $db->query("SHOW COLUMNS FROM boarding_houses")->fetchAll() ?: [];
$bhFields = array_map(fn($r) => (string)($r['Field'] ?? ''), $bhCols);
$hasApprovalStatus = in_array('approval_status', $bhFields, true);
$hasIsActive = in_array('is_active', $bhFields, true);
$hasExpiresAt = in_array('expires_at', $bhFields, true);
$activeWhere = '';
if ($hasIsActive) $activeWhere .= " AND bh.is_active = 1";
if ($hasExpiresAt) $activeWhere .= " AND (bh.expires_at IS NULL OR bh.expires_at >= NOW())";
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
        u.created_at AS owner_since,\r\n        (SELECT plan FROM owner_subscriptions os WHERE os.owner_id = bh.owner_id AND os.status = 'active' AND (os.end_date IS NULL OR os.end_date >= CURDATE()) ORDER BY COALESCE(os.end_date, '9999-12-31') DESC, os.id DESC LIMIT 1) AS owner_plan_type
        $ownerSelectExtra
    FROM boarding_houses bh
    JOIN users u ON u.id = bh.owner_id
    WHERE bh.id = ? AND bh.status != 'inactive'$activeWhere$approvalWhere
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

        // Track daily views for lightweight notifications
        ensureBoardingHouseDailyViewsTable();
        try {
            $db->prepare("INSERT INTO boarding_house_daily_views (boarding_house_id, view_date, views)
              VALUES (?, CURDATE(), 1)
              ON DUPLICATE KEY UPDATE views = views + 1")
              ->execute([$id]);

            $todayViews = 0;
            try {
                $q = $db->prepare('SELECT views FROM boarding_house_daily_views WHERE boarding_house_id = ? AND view_date = CURDATE() LIMIT 1');
                $q->execute([$id]);
                $todayViews = intval($q->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                $todayViews = 0;
            }

            if ($todayViews === 10 && notificationsEnabled()) {
                $ownerId = intval($bh['owner_id'] ?? 0);
                if ($ownerId > 0) {
                    $type = 'listing_views_10_today';
                    $link = SITE_URL . '/pages/owner/dashboard.php';

                    $chk = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = ? AND link_url = ? AND created_at >= CURDATE()');
                    $chk->execute([$ownerId, $type, $link]);
                    if (intval($chk->fetchColumn() ?: 0) === 0) {
                        $name = trim((string)($bh['name'] ?? ''));
                        $title = 'Your listing got 10 views today';
                        $body = $name !== '' ? ('"' . $name . '" reached 10 views today.') : 'One of your listings reached 10 views today.';
                        createNotification($ownerId, $type, $title, $body, $link);
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    } catch (Throwable $e) {
        // ignore
    }
}
$currentUser = isLoggedIn() ? getCurrentUser() : null;
$isFavorite = false;
if (isLoggedIn()) {
    try {
        $favStmt = $db->prepare("SELECT 1 FROM favorites WHERE user_id = ? AND boarding_house_id = ? LIMIT 1");
        $favStmt->execute([intval($_SESSION['user_id']), $id]);
        $isFavorite = (bool)$favStmt->fetchColumn();
    } catch (Throwable $e) {
        $isFavorite = false;
    }
}

$imagesStmt = $db->prepare("SELECT * FROM boarding_house_images WHERE boarding_house_id = ? ORDER BY is_cover DESC, uploaded_at DESC");
$imagesStmt->execute([$id]);
$images = $imagesStmt->fetchAll() ?: [];

$roomCols = [];
try {
    $roomCols = $db->query("SHOW COLUMNS FROM rooms")->fetchAll() ?: [];
} catch (Throwable $e) {
    $roomCols = [];
}
$roomFields = array_map(fn($r) => (string)($r['Field'] ?? ''), $roomCols);
$hasRoomAmenities = in_array('amenities', $roomFields, true);
$hasRoomAccommodationType = in_array('accommodation_type', $roomFields, true);
$hasRoomSubscription = in_array('subscription_status', $roomFields, true);
$hasRoomSubEnd = in_array('end_date', $roomFields, true);
$enforceRoomSub = function_exists('roomSubscriptionEnforced') ? roomSubscriptionEnforced() : false;
$roomsEmptyNote = 'The owner has not added room details for this property yet.';

$rooms = [];
$roomRequestsByRoom = [];
try {
    $roomSql = "SELECT id, room_name, price, capacity, current_occupants, status"
        . ($hasRoomAccommodationType ? ", accommodation_type" : "")
        . ($hasRoomAmenities ? ", amenities" : "")
        . (in_array('room_image', $roomFields, true) ? ", room_image" : "")
        . ($hasRoomSubscription ? ", subscription_status" : "")
        . ($hasRoomSubEnd ? ", end_date" : "")
        . "
      FROM rooms
      WHERE boarding_house_id = ? ORDER BY price ASC, id ASC";
    $roomsStmt = $db->prepare($roomSql);
    $roomsStmt->execute([$id]);
    $rooms = $roomsStmt->fetchAll() ?: [];

    if (isLoggedIn() && isTenant()) {
        $reqStmt = $db->prepare("SELECT room_id, status\r\n          FROM room_requests\r\n          WHERE tenant_id = ?\r\n            AND room_id IN (SELECT id FROM rooms WHERE boarding_house_id = ?)\r\n          ORDER BY created_at DESC");
        $reqStmt->execute([intval($_SESSION['user_id']), $id]);
        $seenRoomReq = [];
        foreach (($reqStmt->fetchAll() ?: []) as $reqRow) {
            $rid = intval($reqRow['room_id'] ?? 0);
            if ($rid <= 0) continue;
            if (isset($seenRoomReq[$rid])) continue;
            $seenRoomReq[$rid] = true;
            $roomRequestsByRoom[$rid] = (string)($reqRow['status'] ?? '');
        }
    }
} catch (Throwable $e) {
    $rooms = [];
    $roomRequestsByRoom = [];
}


$computedPriceMin = (float)($bh['price_min'] ?? 0);
$computedPriceMax = $bh['price_max'] ?? null;

// Prefer deriving the display price range from room prices (all rooms), then fallback to boarding_houses.price_min/max.
try {
    $pStmt = $db->prepare("SELECT MIN(price) AS min_price, MAX(price) AS max_price
      FROM rooms
      WHERE boarding_house_id = ?
        AND price IS NOT NULL
        AND price > 0");
    $pStmt->execute([$id]);
    $pRow = $pStmt->fetch() ?: [];
    if (($pRow['min_price'] ?? null) !== null) {
        $computedPriceMin = (float)$pRow['min_price'];
        $computedPriceMax = ($pRow['max_price'] ?? null) !== null ? (float)$pRow['max_price'] : null;
        if ($computedPriceMax !== null && $computedPriceMax <= $computedPriceMin) $computedPriceMax = null;
    }
} catch (Throwable $e) {
    // ignore
}
$amenitiesStmt = $db->prepare("SELECT a.* FROM amenities a JOIN boarding_house_amenities bha ON bha.amenity_id = a.id WHERE bha.boarding_house_id = ?");
$amenitiesStmt->execute([$id]);
$amenities = $amenitiesStmt->fetchAll() ?: [];

$typeLabels = ['solo_room' => 'Solo Room', 'shared_room' => 'Shared Room', 'studio' => 'Studio', 'apartment' => 'Apartment', 'bedspace' => 'Bedspace', 'entire_unit' => 'Entire Unit'];

$bhName = (string)($bh['name'] ?? 'Listing');
$bhLocation = trim((string)($bh['location'] ?? ($bh['address'] ?? '')));
$bhCity = trim((string)($bh['city'] ?? ''));
$bhFullLocation = trim($bhLocation . (($bhLocation !== '' && $bhCity !== '') ? ', ' : '') . $bhCity);
$bhStatusUi = boardingHouseStatusUi((string)($bh['status'] ?? 'active'));
$bhAvailableRooms = intval($bh['available_rooms'] ?? 0);
$bhTotalRooms = intval($bh['total_rooms'] ?? 0);
$bhPriceMin = $computedPriceMin;
$bhPriceMax = $computedPriceMax;
$bhContactPhone = trim((string)($bh['contact_phone'] ?? ($bh['owner_phone'] ?? '')));
$bhContactEmail = trim((string)($bh['contact_email'] ?? ($bh['owner_email'] ?? '')));
$ownerName = (string)($bh['owner_name'] ?? 'Property Owner');
$ownerSince = (string)($bh['owner_since'] ?? '');
$ownerVerified = ((string)($bh['owner_verification_status'] ?? '') === 'verified') || (intval($bh['owner_verified'] ?? 0) === 1);
$bhMapQuery = $bhFullLocation !== '' ? rawurlencode($bhFullLocation) : '';
$bhMapEmbedUrl = $bhMapQuery !== '' ? ("https://www.google.com/maps?q={$bhMapQuery}&output=embed") : '';
$bhMapLinkUrl = $bhMapQuery !== '' ? ("https://www.google.com/maps?q={$bhMapQuery}") : '';

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

// Ratings / Reviews (best-effort, supports older installs without the table yet)
$hasReviews = false;
$reviewSummary = ['avg' => null, 'count' => 0];
$reviews = [];
$reviewErrors = [];
$myReview = [];
$canReview = false;

if (isLoggedIn() && isTenant()) {
    try {
        $canStmt = $db->prepare("
            SELECT 1
            FROM room_requests rr
            JOIN rooms r ON r.id = rr.room_id
            WHERE rr.tenant_id = ?
              AND r.boarding_house_id = ?
              AND rr.status IN ('approved','occupied')
            LIMIT 1
        ");
        $canStmt->execute([intval($_SESSION['user_id']), $id]);
        $canReview = (bool)$canStmt->fetchColumn();
    } catch (Throwable $e) {
        $canReview = false;
    }
}

try {
    $sumStmt = $db->prepare("
        SELECT AVG(rating) AS avg_rating, COUNT(*) AS review_count
        FROM reviews
        WHERE boarding_house_id = ? AND is_hidden = 0
    ");
    $sumStmt->execute([$id]);
    $sumRow = $sumStmt->fetch() ?: [];

    $hasReviews = true;
    $reviewSummary['avg'] = ($sumRow['avg_rating'] ?? null) !== null ? (float)$sumRow['avg_rating'] : null;
    $reviewSummary['count'] = intval($sumRow['review_count'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_submit'])) {
        if (!isLoggedIn() || !isTenant()) {
            $reviewErrors['general'] = 'Please log in as a tenant to leave a review.';
        } elseif (!$canReview) {
            $reviewErrors['general'] = 'You can review after you have an approved stay for this property.';
        } else {
            $rating = intval($_POST['rating'] ?? 0);
            $reviewText = trim((string)($_POST['review'] ?? ''));
            $reviewLen = function_exists('mb_strlen') ? mb_strlen($reviewText) : strlen($reviewText);

            if ($rating < 1 || $rating > 5) $reviewErrors['rating'] = 'Please select a rating from 1 to 5.';
            if ($reviewLen > 2000) $reviewErrors['review'] = 'Review must be 2000 characters or less.';

            if (empty($reviewErrors)) {
                $uid = intval($_SESSION['user_id']);
                $saveStmt = $db->prepare("
                    INSERT INTO reviews (boarding_house_id, user_id, rating, review)
                    VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE rating = VALUES(rating), review = VALUES(review)
                ");
                $saveStmt->execute([$id, $uid, $rating, ($reviewText !== '' ? $reviewText : null)]);
                setFlash('success', 'Review saved.');
                header('Location: ' . SITE_URL . '/pages/detail.php?id=' . $id . '#reviews');
                exit;
            }
        }
    }

    $listStmt = $db->prepare("
        SELECT r.rating, r.review, r.created_at, u.full_name
        FROM reviews r
        JOIN users u ON u.id = r.user_id
        WHERE r.boarding_house_id = ? AND r.is_hidden = 0
        ORDER BY r.created_at DESC
        LIMIT 50
    ");
    $listStmt->execute([$id]);
    $reviews = $listStmt->fetchAll() ?: [];

    if (isLoggedIn() && isTenant()) {
        $mineStmt = $db->prepare("SELECT rating, review FROM reviews WHERE boarding_house_id = ? AND user_id = ? LIMIT 1");
        $mineStmt->execute([$id, intval($_SESSION['user_id'])]);
        $myReview = $mineStmt->fetch() ?: [];
    }
} catch (Throwable $e) {
    $hasReviews = false;
    $reviewSummary = ['avg' => null, 'count' => 0];
    $reviews = [];
    $myReview = [];
}
$pageTitle = sanitize($bhName);
require_once __DIR__ . '/../includes/header.php';
?>
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
      </div>

      <div class="detail-price">
        <?= formatPrice($bhPriceMin) ?>
        <?php if ($bhPriceMax !== null && (float)$bhPriceMax > $bhPriceMin): ?> &ndash; <?= formatPrice((float)$bhPriceMax) ?><?php endif; ?>
        <small>/month</small>
      </div>

      <div class="flex gap-3 mb-4" style="flex-wrap:wrap">
        <div class="amenity-chip"><i class="fas fa-door-open"></i> <?= $bhAvailableRooms ?> / <?= $bhTotalRooms ?> rooms available</div>
        <span class="property-status status-<?= sanitize($bhStatusUi) ?>" style="position:static;padding:6px 14px;border-radius:50px">
          <?= $bhStatusUi === 'full' ? 'Full' : ($bhStatusUi === 'active' ? '&bull; Available' : 'Inactive') ?>
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

      <div class="detail-section" id="rooms">
        <h2 class="detail-section-title"><i class="fas fa-door-open" style="color:var(--primary)"></i> Available Rooms</h2>

        <?php if (empty($rooms)): ?>
          <div class="empty-state compact" style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);">
            <i class="fas fa-bed"></i>
            <h3>No rooms listed yet</h3>
            <p><?= sanitize($roomsEmptyNote) ?></p>
          </div>
        <?php else: ?>
          <div class="room-grid">
            <?php foreach ($rooms as $room):
              $roomId = intval($room['id'] ?? 0);
              $capacity = max(1, intval($room['capacity'] ?? 1));
              $currentOccupants = max(0, intval($room['current_occupants'] ?? 0));
              if ($currentOccupants > $capacity) $currentOccupants = $capacity;
              $roomStatus = ($room['status'] ?? '') === 'occupied' || $currentOccupants >= $capacity ? 'occupied' : 'available';
              $roomSubOk = true;
              if ($enforceRoomSub && $hasRoomSubscription) {
                  $subStatus = (string)($room['subscription_status'] ?? '');
                  $endDate = (string)($room['end_date'] ?? '');
                  $roomSubOk = ($subStatus === 'active');
                  if ($roomSubOk && $endDate !== '') $roomSubOk = (strtotime($endDate) >= strtotime(date('Y-m-d')));
                  if (!$roomSubOk && $roomStatus === 'available') $roomStatus = 'inactive';
              }
              $requestStatus = $roomRequestsByRoom[$roomId] ?? '';
              $roomTypeValue = trim((string)($room['accommodation_type'] ?? ''));
              $roomAmenities = [];
              if ($hasRoomAmenities && !empty($room['amenities'])) {
                  $roomAmenities = preg_split('/[\r\n,]+/', (string)$room['amenities']) ?: [];
                  $roomAmenities = array_values(array_filter(array_map('trim', $roomAmenities), fn($v) => $v !== ''));
              }
              $roomImageUrl = !empty($room['room_image']) ? (UPLOAD_URL . sanitize($room['room_image'])) : null;
            ?>
              <article class="room-card <?= $roomStatus === 'occupied' ? 'is-occupied' : 'is-available' ?>">
                <?php if ($roomImageUrl): ?>
                  <div class="room-card-image">
                    <img src="<?= $roomImageUrl ?>" alt="<?= sanitize($room['room_name'] ?? ('Room ' . $roomId)) ?>">
                  </div>
                <?php endif; ?>
                <div class="room-card-head">
                  <div>
                    <h3 class="room-card-title"><?= sanitize($room['room_name'] ?? ('Room ' . $roomId)) ?></h3>
                    <?php if ($hasRoomAccommodationType && $roomTypeValue !== ''): ?>
                      <div class="room-card-type"><?= sanitize($typeLabels[$roomTypeValue] ?? ucwords(str_replace('_', ' ', $roomTypeValue))) ?></div>
                    <?php endif; ?>
                    <div class="room-card-meta">
                      <span><i class="fas fa-user-group"></i> Good for <?= $capacity ?></span>
                      <span><i class="fas fa-users"></i> <?= $currentOccupants ?>/<?= $capacity ?> occupied</span>
                    </div>
                  </div>
                  <span class="badge <?= $roomStatus === 'occupied' ? 'status-full' : ($roomStatus === 'inactive' ? 'status-inactive' : 'status-active') ?>">
                    <?= $roomStatus === 'occupied' ? 'Occupied' : ($roomStatus === 'inactive' ? 'Inactive' : 'Available') ?>
                  </span>
                </div>

                <div class="room-card-price">
  <?php
    $basePrice = (float)($room['price'] ?? 0);
    $feeAmount = round($basePrice * ($serviceFeePct / 100), 2);
    $totalPrice = $basePrice + $feeAmount;
  ?>
  <?= formatPrice($totalPrice) ?>
  <small>/month</small>
  <div class="text-muted text-xs" style="margin-top:6px">
    Room: <?= formatPrice($basePrice) ?> &middot; Service fee (<?= sanitize((string)$serviceFeePct) ?>%): <?= formatPrice($feeAmount) ?>
  </div>
</div>

                <div class="room-card-amenities">
                  <?php if (!empty($roomAmenities)): ?>
                    <?php foreach ($roomAmenities as $roomAmenity): ?>
                      <span class="amenity-chip"><i class="fas fa-check"></i><?= sanitize($roomAmenity) ?></span>
                    <?php endforeach; ?>
                  <?php elseif (!empty($amenities)): ?>
                    <?php foreach (array_slice($amenities, 0, 4) as $propertyAmenity): ?>
                      <span class="amenity-chip"><i class="fas fa-check"></i><?= sanitize($propertyAmenity['name'] ?? '') ?></span>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <span class="text-muted text-sm">No room amenities listed yet.</span>
                  <?php endif; ?>
                </div>

                <div class="room-card-actions">
                  <?php if ($roomStatus === 'available' && $roomSubOk && isLoggedIn() && isTenant() && intval($bh['owner_id'] ?? 0) !== intval($_SESSION['user_id'] ?? 0)): ?>
                    <?php if ($requestStatus === 'pending'): ?>
                      <button class="btn btn-ghost btn-sm" type="button" disabled><i class="fas fa-hourglass-half"></i> Request Pending</button>
                    <?php elseif (in_array($requestStatus, ['approved','occupied'], true)): ?>
                      <button class="btn btn-ghost btn-sm" type="button" disabled><i class="fas fa-circle-check"></i> Assigned to You</button>
                    <?php else: ?>
                      <form method="POST" action="<?= SITE_URL ?>/pages/room_request.php">
                        <input type="hidden" name="room_id" value="<?= $roomId ?>">
                        <input type="hidden" name="redirect" value="<?= sanitize('/pages/detail.php?id=' . $id . '#rooms') ?>">
                        <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-paper-plane"></i> Inquire / Reserve</button>
                      </form>
                    <?php endif; ?>
                  <?php elseif ($roomStatus === 'available' && !$roomSubOk): ?>
  <button class="btn btn-ghost btn-sm" type="button" disabled><i class="fas fa-ban"></i> Subscription Inactive</button>
<?php elseif ($roomStatus === 'available' && !isLoggedIn()): ?>
                    <a class="btn btn-primary btn-sm" href="<?= SITE_URL ?>/login.php?redirect=<?= urlencode('/pages/detail.php?id=' . $id . '#rooms') ?>">
                      <i class="fas fa-lock"></i> Login to Inquire
                    </a>
                  <?php else: ?>
                    <button class="btn btn-ghost btn-sm" type="button" disabled><i class="fas fa-ban"></i> Not Available</button>
                  <?php endif; ?>

                  <?php if (isLoggedIn()): ?>
                    <form method="POST" action="<?= SITE_URL ?>/pages/favorite_toggle.php">
                      <input type="hidden" name="bh_id" value="<?= intval($id) ?>">
                      <input type="hidden" name="action" value="<?= $isFavorite ? 'remove' : 'add' ?>">
                      <input type="hidden" name="redirect" value="<?= sanitize('/pages/detail.php?id=' . $id . '#rooms') ?>">
                      <button class="btn btn-ghost btn-sm" type="submit"><i class="fas fa-heart"></i> <?= $isFavorite ? 'Saved' : 'Save Listing' ?></button>
                    </form>
                  <?php else: ?>
                    <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/login.php?redirect=<?= urlencode('/pages/detail.php?id=' . $id . '#rooms') ?>"><i class="fas fa-heart"></i> Save Listing</a>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

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
            <strong><?= sanitize($ownerName) ?><?php if ($ownerVerified): ?> <span class="badge status-active" style="margin-left:6px"><i class="fas fa-circle-check"></i> Verified</span><?php endif; ?><?php if ($isPremiumOwner): ?> <span class="badge" style="margin-left:6px;background:rgba(var(--primary-rgb),0.12);color:var(--primary);border:1px solid rgba(var(--primary-rgb),0.25)"><i class="fas fa-crown"></i> Premium Owner</span><?php endif; ?></strong>
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


        <?php if (isLoggedIn()): ?>
          <form method="POST" action="<?= SITE_URL ?>/pages/favorite_toggle.php" style="margin-bottom:14px">
            <input type="hidden" name="bh_id" value="<?= intval($id) ?>">
            <input type="hidden" name="action" value="<?= $isFavorite ? 'remove' : 'add' ?>">
            <input type="hidden" name="redirect" value="<?= sanitize('/pages/detail.php?id=' . $id) ?>">
            <button class="btn btn-ghost btn-block" type="submit"><i class="fas fa-heart"></i> <?= $isFavorite ? 'Remove from Favorites' : 'Save Listing to Favorites' ?></button>
          </form>
        <?php endif; ?>

        <?php if (isLoggedIn() && isTenant() && intval($bh['owner_id'] ?? 0) !== intval($_SESSION['user_id'] ?? 0)): ?>
          <a class="btn btn-primary btn-block" style="margin-bottom:14px" href="<?= SITE_URL ?>/pages/chat.php?bh_id=<?= intval($id) ?>"><i class="fas fa-comments"></i> Chat with Property Owner</a>
        <?php elseif (!isLoggedIn()): ?>
          <a
            class="btn btn-primary btn-block"
            style="margin-bottom:14px"
            href="<?= SITE_URL ?>/login.php?redirect=<?= urlencode('/pages/detail.php?id=' . intval($id)) ?>"
          >
            <i class="fas fa-lock"></i> You need to login in order to message the owner
          </a>
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










