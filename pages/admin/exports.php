<?php
require_once __DIR__ . '/_common.php';

$pageTitle = 'Exports';
function csvOut(array $header, array $rows, string $filename): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    $out = fopen('php://output', 'w');
    fputcsv($out, $header);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

$download = trim($_GET['download'] ?? '');
if ($download !== '') {
    if ($download === 'users') {
        $hasActive = adminTableHasColumn($db, 'users', 'is_active');
        $hasOwnerVerified = adminTableHasColumn($db, 'users', 'owner_verified');
        $cols = 'id, full_name, email, role, phone, created_at';
        if ($hasActive) $cols .= ', is_active';
        if ($hasOwnerVerified) $cols .= ', owner_verified, owner_verified_at';
        $rows = $db->query("SELECT $cols FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_NUM) ?: [];
        $hdr = explode(', ', $cols);
        csvOut($hdr, $rows, 'users.csv');
    }

    if ($download === 'listings') {
        $hasApproval = adminTableHasColumn($db, 'boarding_houses', 'approval_status');
        $cols = 'id, owner_id, name, city, status, price_min, price_max, accommodation_type, total_rooms, available_rooms, created_at';
        if ($hasApproval) $cols .= ', approval_status';
        $rows = $db->query("SELECT $cols FROM boarding_houses ORDER BY id ASC")->fetchAll(PDO::FETCH_NUM) ?: [];
        $hdr = explode(', ', $cols);
        csvOut($hdr, $rows, 'listings.csv');
    }

    if ($download === 'reports') {
        try {
            $cols = 'id, boarding_house_id, reporter_id, reason, status, created_at';
            $rows = $db->query("SELECT $cols FROM reports ORDER BY id ASC")->fetchAll(PDO::FETCH_NUM) ?: [];
            $hdr = explode(', ', $cols);
            csvOut($hdr, $rows, 'reports.csv');
        } catch (Throwable $e) {
            setFlash('error', 'Reports table not found.');
            header('Location: exports.php');
            exit;
        }
    }

    if ($download === 'search_logs') {
        try {
            $cols = 'id, user_id, ip, channel, search, city, min_price, max_price, accommodation_type, created_at';
            $rows = $db->query("SELECT $cols FROM search_logs ORDER BY id ASC")->fetchAll(PDO::FETCH_NUM) ?: [];
            $hdr = explode(', ', $cols);
            csvOut($hdr, $rows, 'search_logs.csv');
        } catch (Throwable $e) {
            setFlash('error', 'search_logs table not found.');
            header('Location: exports.php');
            exit;
        }
    }

    setFlash('error', 'Unknown export type.');
    header('Location: exports.php');
    exit;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="dash-shell">
  <?php adminSidebar('exports'); ?>
  <div class="dash-main">
    <?php adminTopbar(); ?>
    <div class="dash-content">
      <div class="dash-heading">
        <div>
          <h1 class="dash-title">Exports</h1>
          <div class="dash-breadcrumb">
            <a href="dashboard.php">Admin</a>
            <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
            <span>Exports</span>
          </div>
        </div>
      </div>

      <main>
      <div class="card">
        <div class="card-header">
          <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">Data Backup (CSV)</h2>
        </div>
        <div class="card-body">
          <p class="text-muted" style="margin-bottom:14px">Download CSV exports as a simple backup or for analytics.</p>
          <div class="flex flex-wrap gap-2">
            <a class="btn btn-primary btn-sm" href="exports.php?download=users"><i class="fas fa-download"></i> Users</a>
            <a class="btn btn-primary btn-sm" href="exports.php?download=listings"><i class="fas fa-download"></i> Property Listings</a>
            <a class="btn btn-primary btn-sm" href="exports.php?download=reports"><i class="fas fa-download"></i> Reports</a>
            <a class="btn btn-primary btn-sm" href="exports.php?download=search_logs"><i class="fas fa-download"></i> Search Logs</a>
          </div>
          <div class="text-muted text-xs" style="margin-top:12px">
            For a full DB backup, use MySQL tools (e.g. <code>mysqldump</code>) on the server.
          </div>
        </div>
      </div>
    </main>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>




