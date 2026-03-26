<?php
require_once __DIR__ . '/../../includes/config.php';
requireOwner();

$db = getDB();
$me = getCurrentUser();
$pageTitle = 'Room Management';

function syncBoardingHouseRoomStats(PDO $db, int $bhId): void {
    try {
        $stmt = $db->prepare("SELECT
            COUNT(*) AS total_rooms,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_rooms
          FROM rooms WHERE boarding_house_id = ?");
        $stmt->execute([$bhId]);
        $row = $stmt->fetch() ?: [];
        $total = intval($row['total_rooms'] ?? 0);
        $available = intval($row['available_rooms'] ?? 0);
        $status = ($total > 0 && $available <= 0) ? 'full' : 'active';

        $upd = $db->prepare("UPDATE boarding_houses
          SET total_rooms = ?, available_rooms = ?, status = CASE WHEN status='inactive' THEN status ELSE ? END
          WHERE id = ?");
        $upd->execute([$total, $available, $status, $bhId]);
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
            if ($current > $capacity) $current = $capacity;

            if ($roomName === '') throw new RuntimeException('Room name is required.');
            if ($price < 0) $price = 0;

            $status = ($current >= $capacity) ? 'occupied' : 'available';

            if ($action === 'add_room') {
                $ins = $db->prepare("INSERT INTO rooms (boarding_house_id, room_name, price, capacity, current_occupants, status)
                    VALUES (?,?,?,?,?,?)");
                $ins->execute([$bhId, $roomName, $price, $capacity, $current, $status]);
                setFlash('success', 'Room added.');
            } else {
                $chk = $db->prepare("SELECT id FROM rooms WHERE id = ? AND boarding_house_id = ? LIMIT 1");
                $chk->execute([$roomId, $bhId]);
                if (!$chk->fetch()) throw new RuntimeException('Room not found.');

                $upd = $db->prepare("UPDATE rooms
                  SET room_name=?, price=?, capacity=?, current_occupants=?, status=?
                  WHERE id = ? AND boarding_house_id = ?");
                $upd->execute([$roomName, $price, $capacity, $current, $status, $roomId, $bhId]);
                setFlash('success', 'Room updated.');
            }

            syncBoardingHouseRoomStats($db, $bhId);
        } elseif ($action === 'delete_room') {
            $roomId = intval($_POST['room_id'] ?? 0);
            $del = $db->prepare("DELETE FROM rooms WHERE id = ? AND boarding_house_id = ?");
            $del->execute([$roomId, $bhId]);
            setFlash('success', 'Room deleted.');
            syncBoardingHouseRoomStats($db, $bhId);
        } elseif ($action === 'assign_tenant') {
            $roomId = intval($_POST['room_id'] ?? 0);
            $tenantId = intval($_POST['tenant_id'] ?? 0);

            if ($roomId <= 0 || $tenantId <= 0) {
                throw new RuntimeException('Invalid room or tenant.');
            }

            $db->beginTransaction();

            $roomQ = $db->prepare("SELECT r.id, r.boarding_house_id, r.capacity, r.current_occupants
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

            $tenantQ = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'tenant' AND is_active = 1 LIMIT 1");
            $tenantQ->execute([$tenantId]);
            if (!$tenantQ->fetch()) throw new RuntimeException('Tenant not found.');

            $dup = $db->prepare("SELECT id FROM room_requests WHERE room_id = ? AND tenant_id = ? AND status IN ('pending','approved') LIMIT 1");
            $dup->execute([$roomId, $tenantId]);
            if ($dup->fetch()) throw new RuntimeException('That tenant is already assigned (or has a pending request) for this room.');

            $ins = $db->prepare("INSERT INTO room_requests (room_id, tenant_id, status) VALUES (?,?, 'approved')");
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
            $q = $db->prepare("SELECT rr.id, rr.status, rr.room_id, r.boarding_house_id, r.capacity, r.current_occupants
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

            if ($cur >= $cap) throw new RuntimeException('Room is already full.');

            $cur2 = $cur + 1;
            $status2 = ($cur2 >= $cap) ? 'occupied' : 'available';

            $updRoom = $db->prepare("UPDATE rooms SET current_occupants = ?, status = ? WHERE id = ?");
            $updRoom->execute([$cur2, $status2, $roomId]);

            if ($moveInDate !== null) {
                $updReq = $db->prepare("UPDATE room_requests SET status='approved', move_in_date = ? WHERE id = ?");
                $updReq->execute([$moveInDate, $reqId]);
            } else {
                $updReq = $db->prepare("UPDATE room_requests SET status='approved' WHERE id = ?");
                $updReq->execute([$reqId]);
            }

            $db->commit();
            syncBoardingHouseRoomStats($db, $bhId2);
            setFlash('success', 'Request approved. Tenant assigned to the room.');
        } elseif ($action === 'reject_request') {
            $reqId = intval($_POST['request_id'] ?? 0);
            $upd = $db->prepare("UPDATE room_requests rr
              JOIN rooms r ON r.id = rr.room_id
              JOIN boarding_houses bh ON bh.id = r.boarding_house_id
              SET rr.status='rejected'
              WHERE rr.id = ? AND bh.owner_id = ? AND rr.status='pending'");
            $upd->execute([$reqId, intval($_SESSION['user_id'])]);
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
  <aside class="dash-sidebar">
    <a class="dash-brand" href="dashboard.php" aria-label="<?= sanitize(SITE_NAME) ?>">
      <span class="dash-logo-wrap"><img class="dash-logo" src="<?= SITE_URL ?>/boardease-logo.png" alt="<?= sanitize(SITE_NAME) ?> logo"></span>
      <span class="sr-only"><?= sanitize(SITE_NAME) ?></span>
    </a>

    <a class="dash-action" href="add_listing.php" title="Create a new listing">
      <span>Add Listing</span>
      <i class="fas fa-plus"></i>
    </a>

    <nav class="dash-nav">
      <a href="dashboard.php"><i class="fas fa-gauge"></i> Overview</a>
      <a class="active" href="rooms.php"><i class="fas fa-door-open"></i> Rooms</a>
      <a href="chats.php"><i class="fas fa-comments"></i> Chats</a>
      <a href="<?= SITE_URL ?>/index.php"><i class="fas fa-house"></i> Browse</a>
    </nav>

  </aside>

  <div class="dash-main">
    <div class="dash-topbar">
      <div class="dash-search" aria-label="Search">
        <i class="fas fa-magnifying-glass"></i>
        <input type="search" placeholder="Search..." disabled>
      </div>

      <div class="dash-top-actions">
        <a class="dash-icon-btn" href="chats.php" title="Chats" aria-label="Chats"><i class="fas fa-comments"></i></a>

        <div class="nav-user">
          <button class="user-btn" id="userBtn" type="button">
            <span class="user-avatar"><?= strtoupper(substr(sanitize($me['full_name'] ?? 'U'), 0, 1)) ?></span>
            <span><?= sanitize($me['full_name'] ?? 'Owner') ?></span>
            <i class="fas fa-chevron-down" style="font-size:0.7rem;color:var(--text-light)"></i>
          </button>

          <div class="user-dropdown" id="userDropdown">
            <div class="dropdown-header">
              <strong><?= sanitize($me['full_name'] ?? '') ?></strong>
              <span><?= sanitize($me['email'] ?? '') ?></span>
              <span class="role-badge role-owner">Owner</span>
            </div>

            <a href="dashboard.php"><i class="fas fa-gauge"></i> Dashboard</a>
            <a href="rooms.php"><i class="fas fa-door-open"></i> Rooms</a>
            <a href="chats.php"><i class="fas fa-comments"></i> Chats</a>
            <hr>

            <a class="logout-link" href="<?= SITE_URL ?>/logout.php"><i class="fas fa-right-from-bracket"></i> Logout</a>
          </div>
        </div>
      </div>
    </div>

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
              <div style="border:1px solid var(--border);border-radius:var(--radius-md);padding:14px;background:var(--bg);margin-bottom:16px">
                <form method="POST" action="rooms.php?bh_id=<?= intval($selectedBhId) ?>#rooms" class="grid" style="grid-template-columns:1.1fr 1.2fr .7fr .5fr .6fr auto;gap:10px;align-items:end">
                  <input type="hidden" name="action" value="add_room">

                  <div class="form-group" style="margin:0">
                    <label class="form-label">Add to listing</label>
                    <select name="bh_id" class="form-control" required>
                      <option value="" disabled <?= $selectedBhId === 0 ? 'selected' : '' ?>>Select listing</option>
                      <?php foreach ($boardingHouses as $b): ?>
                        <option value="<?= intval($b['id']) ?>" <?= intval($b['id']) === $selectedBhId ? 'selected' : '' ?>><?= sanitize($b['name'] ?? '') ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="form-group" style="margin:0">
                    <label class="form-label">Room name/number</label>
                    <input name="room_name" class="form-control" placeholder="Room 1" required>
                  </div>

                  <div class="form-group" style="margin:0">
                    <label class="form-label">Price</label>
                    <input name="price" type="number" step="0.01" min="0" class="form-control" placeholder="3500" required>
                  </div>

                  <div class="form-group" style="margin:0">
                    <label class="form-label">Capacity</label>
                    <input name="capacity" type="number" min="1" class="form-control" value="1" required>
                  </div>

                  <div class="form-group" style="margin:0">
                    <label class="form-label">Current</label>
                    <input name="current_occupants" type="number" min="0" class="form-control" value="0" required>
                  </div>

                  <button class="btn btn-primary" type="submit"><i class="fas fa-plus"></i> Add</button>
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
                            Room: <strong><?= sanitize($assignRoom['room_name'] ?? '') ?></strong> · Occupancy: <?= $acur ?>/<?= $acap ?>
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
                          <th>Price</th>
                          <th>Capacity</th>
                          <th>Occupancy</th>
                          <th>Status</th>
                          <th style="width:260px">Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($rooms as $r):
                          $cap = max(1, intval($r['capacity'] ?? 1));
                          $cur = max(0, intval($r['current_occupants'] ?? 0));
                          if ($cur > $cap) $cur = $cap;
                          $status = ($cur >= $cap) ? 'occupied' : 'available';
                          $badgeClass = $status === 'occupied' ? 'status-full' : 'status-active';
                        ?>
                          <tr>
                            <form method="POST" action="rooms.php?bh_id=<?= intval($selectedBhId) ?>#rooms">
                              <input type="hidden" name="bh_id" value="<?= intval($selectedBhId) ?>">
                              <input type="hidden" name="room_id" value="<?= intval($r['id']) ?>">

                              <td><input name="room_name" class="form-control" value="<?= sanitize($r['room_name'] ?? '') ?>" required></td>
                              <td><input name="price" type="number" step="0.01" min="0" class="form-control" value="<?= sanitize((string)($r['price'] ?? '0')) ?>" required></td>
                              <td><input name="capacity" type="number" min="1" class="form-control" value="<?= $cap ?>" required></td>
                              <td><input name="current_occupants" type="number" min="0" class="form-control" value="<?= $cur ?>" required></td>
                              <td><span class="badge <?= $badgeClass ?>"><?= $status === 'occupied' ? 'Occupied' : 'Available' ?></span></td>
                              <td>
                                <div class="flex flex-wrap gap-2">
                                  <button class="btn btn-ghost btn-sm" type="submit" name="action" value="update_room"><i class="fas fa-floppy-disk"></i> Save</button>
                                  <?php if ($cur < $cap): ?>
                                    <a class="btn btn-primary btn-sm" href="rooms.php?bh_id=<?= intval($selectedBhId) ?>&assign_room_id=<?= intval($r['id']) ?>#assign"><i class="fas fa-user-plus"></i> Assign Tenant</a>
                                  <?php endif; ?>
                                  <button class="btn btn-danger btn-sm" type="submit" name="action" value="delete_room" onclick="return confirm('Delete this room?');"><i class="fas fa-trash"></i> Delete</button>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

