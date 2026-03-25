<?php
require_once __DIR__ . '/../../includes/config.php';
requireAdmin();

$db = getDB();

$showNavbar = false;

function adminTableHasColumn(PDO $db, string $table, string $col): bool {
    static $cache = [];
    $k = $table . '.' . $col;
    if (array_key_exists($k, $cache)) return $cache[$k];
    try {
        $stmt = $db->prepare("SELECT COUNT(*)
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?");
        $stmt->execute([$table, $col]);
        $cache[$k] = intval($stmt->fetchColumn() ?: 0) > 0;
        return $cache[$k];
    } catch (Throwable $e) {
        $cache[$k] = false;
        return false;
    }
}

function adminLog(PDO $db, string $action, ?string $entityType = null, ?int $entityId = null, ?array $meta = null): void {
    // Safe no-op if audit_logs doesn't exist.
    try {
        $stmt = $db->prepare("INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, meta_json) VALUES (?,?,?,?,?)");
        $stmt->execute([
            intval($_SESSION['user_id']),
            $action,
            $entityType,
            $entityId,
            $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        // ignore
    }
}

function adminSidebar(string $active): void {
    $items = [
        ['k' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fa-gauge', 'href' => 'dashboard.php'],
        ['k' => 'users', 'label' => 'Users', 'icon' => 'fa-users', 'href' => 'users.php'],
        ['k' => 'owners', 'label' => 'Owner Verification', 'icon' => 'fa-user-check', 'href' => 'owners.php'],
        ['k' => 'listings', 'label' => 'Listings', 'icon' => 'fa-building', 'href' => 'listings.php'],
        ['k' => 'reports', 'label' => 'Reports', 'icon' => 'fa-flag', 'href' => 'reports.php'],
        ['k' => 'content', 'label' => 'Content', 'icon' => 'fa-bullhorn', 'href' => 'content.php'],
        ['k' => 'searches', 'label' => 'Search Logs', 'icon' => 'fa-magnifying-glass', 'href' => 'searches.php'],
        ['k' => 'settings', 'label' => 'Settings', 'icon' => 'fa-gear', 'href' => 'settings.php'],
        ['k' => 'exports', 'label' => 'Exports', 'icon' => 'fa-file-export', 'href' => 'exports.php'],
    ];

    $me = getCurrentUser();
    ?>
    <aside class="sidebar">
        <div class="sidebar-user">
            <div class="sidebar-avatar"><?= strtoupper(substr(sanitize($me['full_name'] ?? 'A'), 0, 1)) ?></div>
            <div class="sidebar-name"><?= sanitize($me['full_name'] ?? 'Admin') ?></div>
            <div class="sidebar-email"><?= sanitize($me['email'] ?? '') ?></div>
            <div class="role-badge role-admin" style="margin-top:10px;display:inline-flex">admin</div>
        </div>

        <nav class="sidebar-nav">
            <?php foreach ($items as $it): ?>
                <a class="<?= $active === $it['k'] ? 'active' : '' ?>" href="<?= sanitize($it['href']) ?>">
                    <i class="fas <?= sanitize($it['icon']) ?>"></i> <?= sanitize($it['label']) ?>
                </a>
            <?php endforeach; ?>
            <hr style="border:none;border-top:1px solid var(--border);margin:10px 0">
            <a href="<?= SITE_URL ?>/index.php"><i class="fas fa-house"></i> Back to site</a>
            <a href="<?= SITE_URL ?>/logout.php" class="logout-link"><i class="fas fa-right-from-bracket"></i> Logout</a>
        </nav>
    </aside>
    <?php
}






