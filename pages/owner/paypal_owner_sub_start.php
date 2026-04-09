<?php
require_once __DIR__ . '/../../includes/config.php';
requireOwner();
requireVerifiedOwner();

if (!paypalEnabled()) {
    setFlash('error', 'PayPal Sandbox is not configured. Set PAYPAL_CLIENT_ID and PAYPAL_SECRET in includes/secrets.local.php.');
    header('Location: ' . SITE_URL . '/pages/owner/subscriptions.php');
    exit;
}

$uid = intval($_SESSION['user_id'] ?? 0);
$plan = strtolower(trim((string)($_POST['plan'] ?? ($_GET['plan'] ?? 'basic'))));
if (!in_array($plan, ['basic', 'pro'], true)) $plan = 'basic';

$days = max(1, intval(getSetting('owner_subscription_days', '30') ?? '30'));

$pricing = ownerSubscriptionPricing($plan);
$amount = (float)($pricing['paid'] ?? 0);
$originalAmount = (float)($pricing['original'] ?? $amount);
$isIntro = intval($pricing['is_intro'] ?? 0);

$referralCode = strtoupper(trim((string)($_POST['referral_code'] ?? ($_GET['ref'] ?? ($_GET['referral_code'] ?? '')))));
$referralPct = 0.0;
$chargeAmount = $amount;
$referralNote = null;
if ($referralCode !== '') {
    $referralPct = referralDiscountPct($referralCode);
    if ($referralPct <= 0) {
        setFlash('error', 'Invalid referral code.');
        header('Location: ' . SITE_URL . '/pages/owner/subscriptions.php?plan=' . urlencode($plan));
        exit;
    }
    $chargeAmount = round(max(0.01, $amount * (1 - ($referralPct / 100.0))), 2);
    $referralNote = 'Referral code: ' . $referralCode . ' (-' . rtrim(rtrim(number_format($referralPct, 2, '.', ''), '0'), '.') . '%)';
}

$db = getDB();
ensurePaymentsSubscriptionIdColumn();
ensureOwnerSubscriptionTrialColumns();

try {
    $activeSub = getActiveOwnerSubscription($uid);

    $db->beginTransaction();

    $subId = intval($activeSub['id'] ?? 0);
    if ($subId <= 0) {
        $insSub = $db->prepare("INSERT INTO owner_subscriptions (owner_id, plan, status, is_trial) VALUES (?,?, 'pending', 0)");
        $insSub->execute([$uid, $plan]);
        $subId = intval($db->lastInsertId());
    }

    // Supersede older pending PayPal attempts for this subscription
    try {
        $note = 'Superseded by a new PayPal checkout start (' . date('Y-m-d H:i:s') . ').';
        $db->prepare("UPDATE payments SET status='rejected', admin_note=?
          WHERE user_id=? AND subscription_id=? AND kind='owner_subscription' AND method='paypal' AND status='pending'")
          ->execute([$note, $uid, $subId]);
    } catch (Throwable $e) {
        // ignore
    }

    $returnUrl = SITE_URL . '/pages/owner/paypal_owner_sub_return.php';
    $cancelUrl = SITE_URL . '/pages/owner/paypal_owner_sub_cancel.php';

    $customId = 'owner_sub:' . $subId
      . ';owner:' . $uid
      . ';plan:' . $plan
      . ';days:' . $days
      . ';is_intro:' . $isIntro
      . ';original:' . $originalAmount
      . ';paid:' . $amount
      . ($referralCode !== '' ? (';ref:' . $referralCode . ';ref_pct:' . $referralPct . ';charged:' . $chargeAmount) : '');

    $order = paypalCreateOrder((float)$chargeAmount, $returnUrl, $cancelUrl, $customId);
    $orderId = (string)($order['order_id'] ?? '');
    $approveUrl = (string)($order['approve_url'] ?? '');

    if ($orderId === '' || $approveUrl === '') {
        throw new RuntimeException('Unable to create PayPal order.');
    }

    $insPay = $db->prepare("INSERT INTO payments (user_id, subscription_id, kind, plan, plan_type, original_price, paid_price, is_intro, amount, method, paypal_order_id, admin_note, status)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'pending')");
    $insPay->execute([
        $uid,
        $subId,
        'owner_subscription',
        $plan,
        $plan,
        $originalAmount,
        $amount,
        $isIntro,
        $chargeAmount,
        'paypal',
        $orderId,
        $referralNote,
    ]);

    $db->commit();

    header('Location: ' . $approveUrl);
    exit;
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    setFlash('error', 'Unable to start PayPal checkout: ' . $e->getMessage());
    header('Location: ' . SITE_URL . '/pages/owner/subscriptions.php');
    exit;
}
