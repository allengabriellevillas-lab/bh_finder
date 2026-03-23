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

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

function requireOwner(): void {
    requireLogin();
    if (!isOwner()) {
        header('Location: ' . SITE_URL . '/index.php?error=access_denied');
        exit;
    }
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;

    static $hasAvatar = null;
    $db = getDB();

    if ($hasAvatar === null) {
        try {
            $chk = $db->prepare("SELECT COUNT(*)
              FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'users'
                AND COLUMN_NAME = 'avatar'");
            $chk->execute();
            $hasAvatar = intval($chk->fetchColumn() ?: 0) > 0;
        } catch (Throwable $e) {
            // If INFORMATION_SCHEMA is restricted, just assume the column doesn't exist.
            $hasAvatar = false;
        }
    }

    $cols = "id, full_name, email, role, phone" . ($hasAvatar ? ", avatar" : "");
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
    return '₱' . number_format($price, 2);
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


