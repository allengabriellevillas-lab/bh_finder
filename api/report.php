<?php
require_once __DIR__ . '/_bootstrap.php';

requireMethod('POST');

$raw = file_get_contents('php://input');
$payload = [];
if (is_string($raw) && $raw !== '') {
    $payload = json_decode($raw, true);
    if (!is_array($payload)) $payload = [];
}

$boardingHouseId = intval($payload['boarding_house_id'] ?? ($_POST['boarding_house_id'] ?? 0));
$reason = trim((string)($payload['reason'] ?? ($_POST['reason'] ?? '')));
$details = trim((string)($payload['details'] ?? ($_POST['details'] ?? '')));

$errors = [];
if ($boardingHouseId <= 0) $errors['boarding_house_id'] = 'Invalid listing.';
if ($reason === '') $errors['reason'] = 'Reason is required.';

if (!empty($errors)) jsonResponse(['error' => 'Validation failed', 'errors' => $errors], 422);

$db = getDB();
$reporterId = isLoggedIn() ? intval($_SESSION['user_id']) : null;

try {
    $ins = $db->prepare("INSERT INTO reports (boarding_house_id, reporter_id, reason, details) VALUES (?,?,?,?)");
    $ins->execute([$boardingHouseId, $reporterId, $reason, $details !== '' ? $details : null]);
    jsonResponse(['ok' => true]);
} catch (Throwable $e) {
    jsonResponse(['error' => 'Reporting unavailable'], 500);
}
