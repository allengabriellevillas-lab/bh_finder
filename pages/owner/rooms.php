<?php
require_once __DIR__ . '/../../includes/config.php';
requireOwner();
requireVerifiedOwner();

$db = getDB();
ensureSubscriptionExpiringNotifications(intval($_SESSION['user_id'] ?? 0));
$me = getCurrentUser();
$pageTitle = 'Room Management';

$roomCols = null;
try {
    $roomCols = $db->query("SHOW COLUMNS FROM rooms")->fetchAll() ?: [];
} catch (Throwable $e) {
    $roomCols = [];
}
$roomFields = array_map(fn($r) => (string)($r['Field'] ?? ''), $roomCols);
$hasRoomAmenities = in_array('amenities', $roomFields, true);
$hasRoomImage = in_array('room_image', $roomFields, true);
$hasRoomAccommodationType = in_array('accommodation_type', $roomFields, true);
$hasRoomSubscription = false; // Rooms are free (no per-room subscriptions)
$hasRoomSubEnd = false;
$roomStatusOptions = [
    'available' => 'Available',
    'occupied' => 'Occupied',
];
$roomTypeOptions = [
    'solo_room' => 'Solo Room',
    'shared_room' => 'Shared Room',
    'bedspace' => 'Bedspace',
    'studio' => 'Studio',
    'apartment' => 'Apartment',
    'entire_unit' => 'Entire Unit',
];

// Per-room subscriptions removed


function syncBoardingHouseRoomStats(PDO $db, int $bhId): void {
    try {
        $row = [];

        $enforce = roomSubscriptionEnforced();

        try {
            if ($enforce) {
                $stmt = $db->prepare("SELECT
                    COUNT(*) AS total_rooms,
                    SUM(CASE WHEN status = 'available'
                        AND subscription_status = 'active'
                        AND (end_date IS NULL OR end_date >= CURDATE())
                    THEN 1 ELSE 0 END) AS available_rooms
                  FROM rooms WHERE boarding_house_id = ?");
            } else {
                $stmt = $db->prepare("SELECT
                    COUNT(*) AS total_rooms,
                    SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_rooms
                  FROM rooms WHERE boarding_house_id = ?");
            }
            $stmt->execute([$bhId]);
            $row = $stmt->fetch() ?: [];
        } catch (Throwable $e) {
            $stmt = $db->prepare("SELECT
                COUNT(*) AS total_rooms,
                SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_rooms
              FROM rooms WHERE boarding_house_id = ?");
            $stmt->execute([$bhId]);
            $row = $stmt->fetch() ?: [];
        }

        $total = intval($row['total_rooms'] ?? 0);
        $available = intval($row['available_rooms'] ?? 0);
        $canonicalStatus = ($total > 0 && $available <= 0) ? 'full' : 'active';
        $status = boardingHouseStatusDbValue($db, $canonicalStatus);
        $inactiveDb = boardingHouseStatusDbValue($db, 'inactive');

        $upd = $db->prepare("UPDATE boarding_houses
  SET total_rooms = ?, available_rooms = ?, status = CASE WHEN status=? THEN status ELSE ? END
  WHERE id = ?");
        $upd->execute([$total, $available, $inactiveDb, $status, $bhId]);
    } catch (Throwable $e) {
        // ignore
    }
}

// Owner boarding houses
$bhStmt = $db->prepare("SELECT id, name FROM boarding_houses WHERE owner_id = ? ORDER BY created_at DESC");
$bhStmt->execute([intval($_SESSION['user_id'])]);
$boardingHouses = $bhStmt->fetchAll() ?: [];

$selectedBhId = isset($_GET['bh_id']) ? intval($_GET['bh_id']) : 0;
if (!isset($_GET['bh_id']) && !empty($boardingHouses)) {
    $selectedBhId = intval($boardingHouses[0]['id']);
}

// Validate selected boarding house belongs to owner (0 is allowed as "All listings")
$selectedBh = null;
foreach ($boardingHouses as $b) {
    if (intval($b['id']) === $selectedBhId) { $selectedBh = $b; break; }
}
if ($selectedBhId > 0 && !$selectedBh) {
    $selectedBhId = !empty($boardingHouses) ? intval($boardingHouses[0]['id']) : 0;
    $selectedBh = !empty($boardingHouses) ? $boardingHouses[0] : null;
}

// Keep listing counters in sync (so tenants see correct room availability)
foreach ($boardingHouses as $b) {
    $bid = intval($b['id'] ?? 0);
    if ($bid > 0) syncBoardingHouseRoomStats($db, $bid);
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $bhId = intval($_POST['bh_id'] ?? $selectedBhId);

    $isRoomCrud = in_array($action, ['add_room', 'update_room', 'delete_room'], true);
    if ($isRoomCrud) {
        $owns = false;
        foreach ($boardingHouses as $b) { if (intval($b['id']) === $bhId) { $owns = true; break; } }
        if (!$owns || $bhId <= 0) {
            setFlash('error', 'Please select a valid boarding house.');
            header('Location: rooms.php');
            exit;
        }
    }

    try {
        if ($action === 'add_room' || $action === 'update_room') {
            $roomId = intval($_POST['room_id'] ?? 0);
            $roomName = trim((string)($_POST['room_name'] ?? ''));
            $price = (float)($_POST['price'] ?? 0);
            $capacity = max(1, intval($_POST['capacity'] ?? 1));
            $current = max(0, intval($_POST['current_occupants'] ?? 0));
            $roomAmenities = trim((string)($_POST['amenities'] ?? ''));
            $roomType = trim((string)($_POST['accommodation_type'] ?? ''));
            $removeRoomImage = !empty($_POST['remove_room_image']);
            $requestedStatus = strtolower(trim((string)($_POST['status'] ?? '')));
            if ($current > $capacity) $current = $capacity;

            if ($roomName === '') throw new RuntimeException('Room name is required.');
            if ($price < 0) $price = 0;
            if (!array_key_exists($requestedStatus, $roomStatusOptions)) $requestedStatus = '';
            if (!array_key_exists($roomType, $roomTypeOptions)) $roomType = '';

            $status = ($current >= $capacity || $requestedStatus === 'occupied') ? 'occupied' : 'available';

            if ($action === 'add_room') {
                // Subscription enforcement (optional)
                if ($hasRoomSubscription && roomSubscriptionEnforced()) {
                    $cntStmt = $db->prepare("SELECT COUNT(*) FROM rooms WHERE boarding_house_id = ?");
                    $cntStmt->execute([$bhId]);
                    $existing = intval($cntStmt->fetchColumn() ?: 0);
                    if ($existing > 0) {
                        $actStmt = $db->prepare("SELECT COUNT(*) FROM rooms WHERE boarding_house_id = ? AND subscription_status = 'active' AND (end_date IS NULL OR end_date >= CURDATE())");
                        $actStmt->execute([$bhId]);
                        $active = intval($actStmt->fetchColumn() ?: 0);
                        if ($active <= 0) {
                            throw new RuntimeException('Subscription required: please activate at least one room subscription before adding more rooms.');
                        }
                    }
                }
                if ($hasRoomAmenities || $hasRoomAccommodationType) {
                    if ($hasRoomImage) {
                        $roomImageName = null;
                        if (!empty($_FILES['room_image']['name']) && is_array($_FILES['room_image'])) {
                            $uploaded = uploadImage($_FILES['room_image'], 'room' . $bhId);
                            if ($uploaded === false) throw new RuntimeException('Room image upload failed. Please use JPG, PNG, or WebP under 5MB.');
                            $roomImageName = $uploaded;
                        }
                        $cols = ['boarding_house_id', 'room_name', 'price', 'capacity', 'current_occupants'];
                        $vals = [$bhId, $roomName, $price, $capacity, $current];
                        if ($hasRoomAccommodationType) { $cols[] = 'accommodation_type'; $vals[] = $roomType !== '' ? $roomType : null; }
                        if ($hasRoomAmenities) { $cols[] = 'amenities'; $vals[] = $roomAmenities !== '' ? $roomAmenities : null; }
                        $cols[] = 'room_image'; $vals[] = $roomImageName;
                        $cols[] = 'status'; $vals[] = $status;
                        $ins = $db->prepare("INSERT INTO rooms (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")");
                        $ins->execute($vals);
                    } else {
                        $cols = ['boarding_house_id', 'room_name', 'price', 'capacity', 'current_occupants'];
                        $vals = [$bhId, $roomName, $price, $capacity, $current];
                        if ($hasRoomAccommodationType) { $cols[] = 'accommodation_type'; $vals[] = $roomType !== '' ? $roomType : null; }
                        if ($hasRoomAmenities) { $cols[] = 'amenities'; $vals[] = $roomAmenities !== '' ? $roomAmenities : null; }
                        $cols[] = 'status'; $vals[] = $status;
                        $ins = $db->prepare("INSERT INTO rooms (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")");
                        $ins->execute($vals);
                    }
                } else {
                    if ($hasRoomImage) {
                        $roomImageName = null;
                        if (!empty($_FILES['room_image']['name']) && is_array($_FILES['room_image'])) {
                            $uploaded = uploadImage($_FILES['room_image'], 'room' . $bhId);
                            if ($uploaded === false) throw new RuntimeException('Room image upload failed. Please use JPG, PNG, or WebP under 5MB.');
                            $roomImageName = $uploaded;
                        }
                        $ins = $db->prepare("INSERT INTO rooms (boarding_house_id, room_name, price, capacity, current_occupants, room_image, status)
                            VALUES (?,?,?,?,?,?,?)");
                        $ins->execute([$bhId, $roomName, $price, $capacity, $current, $roomImageName, $status]);
                    } else {
                        $ins = $db->prepare("INSERT INTO rooms (boarding_house_id, room_name, price, capacity, current_occupants, status)
                            VALUES (?,?,?,?,?,?)");
                        $ins->execute([$bhId, $roomName, $price, $capacity, $current, $status]);
                    }
                }
                $createdRoomId = intval($db->lastInsertId() ?: 0);
                if ($createdRoomId > 0 && $hasRoomSubscription) {
                    $_SESSION['subscription_modal_room_id'] = $createdRoomId;
                    $_SESSION['subscription_modal_bh_id'] = $bhId;
                }

                setFlash('success', 'Room added.');
            } else {
                $chk = $db->prepare("SELECT id" . ($hasRoomImage ? ", room_image" : "") . " FROM rooms WHERE id = ? AND boarding_house_id = ? LIMIT 1");
                $chk->execute([$roomId, $bhId]);
                $existingRoom = $chk->fetch();
                if (!$existingRoom) throw new RuntimeException('Room not found.');

                $nextRoomImage = $hasRoomImage ? (string)($existingRoom['room_image'] ?? '') : '';
                $newRoomImageUploaded = false;
                if ($hasRoomImage && !empty($_FILES['room_image']['name']) && is_array($_FILES['room_image'])) {
                    $uploaded = uploadImage($_FILES['room_image'], 'room' . $bhId);
                    if ($uploaded === false) throw new RuntimeException('Room image upload failed. Please use JPG, PNG, or WebP under 5MB.');
                    $nextRoomImage = $uploaded;
                    $newRoomImageUploaded = true;
                } elseif ($hasRoomImage && $removeRoomImage) {
                    $nextRoomImage = '';
                }

                if ($hasRoomAmenities || $hasRoomAccommodationType) {
                    if ($hasRoomImage) {
                        $set = ['room_name=?', 'price=?', 'capacity=?', 'current_occupants=?'];
                        $vals = [$roomName, $price, $capacity, $current];
                        if ($hasRoomAccommodationType) { $set[] = 'accommodation_type=?'; $vals[] = $roomType !== '' ? $roomType : null; }
                        if ($hasRoomAmenities) { $set[] = 'amenities=?'; $vals[] = $roomAmenities !== '' ? $roomAmenities : null; }
                        $set[] = 'room_image=?'; $vals[] = $nextRoomImage !== '' ? $nextRoomImage : null;
                        $set[] = 'status=?'; $vals[] = $status;
                        $vals[] = $roomId; $vals[] = $bhId;
                        $upd = $db->prepare("UPDATE rooms SET " . implode(', ', $set) . " WHERE id = ? AND boarding_house_id = ?");
                        $upd->execute($vals);
                    } else {
                        $set = ['room_name=?', 'price=?', 'capacity=?', 'current_occupants=?'];
                        $vals = [$roomName, $price, $capacity, $current];
                        if ($hasRoomAccommodationType) { $set[] = 'accommodation_type=?'; $vals[] = $roomType !== '' ? $roomType : null; }
                        if ($hasRoomAmenities) { $set[] = 'amenities=?'; $vals[] = $roomAmenities !== '' ? $roomAmenities : null; }
                        $set[] = 'status=?'; $vals[] = $status;
                        $vals[] = $roomId; $vals[] = $bhId;
                        $upd = $db->prepare("UPDATE rooms SET " . implode(', ', $set) . " WHERE id = ? AND boarding_house_id = ?");
                        $upd->execute($vals);
                    }
                } else {
                    if ($hasRoomImage) {
                        $upd = $db->prepare("UPDATE rooms
                          SET room_name=?, price=?, capacity=?, current_occupants=?, room_image=?, status=?
                          WHERE id = ? AND boarding_house_id = ?");
                        $upd->execute([$roomName, $price, $capacity, $current, $nextRoomImage !== '' ? $nextRoomImage : null, $status, $roomId, $bhId]);
                    } else {
                        $upd = $db->prepare("UPDATE rooms
                          SET room_name=?, price=?, capacity=?, current_occupants=?, status=?
                          WHERE id = ? AND boarding_house_id = ?");
                        $upd->execute([$roomName, $price, $capacity, $current, $status, $roomId, $bhId]);
                    }
                }

                if ($hasRoomImage) {
                    $oldRoomImage = (string)($existingRoom['room_image'] ?? '');
                    if ($newRoomImageUploaded && $oldRoomImage !== '' && $oldRoomImage !== $nextRoomImage) {
                        deleteUploadedFile($oldRoomImage);
                    } elseif ($removeRoomImage && $oldRoomImage !== '') {
                        deleteUploadedFile($oldRoomImage);
                    }
                }
                setFlash('success', 'Room updated.');
            }

            syncBoardingHouseRoomStats($db, $bhId);
        } elseif ($action === 'delete_room') {
            $roomId = intval($_POST['room_id'] ?? 0);
            $roomImageToDelete = '';
            if ($hasRoomImage && $roomId > 0) {
                $imgStmt = $db->prepare("SELECT room_image FROM rooms WHERE id = ? AND boarding_house_id = ? LIMIT 1");
                $imgStmt->execute([$roomId, $bhId]);
                $roomImageToDelete = (string)($imgStmt->fetchColumn() ?: '');
            }
            $del = $db->prepare("DELETE FROM rooms WHERE id = ? AND boarding_house_id = ?");
            $del->execute([$roomId, $bhId]);
            if ($hasRoomImage && $roomImageToDelete !== '') deleteUploadedFile($roomImageToDelete);
            setFlash('success', 'Room deleted.');
            syncBoardingHouseRoomStats($db, $bhId);
        } elseif ($action === 'pay_subscription') {
            if (!$hasRoomSubscription) {
                throw new RuntimeException('Subscriptions are not available yet. Please run install.php or import the updated schema.sql.');
            }

            $roomId = intval($_POST['room_id'] ?? 0);
            if ($roomId <= 0) throw new RuntimeException('Invalid room.');

            $q = $db->prepare("SELECT r.id, r.boarding_house_id
              FROM rooms r
              JOIN boarding_houses bh ON bh.id = r.boarding_house_id
              WHERE r.id = ? AND bh.owner_id = ?
              LIMIT 1");
            $q->execute([$roomId, intval($_SESSION['user_id'])]);
            $room = $q->fetch();
            if (!$room) throw new RuntimeException('Room not found.');

            $proofPath = null;

            {
                $file = $_FILES['payment_proof'] ?? null;
                if (!is_array($file) || empty($file['name'])) {
                    throw new RuntimeException('Please upload a receipt screenshot, or use PayPal Sandbox checkout.');
                }
                $uploaded = uploadImage($file, 'pay_room_' . $roomId);
                if ($uploaded === false) {
                    throw new RuntimeException('Proof upload failed. Please use JPG, PNG, or WebP under 5MB.');
                }
                $proofPath = $uploaded;
            }

            try {
                $ins = $db->prepare("INSERT INTO payments (user_id, room_id, amount, method, proof_path, status)
                  VALUES (?,?,?,?,?, 'pending')");
                $ins->execute([
                    intval($_SESSION['user_id']),
                    $roomId,
                    (float)$subscriptionAmount,
                    'proof_upload',
                    $proofPath,
                ]);

                $db->prepare("UPDATE rooms SET subscription_status = 'pending' WHERE id = ?")
                   ->execute([$roomId]);

                setFlash('success', 'Payment submitted. Waiting for admin approval.');
            } catch (Throwable $e) {
                if ($proofPath) deleteUploadedFile($proofPath);
                throw $e;
            }
        } elseif ($action === 'assign_tenant') {
            $roomId = intval($_POST['room_id'] ?? 0);
            $tenantId = intval($_POST['tenant_id'] ?? 0);

            if ($roomId <= 0 || $tenantId <= 0) {
                throw new RuntimeException('Invalid room or tenant.');
            }

            $db->beginTransaction();

            $roomQ = $db->prepare("SELECT r.id, r.boarding_house_id, r.capacity, r.current_occupants, r.status
              FROM rooms r
              JOIN boarding_houses bh ON bh.id = r.boarding_house_id
              WHERE r.id = ? AND bh.owner_id = ?
              FOR UPDATE");
            $roomQ->execute([$roomId, intval($_SESSION['user_id'])]);
            $room = $roomQ->fetch();
            if (!$room) throw new RuntimeException('Room not found.');

            $cap = max(1, intval($room['capacity'] ?? 1));
            $cur = max(0, intval($room['current_occupants'] ?? 0));
            if ($cur >= $cap) throw new RuntimeException('Room is already full.');
            if (($room['status'] ?? '') === 'occupied') throw new RuntimeException('Room is currently marked occupied.');

            $tenantQ = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'tenant' AND is_active = 1 LIMIT 1");
            $tenantQ->execute([$tenantId]);
            if (!$tenantQ->fetch()) throw new RuntimeException('Tenant not found.');

            $dup = $db->prepare("SELECT id FROM room_requests WHERE room_id = ? AND tenant_id = ? AND status IN ('pending','approved','occupied') LIMIT 1");
            $dup->execute([$roomId, $tenantId]);
            if ($dup->fetch()) throw new RuntimeException('That tenant is already assigned (or has a pending request) for this room.');

            $ins = $db->prepare("INSERT INTO room_requests (room_id, tenant_id, status) VALUES (?,?, 'occupied')");
            $ins->execute([$roomId, $tenantId]);

            $cur2 = $cur + 1;
            $status2 = ($cur2 >= $cap) ? 'occupied' : 'available';

            $updRoom = $db->prepare("UPDATE rooms SET current_occupants = ?, status = ? WHERE id = ?");
            $updRoom->execute([$cur2, $status2, $roomId]);

            $db->commit();
            syncBoardingHouseRoomStats($db, intval($room['boarding_house_id'] ?? 0));
            setFlash('success', 'Tenant assigned to the room.');
        } elseif ($action === 'approve_request') {
            $reqId = intval($_POST['request_id'] ?? 0);
            $moveIn = trim((string)($_POST['move_in_date'] ?? ''));
            $moveInDate = $moveIn !== '' ? $moveIn : null;

            $db->beginTransaction();
            $q = $db->prepare("SELECT rr.id, rr.status, rr.room_id, r.boarding_house_id, r.capacity, r.current_occupants, r.status AS room_status
              FROM room_requests rr
              JOIN rooms r ON r.id = rr.room_id
              JOIN boarding_houses bh ON bh.id = r.boarding_house_id
              WHERE rr.id = ? AND bh.owner_id = ?");
            $q->execute([$reqId, intval($_SESSION['user_id'])]);
            $row = $q->fetch();
            if (!$row) throw new RuntimeException('Request not found.');
            if (($row['status'] ?? '') !== 'pending') throw new RuntimeException('Request is not pending.');

            $roomId = intval($row['room_id']);
            $bhId2 = intval($row['boarding_house_id']);

            $lock = $db->prepare("SELECT capacity, current_occupants FROM rooms WHERE id = ? FOR UPDATE");
            $lock->execute([$roomId]);
            $room = $lock->fetch() ?: [];
            $cap = intval($room['capacity'] ?? 0);
            $cur = intval($room['current_occupants'] ?? 0);
            if ($cap <= 0) $cap = intval($row['capacity'] ?? 1);
            if (($row['room_status'] ?? '') === 'occupied' && $cur < $cap) throw new RuntimeException('Room is currently marked occupied.');

            if ($cur >= $cap) throw new RuntimeException('Room is already full.');

            $cur2 = $cur + 1;
            $status2 = ($cur2 >= $cap) ? 'occupied' : 'available';

            $updRoom = $db->prepare("UPDATE rooms SET current_occupants = ?, status = ? WHERE id = ?");
            $updRoom->execute([$cur2, $status2, $roomId]);

            if ($moveInDate !== null) {
                $updReq = $db->prepare("UPDATE room_requests SET status='occupied', move_in_date = ? WHERE id = ?");
                $updReq->execute([$moveInDate, $reqId]);
            } else {
                $updReq = $db->prepare("UPDATE room_requests SET status='occupied' WHERE id = ?");
                $updReq->execute([$reqId]);
            }

            $db->commit();
            syncBoardingHouseRoomStats($db, $bhId2);
            // Notification (best-effort)
            try {
                $tenantId = 0;
                try {
                    $tq = $db->prepare("SELECT tenant_id FROM room_requests WHERE id = ? LIMIT 1");
                    $tq->execute([$reqId]);
                    $tenantId = intval($tq->fetchColumn() ?: 0);
                } catch (Throwable $e) {
                    $tenantId = 0;
                }
                if ($tenantId > 0) notifyRoomRequestDecision($tenantId, $bhId2, $roomId, 'approved');
            } catch (Throwable $e) {
                // ignore
            }

            setFlash('success', 'Request approved. Tenant assigned to the room.');
        } elseif ($action === 'reject_request') {
            $reqId = intval($_POST['request_id'] ?? 0);
            $upd = $db->prepare("UPDATE room_requests rr
              JOIN rooms r ON r.id = rr.room_id
              JOIN boarding_houses bh ON bh.id = r.boarding_house_id
              SET rr.status='rejected'
              WHERE rr.id = ? AND bh.owner_id = ? AND rr.status='pending'");
            $upd->execute([$reqId, intval($_SESSION['user_id'])]);
            // Notification (best-effort)
            try {
                $rq = $db->prepare("SELECT rr.tenant_id, rr.room_id, r.boarding_house_id
                  FROM room_requests rr
                  JOIN rooms r ON r.id = rr.room_id
                  JOIN boarding_houses bh ON bh.id = r.boarding_house_id
                  WHERE rr.id = ? AND bh.owner_id = ? LIMIT 1");
                $rq->execute([$reqId, intval($_SESSION['user_id'])]);
                $rr = $rq->fetch() ?: null;
                if ($rr) {
                    notifyRoomRequestDecision(intval($rr['tenant_id'] ?? 0), intval($rr['boarding_house_id'] ?? 0), intval($rr['room_id'] ?? 0), 'rejected');
                }
            } catch (Throwable $e) {
                // ignore
            }

            setFlash('success', 'Request rejected.');
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        setFlash('error', $e->getMessage());
    }

    $redirectBhId = $isRoomCrud ? $bhId : intval($_POST['bh_id'] ?? $selectedBhId);
    $anchor = in_array($action, ['approve_request', 'reject_request'], true) ? '#requests' : '#rooms';
    if ($action === 'assign_tenant') $anchor = '#assign';
    header('Location: rooms.php?bh_id=' . intval($redirectBhId) . $anchor);
    exit;
}


$subscriptionModalRoom = null;
$subscriptionModalRoomId = intval($_SESSION['subscription_modal_room_id'] ?? 0);
$subscriptionModalBhId = intval($_SESSION['subscription_modal_bh_id'] ?? 0);
unset($_SESSION['subscription_modal_room_id'], $_SESSION['subscription_modal_bh_id']);

if ($subscriptionModalRoomId > 0 && $hasRoomSubscription) {
    try {
        $stmt = $db->prepare("SELECT r.id, r.room_name, r.subscription_status, r.boarding_house_id
          FROM rooms r
          JOIN boarding_houses bh ON bh.id = r.boarding_house_id
          WHERE r.id = ? AND bh.owner_id = ?
          LIMIT 1");
        $stmt->execute([$subscriptionModalRoomId, intval($_SESSION['user_id'])]);
        $subscriptionModalRoom = $stmt->fetch() ?: null;
        if ($subscriptionModalRoom) {
            if ($subscriptionModalBhId <= 0) $subscriptionModalBhId = intval($subscriptionModalRoom['boarding_house_id'] ?? 0);
            $subscriptionModalRoom['boarding_house_id'] = $subscriptionModalBhId;
        }
    } catch (Throwable $e) {
        $subscriptionModalRoom = null;
    }
}
$rooms = [];
$pendingRequests = [];
$roomsError = null;

$assignRoomId = isset($_GET['assign_room_id']) ? intval($_GET['assign_room_id']) : 0;
$tenantQ = trim((string)($_GET['tenant_q'] ?? ''));
$assignRoom = null;
$tenantResults = [];

try {
    if ($selectedBhId > 0) {
        $rStmt = $db->prepare("SELECT * FROM rooms WHERE boarding_house_id = ? ORDER BY id ASC");
        $rStmt->execute([$selectedBhId]);
        $rooms = $rStmt->fetchAll() ?: [];

        $pStmt = $db->prepare("SELECT rr.*, r.room_name, r.capacity, r.current_occupants, u.full_name, u.email
          FROM room_requests rr
          JOIN rooms r ON r.id = rr.room_id
          JOIN users u ON u.id = rr.tenant_id
          WHERE r.boarding_house_id = ? AND rr.status = 'pending'
          ORDER BY rr.created_at DESC");
        $pStmt->execute([$selectedBhId]);
        $pendingRequests = $pStmt->fetchAll() ?: [];

        if ($assignRoomId > 0) {
            $ar = $db->prepare("SELECT r.id, r.room_name, r.capacity, r.current_occupants
              FROM rooms r
              JOIN boarding_houses bh ON bh.id = r.boarding_house_id
              WHERE r.id = ? AND r.boarding_house_id = ? AND bh.owner_id = ?");
            $ar->execute([$assignRoomId, $selectedBhId, intval($_SESSION['user_id'])]);
            $assignRoom = $ar->fetch() ?: null;

            if ($assignRoom && $tenantQ !== '' && strlen($tenantQ) >= 2) {
                $like = '%' . $tenantQ . '%';
                $tStmt = $db->prepare("SELECT id, full_name, email
                  FROM users
                  WHERE role = 'tenant' AND is_active = 1 AND (full_name LIKE ? OR email LIKE ?)
                  ORDER BY full_name ASC
                  LIMIT 25");
                $tStmt->execute([$like, $like]);
                $tenantResults = $tStmt->fetchAll() ?: [];
            }
        }
    } else {
        $pStmt = $db->prepare("SELECT rr.*, r.room_name, r.capacity, r.current_occupants, u.full_name, u.email, bh.name AS boarding_house_name
          FROM room_requests rr
          JOIN rooms r ON r.id = rr.room_id
          JOIN boarding_houses bh ON bh.id = r.boarding_house_id
          JOIN users u ON u.id = rr.tenant_id
          WHERE bh.owner_id = ? AND rr.status = 'pending'
          ORDER BY rr.created_at DESC");
        $pStmt->execute([intval($_SESSION['user_id'])]);
        $pendingRequests = $pStmt->fetchAll() ?: [];
    }
} catch (Throwable $e) {
    $roomsError = 'Rooms are not available yet. Please run install.php or import the updated schema.sql.';
}

$showNavbar = false;
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="dash-shell">
<?php $activeNav = 'rooms'; include __DIR__ . '/_partials/sidebar.php'; ?>

  <div class="dash-main">
<?php include __DIR__ . '/_partials/topbar.php'; ?>

    <div class="dash-content">
      <div class="dash-heading">
        <div>
          <h1 class="dash-title">Room Management</h1>
          <div class="dash-breadcrumb">
            <a href="dashboard.php">My Boarding Houses</a>
            <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
            <span>Rooms</span>
          </div>
        </div>
      </div>

      <main>
        <?php if ($roomsError): ?>
          <div class="flash flash-error mb-3"><i class="fas fa-exclamation-circle"></i><?= sanitize($roomsError) ?></div>
        <?php endif; ?>
        <?php if (!$hasRoomImage): ?>
          <div class="flash flash-info mb-3"><i class="fas fa-circle-info"></i> Room photo upload is ready in the code, but your database still needs the <code>room_image</code> column. Run <code>install.php</code> once, or update your <code>rooms</code> table schema.</div>
        <?php endif; ?>
        <?php if (!$hasRoomAccommodationType): ?>
          <div class="flash flash-info mb-3"><i class="fas fa-circle-info"></i> Room accommodation type is ready in the code, but your database still needs the <code>accommodation_type</code> column on <code>rooms</code>. Run <code>install.php</code> once to enable it.</div>
        <?php endif; ?>

        <?php if (empty($boardingHouses)): ?>
          <div class="card">
            <div class="card-body">
              <div class="empty-state">
                <i class="fas fa-building"></i>
                <h3>No listings yet</h3>
                <p>Add a listing first, then create rooms under it.</p>
                <a class="btn btn-primary" href="add_listing.php"><i class="fas fa-plus"></i> Add Listing</a>
              </div>
            </div>
          </div>
        <?php else: ?>

          <div class="card" id="rooms">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
              <div>
                <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">Rooms</h2>
                <div class="text-muted text-sm" style="margin-top:4px">Select a boarding house to manage rooms and assign tenants.</div>
              </div>

              <form method="GET" action="rooms.php" class="flex items-center gap-2">
                <label class="text-sm text-muted">Boarding house</label>
                <select name="bh_id" class="form-control" style="min-width:260px" onchange="this.form.submit()">
                  <option value="0" <?= $selectedBhId === 0 ? 'selected' : '' ?>>All listings (requests only)</option>
                  <?php foreach ($boardingHouses as $b): ?>
                    <option value="<?= intval($b['id']) ?>" <?= intval($b['id']) === $selectedBhId ? 'selected' : '' ?>><?= sanitize($b['name'] ?? '') ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </div>

            <div class="card-body">
              <div class="room-form-panel">
                <form method="POST" action="rooms.php?bh_id=<?= intval($selectedBhId) ?>#rooms" enctype="multipart/form-data" class="room-create-form">
                  <input type="hidden" name="action" value="add_room">

                  <div class="form-group">
                    <label class="form-label">Add to listing</label>
                    <select name="bh_id" class="form-control" required>
                      <option value="" disabled <?= $selectedBhId === 0 ? 'selected' : '' ?>>Select listing</option>
                      <?php foreach ($boardingHouses as $b): ?>
                        <option value="<?= intval($b['id']) ?>" <?= intval($b['id']) === $selectedBhId ? 'selected' : '' ?>><?= sanitize($b['name'] ?? '') ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="form-group">
                    <label class="form-label">Room name/number</label>
                    <input name="room_name" class="form-control" placeholder="Room 1" required>
                  </div>

                  <?php if ($hasRoomAccommodationType): ?>
                    <div class="form-group">
                      <label class="form-label">Accommodation Type</label>
                      <select name="accommodation_type" class="form-control">
                        <option value="">Select type</option>
                        <?php foreach ($roomTypeOptions as $typeValue => $typeLabel): ?>
                          <option value="<?= sanitize($typeValue) ?>"><?= sanitize($typeLabel) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  <?php endif; ?>

                  <?php if ($hasRoomAmenities): ?>
                    <div class="form-group">
                      <label class="form-label">Amenities</label>
                      <input name="amenities" class="form-control" placeholder="WiFi, AC, CR, Bed">
                    </div>
                  <?php endif; ?>

                  <?php if ($hasRoomImage): ?>
                    <div class="form-group room-create-photo">
                      <label class="form-label">Upload photo</label>
                      <div class="file-upload file-upload-compact">
                        <input name="room_image" type="file" accept="image/jpeg,image/png,image/webp">
                        <div class="file-upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <p class="file-upload-text"><strong>Click to upload</strong> or drag & drop</p>
                        <p class="file-upload-text" style="font-size:.76rem;margin-top:4px">JPG, PNG, or WebP ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¦ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â· Max 5MB</p>
                      </div>
                      <div class="file-preview room-inline-preview"></div>
                    </div>
                  <?php endif; ?>

                  <div class="form-group">
                    <label class="form-label">Price</label>
                    <input name="price" type="number" step="0.01" min="0" class="form-control" placeholder="3500" required>
                  </div>

                  <div class="form-group">
                    <label class="form-label">Capacity</label>
                    <input name="capacity" type="number" min="1" class="form-control" value="1" required>
                  </div>

                  <div class="form-group">
                    <label class="form-label">Current</label>
                    <input name="current_occupants" type="number" min="0" class="form-control" value="0" required>
                  </div>

                  <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control room-status-select">
                      <?php foreach ($roomStatusOptions as $statusValue => $statusLabel): ?>
                        <option value="<?= sanitize($statusValue) ?>"><?= sanitize($statusLabel) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="room-create-actions">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-plus"></i> Add</button>
                  </div>
                </form>
              </div>

              <?php if ($selectedBhId === 0): ?>
                <div class="flash flash-info mb-3"><i class="fas fa-circle-info"></i> Select a specific listing above to view and edit its rooms.</div>
              <?php else: ?>

                <?php if ($assignRoomId > 0): ?>
                  <div class="card" id="assign" style="margin-bottom:16px">
                    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
                      <div>
                        <h3 style="margin:0;font-family:var(--font-display);font-size:1.05rem;font-weight:800">Assign Tenant</h3>
                        <?php if ($assignRoom): ?>
                          <?php
                            $acap = max(1, intval($assignRoom['capacity'] ?? 1));
                            $acur = max(0, intval($assignRoom['current_occupants'] ?? 0));
                            if ($acur > $acap) $acur = $acap;
                          ?>
                          <div class="text-muted text-sm" style="margin-top:4px">
                            Room: <strong><?= sanitize($assignRoom['room_name'] ?? '') ?></strong> ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¦ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â· Occupancy: <?= $acur ?>/<?= $acap ?>
                          </div>
                        <?php else: ?>
                          <div class="text-muted text-sm" style="margin-top:4px">That room was not found.</div>
                        <?php endif; ?>
                      </div>

                      <a class="btn btn-ghost" href="rooms.php?bh_id=<?= intval($selectedBhId) ?>#rooms"><i class="fas fa-xmark"></i> Close</a>
                    </div>

                    <div class="card-body">
                      <?php if ($assignRoom): ?>
                        <form method="GET" action="rooms.php" class="flex items-center gap-2" style="flex-wrap:wrap">
                          <input type="hidden" name="bh_id" value="<?= intval($selectedBhId) ?>">
                          <input type="hidden" name="assign_room_id" value="<?= intval($assignRoomId) ?>">
                          <div class="form-group" style="margin:0;min-width:320px;flex:1">
                            <label class="form-label">Search tenant (name or email)</label>
                            <input class="form-control" name="tenant_q" value="<?= sanitize($tenantQ) ?>" placeholder="e.g. tenant@demo.com">
                          </div>
                          <button class="btn btn-primary" type="submit" style="margin-top:22px"><i class="fas fa-magnifying-glass"></i> Search</button>
                        </form>

                        <?php if ($tenantQ !== '' && strlen($tenantQ) < 2): ?>
                          <div class="flash flash-info mt-3"><i class="fas fa-circle-info"></i> Type at least 2 characters to search.</div>
                        <?php endif; ?>

                        <?php if ($tenantQ !== '' && strlen($tenantQ) >= 2 && empty($tenantResults)): ?>
                          <div class="empty-state compact mt-3">
                            <i class="fas fa-user"></i>
                            <h3>No tenants found</h3>
                            <p>Try a different name/email.</p>
                          </div>
                        <?php endif; ?>

                        <?php if (!empty($tenantResults)): ?>
                          <div class="table-wrap mt-3">
                            <table>
                              <thead>
                                <tr>
                                  <th>Tenant</th>
                                  <th>Email</th>
                                  <th style="width:160px">Action</th>
                                </tr>
                              </thead>
                              <tbody>
                                <?php foreach ($tenantResults as $t): ?>
                                  <tr>
                                    <td class="font-bold"><?= sanitize($t['full_name'] ?? '') ?></td>
                                    <td class="text-muted text-sm"><?= sanitize($t['email'] ?? '') ?></td>
                                    <td>
                                      <form method="POST" action="rooms.php?bh_id=<?= intval($selectedBhId) ?>#assign" onsubmit="return confirm('Assign this tenant to the room?');">
                                        <input type="hidden" name="action" value="assign_tenant">
                                        <input type="hidden" name="bh_id" value="<?= intval($selectedBhId) ?>">
                                        <input type="hidden" name="room_id" value="<?= intval($assignRoomId) ?>">
                                        <input type="hidden" name="tenant_id" value="<?= intval($t['id'] ?? 0) ?>">
                                        <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-user-plus"></i> Assign</button>
                                      </form>
                                    </td>
                                  </tr>
                                <?php endforeach; ?>
                              </tbody>
                            </table>
                          </div>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endif; ?>

                <?php if (empty($rooms)): ?>
                  <div class="empty-state compact">
                    <i class="fas fa-door-open"></i>
                    <h3>No rooms yet</h3>
                    <p>Add your first room above.</p>
                  </div>
                <?php else: ?>
                  <div class="table-wrap">
                    <table>
                      <thead>
                        <tr>
                          <th>Room</th>
                          <?php if ($hasRoomAccommodationType): ?><th>Type</th><?php endif; ?>
                          <?php if ($hasRoomAmenities): ?><th>Amenities</th><?php endif; ?>
                          <?php if ($hasRoomImage): ?><th>Image</th><?php endif; ?>
                          <th>Price</th>
                          <th>Capacity</th>
                          <th>Occupancy</th>
                          <th>Status</th>
                          <?php if ($hasRoomSubscription): ?><th>Subscription</th><?php endif; ?>
                          <th style="width:260px">Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($rooms as $r):
                          $cap = max(1, intval($r['capacity'] ?? 1));
                          $cur = max(0, intval($r['current_occupants'] ?? 0));
                          if ($cur > $cap) $cur = $cap;
                          $selectedStatus = strtolower((string)($r['status'] ?? 'available'));
                          if (!array_key_exists($selectedStatus, $roomStatusOptions)) $selectedStatus = 'available';
                          $status = ($selectedStatus === 'occupied' || $cur >= $cap) ? 'occupied' : 'available';
                          $badgeClass = $status === 'occupied' ? 'status-full' : 'status-active';
                          $priceValue = number_format((float)($r['price'] ?? 0), 2, '.', '');
                          $roomTypeValue = trim((string)($r['accommodation_type'] ?? ''));
                        ?>
                          <tr class="room-row">
                            <form method="POST" action="rooms.php?bh_id=<?= intval($selectedBhId) ?>#rooms" enctype="multipart/form-data">
                              <input type="hidden" name="bh_id" value="<?= intval($selectedBhId) ?>">
                              <input type="hidden" name="room_id" value="<?= intval($r['id']) ?>">

                              <td>
                                <div class="room-cell-display room-text-value"><?= sanitize($r['room_name'] ?? '') ?></div>
                                <div class="room-cell-edit">
                                  <input name="room_name" class="form-control" value="<?= sanitize($r['room_name'] ?? '') ?>" required>
                                </div>
                              </td>
                              <?php if ($hasRoomAccommodationType): ?>
                                <td>
                                  <div class="room-cell-display room-text-value"><?= sanitize($roomTypeOptions[$roomTypeValue] ?? 'Not set') ?></div>
                                  <div class="room-cell-edit">
                                    <select name="accommodation_type" class="form-control">
                                      <option value="">Select type</option>
                                      <?php foreach ($roomTypeOptions as $typeValue => $typeLabel): ?>
                                        <option value="<?= sanitize($typeValue) ?>" <?= $roomTypeValue === $typeValue ? 'selected' : '' ?>><?= sanitize($typeLabel) ?></option>
                                      <?php endforeach; ?>
                                    </select>
                                  </div>
                                </td>
                              <?php endif; ?>
                              <?php if ($hasRoomAmenities): ?>
                                <td>
                                  <div class="room-cell-display room-text-value"><?= sanitize($r['amenities'] ?? 'Not set') ?></div>
                                  <div class="room-cell-edit">
                                    <input name="amenities" class="form-control" value="<?= sanitize($r['amenities'] ?? '') ?>" placeholder="WiFi, AC, CR">
                                  </div>
                                </td>
                              <?php endif; ?>
                              <?php if ($hasRoomImage): ?>
                                <td class="room-table-image-cell">
                                  <div class="room-image-stack room-cell-display">
                                    <?php if (!empty($r['room_image'])): ?>
                                    <div class="room-thumb-wrap room-thumb-large">
                                      <img class="room-thumb" src="<?= UPLOAD_URL . sanitize($r['room_image'] ?? '') ?>" alt="<?= sanitize($r['room_name'] ?? 'Room') ?>">
                                    </div>
                                    <?php else: ?>
                                    <span class="room-text-muted">No photo</span>
                                    <?php endif; ?>
                                  </div>
                                  <div class="room-image-stack room-cell-edit">
                                    <?php if (!empty($r['room_image'])): ?>
                                    <div class="room-thumb-wrap room-thumb-large">
                                      <img class="room-thumb" src="<?= UPLOAD_URL . sanitize($r['room_image'] ?? '') ?>" alt="<?= sanitize($r['room_name'] ?? 'Room') ?>">
                                    </div>
                                    <label class="room-image-remove">
                                      <input type="checkbox" name="remove_room_image" value="1"> Remove
                                    </label>
                                    <?php endif; ?>
                                    <div class="file-upload file-upload-compact">
                                      <input name="room_image" type="file" accept="image/jpeg,image/png,image/webp">
                                      <div class="file-upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                                      <p class="file-upload-text"><strong><?= !empty($r['room_image']) ? 'Replace photo' : 'Upload photo' ?></strong></p>
                                      <p class="file-upload-text" style="font-size:.74rem;margin-top:4px">JPG, PNG, or WebP</p>
                                    </div>
                                    <div class="file-preview room-inline-preview"></div>
                                  </div>
                                </td>
                              <?php endif; ?>
                              <td>
                                <div class="room-cell-display room-text-value"><?= sanitize($priceValue) ?></div>
                                <div class="room-cell-edit">
                                  <input name="price" type="number" step="0.01" min="0" class="form-control" value="<?= sanitize($priceValue) ?>" required>
                                </div>
                              </td>
                              <td>
                                <div class="room-cell-display room-text-value"><?= $cap ?></div>
                                <div class="room-cell-edit">
                                  <input name="capacity" type="number" min="1" class="form-control" value="<?= $cap ?>" required>
                                </div>
                              </td>
                              <td>
                                <div class="room-cell-display room-text-value"><?= $cur ?></div>
                                <div class="room-cell-edit">
                                  <input name="current_occupants" type="number" min="0" class="form-control" value="<?= $cur ?>" required>
                                </div>
                              </td>
                              <td>
                                <div class="room-cell-display">
                                  <div class="room-status-chip">
                                    <span class="badge <?= $badgeClass ?>"><?= $status === 'occupied' ? 'Occupied' : 'Available' ?></span>
                                  </div>
                                </div>
                                <div class="room-cell-edit">
                                  <select name="status" class="form-control room-status-select">
                                    <?php foreach ($roomStatusOptions as $statusValue => $statusLabel): ?>
                                      <option value="<?= sanitize($statusValue) ?>" <?= $selectedStatus === $statusValue ? 'selected' : '' ?>><?= sanitize($statusLabel) ?></option>
                                    <?php endforeach; ?>
                                  </select>
                                </div>
                              </td>
                              <?php if ($hasRoomSubscription): ?>
                              <td>
                                <?php
                                  $sub = strtolower((string)($r['subscription_status'] ?? 'inactive'));
                                  $sEnd = trim((string)($r['end_date'] ?? ''));
                                  if ($sub === 'active' && $sEnd !== '' && strtotime($sEnd) < strtotime(date('Y-m-d'))) {
                                      $sub = 'expired';
                                  }
                                ?>
                                <div class="room-cell-display">
                                  <span class="badge" style="background:var(--bg);border:1px solid var(--border)"><?= sanitize($sub) ?></span>
                                  <?php if ($sEnd !== ''): ?>
                                    <div class="text-muted text-xs" style="margin-top:6px">Until <?= sanitize(date('M d, Y', strtotime($sEnd))) ?></div>
                                  <?php endif; ?>
                                </div>
                                <div class="room-cell-edit">
                                  <div class="room-subscription-panel">
  <div class="text-muted text-xs room-subscription-price">Per-room subscription: <?= formatPrice((float)$subscriptionAmount) ?> / <?= intval($subscriptionDays) ?> days</div>
  <?php if ($sub !== 'active'): ?>
    <div class="room-subscription-upload">
      <div class="file-upload file-upload-compact">
        <input name="payment_proof" type="file" accept="image/jpeg,image/png,image/webp">
        <div class="file-upload-icon"><i class="fas fa-receipt"></i></div>
        <p class="file-upload-text"><strong>Upload receipt</strong></p>
        <p class="file-upload-text" style="font-size:.74rem;margin-top:4px">JPG, PNG, or WebP</p>
      </div>
      <div class="file-preview room-inline-preview"></div>
    </div>
    <div class="room-subscription-actions">
      <button class="btn btn-ghost btn-sm" type="submit" name="action" value="pay_subscription" formnovalidate><i class="fas fa-file-upload"></i> Submit Receipt</button>
      <button class="btn btn-primary btn-sm" type="submit" formaction="paypal_start.php" formmethod="post" formnovalidate <?= paypalEnabled() ? "" : "disabled" ?> title="<?= paypalEnabled() ? "Pay with PayPal Sandbox" : "Set PAYPAL_CLIENT_ID and PAYPAL_SECRET to enable PayPal" ?>"><i class="fab fa-paypal"></i> Pay with PayPal (Sandbox)</button>
    </div>
  <?php else: ?>
    <div class="text-muted text-xs">Active subscription.</div>
  <?php endif; ?>
</div>
                                </div>
                              </td>
                              <?php endif; ?>
                              <td>
                                <div class="room-row-actions">
  <button class="btn btn-ghost btn-sm room-edit-toggle btn-icon" type="button" title="Update" aria-label="Update"><i class="fas fa-pen-to-square"></i><span class="sr-only">Update</span></button>
  <button class="btn btn-primary btn-sm room-save-btn btn-icon" type="submit" name="action" value="update_room" title="Save" aria-label="Save"><i class="fas fa-floppy-disk"></i><span class="sr-only">Save</span></button>
  <button class="btn btn-ghost btn-sm room-cancel-btn btn-icon" type="button" title="Cancel" aria-label="Cancel"><i class="fas fa-xmark"></i><span class="sr-only">Cancel</span></button>
  <?php if ($cur < $cap && $status === 'available'): ?>
    <a class="btn btn-primary btn-sm room-assign-btn btn-icon" href="rooms.php?bh_id=<?= intval($selectedBhId) ?>&assign_room_id=<?= intval($r['id']) ?>#assign" title="Assign Tenant" aria-label="Assign Tenant"><i class="fas fa-user-plus"></i><span class="sr-only">Assign Tenant</span></a>
  <?php endif; ?>
  <button class="btn btn-danger btn-sm btn-icon" type="submit" name="action" value="delete_room" onclick="return confirm('Delete this room?');" title="Delete" aria-label="Delete"><i class="fas fa-trash"></i><span class="sr-only">Delete</span></button>
</div>
</td>
                            </form>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>

              <?php endif; ?>
            </div>
          </div>

          <div class="card" id="requests" style="margin-top:18px">
            <div class="card-header">
              <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">Room Requests</h2>
              <div class="text-muted text-sm" style="margin-top:4px">Approve a tenant request to assign them to a room.</div>
            </div>
            <div class="card-body">
              <?php if (empty($pendingRequests)): ?>
                <div class="empty-state compact">
                  <i class="fas fa-user-check"></i>
                  <h3>No pending requests</h3>
                  <p>Tenants can request a specific room from the listing page.</p>
                </div>
              <?php else: ?>
                <div class="table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <?php if ($selectedBhId === 0): ?><th>Listing</th><?php endif; ?>
                        <th>Tenant</th>
                        <th>Email</th>
                        <th>Room</th>
                        <th style="width:170px">Move-in date</th>
                        <th style="width:120px">Requested</th>
                        <th style="width:200px">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($pendingRequests as $pr): ?>
                        <tr>
                          <form method="POST" action="rooms.php?bh_id=<?= intval($selectedBhId) ?>#requests">
                            <input type="hidden" name="bh_id" value="<?= intval($selectedBhId) ?>">
                            <input type="hidden" name="request_id" value="<?= intval($pr['id']) ?>">

                            <?php if ($selectedBhId === 0): ?>
                              <td><?= sanitize($pr['boarding_house_name'] ?? '') ?></td>
                            <?php endif; ?>
                            <td class="font-bold"><?= sanitize($pr['full_name'] ?? '') ?></td>
                            <td class="text-muted text-sm"><?= sanitize($pr['email'] ?? '') ?></td>
                            <td><?= sanitize($pr['room_name'] ?? '') ?></td>
                            <td><input type="date" name="move_in_date" class="form-control" value="<?= sanitize((string)($pr['move_in_date'] ?? '')) ?>"></td>
                            <td class="text-muted text-sm"><?= !empty($pr['created_at']) ? sanitize(date('M d, Y', strtotime((string)$pr['created_at']))) : '' ?></td>
                            <td>
                              <div class="flex flex-wrap gap-2">
                                <button class="btn btn-primary btn-sm" type="submit" name="action" value="approve_request"><i class="fas fa-check"></i> Approve</button>
                                <button class="btn btn-ghost btn-sm" type="submit" name="action" value="reject_request"><i class="fas fa-xmark"></i> Reject</button>
                              </div>
                            </td>
                          </form>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>

        <?php endif; ?>
      </main>
    </div>

  </div>
</div>


<?php if ($subscriptionModalRoom && strtolower((string)($subscriptionModalRoom["subscription_status"] ?? "inactive")) !== "active"): ?>
  <?php
    $mRoomId = intval($subscriptionModalRoom['id'] ?? 0);
    $mBhId = intval($subscriptionModalRoom['boarding_house_id'] ?? 0);
    if ($mBhId <= 0) $mBhId = $selectedBhId;
    $mRoomName = sanitize((string)($subscriptionModalRoom['room_name'] ?? ''));
  ?>
  <div class="be-modal open" id="subscriptionModal" aria-hidden="false">
    <div class="be-modal__backdrop" data-close-modal></div>
    <div class="be-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="subscriptionModalTitle">
      <div class="be-modal__header">
        <h3 class="be-modal__title" id="subscriptionModalTitle">Activate room subscription</h3>
        <button class="btn btn-ghost btn-sm" type="button" data-close-modal><i class="fas fa-xmark"></i> Close</button>
      </div>
      <div class="be-modal__body">
        <div class="text-muted text-sm" style="margin-bottom:12px">
          Room <span class="font-bold"><?= $mRoomName !== '' ? $mRoomName : ('#' . $mRoomId) ?></span> was added. To make it available to tenants, pay the subscription.
          <div class="text-muted text-xs" style="margin-top:6px">Per-room subscription: <?= formatPrice((float)$subscriptionAmount) ?> / <?= intval($subscriptionDays) ?> days</div>
        </div>

        <div class="be-subscribe-grid">
          <div class="be-subscribe-card">
            <h4 class="be-subscribe-title"><i class="fab fa-paypal"></i> Pay with PayPal</h4>
            <p class="text-muted text-sm" style="margin-top:6px">Fastest option. YouÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ll be redirected to PayPal checkout.</p>
            <form method="POST" action="paypal_start.php" style="margin-top:12px">
              <input type="hidden" name="room_id" value="<?= $mRoomId ?>">
              <input type="hidden" name="bh_id" value="<?= $mBhId ?>">
              <button class="btn btn-primary" type="submit" <?= paypalEnabled() ? "" : "disabled" ?> title="<?= paypalEnabled() ? "Pay with PayPal Sandbox" : "Set PAYPAL_CLIENT_ID and PAYPAL_SECRET to enable PayPal" ?>">
                <i class="fab fa-paypal"></i> Pay with PayPal (Sandbox)
              </button>
            </form>
          </div>

          <div class="be-subscribe-card">
            <h4 class="be-subscribe-title"><i class="fas fa-receipt"></i> Upload receipt</h4>
            <p class="text-muted text-sm" style="margin-top:6px">If you paid outside PayPal, upload a receipt screenshot for admin approval.</p>

            <form method="POST" action="rooms.php?bh_id=<?= $mBhId ?>#rooms" enctype="multipart/form-data" style="margin-top:12px">
              <input type="hidden" name="action" value="pay_subscription">
              <input type="hidden" name="bh_id" value="<?= $mBhId ?>">
              <input type="hidden" name="room_id" value="<?= $mRoomId ?>">

              <div class="room-subscription-upload">
                <div class="file-upload file-upload-compact">
                  <input name="payment_proof" type="file" accept="image/jpeg,image/png,image/webp" required>
                  <div class="file-upload-icon"><i class="fas fa-receipt"></i></div>
                  <p class="file-upload-text"><strong>Upload receipt</strong></p>
                  <p class="file-upload-text" style="font-size:.74rem;margin-top:4px">JPG, PNG, or WebP</p>
                </div>
                <div class="file-preview"></div>
              </div>

              <button class="btn btn-ghost" type="submit" style="margin-top:10px">
                <i class="fas fa-file-upload"></i> Submit Receipt
              </button>
            </form>
          </div>
        </div>
      </div>

      <div class="be-modal__footer">
        <button class="btn btn-ghost btn-sm" type="button" data-close-modal>Later</button>
      </div>
    </div>
  </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>




























