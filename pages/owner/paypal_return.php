<?php
require_once __DIR__ . '/../../includes/config.php';
requireVerifiedOwner();

$db = getDB();
$orderId = trim((string)($_GET['token'] ?? ($_GET['orderId'] ?? '')));
$bhId = intval($_GET['bh_id'] ?? 0);

if ($orderId === '') {
    setFlash('error', 'Missing PayPal order token.');
    header('Location: ' . SITE_URL . '/pages/owner/rooms.php' . ($bhId > 0 ? ('?bh_id=' . intval($bhId) . '#rooms') : ''));
    exit;
}

$redirectBhId = $bhId;

try {
    $ownerId = intval($_SESSION['user_id'] ?? 0);

    // Make sure this order belongs to the logged-in owner and room
    $stmt = $db->prepare("SELECT p.id, p.room_id, p.status, p.paypal_capture_id, r.boarding_house_id
      FROM payments p
      JOIN rooms r ON r.id = p.room_id
      JOIN boarding_houses bh ON bh.id = r.boarding_house_id
      WHERE p.user_id = ?
        AND bh.owner_id = ?
        AND p.method = 'paypal'\n        AND p.kind = 'room_subscription'
        AND p.paypal_order_id = ?
      ORDER BY p.id DESC
      LIMIT 1");
    $stmt->execute([$ownerId, $ownerId, $orderId]);
    $pay = $stmt->fetch();
    if (!$pay) throw new RuntimeException('Payment record not found.');

    $roomId = intval($pay['room_id'] ?? 0);
    $redirectBhId = $redirectBhId > 0 ? $redirectBhId : intval($pay['boarding_house_id'] ?? 0);

    // Already captured (idempotent return)
    $existingCapture = trim((string)($pay['paypal_capture_id'] ?? ''));
    if ($existingCapture !== '') {
        setFlash('success', 'PayPal payment received. Waiting for admin approval.');
    } else {
        $cap = paypalCaptureOrder($orderId);
        $captureId = trim((string)($cap['capture_id'] ?? ''));
        if ($captureId === '') throw new RuntimeException('PayPal capture returned no capture id.');

        $db->beginTransaction();

        $upd = $db->prepare("UPDATE payments
          SET paypal_capture_id = ?,
              admin_note = ?,
              status = 'pending'
          WHERE id = ?");
        $note = 'PayPal captured: ' . $captureId;
        $upd->execute([$captureId, $note, intval($pay['id'])]);

        $db->prepare("UPDATE rooms SET subscription_status = 'pending' WHERE id = ?")->execute([$roomId]);

        $db->commit();

        setFlash('success', 'PayPal payment received. Waiting for admin approval.');
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();

    // Best-effort: mark payment rejected and revert room pending state
    try {
        $ownerId = intval($_SESSION['user_id'] ?? 0);
        $note = 'PayPal payment failed: ' . $e->getMessage();
        $db->prepare("UPDATE payments
          SET status='rejected', admin_note = ?
          WHERE user_id = ? AND method='paypal' AND kind='room_subscription' AND paypal_order_id = ? AND status='pending'")
          ->execute([$note, $ownerId, $orderId]);

        $roomId = intval($_GET['room_id'] ?? 0);
        if ($roomId > 0) {
            $db->prepare("UPDATE rooms SET subscription_status='inactive' WHERE id = ? AND subscription_status='pending'")
              ->execute([$roomId]);
        }
    } catch (Throwable $e2) {
        // ignore
    }

    setFlash('error', 'PayPal payment failed: ' . $e->getMessage());
}

header('Location: ' . SITE_URL . '/pages/owner/rooms.php' . ($redirectBhId > 0 ? ('?bh_id=' . intval($redirectBhId) . '#rooms') : ''));
exit;

