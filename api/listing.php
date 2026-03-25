<?php
require_once __DIR__ . '/_bootstrap.php';

requireMethod('GET');

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) jsonResponse(['error' => 'Missing id'], 400);

$db = getDB();

$bhCols = $db->query("SHOW COLUMNS FROM boarding_houses")->fetchAll() ?: [];
$bhFields = array_map(fn($r) => (string)($r['Field'] ?? ''), $bhCols);
$hasApprovalStatus = in_array('approval_status', $bhFields, true);
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


$stmt = $db->prepare("
  SELECT
    bh.*,
    u.full_name AS owner_name,
    u.phone AS owner_phone,
    u.email AS owner_email,
    u.created_at AS owner_since
  FROM boarding_houses bh
  JOIN users u ON u.id = bh.owner_id
  WHERE bh.id = ? AND bh.status != 'inactive'$approvalWhere
  LIMIT 1
");
$stmt->execute([$id]);
$bh = $stmt->fetch();
if (!$bh) jsonResponse(['error' => 'Listing not found'], 404);

$imagesStmt = $db->prepare("SELECT id, image_path, is_cover FROM boarding_house_images WHERE boarding_house_id = ? ORDER BY is_cover DESC, uploaded_at DESC");
$imagesStmt->execute([$id]);
$images = $imagesStmt->fetchAll() ?: [];

$amenitiesStmt = $db->prepare("SELECT a.id, a.name FROM amenities a JOIN boarding_house_amenities bha ON bha.amenity_id = a.id WHERE bha.boarding_house_id = ? ORDER BY a.name");
$amenitiesStmt->execute([$id]);
$amenities = $amenitiesStmt->fetchAll() ?: [];

$location = trim((string)($bh['location'] ?? ($bh['address'] ?? '')));
$city = trim((string)($bh['city'] ?? ''));
$fullLocation = trim($location . (($location !== '' && $city !== '') ? ', ' : '') . $city);

$data = [
    'id' => intval($bh['id'] ?? 0),
    'name' => (string)($bh['name'] ?? ''),
    'location' => $location,
    'city' => $city,
    'full_location' => $fullLocation,
    'description' => (string)($bh['description'] ?? ''),
    'rules' => (string)($bh['rules'] ?? ''),
    'price_min' => (float)($bh['price_min'] ?? 0),
    'price_max' => $bh['price_max'] !== null ? (float)$bh['price_max'] : null,
    'accommodation_type' => (string)($bh['accommodation_type'] ?? ''),
    'total_rooms' => intval($bh['total_rooms'] ?? 0),
    'available_rooms' => intval($bh['available_rooms'] ?? 0),
    'status' => (string)($bh['status'] ?? ''),
    'contact_phone' => (string)($bh['contact_phone'] ?? ($bh['owner_phone'] ?? '')),
    'contact_email' => (string)($bh['contact_email'] ?? ($bh['owner_email'] ?? '')),
    'owner' => [
        'name' => (string)($bh['owner_name'] ?? ''),
        'since' => (string)($bh['owner_since'] ?? ''),
    ],
    'images' => array_map(fn($img) => [
        'id' => intval($img['id'] ?? 0),
        'is_cover' => !!($img['is_cover'] ?? 0),
        'url' => UPLOAD_URL . sanitize((string)($img['image_path'] ?? '')),
    ], $images),
    'amenities' => array_map(fn($a) => [
        'id' => intval($a['id'] ?? 0),
        'name' => (string)($a['name'] ?? ''),
    ], $amenities),
];

jsonResponse(['data' => $data]);


