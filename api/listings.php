<?php
require_once __DIR__ . '/_bootstrap.php';

requireMethod('GET');

$search = trim($_GET['search'] ?? '');
$city = trim($_GET['city'] ?? '');
$type = trim($_GET['type'] ?? '');
$limit = max(1, min(50, intval($_GET['limit'] ?? 20)));
$offset = max(0, intval($_GET['offset'] ?? 0));

$db = getDB();

$conditions = ["bh.status != 'inactive'"];
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

$stmt = $db->prepare("
  SELECT
    bh.id,
    bh.name,
    bh.location,
    bh.city,
    bh.price_min,
    bh.price_max,
    bh.accommodation_type,
    bh.total_rooms,
    bh.available_rooms,
    bh.status,
    (SELECT pi.image_path FROM boarding_house_images pi WHERE pi.boarding_house_id = bh.id AND pi.is_cover = 1 LIMIT 1) AS primary_image
  FROM boarding_houses bh
  $where
  ORDER BY bh.created_at DESC
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
    ];
}, $rows);

jsonResponse(['data' => $listings]);

