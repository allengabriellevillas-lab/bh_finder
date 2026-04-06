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

$redirect = normalizeInternalRedirect($redirect);

if ($roomId <= 0) {
    if (wantsJson()) jsonOut(['ok' => false, 'message' => 'Invalid room.'], 400);
    setFlash('error', 'Invalid room.');
    header('Location: ' . SITE_URL . '/index.php');
    exit;
}

try {
    $stmt = $db->prepare("SELECT r.*, bh.id AS bh_id, bh.owner_id AS bh_owner_id, bh.name AS bh_name
      FROM rooms r
      JOIN boarding_houses bh ON bh.id = r.boarding_house_id
      WHERE r.id = ?");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();
    if (!$room) throw new RuntimeException('Room not found.');

    // Disallow requests when the owner has no active subscription/trial.
    $ownerId = intval($room['bh_owner_id'] ?? 0);
    if ($ownerId > 0 && !isOwnerActive($ownerId)) {
        throw new RuntimeException('This room is not available right now.');
    }

    // Existing request short-circuit (so users see a clear status even if the room later becomes full)
    $check = $db->prepare("SELECT id, status FROM room_requests
      WHERE room_id = ? AND tenant_id = ? AND status IN ('pending','approved','occupied')
      ORDER BY created_at DESC
      LIMIT 1");
    $check->execute([$roomId, intval($_SESSION['user_id'])]);
    $existing = $check->fetch();

    if ($existing) {
        $status = (string)($existing['status'] ?? 'pending');
        $msg = 'You already have an active request for this room.';
        if (wantsJson()) jsonOut(['ok' => true, 'status' => $status, 'message' => $msg]);
        setFlash('success', $msg);
    } else {
        // Subscription enforcement (best-effort)
        try {
            if (function_exists('roomSubscriptionEnforced') && roomSubscriptionEnforced()) {
                $sub = strtolower((string)($room['subscription_status'] ?? ''));
                $end = trim((string)($room['end_date'] ?? ''));
                $active = ($sub === 'active') && ($end === '' || strtotime($end) >= strtotime(date('Y-m-d')));
                if (!$active) throw new RuntimeException('This room is not available right now.');
            }
        } catch (Throwable $e) {
            // If columns are missing, ignore.
        }

        $cap = max(1, intval($room['capacity'] ?? 1));
        $cur = max(0, intval($room['current_occupants'] ?? 0));
        if ($cur >= $cap || ($room['status'] ?? '') === 'occupied') {
            throw new RuntimeException('This room is already full.');
        }

        $ins = $db->prepare("INSERT INTO room_requests (room_id, tenant_id, status) VALUES (?,?, 'pending')");
        $ins->execute([$roomId, intval($_SESSION['user_id'])]);

        // Notify owner (best-effort)
        try {
            if (notificationsEnabled()) {
                $ownerId = intval($room['bh_owner_id'] ?? 0);
                if ($ownerId > 0) {
                    $bhName = trim((string)($room['bh_name'] ?? ''));
                    $roomName = trim((string)($room['room_name'] ?? ''));
                    $title = 'New room request';
                    $body = ($roomName !== '' ? ('Room: ' . $roomName . '. ') : '')
                        . ($bhName !== '' ? ('Listing: ' . $bhName . '.') : 'A tenant requested a room.');
                    $link = SITE_URL . '/pages/owner/rooms.php#requests';
                    createNotification($ownerId, 'room_request', $title, $body, $link);
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        $msg = 'Room request sent. The owner will review it.';
        if (wantsJson()) jsonOut(['ok' => true, 'status' => 'pending', 'message' => $msg]);
        setFlash('success', $msg);
    }

    $bhId = intval($room['bh_id'] ?? 0);
    if ($redirect === '') {
        $redirect = '/pages/detail.php?id=' . $bhId . '#rooms';
    }

    header('Location: ' . SITE_URL . $redirect);
    exit;

} catch (Throwable $e) {
    $msg = $e->getMessage() ?: 'Unable to send request.';
    if (wantsJson()) jsonOut(['ok' => false, 'message' => $msg], 400);
    setFlash('error', $msg);
    if ($redirect === '') {
        header('Location: ' . SITE_URL . '/index.php');
    } else {
        header('Location: ' . SITE_URL . $redirect);
    }
    exit;
}
