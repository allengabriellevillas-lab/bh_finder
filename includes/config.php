<?php
// includes/config.php - Configuration & Database Connection

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

    // Owners may be required to be verified by admin before using owner tools.
    try {
        $u = getCurrentUser();
        if (is_array($u) && array_key_exists('owner_verified', $u) && intval($u['owner_verified']) !== 1) {
            setFlash('error', 'Your owner account is pending verification by an administrator.');
            header('Location: ' . SITE_URL . '/index.php?error=owner_unverified');
            exit;
        }
    } catch (Throwable $e) {
        // Ignore for compatibility with older schemas.
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
        ];
        try {
            $chk = $db->prepare("SELECT COLUMN_NAME
              FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'users'
                AND COLUMN_NAME IN ('avatar','is_active','owner_verified','owner_verified_at')");
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
            ];
        }
    }

    $cols = "id, full_name, email, role, phone";
    if (!empty($userColFlags['avatar'])) $cols .= ", avatar";
    if (!empty($userColFlags['is_active'])) $cols .= ", is_active";
    if (!empty($userColFlags['owner_verified'])) $cols .= ", owner_verified";
    if (!empty($userColFlags['owner_verified_at'])) $cols .= ", owner_verified_at";

    $stmt = $db->prepare("SELECT $cols FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
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



