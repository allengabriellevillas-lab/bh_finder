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
    <aside class="dash-sidebar">
        <a class="dash-brand" href="dashboard.php" aria-label="<?= sanitize(SITE_NAME) ?>">
            <span class="dash-logo-wrap"><img class="dash-logo" src="<?= SITE_URL ?>/bh_finder-logo.png" alt="<?= sanitize(SITE_NAME) ?> logo"></span>
            <span class="sr-only"><?= sanitize(SITE_NAME) ?></span>
        </a>

        <a class="dash-action" href="content.php" title="Manage announcements and pages">
            <span>Manage Content</span>
            <i class="fas fa-plus"></i>
        </a>

        <nav class="dash-nav">
            <?php foreach ($items as $it): ?>
                <a class="<?= $active === $it['k'] ? 'active' : '' ?>" href="<?= sanitize($it['href']) ?>">
                    <i class="fas <?= sanitize($it['icon']) ?>"></i> <?= sanitize($it['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="dash-sidebar-footer">
            <div class="dash-me">
                <div class="dash-avatar"><?= strtoupper(substr(sanitize($me['full_name'] ?? 'A'), 0, 1)) ?></div>
                <div>
                    <strong style="display:block;font-size:.92rem"><?= sanitize($me['full_name'] ?? 'Admin') ?></strong>
                    <small><?= sanitize($me['email'] ?? '') ?></small>
                </div>
            </div>

            <div class="dash-nav" style="margin-top:6px">
                <a href="<?= SITE_URL ?>/index.php"><i class="fas fa-house"></i> Back to site</a>
                <a href="<?= SITE_URL ?>/logout.php"><i class="fas fa-right-from-bracket"></i> Logout</a>
            </div>
        </div>
    </aside>
    <?php
}

function adminTopbar(): void {
    $me = getCurrentUser();
    ?>
    <div class="dash-topbar">
        <div class="dash-search" aria-label="Search">
            <i class="fas fa-magnifying-glass"></i>
            <input type="search" placeholder="Search...">
        </div>

        <div class="dash-top-actions">
            <button class="dash-icon-btn" type="button" title="Notifications" aria-label="Notifications">
                <i class="far fa-bell"></i>
            </button>

            <div class="dash-user" aria-label="Account">
                <div class="dash-avatar"><?= strtoupper(substr(sanitize($me['full_name'] ?? 'A'), 0, 1)) ?></div>
                <div class="dash-user-meta">
                    <strong><?= sanitize($me['full_name'] ?? 'Admin') ?></strong>
                    <span>Admin</span>
                </div>
                <i class="fas fa-chevron-down" style="font-size:.75rem;color:#9CA3AF"></i>
            </div>
        </div>
    </div>
    <?php
}








