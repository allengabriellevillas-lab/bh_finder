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
$name = trim((string)($payload['sender_name'] ?? ($_POST['sender_name'] ?? '')));
$email = trim((string)($payload['sender_email'] ?? ($_POST['sender_email'] ?? '')));
$phone = trim((string)($payload['sender_phone'] ?? ($_POST['sender_phone'] ?? '')));
$message = trim((string)($payload['message'] ?? ($_POST['message'] ?? '')));

$errors = [];
if ($boardingHouseId <= 0) $errors['boarding_house_id'] = 'Invalid listing.';
if ($name === '') $errors['sender_name'] = 'Name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['sender_email'] = 'Valid email required.';
if ($message === '') $errors['message'] = 'Message is required.';

if (!empty($errors)) jsonResponse(['error' => 'Validation failed', 'errors' => $errors], 422);

$db = getDB();
$senderId = isLoggedIn() ? intval($_SESSION['user_id']) : null;

$ins = $db->prepare("INSERT INTO contact_messages (boarding_house_id, sender_id, sender_name, sender_email, sender_phone, message) VALUES(?,?,?,?,?,?)");
$ins->execute([$boardingHouseId, $senderId, $name, $email, $phone, $message]);

jsonResponse(['ok' => true]);

