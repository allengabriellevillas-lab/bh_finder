<?php
require_once __DIR__ . '/../../includes/config.php';
requireOwner();
requireVerifiedOwner();

$db = getDB();
ensurePaymentsSubscriptionIdColumn();
$orderId = trim((string)($_GET['token'] ?? ($_GET['orderId'] ?? '')));

if ($orderId === '') {
    setFlash('error', 'Missing PayPal order token.');
    header('Location: ' . SITE_URL . '/pages/owner/subscriptions.php');
    exit;
}

try {
    $uid = intval($_SESSION['user_id'] ?? 0);

    $stmt = $db->prepare("SELECT p.*, os.owner_id
      FROM payments p
      JOIN owner_subscriptions os ON os.id = p.subscription_id
      WHERE p.user_id = ?
        AND os.owner_id = ?
        AND p.kind = 'owner_subscription'
        AND p.method = 'paypal'
        AND p.paypal_order_id = ?
      ORDER BY p.id DESC
      LIMIT 1");
    $stmt->execute([$uid, $uid, $orderId]);
    $pay = $stmt->fetch();
    if (!$pay) throw new RuntimeException('Payment record not found.');

    $paymentId = intval($pay['id'] ?? 0);
    $subId = intval($pay['subscription_id'] ?? 0);
    $plan = strtolower((string)($pay['plan'] ?? 'basic'));
    if (!in_array($plan, ['basic', 'pro'], true)) $plan = 'basic';

    $existingCapture = trim((string)($pay['paypal_capture_id'] ?? ''));

    $db->beginTransaction();

    if ($existingCapture === '') {
        $cap = paypalCaptureOrder($orderId);
        $captureId = trim((string)($cap['capture_id'] ?? ''));
        if ($captureId === '') throw new RuntimeException('PayPal capture returned no capture id.');

        $note = 'PayPal captured: ' . $captureId;
        $db->prepare("UPDATE payments
            SET paypal_capture_id = ?, admin_note = ?, status = 'approved', reviewed_at = NOW()
            WHERE id = ?")
          ->execute([$captureId, $note, $paymentId]);
    }

    // Activate/extend subscription immediately (PayPal capture = paid)
    $days = max(1, intval(getSetting('owner_subscription_days', '30') ?? '30'));
    $db->prepare("UPDATE owner_subscriptions
        SET plan = ?,
            status = 'active',
            start_date = IF(start_date IS NULL, CURDATE(), start_date),
            end_date = IF(end_date IS NOT NULL AND end_date >= CURDATE(), DATE_ADD(end_date, INTERVAL ? DAY), DATE_ADD(CURDATE(), INTERVAL ? DAY))
        WHERE id = ?")
      ->execute([$plan, $days, $days, $subId]);

    $subRow = null;
    try {
        $q = $db->prepare('SELECT * FROM owner_subscriptions WHERE id = ? LIMIT 1');
        $q->execute([$subId]);
        $subRow = $q->fetch() ?: null;
    } catch (Throwable $e) {
        $subRow = null;
    }

    if ($subRow) {
        $ownerId = intval($subRow['owner_id'] ?? 0);
        if ($ownerId > 0) {
            syncOwnerPropertiesToSubscription($ownerId, $subRow);
            if (notificationsEnabled()) {
                createNotification(
                    $ownerId,
                    'subscription_approved',
                    'Subscription active',
                    'Your PayPal subscription payment was received. Your listings are now active.',
                    SITE_URL . '/pages/owner/subscriptions.php'
                );
            }
        }
    }

    $db->commit();

    setFlash('success', 'Payment received. Your subscription is now active.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();

    // Best-effort: mark the payment as rejected
    try {
        $uid = intval($_SESSION['user_id'] ?? 0);
        $note = 'PayPal payment failed: ' . $e->getMessage();
        $db->prepare("UPDATE payments SET status='rejected', admin_note=? WHERE user_id=? AND kind='owner_subscription' AND method='paypal' AND paypal_order_id=? AND status='pending'")
          ->execute([$note, $uid, $orderId]);
    } catch (Throwable $e2) {
        // ignore
    }

    setFlash('error', 'PayPal payment failed: ' . $e->getMessage());
}

header('Location: ' . SITE_URL . '/pages/owner/subscriptions.php');
exit;

