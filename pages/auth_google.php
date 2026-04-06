<?php
require_once __DIR__ . '/../includes/config.php';

if (isLoggedIn()) {
    header('Location: ' . SITE_URL . '/index.php');
    exit;
}

function normalizeLoginRedirect(string $redirect, string $fallback): string {
    $redirect = trim($redirect);
    if ($redirect === '') return $fallback;

    if (preg_match('#^https?://#i', $redirect)) {
        $siteHost = (string)(parse_url(SITE_URL, PHP_URL_HOST) ?? '');
        $targetHost = (string)(parse_url($redirect, PHP_URL_HOST) ?? '');
        if ($siteHost !== '' && $targetHost !== '' && strcasecmp($siteHost, $targetHost) === 0) {
            return $redirect;
        }
        return $fallback;
    }

    $basePath = rtrim((string)(parse_url(SITE_URL, PHP_URL_PATH) ?? ''), '/');
    $redirect = '/' . ltrim($redirect, '/');

    if ($basePath !== '' && $basePath !== '/' && !str_starts_with($redirect, $basePath . '/')) {
        $redirect = $basePath . $redirect;
    }

    return $redirect;
}

function googleTokenInfo(string $idToken): ?array {
    if (!function_exists('curl_init')) {
        return null;
    }

    $idToken = trim($idToken);
    if ($idToken === '') return null;

    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

    $resp = curl_exec($ch);
    $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0);
    curl_close($ch);

    if ($resp === false || $code < 200 || $code >= 300) {
        return null;
    }

    $data = json_decode((string)$resp, true);
    if (!is_array($data)) return null;

    $aud = trim((string)($data['aud'] ?? ''));
    if ($aud === '' || $aud !== GOOGLE_CLIENT_ID) return null;

    $iss = trim((string)($data['iss'] ?? ''));
    if ($iss !== '' && !in_array($iss, ['accounts.google.com', 'https://accounts.google.com'], true)) {
        return null;
    }

    $exp = intval($data['exp'] ?? 0);
    if ($exp > 0 && time() >= $exp) return null;

    $email = trim((string)($data['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

    $emailVerified = $data['email_verified'] ?? null;
    if ($emailVerified !== null) {
        $isVerified = $emailVerified === true || $emailVerified === 1 || $emailVerified === 'true' || $emailVerified === '1';
        if (!$isVerified) return null;
    }

    return $data;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

if (trim(GOOGLE_CLIENT_ID) === '') {
    setFlash('error', 'Google sign-in is not configured.');
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

$credential = trim((string)($_POST['credential'] ?? ''));
$redirectRaw = (string)($_POST['redirect'] ?? '');
$desiredRole = in_array((string)($_POST['role'] ?? ''), ['tenant', 'owner'], true) ? (string)$_POST['role'] : 'tenant';

if ($credential === '') {
    setFlash('error', 'Google sign-in failed. Please try again.');
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

$claims = googleTokenInfo($credential);
if (!is_array($claims)) {
    setFlash('error', 'Google sign-in verification failed.');
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

$email = strtolower(trim((string)($claims['email'] ?? '')));
$name = trim((string)($claims['name'] ?? ''));
if ($name === '') {
    $name = trim((string)($claims['given_name'] ?? ''));
}
if ($name === '') {
    $name = strtok($email, '@') ?: 'User';
}

try {
    $db = getDB();

    $stmt = $db->prepare("SELECT id, full_name, role, is_active FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    $isNew = false;
    if (is_array($user) && !empty($user['id'])) {
        if (array_key_exists('is_active', $user) && intval($user['is_active'] ?? 1) === 0) {
            setFlash('error', 'Your account is deactivated.');
            header('Location: ' . SITE_URL . '/login.php?error=deactivated');
            exit;
        }

        $userId = intval($user['id']);
        $fullName = (string)($user['full_name'] ?? $name);
        $role = (string)($user['role'] ?? 'tenant');
    } else {
        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (full_name,email,password,role,phone) VALUES(?,?,?,?,?)");
        $stmt->execute([$name, $email, $hash, $desiredRole, null]);

        $isNew = true;
        $userId = intval($db->lastInsertId());
        $fullName = $name;
        $role = $desiredRole;
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['user_role'] = $role;

    setFlash('success', 'Welcome, ' . $fullName . '!');

    $fallback = (
        $role === 'admin' ? SITE_URL . '/pages/admin/dashboard.php' :
        ($role === 'owner' ? SITE_URL . '/pages/owner/dashboard.php' : SITE_URL . '/index.php')
    );

    if ($role === 'owner') {
        $dest = $isNew ? (SITE_URL . '/pages/owner/verification.php') : (SITE_URL . '/pages/owner/dashboard.php');
        header('Location: ' . $dest);
        exit;
    }

    header('Location: ' . normalizeLoginRedirect($redirectRaw, $fallback));
    exit;

} catch (Throwable $e) {
    setFlash('error', 'Unable to sign in with Google right now.');
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}
