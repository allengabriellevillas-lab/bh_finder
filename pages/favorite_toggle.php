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
    if (!preg_match('/^https?:\\/\\//i', $redirect)) {
        $redirect = '/' . ltrim($redirect, '/');
        return SITE_URL . $redirect;
    }

    return SITE_URL . '/index.php';
}

if ($bhId <= 0) {
    setFlash('error', 'Invalid listing.');
    header('Location: ' . safeRedirect($redirect));
    exit;
}

$db = getDB();

try {
    if ($action === 'remove') {
        $db->prepare("DELETE FROM favorites WHERE user_id = ? AND boarding_house_id = ?")
           ->execute([intval($_SESSION['user_id']), $bhId]);
        setFlash('success', 'Removed from favorites.');
    } elseif ($action === 'add') {
        $db->prepare("INSERT IGNORE INTO favorites (user_id, boarding_house_id) VALUES (?,?)")
           ->execute([intval($_SESSION['user_id']), $bhId]);
        setFlash('success', 'Saved to favorites.');
    } else {
        // toggle
        $del = $db->prepare("DELETE FROM favorites WHERE user_id = ? AND boarding_house_id = ?");
        $del->execute([intval($_SESSION['user_id']), $bhId]);
        if (intval($del->rowCount()) > 0) {
            setFlash('success', 'Removed from favorites.');
        } else {
            $db->prepare("INSERT INTO favorites (user_id, boarding_house_id) VALUES (?,?)")
               ->execute([intval($_SESSION['user_id']), $bhId]);
            setFlash('success', 'Saved to favorites.');
        }
    }
} catch (Throwable $e) {
    setFlash('error', 'Favorites feature is not available yet. Please run install.php or import the updated schema.sql.');
}

header('Location: ' . safeRedirect($redirect));
exit;
