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

    $db = getDB();
    foreach (preg_split('/;\\s*\\R/', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || str_starts_with($stmt, '--')) continue;
        $db->exec($stmt);
    }
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
        $db->exec("UPDATE users SET owner_verified = 1, owner_verified_at = NOW() WHERE email = 'owner@demo.com'");
    } catch (Throwable $e) {
        // ignore on older schemas
    }

    // Avoid breaking existing owner accounts on upgrade (best-effort)
    try {
        $db->exec("UPDATE users SET owner_verified = 1, owner_verified_at = COALESCE(owner_verified_at, NOW()) WHERE role = 'owner' AND owner_verified = 0");
    } catch (Throwable $e) {
        // ignore on older schemas
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
    ensureContactReplyColumns();
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




