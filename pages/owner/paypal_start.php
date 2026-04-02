<?php
require_once __DIR__ . '/../../includes/config.php';
requireVerifiedOwner();

$db = getDB();

if (!paypalEnabled()) {
    setFlash('error', 'PayPal Sandbox is not configured. Set PAYPAL_CLIENT_ID and PAYPAL_SECRET in your environment.');
    header('Location: ' . SITE_URL . '/pages/owner/rooms.php');
    exit;
}

$roomId = intval($_POST['room_id'] ?? ($_GET['room_id'] ?? 0));
$bhId = intval($_POST['bh_id'] ?? ($_GET['bh_id'] ?? 0));
$subscriptionAmount = floatval(getSetting('room_subscription_amount', '299') ?: '299');

if ($roomId <= 0) {
    setFlash('error', 'Invalid room.');
    header('Location: ' . SITE_URL . '/pages/owner/rooms.php');
    exit;
}

try {
    $ownerId = intval($_SESSION['user_id'] ?? 0);

    // Verify ownership of room
    $q = $db->prepare("SELECT r.id, r.boarding_house_id
      FROM rooms r
      JOIN boarding_houses bh ON bh.id = r.boarding_house_id
      WHERE r.id = ? AND bh.owner_id = ?
      LIMIT 1");
    $q->execute([$roomId, $ownerId]);
    $room = $q->fetch();
    if (!$room) throw new RuntimeException('Room not found.');

    $roomBhId = intval($room['boarding_house_id'] ?? 0);
    if ($bhId <= 0) $bhId = $roomBhId;

    // Clean up any older pending PayPal attempts for this room by this owner
    try {
        $note = 'Superseded by a new PayPal checkout start (' . date('Y-m-d H:i:s') . ').';
        $db->prepare("UPDATE payments
          SET status='rejected', admin_note = ?
          WHERE user_id = ? AND room_id = ? AND method = 'paypal' AND kind = 'room_subscription' AND status = 'pending'")
          ->execute([$note, $ownerId, $roomId]);
    } catch (Throwable $e) {
        // ignore
    }

    $returnUrl = SITE_URL . '/pages/owner/paypal_return.php?bh_id=' . intval($bhId) . '&room_id=' . intval($roomId);
    $cancelUrl = SITE_URL . '/pages/owner/paypal_cancel.php?bh_id=' . intval($bhId) . '&room_id=' . intval($roomId);

    $customId = 'room:' . $roomId . ';owner:' . $ownerId;
    $order = paypalCreateOrder((float)$subscriptionAmount, $returnUrl, $cancelUrl, $customId);
    $orderId = (string)($order['order_id'] ?? '');
    $approveUrl = (string)($order['approve_url'] ?? '');

    $db->beginTransaction();

    // Record payment
    $ins = $db->prepare("INSERT INTO payments (user_id, room_id, kind, amount, method, paypal_order_id, status)
      VALUES (?,?,?,?,?, ?, 'pending')");
    $ins->execute([
        $ownerId,
        $roomId,
        'room_subscription',
        (float)$subscriptionAmount,
        'paypal',
        $orderId,
    ]);

    // Mark room as pending
    $db->prepare("UPDATE rooms SET subscription_status = 'pending' WHERE id = ?")->execute([$roomId]);

    $db->commit();

    header('Location: ' . $approveUrl);
    exit;
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    setFlash('error', 'Unable to start PayPal payment: ' . $e->getMessage());
    header('Location: ' . SITE_URL . '/pages/owner/rooms.php' . ($bhId > 0 ? ('?bh_id=' . intval($bhId)) : ''));
    exit;
}



