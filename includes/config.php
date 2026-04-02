<?php
// includes/config.php - Configuration & Database Connection

// Optional local secrets (not committed). Use this to set env vars like PAYPAL_CLIENT_ID, PAYPAL_SECRET, etc.
$__secretsLocal = __DIR__ . DIRECTORY_SEPARATOR . 'secrets.local.php';
if (is_file($__secretsLocal)) {
    require_once $__secretsLocal;
}
unset($__secretsLocal);

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'boarding_house_finder');

define('SITE_NAME', 'BoardingFinder');

// Base URL (used for linking assets like /style.css). Prefer detecting the project folder, not the current script's directory.
$__appRootForUrl = __DIR__;
if (basename(__DIR__) === 'includes') {
    $__appRootForUrl = dirname(__DIR__);
}
$__appDir = basename($__appRootForUrl);
$__defaultSiteUrl = 'http://localhost/' . $__appDir;

$__detectedSiteUrl = null;
if (PHP_SAPI !== 'cli' && isset($_SERVER['HTTP_HOST'], $_SERVER['SCRIPT_NAME'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);

    $needle = '/' . $__appDir;
    $pos = stripos($scriptName, $needle);
    if ($pos !== false) {
        $basePath = substr($scriptName, 0, $pos + strlen($needle));
    } else {
        $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        if ($basePath === '/') $basePath = '';
    }

    $__detectedSiteUrl = $scheme . '://' . $host . $basePath;
}
define('SITE_URL', $__detectedSiteUrl ?: $__defaultSiteUrl);
unset($__appRootForUrl, $__appDir, $__defaultSiteUrl, $__detectedSiteUrl);
// Allow this config to live either in the project root or under an /includes folder.
$__appRoot = __DIR__;
if (basename(__DIR__) === 'includes') {
    $__appRoot = dirname(__DIR__);
}
define('APP_ROOT', $__appRoot);
unset($__appRoot);

define('UPLOAD_DIR', rtrim(APP_ROOT, "/\\") . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR);
define('UPLOAD_URL', SITE_URL . '/uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/webp']);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Create PDO connection
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// Auth helpers
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function isOwner(): bool {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'owner';
}


function isTenant(): bool {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'tenant';
}

function isAdmin(): bool {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function roleLabel(?string $role): string {
    return match (strtolower(trim((string)($role ?? '')))) {
        'owner' => 'Property Owner',
        'tenant' => 'Tenant',
        'admin' => 'Admin',
        default => trim((string)($role ?? '')),
    };
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }

    // If the account was deactivated, force logout.
    try {
        $u = getCurrentUser();
        if (is_array($u) && array_key_exists('is_active', $u) && intval($u['is_active']) === 0) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
            header('Location: ' . SITE_URL . '/login.php?error=deactivated');
            exit;
        }
    } catch (Throwable $e) {
        // Ignore DB errors here; the page will likely error elsewhere anyway.
    }
}

function requireOwner(): void {
    requireLogin();
    if (!isOwner()) {
        header('Location: ' . SITE_URL . '/index.php?error=access_denied');
        exit;
    }
}


function requireTenant(): void {
    requireLogin();
    if (!isTenant()) {
        header('Location: ' . SITE_URL . '/index.php?error=access_denied');
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . SITE_URL . '/index.php?error=access_denied');
        exit;
    }
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;

    static $userColFlags = null;
    $db = getDB();

    if ($userColFlags === null) {
        $userColFlags = [
            'avatar' => false,
            'is_active' => false,
            'owner_verified' => false,
            'owner_verified_at' => false,
            'owner_verification_status' => false,
            'owner_id_doc_path' => false,
            'owner_verification_reason' => false,
        ];
        try {
            $chk = $db->prepare("SELECT COLUMN_NAME
              FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'users'
                AND COLUMN_NAME IN ('avatar','is_active','owner_verified','owner_verified_at','owner_verification_status','owner_id_doc_path','owner_verification_reason')");
            $chk->execute();
            $cols = $chk->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($cols as $c) {
                $c = (string)$c;
                if (array_key_exists($c, $userColFlags)) $userColFlags[$c] = true;
            }
        } catch (Throwable $e) {
            // If INFORMATION_SCHEMA is restricted, just assume extra columns don't exist.
            $userColFlags = [
                'avatar' => false,
                'is_active' => false,
                'owner_verified' => false,
                'owner_verified_at' => false,
                'owner_verification_status' => false,
                'owner_id_doc_path' => false,
                'owner_verification_reason' => false,
            ];
        }
    }

    $cols = "id, full_name, email, role, phone";
    if (!empty($userColFlags['avatar'])) $cols .= ", avatar";
    if (!empty($userColFlags['is_active'])) $cols .= ", is_active";
    if (!empty($userColFlags['owner_verified'])) $cols .= ", owner_verified";
    if (!empty($userColFlags['owner_verified_at'])) $cols .= ", owner_verified_at";
    if (!empty($userColFlags['owner_verification_status'])) $cols .= ", owner_verification_status";
    if (!empty($userColFlags['owner_id_doc_path'])) $cols .= ", owner_id_doc_path";
    if (!empty($userColFlags['owner_verification_reason'])) $cols .= ", owner_verification_reason";

    // Prefer including owner verification fields when available; fallback if the DB is older.
    $colsTry = $cols;
    foreach (["owner_verification_status","owner_id_doc_path","owner_verification_reason"] as $c) {
        if (!str_contains($colsTry, $c)) $colsTry .= ", $c";
    }

    try {
        $stmt = $db->prepare("SELECT $colsTry FROM users WHERE id = ?");
        $stmt->execute([$_SESSION["user_id"]]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        $stmt = $db->prepare("SELECT $cols FROM users WHERE id = ?");
        $stmt->execute([$_SESSION["user_id"]]);
        return $stmt->fetch() ?: null;
    }
}

// Flash messages
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Sanitize output
function sanitize(mixed $input): string {
    if ($input === null) return '';
    if (is_bool($input)) $input = $input ? '1' : '0';
    if (!is_scalar($input)) return '';
    return htmlspecialchars(trim((string)$input), ENT_QUOTES, 'UTF-8');
}

function textLength(?string $value): int {
    $value = (string)($value ?? '');
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function textSlice(?string $value, int $start, ?int $length = null): string {
    $value = (string)($value ?? '');

    if (function_exists('mb_substr')) {
        return $length === null ? mb_substr($value, $start) : mb_substr($value, $start, $length);
    }

    return $length === null ? substr($value, $start) : substr($value, $start, $length);
}

// Format currency
function formatPrice(float $price): string {
    // Use HTML entity for robustness across file/DB encodings.
    return '&#8369;' . number_format($price, 2);
}


// Upload image helper
function uploadImage(array $file, string $prefix = 'img'): string|false {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return false;
    if (!isset($file['tmp_name']) || !is_string($file['tmp_name']) || $file['tmp_name'] === '') return false;
    if (!is_uploaded_file($file['tmp_name'])) return false;

    $size = intval($file['size'] ?? 0);
    if ($size <= 0 || $size > MAX_FILE_SIZE) return false;

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: '';
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($file['tmp_name']) ?: '';
    } else {
        $mime = strval($file['type'] ?? '');
    }
    if (!in_array($mime, ALLOWED_TYPES, true)) return false;

    $extByMime = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    $ext = $extByMime[$mime] ?? null;
    if ($ext === null) return false;

    $safePrefix = preg_replace('/[^a-zA-Z0-9_-]+/', '', $prefix) ?: 'img';
    $token = bin2hex(random_bytes(16));
    $filename = $safePrefix . '_' . $token . '.' . $ext;
    $destination = UPLOAD_DIR . $filename;

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) return false;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return $filename;
    }

    return false;
}

function deleteUploadedFile(string $storedName): bool {
    $base = basename($storedName);
    if ($base === '' || $base === '.' || $base === '..') return false;

    $uploadRoot = realpath(UPLOAD_DIR);
    $candidate = realpath(UPLOAD_DIR . $base);

    // If the file doesn't exist, treat it as already deleted.
    if ($candidate === false) return true;
    if ($uploadRoot === false) return false;

    $uploadRoot = rtrim($uploadRoot, "/\\") . DIRECTORY_SEPARATOR;
    if (stripos($candidate, $uploadRoot) !== 0) return false;

    if (!is_file($candidate)) return true;

    return unlink($candidate);
}




// ------------------------------
// Extended helpers (settings, notifications, subscriptions)
// ------------------------------

function parseEnumValues(string $columnType): array {
    $columnType = trim($columnType);
    if ($columnType === '' || stripos($columnType, 'enum(') !== 0) return [];

    if (!preg_match_all("/'([^']*)'/", $columnType, $m)) return [];
    $vals = $m[1] ?? [];
    $out = [];
    foreach ($vals as $v) {
        $v = trim((string)$v);
        if ($v === '') continue;
        $out[] = $v;
    }
    return array_values(array_unique($out));
}

function boardingHouseStatusDbValues(PDO $db): array {
    static $cache = null;
    if (is_array($cache)) return $cache;

    try {
        $cols = $db->query("SHOW COLUMNS FROM boarding_houses")->fetchAll() ?: [];
        foreach ($cols as $c) {
            if (($c['Field'] ?? null) !== 'status') continue;
            $cache = parseEnumValues((string)($c['Type'] ?? ''));
            return $cache;
        }
    } catch (Throwable $e) {
        // ignore
    }

    $cache = [];
    return $cache;
}

// Canonical (UI) statuses: active|full|inactive.
function boardingHouseStatusUi(string $rawStatus): string {
    $s = strtolower(trim($rawStatus));
    if ($s === 'full') return 'full';
    if ($s === 'active' || $s === 'available' || $s === 'open' || $s === 'published') return 'active';
    if ($s === 'inactive' || $s === 'disabled' || $s === 'deactivated' || $s === 'hidden') return 'inactive';
    return 'inactive';
}

function boardingHouseStatusDbValue(PDO $db, string $canonical): string {
    $canonical = strtolower(trim($canonical));
    $vals = boardingHouseStatusDbValues($db);
    $valSet = array_fill_keys($vals, true);

    if ($canonical === 'full') {
        if (isset($valSet['full'])) return 'full';
        return 'full';
    }

    if ($canonical === 'inactive') {
        if (isset($valSet['inactive'])) return 'inactive';
        if (isset($valSet['disabled'])) return 'disabled';
        if (isset($valSet['deactivated'])) return 'deactivated';
        return 'inactive';
    }

    // active
    if (isset($valSet['active'])) return 'active';
    if (isset($valSet['available'])) return 'available';
    if (!empty($vals)) {
        foreach ($vals as $v) {
            $v2 = strtolower(trim((string)$v));
            if ($v2 !== '' && $v2 !== 'inactive' && $v2 !== 'full') return (string)$v;
        }
    }
    return 'active';
}

// ---- App settings (settings table) ----
function settingsTableExists(PDO $db): bool {
    static $cache = null;
    if (is_bool($cache)) return $cache;
    try {
        $db->query("SELECT 1 FROM settings LIMIT 1");
        $cache = true;
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
}

function getSetting(string $key, ?string $default = null): ?string {
    $key = trim($key);
    if ($key === '') return $default;
    try {
        $db = getDB();
        if (!settingsTableExists($db)) return $default;
        $stmt = $db->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        if ($v === false || $v === null) return $default;
        return (string)$v;
    } catch (Throwable $e) {
        return $default;
    }
}

function setSetting(string $key, ?string $value, ?int $updatedBy = null): bool {
    $key = trim($key);
    if ($key === '') return false;
    try {
        $db = getDB();
        if (!settingsTableExists($db)) return false;
        $stmt = $db->prepare("INSERT INTO settings (`key`, `value`, updated_by)
          VALUES (?,?,?)
          ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_by = VALUES(updated_by)");
        $stmt->execute([$key, $value, $updatedBy]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function getServiceFeePercentage(): float {
    $raw = getSetting('service_fee_percentage', '5');
    $p = floatval($raw ?? '5');
    if ($p < 0) $p = 0;
    if ($p > 30) $p = 30;
    return $p;
}

// ---- Notifications helpers ----
function notificationsTableExists(PDO $db): bool {
    static $cache = null;
    if (is_bool($cache)) return $cache;
    try {
        $db->query("SELECT 1 FROM notifications LIMIT 1");
        $cache = true;
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
}

function notificationsEnabled(): bool {
    try {
        $db = getDB();
        if (!notificationsTableExists($db)) return false;
        $enabled = getSetting('notifications_enabled', '1');
        return trim((string)$enabled) !== '0';
    } catch (Throwable $e) {
        return false;
    }
}

function createNotification(int $userId, string $type, string $title, ?string $body = null, ?string $linkUrl = null): bool {
    if ($userId <= 0) return false;
    try {
        $db = getDB();
        if (!notificationsEnabled()) return false;
        $stmt = $db->prepare("INSERT INTO notifications (user_id, type, title, body, link_url)
          VALUES (?,?,?,?,?)");
        $stmt->execute([$userId, $type, $title, $body, $linkUrl]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function unreadNotificationCount(int $userId): int {
    if ($userId <= 0) return 0;
    try {
        $db = getDB();
        if (!notificationsEnabled()) return 0;
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return intval($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

// ---- Owner verification / subscriptions ----
function requireVerifiedOwner(): void {
    requireOwner();
    $me = getCurrentUser() ?: [];
    $verified = false;

    if (array_key_exists('owner_verification_status', $me)) {
        $verified = strtolower((string)($me['owner_verification_status'] ?? '')) === 'verified';
    } elseif (array_key_exists('owner_verified', $me)) {
        $verified = intval($me['owner_verified'] ?? 0) === 1;
    }

// Best-effort runtime migration for owner verification fields.
function ensureOwnerVerificationColumns(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $db = getDB();
        $cols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $names = array_map(fn($r) => (string)($r["Field"] ?? ""), $cols);
        $has = fn(string $c): bool => in_array($c, $names, true);

        if (!$has("owner_verification_status")) {
            $db->exec("ALTER TABLE users ADD COLUMN owner_verification_status ENUM('pending','verified','rejected') NULL DEFAULT NULL");
            try { $db->exec("ALTER TABLE users ADD INDEX idx_users_owner_vstatus (owner_verification_status)"); } catch (Throwable $e) { }
        }
        if (!$has("owner_id_doc_path")) {
            $db->exec("ALTER TABLE users ADD COLUMN owner_id_doc_path VARCHAR(255) NULL");
        }
        if (!$has("owner_verification_reason")) {
            $db->exec("ALTER TABLE users ADD COLUMN owner_verification_reason TEXT NULL");
        }
    } catch (Throwable $e) {
        // ignore
    }
}


    if (!$verified) {
        setFlash('error', 'Please complete owner verification to continue.');
        header('Location: ' . SITE_URL . '/pages/owner/verification.php');
        exit;
    }
}

function ownerSubscriptionsTableExists(PDO $db): bool {
    static $cache = null;
    if (is_bool($cache)) return $cache;
    try {
        $db->query("SELECT 1 FROM owner_subscriptions LIMIT 1");
        $cache = true;
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
}

function getActiveOwnerSubscription(int $ownerId): ?array {
    if ($ownerId <= 0) return null;
    try {
        $db = getDB();
        if (!ownerSubscriptionsTableExists($db)) return null;
        $stmt = $db->prepare("SELECT *
          FROM owner_subscriptions
          WHERE owner_id = ?
            AND status = 'active'
            AND (end_date IS NULL OR end_date >= CURDATE())
          ORDER BY COALESCE(end_date, '9999-12-31') DESC, id DESC
          LIMIT 1");
        $stmt->execute([$ownerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

// Intro pricing settings
function ownerIntroPricingActive(): bool {
    $enabled = trim((string)(getSetting('intro_price_enabled', '1') ?? '1'));
    if ($enabled === '0') return false;

    $end = trim((string)(getSetting('intro_end_date', '2026-12-31') ?? '2026-12-31'));
    if ($end === '') return true;

    try {
        $today = new DateTime('today');
        $endDt = new DateTime($end);
        return $today <= $endDt;
    } catch (Throwable $e) {
        return true;
    }
}

/**
 * Returns pricing details for a plan:
 * - original: regular price
 * - paid: price to charge (intro if active)
 * - is_intro: whether intro pricing applied
 */
function ownerSubscriptionPricing(string $plan): array {
    $plan = strtolower(trim($plan));
    if (!in_array($plan, ['basic','pro'], true)) $plan = 'basic';

    $regularBasic = floatval(getSetting('owner_subscription_amount_basic', '999') ?: '999');
    $regularPro = floatval(getSetting('owner_subscription_amount_pro', '1999') ?: '1999');

    $introBasic = floatval(getSetting('owner_subscription_intro_amount_basic', '499') ?: '499');
    $introPro = floatval(getSetting('owner_subscription_intro_amount_pro', '999') ?: '999');

    $original = $plan === 'pro' ? (float)$regularPro : (float)$regularBasic;
    $intro = $plan === 'pro' ? (float)$introPro : (float)$introBasic;

    $isIntro = ownerIntroPricingActive();
    $paid = $isIntro ? $intro : $original;

    return [
        'plan_type' => $plan,
        'original' => $original,
        'paid' => $paid,
        'is_intro' => $isIntro ? 1 : 0,
    ];
}

// Featured listing columns (Pro feature)
function ensureFeaturedListingColumns(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $db = getDB();
        $cols = $db->query("SHOW COLUMNS FROM boarding_houses")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $names = array_map(fn($r) => (string)($r['Field'] ?? ''), $cols);
        $has = fn(string $col): bool => in_array($col, $names, true);

        if (!$has('is_featured')) {
            $db->exec("ALTER TABLE boarding_houses ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0");
            try { $db->exec("ALTER TABLE boarding_houses ADD INDEX idx_bh_featured (is_featured)"); } catch (Throwable $e) { }
        }
        if (!$has('featured_until')) {
            $db->exec("ALTER TABLE boarding_houses ADD COLUMN featured_until DATETIME NULL");
            try { $db->exec("ALTER TABLE boarding_houses ADD INDEX idx_bh_featured_until (featured_until)"); } catch (Throwable $e) { }
        }
    } catch (Throwable $e) {
        // ignore
    }
}
function ownerSubscriptionMaxProperties(string $plan): ?int {
    $plan = strtolower(trim($plan));
    if ($plan === 'pro') return null;
    $raw = getSetting('owner_subscription_basic_max_properties', '1');
    return max(1, intval($raw ?? '2'));
}

function ownerHasPropertyCapacity(int $ownerId, ?array $activeSub = null): bool {
    $ownerId = intval($ownerId);
    if ($ownerId <= 0) return false;
    $activeSub = $activeSub ?: getActiveOwnerSubscription($ownerId);
    if (!$activeSub) return false;

    $max = ownerSubscriptionMaxProperties((string)($activeSub['plan'] ?? 'basic'));
    if ($max === null) return true;

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT COUNT(*) FROM boarding_houses WHERE owner_id = ?");
        $stmt->execute([$ownerId]);
        $cnt = intval($stmt->fetchColumn() ?: 0);
        return $cnt < $max;
    } catch (Throwable $e) {
        return false;
    }
}

function syncOwnerPropertiesToSubscription(int $ownerId, array $sub): void {
    $ownerId = intval($ownerId);
    if ($ownerId <= 0) return;
    $subId = intval($sub['id'] ?? 0);
    $end = trim((string)($sub['end_date'] ?? ''));
    if ($subId <= 0 || $end === '') return;

    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE boarding_houses
          SET is_active = 1, expires_at = ?, subscription_id = ?
          WHERE owner_id = ?");
        $stmt->execute([$end . ' 23:59:59', $subId, $ownerId]);
    } catch (Throwable $e) {
        // ignore
    }
}

function requireActiveOwnerSubscriptionForListingCreate(): void {
    requireOwner();
    $uid = intval($_SESSION['user_id'] ?? 0);
    $sub = getActiveOwnerSubscription($uid);
    if (!$sub) {
        setFlash('error', 'Subscription required: please choose a plan before adding a property.');
        header('Location: ' . SITE_URL . '/pages/owner/subscriptions.php');
        exit;
    }
    if (!ownerHasPropertyCapacity($uid, $sub)) {
        setFlash('error', 'Your current plan has reached its property limit. Upgrade to Pro to add more properties.');
        header('Location: ' . SITE_URL . '/pages/owner/subscriptions.php');
        exit;
    }
}

function ensureSubscriptionExpiringNotifications(int $ownerId): void {
    // Backwards-compatible name; now applies to property-based subscriptions.
    if ($ownerId <= 0 || !notificationsEnabled()) return;
    try {
        $db = getDB();
        if (!ownerSubscriptionsTableExists($db)) return;

        $sub = getActiveOwnerSubscription($ownerId);
        if (!$sub) return;

        $end = trim((string)($sub['end_date'] ?? ''));
        if ($end === '') return;

        $endDt = new DateTime($end);
        $today = new DateTime('today');
        $daysLeft = (int)$today->diff($endDt)->format('%r%a');
        if ($daysLeft < 0 || $daysLeft > 7) return;

        $type = 'subscription_expiring';
        $title = 'Your subscription is expiring soon';
        $body = "Your subscription expires in {$daysLeft} day" . ($daysLeft === 1 ? '' : 's') . ". Renew to keep your listings visible.";
        $link = SITE_URL . '/pages/owner/subscriptions.php';

        $chk = $db->prepare("SELECT COUNT(*) FROM notifications
          WHERE user_id = ?
            AND type = ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL 20 HOUR)");
        $chk->execute([$ownerId, $type]);
        if (intval($chk->fetchColumn() ?: 0) > 0) return;

        createNotification($ownerId, $type, $title, $body, $link);
    } catch (Throwable $e) {
        // ignore
    }
}

// Legacy toggle: rooms were previously subscription-gated.
function roomSubscriptionEnforced(): bool {
    return false;
}

// -------------------------
// PayPal (Sandbox) helpers
// -------------------------

function paypalEnv(): string {
    $env = strtolower(trim((string)(getenv('PAYPAL_ENV') ?: 'sandbox')));
    return in_array($env, ['live','production'], true) ? 'live' : 'sandbox';
}

function paypalCurrency(): string {
    $cur = strtoupper(trim((string)(getenv('PAYPAL_CURRENCY') ?: 'PHP')));
    return $cur !== '' ? $cur : 'PHP';
}

function paypalEnabled(): bool {
    $cid = trim((string)(getenv('PAYPAL_CLIENT_ID') ?: ''));
    $sec = trim((string)(getenv('PAYPAL_SECRET') ?: ''));
    return $cid !== '' && $sec !== '';
}


// Best-effort runtime migration for older databases.
// Some installs were created before monetization columns were added.
function ensurePaymentsSubscriptionIdColumn(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $db = getDB();
        $cols = $db->query("SHOW COLUMNS FROM payments")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $names = array_map(fn($r) => (string)($r['Field'] ?? ''), $cols);
        $has = fn(string $col): bool => in_array($col, $names, true);

        if (!$has('subscription_id')) {
            $db->exec("ALTER TABLE payments ADD COLUMN subscription_id INT UNSIGNED NULL");
            try { $db->exec("ALTER TABLE payments ADD INDEX idx_pay_sub (subscription_id)"); } catch (Throwable $e) { }
        }

        // Discount tracking (intro offer)
        if (!$has('plan_type')) {
            $db->exec("ALTER TABLE payments ADD COLUMN plan_type ENUM('basic','pro') NULL");
            try { $db->exec("ALTER TABLE payments ADD INDEX idx_pay_plan_type (plan_type)"); } catch (Throwable $e) { }
        }
        if (!$has('original_price')) {
            $db->exec("ALTER TABLE payments ADD COLUMN original_price DECIMAL(10,2) NULL");
        }
        if (!$has('paid_price')) {
            $db->exec("ALTER TABLE payments ADD COLUMN paid_price DECIMAL(10,2) NULL");
        }
        if (!$has('is_intro')) {
            $db->exec("ALTER TABLE payments ADD COLUMN is_intro TINYINT(1) NOT NULL DEFAULT 0");
            try { $db->exec("ALTER TABLE payments ADD INDEX idx_pay_intro (is_intro)"); } catch (Throwable $e) { }
        }

        // Other columns that older DBs may miss
        if (!$has('kind')) {
            $db->exec("ALTER TABLE payments ADD COLUMN kind ENUM('room_subscription','owner_subscription') NOT NULL DEFAULT 'owner_subscription'");
        }
        if (!$has('plan')) {
            $db->exec("ALTER TABLE payments ADD COLUMN plan ENUM('basic','pro') NULL");
        }
        if (!$has('method')) {
            $db->exec("ALTER TABLE payments ADD COLUMN method ENUM('proof_upload','simulated','paypal') NOT NULL DEFAULT 'proof_upload'");
        }
        if (!$has('proof_path')) {
            $db->exec("ALTER TABLE payments ADD COLUMN proof_path VARCHAR(255) NULL");
        }
        if (!$has('paypal_order_id')) {
            $db->exec("ALTER TABLE payments ADD COLUMN paypal_order_id VARCHAR(64) NULL");
        }
        if (!$has('paypal_capture_id')) {
            $db->exec("ALTER TABLE payments ADD COLUMN paypal_capture_id VARCHAR(64) NULL");
        }
        if (!$has('status')) {
            $db->exec("ALTER TABLE payments ADD COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
        }
        if (!$has('admin_note')) {
            $db->exec("ALTER TABLE payments ADD COLUMN admin_note TEXT NULL");
        }
        if (!$has('reviewed_by')) {
            $db->exec("ALTER TABLE payments ADD COLUMN reviewed_by INT UNSIGNED NULL");
        }
        if (!$has('reviewed_at')) {
            $db->exec("ALTER TABLE payments ADD COLUMN reviewed_at TIMESTAMP NULL DEFAULT NULL");
        }
        if (!$has('created_at')) {
            $db->exec("ALTER TABLE payments ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
    } catch (Throwable $e) {
        // ignore (payments table might not exist yet / no permissions)
    }
}
function paypalApiBase(): string {
    return paypalEnv() === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}

function paypalSslVerify(): bool {
    $v = trim((string)(getenv('PAYPAL_SSL_VERIFY') ?: '1'));
    return !in_array($v, ['0','false','no'], true);
}

function paypalHttpJson(string $method, string $path, array $headers = [], ?array $jsonBody = null, ?string $accessToken = null): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is required for PayPal integration.');
    }

    $url = rtrim(paypalApiBase(), '/') . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    $h = [
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    foreach ($headers as $hh) { $h[] = $hh; }
    if ($accessToken) {
        $h[] = 'Authorization: Bearer ' . $accessToken;
    }

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, paypalSslVerify());
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, paypalSslVerify() ? 2 : 0);

    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody);
        if ($payload === false) throw new RuntimeException('Unable to encode PayPal request JSON.');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        throw new RuntimeException('PayPal request failed: ' . $err);
    }

    $data = null;
    if (is_string($resp) && $resp !== '') {
        $data = json_decode($resp, true);
    }

    if ($code < 200 || $code >= 300) {
        $msg = 'PayPal API error (' . $code . ').';
        if (is_array($data)) {
            $detail = $data['message'] ?? ($data['error_description'] ?? ($data['details'][0]['description'] ?? null));
            if ($detail) $msg .= ' ' . (string)$detail;
        }
        throw new RuntimeException($msg);
    }

    return is_array($data) ? $data : [];
}

function paypalGetAccessToken(): array {
    static $cache = null;
    if (is_array($cache) && isset($cache['token'], $cache['expires_at']) && time() < (int)$cache['expires_at']) {
        return $cache;
    }

    $cid = trim((string)(getenv('PAYPAL_CLIENT_ID') ?: ''));
    $sec = trim((string)(getenv('PAYPAL_SECRET') ?: ''));
    if ($cid === '' || $sec === '') {
        throw new RuntimeException('PayPal is not configured. Set PAYPAL_CLIENT_ID and PAYPAL_SECRET.');
    }

    $url = rtrim(paypalApiBase(), '/') . '/v1/oauth2/token';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_USERPWD, $cid . ':' . $sec);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Accept-Language: en_US']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, paypalSslVerify());
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, paypalSslVerify() ? 2 : 0);

    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        throw new RuntimeException('PayPal auth failed: ' . $err);
    }

    $data = is_string($resp) ? json_decode($resp, true) : null;
    if ($code < 200 || $code >= 300 || !is_array($data)) {
        $msg = 'PayPal auth error (' . $code . ').';
        if (is_array($data) && !empty($data['error_description'])) $msg .= ' ' . (string)$data['error_description'];
        throw new RuntimeException($msg);
    }

    $token = trim((string)($data['access_token'] ?? ''));
    $expiresIn = (int)($data['expires_in'] ?? 0);
    if ($token === '') throw new RuntimeException('PayPal auth returned no access_token.');

    $cache = [
        'token' => $token,
        // Refresh a bit earlier than expiry
        'expires_at' => time() + max(60, $expiresIn - 60),
    ];
    return $cache;
}

function paypalCreateOrder(float $amount, string $returnUrl, string $cancelUrl, string $customId = '', ?string $currency = null): array {
    $currency = $currency ? strtoupper(trim($currency)) : paypalCurrency();
    $access = paypalGetAccessToken();

    $value = number_format(max(0, (float)$amount), 2, '.', '');
    $body = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'amount' => [
                'currency_code' => $currency,
                'value' => $value,
            ],
        ]],
        'application_context' => [
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'user_action' => 'PAY_NOW',
        ],
    ];
    if ($customId !== '') {
        $body['purchase_units'][0]['custom_id'] = $customId;
    }

    $data = paypalHttpJson('POST', '/v2/checkout/orders', [], $body, $access['token']);
    $orderId = (string)($data['id'] ?? '');

    $approve = '';
    if (!empty($data['links']) && is_array($data['links'])) {
        foreach ($data['links'] as $lnk) {
            if (is_array($lnk) && ($lnk['rel'] ?? '') === 'approve') {
                $approve = (string)($lnk['href'] ?? '');
                break;
            }
        }
    }

    if ($orderId === '' || $approve === '') {
        throw new RuntimeException('PayPal order creation failed: missing approval link.');
    }

    return [
        'order_id' => $orderId,
        'approve_url' => $approve,
        'raw' => $data,
    ];
}

function paypalCaptureOrder(string $orderId): array {
    $orderId = trim($orderId);
    if ($orderId === '') throw new RuntimeException('Missing PayPal order id.');

    $access = paypalGetAccessToken();
    $data = paypalHttpJson('POST', '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', [], null, $access['token']);

    $captureId = '';
    if (!empty($data['purchase_units'][0]['payments']['captures'][0]['id'])) {
        $captureId = (string)$data['purchase_units'][0]['payments']['captures'][0]['id'];
    }

    return [
        'capture_id' => $captureId,
        'raw' => $data,
    ];
}
// Daily views tracking (for notifications / analytics)
function ensureBoardingHouseDailyViewsTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $db = getDB();
        try {
            $db->query('SELECT 1 FROM boarding_house_daily_views LIMIT 1');
            return;
        } catch (Throwable $e) {
            // continue
        }

        $db->exec("CREATE TABLE IF NOT EXISTS boarding_house_daily_views (
          boarding_house_id INT NOT NULL,
          view_date DATE NOT NULL,
          views INT UNSIGNED NOT NULL DEFAULT 0,
          PRIMARY KEY (boarding_house_id, view_date),
          KEY idx_bhdv_date (view_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        // ignore
    }
}
