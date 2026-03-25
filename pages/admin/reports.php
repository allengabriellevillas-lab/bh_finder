<?php
require_once __DIR__ . '/_common.php';

$pageTitle = 'Reports';

// If reports table is missing, show a warning.
$hasReports = true;
try {
    $db->query("SELECT 1 FROM reports LIMIT 1");
} catch (Throwable $e) {
    $hasReports = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasReports) {
    $action = $_POST['action'] ?? '';
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        if ($action === 'resolve') {
            $stmt = $db->prepare("UPDATE reports SET status='resolved', handled_by=?, handled_at=NOW() WHERE id=?");
            $stmt->execute([intval($_SESSION['user_id']), $id]);
            adminLog($db, 'report_resolved', 'reports', $id);
            setFlash('success', 'Report resolved.');
        } elseif ($action === 'dismiss') {
            $stmt = $db->prepare("UPDATE reports SET status='dismissed', handled_by=?, handled_at=NOW() WHERE id=?");
            $stmt->execute([intval($_SESSION['user_id']), $id]);
            adminLog($db, 'report_dismissed', 'reports', $id);
            setFlash('success', 'Report dismissed.');
        }
    }
    header('Location: reports.php');
    exit;
}

$status = trim($_GET['status'] ?? 'open');
$allowed = ['open','resolved','dismissed',''];
if (!in_array($status, $allowed, true)) $status = 'open';

$rows = [];
if ($hasReports) {
    $where = '1=1';
    $params = [];
    if ($status !== '') { $where = 'r.status = ?'; $params[] = $status; }

    $sql = "SELECT r.*, bh.name AS bh_name, bh.city AS bh_city, u.full_name AS reporter_name, u.email AS reporter_email
      FROM reports r
      JOIN boarding_houses bh ON bh.id = r.boarding_house_id
      LEFT JOIN users u ON u.id = r.reporter_id
      WHERE $where
      ORDER BY r.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="dash-shell">
  <?php adminSidebar('reports'); ?>
  <div class="dash-main">
    <?php adminTopbar(); ?>
    <div class="dash-content">
      <div class="dash-heading">
        <div>
          <h1 class="dash-title">Reports</h1>
          <div class="dash-breadcrumb">
            <a href="dashboard.php">Admin</a>
            <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
            <span>Reports</span>
          </div>
        </div>
      </div>

      <main>
      <div class="card">
        <div class="card-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
          <div>
            <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">Reports & Complaints</h2>
            <div class="text-muted text-sm" style="margin-top:4px"><?= $hasReports ? number_format(count($rows)) . ' result(s).' : 'Table missing.' ?></div>
          </div>

          <form method="GET" action="" class="card-filters">
            <select name="status" class="form-control">
              <option value="open" <?= $status==='open'?'selected':'' ?>>Open</option>
              <option value="resolved" <?= $status==='resolved'?'selected':'' ?>>Resolved</option>
              <option value="dismissed" <?= $status==='dismissed'?'selected':'' ?>>Dismissed</option>
              <option value="" <?= $status===''?'selected':'' ?>>All</option>
            </select>
            <div class="filter-row">
              <button class="btn btn-ghost btn-sm" type="submit"><i class="fas fa-filter"></i> Filter</button>
              <a class="btn btn-ghost btn-sm" href="reports.php"><i class="fas fa-rotate-left"></i> Reset</a>
            </div>
          </form>
        </div>

        <div class="card-body">
          <?php if (!$hasReports): ?>
            <div class="flash flash-error"><i class="fas fa-exclamation-circle"></i> Missing <code>reports</code> table. Run <code>install.php</code> to update schema.</div>
          <?php elseif (empty($rows)): ?>
            <div class="empty-state">
              <i class="fas fa-flag"></i>
              <h3>No reports</h3>
              <p class="text-muted">No reports found for this filter.</p>
            </div>
          <?php else: ?>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Listing</th>
                    <th>Reporter</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th style="width:260px">Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                  $id = intval($r['id'] ?? 0);
                  $st = (string)($r['status'] ?? 'open');
                  $badge = $st === 'resolved' ? 'background:rgba(27,122,74,0.12);color:var(--success)' : ($st === 'dismissed' ? 'background:var(--bg);border:1px solid var(--border);color:var(--text-muted)' : 'background:var(--warning-bg);color:var(--warning)');
                ?>
                  <tr>
                    <td>
                      <div class="font-bold"><?= sanitize($r['bh_name'] ?? '') ?></div>
                      <div class="text-muted text-xs"><i class="fas fa-location-dot" style="margin-right:6px"></i><?= sanitize($r['bh_city'] ?? '') ?></div>
                      <a class="text-sm" href="<?= SITE_URL ?>/pages/detail.php?id=<?= intval($r['boarding_house_id'] ?? 0) ?>">Open listing</a>
                    </td>
                    <td>
                      <div class="font-bold"><?= sanitize($r['reporter_name'] ?? 'Guest') ?></div>
                      <div class="text-muted text-xs"><?= sanitize($r['reporter_email'] ?? '') ?></div>
                    </td>
                    <td>
                      <div class="font-bold"><?= sanitize($r['reason'] ?? '') ?></div>
                      <?php if (!empty($r['details'])): ?>
                        <div class="text-muted text-xs" style="margin-top:6px;max-width:520px;white-space:pre-wrap"><?= sanitize($r['details']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td><span class="badge" style="<?= $badge ?>"><?= sanitize($st) ?></span></td>
                    <td class="text-muted text-sm"><?= sanitize(date('M d, Y H:i', strtotime((string)($r['created_at'] ?? '')))) ?></td>
                    <td>
                      <div class="flex flex-wrap gap-2">
                        <?php if ($st === 'open'): ?>
                          <form method="POST" action="" style="display:inline">
                            <input type="hidden" name="action" value="resolve">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-check"></i> Resolve</button>
                          </form>
                          <form method="POST" action="" style="display:inline">
                            <input type="hidden" name="action" value="dismiss">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button class="btn btn-ghost btn-sm" type="submit" data-confirm="Dismiss this report?"><i class="fas fa-ban"></i> Dismiss</button>
                          </form>
                        <?php else: ?>
                          <span class="text-muted">No actions</span>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </main>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>


