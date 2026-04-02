<?php
require_once __DIR__ . '/../../includes/config.php';
requireAdmin();

$db = getDB();
ensurePaymentsSubscriptionIdColumn();

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
        ['k' => 'listings', 'label' => 'Property Listings', 'icon' => 'fa-building', 'href' => 'listings.php'],
        ['k' => 'reports', 'label' => 'Reports', 'icon' => 'fa-flag', 'href' => 'reports.php'],
        ['k' => 'content', 'label' => 'Content', 'icon' => 'fa-bullhorn', 'href' => 'content.php'],
        ['k' => 'searches', 'label' => 'Search Logs', 'icon' => 'fa-magnifying-glass', 'href' => 'searches.php'],
        ['k' => 'settings', 'label' => 'Settings', 'icon' => 'fa-gear', 'href' => 'settings.php'],
        ['k' => 'exports', 'label' => 'Exports', 'icon' => 'fa-file-export', 'href' => 'exports.php'],
        ['k' => 'payments', 'label' => 'Payments', 'icon' => 'fa-receipt', 'href' => 'payments.php'],
        ['k' => 'warnings', 'label' => 'Warnings', 'icon' => 'fa-triangle-exclamation', 'href' => 'warnings.php'],
    ];

    $me = getCurrentUser();
    ?>
    <aside class="dash-sidebar">
        <a class="dash-brand" href="dashboard.php" aria-label="<?= sanitize(SITE_NAME) ?>">
            <span class="dash-logo-wrap"><img class="dash-logo" src="<?= SITE_URL ?>/boardease-logo.png" alt="<?= sanitize(SITE_NAME) ?> logo"></span>
            <span class="sr-only"><?= sanitize(SITE_NAME) ?></span>
        </a>

        <a class="dash-action" href="content.php" title="Manage announcements and pages">
            <span>Manage Content</span>
            <i class="fas fa-plus"></i>
        </a>


        <div class="dash-section-label">Admin Pages</div>

        <nav class="dash-nav">
            <?php foreach ($items as $it): ?>
                <a class="<?= $active === $it['k'] ? 'active' : '' ?>" href="<?= sanitize($it['href']) ?>">
                    <i class="fas <?= sanitize($it['icon']) ?>"></i> <?= sanitize($it['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>

    </aside>
    <?php
}

function adminTopbar(): void {
    global $db;
    $me = getCurrentUser();

    // Notification badge: open reports (best-effort)
    $openReports = 0;
    try {
        $openReports = intval($db->query("SELECT COUNT(*) FROM reports WHERE status = 'open'")->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $openReports = 0;
    }
    ?>
    <div class="dash-topbar">
        <div class="dash-search" aria-label="Search">
            <i class="fas fa-magnifying-glass"></i>
            <input type="search" placeholder="Search...">
        </div>

        <div class="dash-top-actions">

            <a class="dash-icon-btn" href="reports.php" title="Reports" aria-label="Reports">
                <i class="far fa-bell"></i>
                <?php if ($openReports > 0): ?>
                    <span class="dash-icon-badge"><?= $openReports > 99 ? "99+" : intval($openReports) ?></span>
                <?php endif; ?>
            </a>

            <div class="nav-user">
            <button class="user-btn" id="userBtn" type="button">
                <span class="user-avatar"><?= strtoupper(substr(sanitize($me['full_name'] ?? 'A'), 0, 1)) ?></span>
                <span><?= sanitize($me['full_name'] ?? 'Admin') ?></span>
                <i class="fas fa-chevron-down" style="font-size:0.7rem;color:var(--text-light)"></i>
            </button>

            <div class="user-dropdown" id="userDropdown">
                <div class="dropdown-header">
                    <strong><?= sanitize($me['full_name'] ?? '') ?></strong>
                    <span><?= sanitize($me['email'] ?? '') ?></span>
                    <span class="role-badge role-admin">Admin</span>
                </div>

                <a href="dashboard.php"><i class="fas fa-gauge"></i> Dashboard</a>
                <a href="users.php"><i class="fas fa-users"></i> Users</a>
                <a href="listings.php"><i class="fas fa-building"></i> Property Listings</a>
                <a href="reports.php"><i class="fas fa-flag"></i> Reports</a>
                <hr>

                <a href="<?= SITE_URL ?>/index.php"><i class="fas fa-house"></i> Back to site</a>
                <a class="logout-link" href="<?= SITE_URL ?>/logout.php"><i class="fas fa-right-from-bracket"></i> Logout</a>
            </div>
        </div>
        </div>
    </div>
    <?php
}














