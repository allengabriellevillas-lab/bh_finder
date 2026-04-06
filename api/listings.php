<?php
require_once __DIR__ . '/_bootstrap.php';

requireMethod('GET');

$search = trim($_GET['search'] ?? '');
$city = trim($_GET['city'] ?? '');
$type = trim($_GET['type'] ?? '');
$limit = max(1, min(50, intval($_GET['limit'] ?? 20)));
$offset = max(0, intval($_GET['offset'] ?? 0));

$db = getDB();
ensureFeaturedListingColumns();

$bhCols = $db->query("SHOW COLUMNS FROM boarding_houses")->fetchAll() ?: [];
$bhFields = array_map(fn($r) => (string)($r['Field'] ?? ''), $bhCols);
$hasApprovalStatus = in_array('approval_status', $bhFields, true);
$hasIsActive = in_array('is_active', $bhFields, true);
$hasExpiresAt = in_array('expires_at', $bhFields, true);

$roomCols = [];
try {
    $roomCols = $db->query('SHOW COLUMNS FROM rooms')->fetchAll() ?: [];
} catch (Throwable $e) {
    $roomCols = [];
}
$roomFields = array_map(fn($r) => (string)($r['Field'] ?? ''), $roomCols);
$hasRoomSubscription = in_array('subscription_status', $roomFields, true);
$hasRoomSubEnd = in_array('end_date', $roomFields, true);
$enforceRoomSub = function_exists('roomSubscriptionEnforced') ? roomSubscriptionEnforced() : false;

$roomPriceExtraWhere = "price IS NOT NULL AND price > 0";
if ($enforceRoomSub && $hasRoomSubscription) {
    $roomPriceExtraWhere .= " AND subscription_status = 'active'";
    if ($hasRoomSubEnd) $roomPriceExtraWhere .= " AND (end_date IS NULL OR end_date >= CURDATE())";
}

$roomPriceJoin = "LEFT JOIN (\n"
    . "  SELECT boarding_house_id, MIN(price) AS room_price_min, MAX(price) AS room_price_max\n"
    . "  FROM rooms\n"
    . "  WHERE $roomPriceExtraWhere\n"
    . "  GROUP BY boarding_house_id\n"
    . ") rp ON rp.boarding_house_id = bh.id";


$conditions = ["bh.status != 'inactive'"];
if ($hasIsActive) $conditions[] = "bh.is_active = 1";
if ($hasApprovalStatus) $conditions[] = "bh.approval_status = 'approved'";
$params = [];

if ($search !== '') {
    $conditions[] = "(bh.name LIKE ? OR bh.location LIKE ? OR bh.city LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($city !== '') {
    $conditions[] = "bh.city = ?";
    $params[] = $city;
}
if ($type !== '') {
    $conditions[] = "bh.accommodation_type = ?";
    $params[] = $type;
}

$where = 'WHERE ' . implode(' AND ', $conditions);

// Only show listings from owners with an active subscription or trial
$where .= ownerActiveSqlWhere($db, 'bh.owner_id');

// Search monitoring (best-effort)
try {
    if (($search !== '') || ($city !== '') || ($type !== '')) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $uid = isLoggedIn() ? intval($_SESSION['user_id']) : null;
        $ins = $db->prepare("INSERT INTO search_logs (user_id, ip, channel, search, city, accommodation_type) VALUES (?,?,?,?,?,?)");
        $ins->execute([$uid, $ip, 'api', $search !== '' ? $search : null, $city !== '' ? $city : null, $type !== '' ? $type : null]);
    }
} catch (Throwable $e) {
    // ignore
}


$stmt = $db->prepare("
  SELECT
    bh.id,
    bh.name,
    bh.location,
    bh.city,
    COALESCE(rp.room_price_min, bh.price_min) AS price_min,
    COALESCE(rp.room_price_max, bh.price_max) AS price_max,
    bh.accommodation_type,
    bh.total_rooms,
    bh.available_rooms,
    bh.status,
    (SELECT pi.image_path FROM boarding_house_images pi WHERE pi.boarding_house_id = bh.id AND pi.is_cover = 1 LIMIT 1) AS primary_image,
    (SELECT plan FROM owner_subscriptions os WHERE os.owner_id = bh.owner_id AND os.status = 'active' AND (os.end_date IS NULL OR os.end_date >= CURDATE()) ORDER BY COALESCE(os.end_date, '9999-12-31') DESC, os.id DESC LIMIT 1) AS owner_plan_type
  FROM boarding_houses bh
  $roomPriceJoin
  $where
  ORDER BY (CASE WHEN bh.is_featured = 1 AND ((bh.featured_until IS NULL OR bh.featured_until >= NOW()) OR (bh.boost_until IS NULL OR bh.boost_until >= NOW())) THEN 1 ELSE 0 END) DESC, (CASE WHEN owner_plan_type = 'pro' THEN 1 ELSE 0 END) DESC, bh.created_at DESC
  LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll() ?: [];

$listings = array_map(function ($r) {
    $location = trim((string)($r['location'] ?? ''));
    $city = trim((string)($r['city'] ?? ''));
    $fullLocation = trim($location . (($location !== '' && $city !== '') ? ', ' : '') . $city);
    $img = (string)($r['primary_image'] ?? '');
    return [
        'id' => intval($r['id'] ?? 0),
        'name' => (string)($r['name'] ?? ''),
        'location' => $location,
        'city' => $city,
        'full_location' => $fullLocation,
        'price_min' => (float)($r['price_min'] ?? 0),
        'price_max' => $r['price_max'] !== null ? (float)$r['price_max'] : null,
        'accommodation_type' => (string)($r['accommodation_type'] ?? ''),
        'total_rooms' => intval($r['total_rooms'] ?? 0),
        'available_rooms' => intval($r['available_rooms'] ?? 0),
        'status' => (string)($r['status'] ?? ''),
        'primary_image_url' => $img !== '' ? (UPLOAD_URL . sanitize($img)) : null,
        'owner_plan_type' => (string)(['owner_plan_type'] ?? ''),
    ];
}, $rows);

jsonResponse(['data' => $listings]);



