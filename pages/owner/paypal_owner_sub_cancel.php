<?php
require_once __DIR__ . '/../../includes/config.php';
requireOwner();
requireVerifiedOwner();

$db = getDB();
$orderId = trim((string)($_GET['token'] ?? ($_GET['orderId'] ?? '')));

if ($orderId === '') {
    setFlash('info', 'PayPal checkout cancelled.');
    header('Location: ' . SITE_URL . '/pages/owner/subscriptions.php');
    exit;
}

try {
    $uid = intval($_SESSION['user_id'] ?? 0);

    $stmt = $db->prepare("SELECT p.id, p.subscription_id, p.status, os.status AS sub_status
      FROM payments p
      JOIN owner_subscriptions os ON os.id = p.subscription_id
      WHERE p.user_id = ?
        AND os.owner_id = ?
        AND p.kind='owner_subscription'
        AND p.method='paypal'
        AND p.paypal_order_id = ?
      ORDER BY p.id DESC
      LIMIT 1");
    $stmt->execute([$uid, $uid, $orderId]);
    $pay = $stmt->fetch();

    if ($pay && ($pay['status'] ?? '') === 'pending') {
        $db->beginTransaction();

        $note = 'PayPal checkout cancelled by owner (' . date('Y-m-d H:i:s') . ').';
        $db->prepare("UPDATE payments SET status='rejected', admin_note=? WHERE id=?")
          ->execute([$note, intval($pay['id'])]);

        $subId = intval($pay['subscription_id'] ?? 0);
        if ($subId > 0) {
            $chk = $db->prepare("SELECT COUNT(*) FROM payments WHERE subscription_id=? AND kind='owner_subscription' AND status='pending'");
            $chk->execute([$subId]);
            $pendingCount = intval($chk->fetchColumn() ?: 0);
            if ($pendingCount <= 0 && strtolower((string)($pay['sub_status'] ?? 'pending')) === 'pending') {
                $db->prepare("UPDATE owner_subscriptions SET status='rejected' WHERE id=? AND status='pending'")
                  ->execute([$subId]);
            }
        }

        $db->commit();
    }

    setFlash('info', 'PayPal checkout cancelled.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    setFlash('error', 'Unable to process PayPal cancellation: ' . $e->getMessage());
}

header('Location: ' . SITE_URL . '/pages/owner/subscriptions.php');
exit;
