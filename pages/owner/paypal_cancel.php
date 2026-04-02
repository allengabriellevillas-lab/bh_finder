<?php
require_once __DIR__ . '/../../includes/config.php';
requireVerifiedOwner();

$db = getDB();
$orderId = trim((string)($_GET['token'] ?? ($_GET['orderId'] ?? '')));
$bhId = intval($_GET['bh_id'] ?? 0);
$roomId = intval($_GET['room_id'] ?? 0);

$redirect = SITE_URL . '/pages/owner/rooms.php' . ($bhId > 0 ? ('?bh_id=' . intval($bhId) . '#rooms') : '');

if ($orderId === '') {
    setFlash('info', 'PayPal checkout cancelled.');
    header('Location: ' . $redirect);
    exit;
}

try {
    $ownerId = intval($_SESSION['user_id'] ?? 0);

    $stmt = $db->prepare("SELECT p.id, p.room_id, p.status
      FROM payments p
      JOIN rooms r ON r.id = p.room_id
      JOIN boarding_houses bh ON bh.id = r.boarding_house_id
      WHERE p.user_id = ?
        AND bh.owner_id = ?
        AND p.method='paypal'\n        AND p.kind='room_subscription'
        AND p.paypal_order_id = ?
      ORDER BY p.id DESC
      LIMIT 1");
    $stmt->execute([$ownerId, $ownerId, $orderId]);
    $pay = $stmt->fetch();

    if ($pay && ($pay['status'] ?? '') === 'pending') {
        $db->beginTransaction();
        $note = 'PayPal checkout cancelled by owner (' . date('Y-m-d H:i:s') . ').';
        $db->prepare("UPDATE payments SET status='rejected', admin_note = ? WHERE id = ?")
          ->execute([$note, intval($pay['id'])]);

        $rid = intval($pay['room_id'] ?? 0);
        if ($rid <= 0) $rid = $roomId;

        // If there are no more pending payments for this room, revert room subscription status.
        if ($rid > 0) {
            $chk = $db->prepare("SELECT COUNT(*) FROM payments WHERE room_id = ? AND status='pending'");
            $chk->execute([$rid]);
            $pendingCount = intval($chk->fetchColumn() ?: 0);
            if ($pendingCount <= 0) {
                $db->prepare("UPDATE rooms SET subscription_status='inactive' WHERE id = ? AND subscription_status='pending'")
                  ->execute([$rid]);
            }
        }

        $db->commit();
    }

    setFlash('info', 'PayPal checkout cancelled.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    setFlash('error', 'Unable to process PayPal cancellation: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;

