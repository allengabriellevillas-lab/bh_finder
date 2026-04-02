<?php
// One-time installer to create the database + tables and seed demo data.
// After running successfully, delete this file (or restrict access) in production.

require_once __DIR__ . '/includes/config.php';

header('Content-Type: text/html; charset=utf-8');

function pdoBase(): PDO {
    return new PDO(
        "mysql:host=" . DB_HOST . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
}

function ensureDatabaseExists(): void {
    $pdo = pdoBase();
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

function runSchema(): void {
    $schemaPath = __DIR__ . '/schema.sql';
    if (!is_file($schemaPath)) {
        throw new RuntimeException('Missing schema.sql');
    }

    $sql = file_get_contents($schemaPath);
    if ($sql === false) {
        throw new RuntimeException('Unable to read schema.sql');
    }

    // Strip UTF-8 BOM if present so leading comment lines are matched correctly.
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
    if (!is_string($sql)) {
        throw new RuntimeException('Unable to parse schema.sql');
    }

    // Remove full-line SQL comments so statement splitting does not try to execute them.
    $sql = preg_replace('/^\\s*--.*$/m', '', $sql);
    if (!is_string($sql)) {
        throw new RuntimeException('Unable to parse schema.sql');
    }

    $db = getDB();
    foreach (preg_split('/;\\s*(?:\\R|$)/', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        try {
            $db->exec($stmt);
        } catch (Throwable $e) {
            // Allow installer to proceed if chat tables fail due to FK mismatch
            // (common when upgrading an existing DB). We'll try to repair later.
            $s = strtolower($stmt);
            if (str_contains($s, 'create table') && (str_contains($s, 'chat_messages') || str_contains($s, 'chat_threads'))) {
                continue;
            }
            throw $e;
        }
    }
}

function ensureChatTables(): void {
    $db = getDB();

    // Create tables without FKs first (robust for upgrades); then best-effort add constraints.
    $db->exec("CREATE TABLE IF NOT EXISTS chat_threads (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      boarding_house_id INT UNSIGNED NOT NULL,
      tenant_id INT UNSIGNED NOT NULL,
      owner_id INT UNSIGNED NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      last_message_at TIMESTAMP NULL DEFAULT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uq_thread_bh_tenant (boarding_house_id, tenant_id),
      KEY idx_thread_owner (owner_id),
      KEY idx_thread_tenant (tenant_id),
      KEY idx_thread_last (last_message_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $db->exec("ALTER TABLE payments MODIFY COLUMN method ENUM('proof_upload','simulated','paypal') NOT NULL DEFAULT 'proof_upload'");
    } catch (Throwable $e) {
        // ignore
    }


    $db->exec("CREATE TABLE IF NOT EXISTS chat_messages (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      thread_id INT UNSIGNED NOT NULL,
      sender_id INT UNSIGNED NOT NULL,
      message TEXT NOT NULL,
      is_read TINYINT(1) NOT NULL DEFAULT 0,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_msg_thread (thread_id),
      KEY idx_msg_created (created_at),
      KEY idx_msg_read (thread_id, is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ensure InnoDB
    try { $db->exec("ALTER TABLE chat_threads ENGINE=InnoDB"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE chat_messages ENGINE=InnoDB"); } catch (Throwable $e) {}

    // Best-effort FK constraints (optional).
    try { $db->exec("ALTER TABLE chat_threads
        ADD CONSTRAINT fk_thread_bh FOREIGN KEY (boarding_house_id) REFERENCES boarding_houses(id) ON DELETE CASCADE"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE chat_threads
        ADD CONSTRAINT fk_thread_tenant FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE CASCADE"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE chat_threads
        ADD CONSTRAINT fk_thread_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE chat_messages
        ADD CONSTRAINT fk_msg_thread FOREIGN KEY (thread_id) REFERENCES chat_threads(id) ON DELETE CASCADE"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE chat_messages
        ADD CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE"); } catch (Throwable $e) {}
}
function ensureRoomTables(): void {
    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS rooms (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      boarding_house_id INT UNSIGNED NOT NULL,
      room_name VARCHAR(120) NOT NULL,
      accommodation_type VARCHAR(60) NULL,
      price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      capacity INT UNSIGNED NOT NULL DEFAULT 1,
      current_occupants INT UNSIGNED NOT NULL DEFAULT 0,
      amenities TEXT NULL,
      room_image VARCHAR(255) NULL,
      status ENUM('available','occupied') NOT NULL DEFAULT 'available',
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_rooms_bh (boarding_house_id),
      KEY idx_rooms_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $db->exec("ALTER TABLE rooms ADD COLUMN accommodation_type VARCHAR(60) NULL AFTER room_name");
    } catch (Throwable $e) {
        // ignore if the column already exists
    }
    try {
        $db->exec("ALTER TABLE rooms ADD COLUMN amenities TEXT NULL");
    } catch (Throwable $e) {
        // ignore if the column already exists
    }
    try {
        $db->exec("ALTER TABLE rooms ADD COLUMN room_image VARCHAR(255) NULL");
    } catch (Throwable $e) {
        // ignore if the column already exists
    }

    // Room subscriptions (per-room monetization)
    try {
        $db->exec("ALTER TABLE rooms ADD COLUMN subscription_status ENUM('inactive','pending','active','expired') NOT NULL DEFAULT 'inactive'");
    } catch (Throwable $e) {
        // ignore if the column already exists
    }
    try {
        $db->exec("ALTER TABLE rooms ADD COLUMN start_date DATE NULL");
    } catch (Throwable $e) {
        // ignore if the column already exists
    }
    try {
        $db->exec("ALTER TABLE rooms ADD COLUMN end_date DATE NULL");
    } catch (Throwable $e) {
        // ignore if the column already exists
    }
    try { $db->exec("ALTER TABLE rooms ADD INDEX idx_rooms_sub_status (subscription_status)"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE rooms ADD INDEX idx_rooms_sub_end (end_date)"); } catch (Throwable $e) {}

    $db->exec("CREATE TABLE IF NOT EXISTS room_requests (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      room_id INT UNSIGNED NOT NULL,
      tenant_id INT UNSIGNED NOT NULL,
      status ENUM('pending','approved','rejected','occupied','cancelled') NOT NULL DEFAULT 'pending',
      move_in_date DATE NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_rr_room (room_id),
      KEY idx_rr_tenant (tenant_id),
      KEY idx_rr_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Upgrade enum to include "occupied" when possible
    try {
        $db->exec("ALTER TABLE room_requests MODIFY COLUMN status ENUM('pending','approved','rejected','occupied','cancelled') NOT NULL DEFAULT 'pending'");
    } catch (Throwable $e) {
        // ignore
    }

    // Best-effort FKs (optional for upgrades)
    try { $db->exec("ALTER TABLE rooms
        ADD CONSTRAINT fk_rooms_bh FOREIGN KEY (boarding_house_id) REFERENCES boarding_houses(id) ON DELETE CASCADE"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE room_requests
        ADD CONSTRAINT fk_rr_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE room_requests
        ADD CONSTRAINT fk_rr_tenant FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE CASCADE"); } catch (Throwable $e) {}
}
function ensureAvatarColumn(): void {
    $db = getDB();
    $exists = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'avatar'");
    $exists->execute();
    if (intval($exists->fetchColumn() ?: 0) === 0) {
        $db->exec("ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL");
    }
}
function ensureUserAdminColumns(): void {
    $db = getDB();
    $cols = $db->prepare("SELECT COLUMN_NAME
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'users'");
    $cols->execute();
    $names = $cols->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $has = fn(string $c): bool => in_array($c, $names, true);

    if (!$has('is_active')) {
        $db->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
    }
    if (!$has('owner_verified')) {
        $db->exec("ALTER TABLE users ADD COLUMN owner_verified TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!$has('owner_verified_at')) {
        $db->exec("ALTER TABLE users ADD COLUMN owner_verified_at TIMESTAMP NULL DEFAULT NULL");
    }

    // Owner verification (ID upload + status)
    if (!$has('owner_verification_status')) {
        $db->exec("ALTER TABLE users ADD COLUMN owner_verification_status ENUM('pending','verified','rejected') NULL DEFAULT NULL");
    }
    if (!$has('owner_id_doc_path')) {
        $db->exec("ALTER TABLE users ADD COLUMN owner_id_doc_path VARCHAR(255) NULL");
    }
    if (!$has('owner_verification_reason')) {
        $db->exec("ALTER TABLE users ADD COLUMN owner_verification_reason TEXT NULL");
    }
}

function ensureUserRoleSupportsAdmin(): void {
    $db = getDB();
    try {
        $stmt = $db->prepare("SELECT COLUMN_TYPE
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'users'
            AND COLUMN_NAME = 'role'
          LIMIT 1");
        $stmt->execute();
        $colType = (string)($stmt->fetchColumn() ?: '');

        // Keep existing ENUM order to avoid remapping stored indexes.
        if ($colType !== '' && stripos($colType, "enum(") === 0 && stripos($colType, "'admin'") === false) {
            $db->exec("ALTER TABLE users MODIFY role ENUM('tenant','owner','admin') NOT NULL DEFAULT 'tenant'");
        }
    } catch (Throwable $e) {
        // ignore
    }
}
function ensureBoardingHouseModerationColumns(): void {
    $db = getDB();
    $cols = $db->prepare("SELECT COLUMN_NAME
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'boarding_houses'");
    $cols->execute();
    $names = $cols->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $has = fn(string $c): bool => in_array($c, $names, true);

    if (!$has('approval_status')) {
        $db->exec("ALTER TABLE boarding_houses ADD COLUMN approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved'");
    }
    if (!$has('approved_by')) {
        $db->exec("ALTER TABLE boarding_houses ADD COLUMN approved_by INT UNSIGNED NULL");
        try {
            $db->exec("ALTER TABLE boarding_houses ADD CONSTRAINT fk_bh_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL");
        } catch (Throwable $e) {
            // ignore if FK can't be created (existing mismatched engines/constraints)
        }
    }
    if (!$has('approved_at')) {
        $db->exec("ALTER TABLE boarding_houses ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL");
    }
    if (!$has('rejected_reason')) {
        $db->exec("ALTER TABLE boarding_houses ADD COLUMN rejected_reason TEXT NULL");
    }
    if (!$has('views')) {
        $db->exec("ALTER TABLE boarding_houses ADD COLUMN views INT UNSIGNED NOT NULL DEFAULT 0");
    }
}

function ensureBoardingHouseSubscriptionColumns(): void {
    $db = getDB();
    $cols = $db->prepare("SELECT COLUMN_NAME
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'boarding_houses'");
    $cols->execute();
    $names = $cols->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $has = fn(string $c): bool => in_array($c, $names, true);

    if (!$has('is_active')) {
        $db->exec("ALTER TABLE boarding_houses ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
    }
    if (!$has('expires_at')) {
        $db->exec("ALTER TABLE boarding_houses ADD COLUMN expires_at DATETIME NULL");
    }
    if (!$has('subscription_id')) {
        $db->exec("ALTER TABLE boarding_houses ADD COLUMN subscription_id INT UNSIGNED NULL");
    }
    if (!$has('is_featured')) {
        $db->exec("ALTER TABLE boarding_houses ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!$has('featured_until')) {
        $db->exec("ALTER TABLE boarding_houses ADD COLUMN featured_until DATETIME NULL");
    }
}

function ensureOwnerSubscriptionTable(): void {
    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS owner_subscriptions (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      owner_id INT UNSIGNED NOT NULL,
      plan ENUM('basic','pro') NOT NULL DEFAULT 'basic',
      status ENUM('pending','active','expired','rejected') NOT NULL DEFAULT 'pending',
      start_date DATE NULL,
      end_date DATE NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_os_owner (owner_id),
      KEY idx_os_status (status),
      KEY idx_os_end (end_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function ensureContactReplyColumns(): void {
    $db = getDB();
    $cols = $db->prepare("SELECT COLUMN_NAME
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'contact_messages'");
    $cols->execute();
    $names = $cols->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $has = fn(string $c): bool => in_array($c, $names, true);

    if (!$has('owner_reply')) {
        $db->exec("ALTER TABLE contact_messages ADD COLUMN owner_reply TEXT NULL");
    }
    if (!$has('replied_at')) {
        $db->exec("ALTER TABLE contact_messages ADD COLUMN replied_at TIMESTAMP NULL DEFAULT NULL");
    }
}

function ensureMonetizationTables(): void {
    $db = getDB();

    ensureOwnerSubscriptionTable();

    // Payments: proof upload / PayPal Sandbox
    $db->exec("CREATE TABLE IF NOT EXISTS payments (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id INT UNSIGNED NOT NULL,
      room_id INT UNSIGNED NULL,
      subscription_id INT UNSIGNED NULL,
      kind ENUM('room_subscription','owner_subscription') NOT NULL DEFAULT 'owner_subscription',
      plan ENUM('basic','pro') NULL,
      plan_type ENUM('basic','pro') NULL,
      original_price DECIMAL(10,2) NULL,
      paid_price DECIMAL(10,2) NULL,
      is_intro TINYINT(1) NOT NULL DEFAULT 0,
      amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      method ENUM('proof_upload','simulated','paypal') NOT NULL DEFAULT 'proof_upload',
      proof_path VARCHAR(255) NULL,
      paypal_order_id VARCHAR(64) NULL,
      paypal_capture_id VARCHAR(64) NULL,
      status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
      admin_note TEXT NULL,
      reviewed_by INT UNSIGNED NULL,
      reviewed_at TIMESTAMP NULL DEFAULT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_pay_user (user_id),
      KEY idx_pay_room (room_id),
      KEY idx_pay_sub (subscription_id),
      KEY idx_pay_kind (kind),
      KEY idx_pay_plan_type (plan_type),
      KEY idx_pay_intro (is_intro),
      KEY idx_pay_status (status),
      KEY idx_pay_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Best-effort upgrades for PayPal columns / method enum
    try {
        $cols = $db->query("SHOW COLUMNS FROM payments")->fetchAll() ?: [];
        $names = array_map(fn($r) => (string)($r["Field"] ?? ""), $cols);
        $has = fn(string $c): bool => in_array($c, $names, true);

        if (!$has("subscription_id")) {
            $db->exec("ALTER TABLE payments ADD COLUMN subscription_id INT UNSIGNED NULL");
        }
        if (!$has("kind")) {
            $db->exec("ALTER TABLE payments ADD COLUMN kind ENUM('room_subscription','owner_subscription') NOT NULL DEFAULT 'owner_subscription'");
        }
        if (!$has("plan")) {
            $db->exec("ALTER TABLE payments ADD COLUMN plan ENUM('basic','pro') NULL");
        }
        if (!$has("plan_type")) {
            $db->exec("ALTER TABLE payments ADD COLUMN plan_type ENUM('basic','pro') NULL");
        }
        if (!$has("original_price")) {
            $db->exec("ALTER TABLE payments ADD COLUMN original_price DECIMAL(10,2) NULL");
        }
        if (!$has("paid_price")) {
            $db->exec("ALTER TABLE payments ADD COLUMN paid_price DECIMAL(10,2) NULL");
        }
        if (!$has("is_intro")) {
            $db->exec("ALTER TABLE payments ADD COLUMN is_intro TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!$has("paypal_order_id")) {
            $db->exec("ALTER TABLE payments ADD COLUMN paypal_order_id VARCHAR(64) NULL");
        }
        if (!$has("paypal_capture_id")) {
            $db->exec("ALTER TABLE payments ADD COLUMN paypal_capture_id VARCHAR(64) NULL");
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $db->exec("ALTER TABLE payments MODIFY COLUMN room_id INT UNSIGNED NULL");
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $db->exec("ALTER TABLE payments MODIFY COLUMN method ENUM('proof_upload','simulated','paypal') NOT NULL DEFAULT 'proof_upload'");
    } catch (Throwable $e) {
        // ignore
    }


    // Notifications
    $db->exec("CREATE TABLE IF NOT EXISTS notifications (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id INT UNSIGNED NOT NULL,
      type VARCHAR(80) NOT NULL,
      title VARCHAR(200) NOT NULL,
      body TEXT NULL,
      link_url VARCHAR(255) NULL,
      is_read TINYINT(1) NOT NULL DEFAULT 0,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_notif_user (user_id, is_read),
      KEY idx_notif_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Admin warnings (anti-scam)
    $db->exec("CREATE TABLE IF NOT EXISTS admin_warnings (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      owner_id INT UNSIGNED NOT NULL,
      message TEXT NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_by INT UNSIGNED NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_warn_owner (owner_id, is_active),
      KEY idx_warn_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}


function ensureAmenityUniqueness(): void {
    $db = getDB();

    try {
        $db->query('SELECT 1 FROM amenities LIMIT 1');
    } catch (Throwable $e) {
        return;
    }

    // Normalize names.
    try {
        $db->exec('UPDATE amenities SET name = TRIM(name)');
    } catch (Throwable $e) {
        // ignore
    }

    // Deduplicate by name, keep smallest id.
    try {
        $dups = $db->query("SELECT name, MIN(id) AS keep_id, GROUP_CONCAT(id ORDER BY id) AS all_ids, COUNT(*) AS cnt
          FROM amenities
          GROUP BY name
          HAVING cnt > 1")
          ->fetchAll() ?: [];

        $hasBha = true;
        try { $db->query('SELECT 1 FROM boarding_house_amenities LIMIT 1'); } catch (Throwable $e) { $hasBha = false; }

        foreach ($dups as $d) {
            $keepId = intval($d['keep_id'] ?? 0);
            $idsRaw = (string)($d['all_ids'] ?? '');
            if ($keepId <= 0 || $idsRaw === '') continue;

            $ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw)), fn($v) => $v > 0 && $v !== $keepId));
            if (empty($ids)) continue;

            if ($hasBha) {
                $upd = $db->prepare('UPDATE IGNORE boarding_house_amenities SET amenity_id = ? WHERE amenity_id = ?');
                foreach ($ids as $id) $upd->execute([$keepId, $id]);
            }

            $del = $db->prepare('DELETE FROM amenities WHERE id = ?');
            foreach ($ids as $id) $del->execute([$id]);
        }
    } catch (Throwable $e) {
        // ignore
    }

    // Ensure unique index exists.
    try {
        $hasIndex = $db->query("SHOW INDEX FROM amenities WHERE Key_name = 'uq_amenities_name'")->fetch();
        if (!$hasIndex) {
            $db->exec('ALTER TABLE amenities ADD UNIQUE KEY uq_amenities_name (name)');
        }
    } catch (Throwable $e) {
        // ignore
    }
}
function seedDemoData(): void {
    $db = getDB();

    // Demo users
    $pw = password_hash('password', PASSWORD_DEFAULT);
    $db->prepare("INSERT INTO users (full_name,email,password,role,phone) VALUES (?,?,?,?,?)
      ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), password=VALUES(password), role=VALUES(role)")
        ->execute(['Demo Owner', 'owner@demo.com', $pw, 'owner', '']);
    $db->prepare("INSERT INTO users (full_name,email,password,role,phone) VALUES (?,?,?,?,?)
      ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), password=VALUES(password), role=VALUES(role)")
        ->execute(['Demo Tenant', 'tenant@demo.com', $pw, 'tenant', '']);
    $db->prepare("INSERT INTO users (full_name,email,password,role,phone) VALUES (?,?,?,?,?)
      ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), password=VALUES(password), role=VALUES(role)")
        ->execute(['Demo Admin', 'admin@demo.com', $pw, 'admin', '']);

    // Auto-verify demo owner (if columns exist)
    try {
        $db->exec("UPDATE users SET owner_verification_status = 'verified', owner_verified = 1, owner_verified_at = NOW() WHERE email = 'owner@demo.com'");
    } catch (Throwable $e) {
        try {
            $db->exec("UPDATE users SET owner_verified = 1, owner_verified_at = NOW() WHERE email = 'owner@demo.com'");
        } catch (Throwable $e2) {
            // ignore on older schemas
        }
    }

    // Amenities (safe to run multiple times)
    $amenities = [
        'WiFi', 'Electricity Included', 'Water Included', 'Air Conditioning', 'Laundry',
        'Parking', 'CCTV/Security', 'Kitchen Access', 'Pet Friendly', 'Near Transport',
    ];
    $ins = $db->prepare("INSERT IGNORE INTO amenities (name) VALUES (?)");
    foreach ($amenities as $name) $ins->execute([$name]);
}

$error = null;
$ok = false;
try {
    ensureDatabaseExists();
    // Reconnect now that DB exists (getDB uses DB_NAME in DSN).
    runSchema();
    ensureAvatarColumn();
    ensureUserAdminColumns();
    ensureUserRoleSupportsAdmin();
    ensureBoardingHouseModerationColumns();
    ensureBoardingHouseSubscriptionColumns();
    ensureContactReplyColumns();
    ensureChatTables();
    ensureRoomTables();
    ensureMonetizationTables();
    ensureAmenityUniqueness();
    seedDemoData();
    $ok = true;
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Installer</title>
  <link rel="stylesheet" href="<?= SITE_URL ?>/style.css">
</head>
<body>
  <div class="container" style="padding:40px 0;max-width:820px">
    <div class="card">
      <div class="card-header"><h1 style="margin:0">Installer</h1></div>
      <div class="card-body">
        <?php if ($ok): ?>
          <p>Database and tables are ready.</p>
           <ul>
             <li>DB: <code><?= sanitize(DB_NAME) ?></code></li>
             <li>Demo owner: <code>owner@demo.com</code> / <code>password</code></li>
             <li>Demo tenant: <code>tenant@demo.com</code> / <code>password</code></li>
             <li>Demo admin: <code>admin@demo.com</code> / <code>password</code></li>
           </ul>
          <p><a class="btn btn-primary" href="<?= SITE_URL ?>/index.php">Go to Home</a></p>
        <?php else: ?>
          <p style="color:#b00020">Install failed: <code><?= sanitize($error ?? 'unknown') ?></code></p>
          <p>Check <code>includes/config.php</code> DB settings and MySQL permissions.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>











