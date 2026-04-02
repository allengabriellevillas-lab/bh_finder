<?php
require_once __DIR__ . '/../includes/config.php';

requireLogin();

$bhId = intval($_POST['bh_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? 'toggle'));
$redirect = trim((string)($_POST['redirect'] ?? ''));

function safeRedirect(string $redirect): string {
    if ($redirect === '') return SITE_URL . '/index.php';

    // Allow same-origin absolute URLs.
    if (str_starts_with($redirect, SITE_URL)) return $redirect;

    // Allow relative paths only (avoid open redirects).
    if (!preg_match('/^https?:\/\//i', $redirect)) {
        $redirect = '/' . ltrim($redirect, '/');
        return SITE_URL . $redirect;
    }

    return SITE_URL . '/index.php';
}

function wantsJson(): bool {
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (str_contains($accept, 'application/json')) return true;
    $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    return $xrw === 'xmlhttprequest';
}

function jsonOut(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

if ($bhId <= 0) {
    if (wantsJson()) jsonOut(['ok' => false, 'message' => 'Invalid listing.'], 400);
    setFlash('error', 'Invalid listing.');
    header('Location: ' . safeRedirect($redirect));
    exit;
}

$db = getDB();
$favorite = false;
$message = '';

try {
    if ($action === 'remove') {
        $db->prepare("DELETE FROM favorites WHERE user_id = ? AND boarding_house_id = ?")
           ->execute([intval($_SESSION['user_id']), $bhId]);
        $favorite = false;
        $message = 'Removed from favorites.';
        setFlash('success', $message);
    } elseif ($action === 'add') {
        $db->prepare("INSERT IGNORE INTO favorites (user_id, boarding_house_id) VALUES (?,?)")
           ->execute([intval($_SESSION['user_id']), $bhId]);
        $favorite = true;
        $message = 'Saved to favorites.';
        setFlash('success', $message);
    } else {
        // toggle
        $del = $db->prepare("DELETE FROM favorites WHERE user_id = ? AND boarding_house_id = ?");
        $del->execute([intval($_SESSION['user_id']), $bhId]);
        if (intval($del->rowCount()) > 0) {
            $favorite = false;
            $message = 'Removed from favorites.';
            setFlash('success', $message);
        } else {
            $db->prepare("INSERT INTO favorites (user_id, boarding_house_id) VALUES (?,?)")
               ->execute([intval($_SESSION['user_id']), $bhId]);
            $favorite = true;
            $message = 'Saved to favorites.';
            setFlash('success', $message);
        }
    }
} catch (Throwable $e) {
    $message = 'Favorites feature is not available yet. Please run install.php or import the updated schema.sql.';
    if (wantsJson()) jsonOut(['ok' => false, 'message' => $message], 500);
    setFlash('error', $message);
}

if (wantsJson()) jsonOut(['ok' => true, 'favorite' => $favorite, 'message' => $message]);

header('Location: ' . safeRedirect($redirect));
exit;