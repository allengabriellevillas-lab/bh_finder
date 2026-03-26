<?php
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . SITE_URL . '/index.php');
    exit;
}

requireTenant();

$db = getDB();
$roomId = intval($_POST['room_id'] ?? 0);
$redirect = trim((string)($_POST['redirect'] ?? ''));

function normalizeInternalRedirect(string $redirect): string {
    $redirect = trim($redirect);
    if ($redirect === '') return '';

    // Prevent open redirects; keep redirects internal to this app.
    if (preg_match('#^https?://#i', $redirect)) {
        $appHost = parse_url(SITE_URL, PHP_URL_HOST) ?: '';
        $targetHost = parse_url($redirect, PHP_URL_HOST) ?: '';
        if ($appHost !== '' && $targetHost !== '' && strcasecmp($appHost, $targetHost) !== 0) {
            return '';
        }
        $path = (string)(parse_url($redirect, PHP_URL_PATH) ?? '');
        $query = (string)(parse_url($redirect, PHP_URL_QUERY) ?? '');
        $frag = (string)(parse_url($redirect, PHP_URL_FRAGMENT) ?? '');
        $redirect = $path;
        if ($query !== '') $redirect .= '?' . $query;
        if ($frag !== '') $redirect .= '#' . $frag;
    }

    $basePath = (string)(parse_url(SITE_URL, PHP_URL_PATH) ?? '');
    $basePath = rtrim($basePath, '/');

    // If redirect already includes the app base path (e.g. /bh_finder/...), strip it.
    if ($basePath !== '' && str_starts_with($redirect, $basePath . '/')) {
        $redirect = substr($redirect, strlen($basePath));
    } elseif ($basePath !== '' && $redirect === $basePath) {
        $redirect = '/';
    }

    if ($redirect === '' || $redirect[0] !== '/') {
        $redirect = '/' . ltrim($redirect, '/');
    }

    return $redirect;
}

$redirect = normalizeInternalRedirect($redirect);

if ($roomId <= 0) {
    setFlash('error', 'Invalid room.');
    header('Location: ' . SITE_URL . '/index.php');
    exit;
}

try {
    $stmt = $db->prepare("SELECT r.*, bh.id AS bh_id
      FROM rooms r
      JOIN boarding_houses bh ON bh.id = r.boarding_house_id
      WHERE r.id = ?");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();
    if (!$room) throw new RuntimeException('Room not found.');

    $cap = max(1, intval($room['capacity'] ?? 1));
    $cur = max(0, intval($room['current_occupants'] ?? 0));
    if ($cur >= $cap || ($room['status'] ?? '') === 'occupied') {
        throw new RuntimeException('This room is already full.');
    }

    // Avoid duplicate pending requests
    $check = $db->prepare("SELECT id FROM room_requests WHERE room_id = ? AND tenant_id = ? AND status = 'pending' LIMIT 1");
    $check->execute([$roomId, intval($_SESSION['user_id'])]);
    if ($check->fetch()) {
        setFlash('success', 'You already have a pending request for this room.');
    } else {
        $ins = $db->prepare("INSERT INTO room_requests (room_id, tenant_id, status) VALUES (?,?, 'pending')");
        $ins->execute([$roomId, intval($_SESSION['user_id'])]);
        setFlash('success', 'Room request sent. The owner will review it.');
    }

    $bhId = intval($room['bh_id'] ?? 0);
    if ($redirect === '') {
        $redirect = '/pages/detail.php?id=' . $bhId . '#rooms';
    }

    header('Location: ' . SITE_URL . $redirect);
    exit;
} catch (Throwable $e) {
    setFlash('error', $e->getMessage() ?: 'Unable to send request.');
    if ($redirect === '') {
        header('Location: ' . SITE_URL . '/index.php');
    } else {
        header('Location: ' . SITE_URL . $redirect);
    }
    exit;
}
