<?php
require_once __DIR__ . '/_common.php';

$pageTitle = 'Listing Management';

$hasApproval = false; // Listing approval is disabled; admins verify owner accounts instead.
$hasApprovedBy = adminTableHasColumn($db, 'boarding_houses', 'approved_by');
$hasApprovedAt = adminTableHasColumn($db, 'boarding_houses', 'approved_at');
$hasRejectedReason = adminTableHasColumn($db, 'boarding_houses', 'rejected_reason');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        if ($action === 'toggle_status') {
            $to = ($_POST['to'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            $stmt = $db->prepare("UPDATE boarding_houses SET status=? WHERE id=?");
            $stmt->execute([$to, $id]);
            adminLog($db, $to === 'inactive' ? 'listing_deactivated' : 'listing_activated', 'boarding_houses', $id);
            setFlash('success', 'Listing status updated.');
        } elseif ($action === 'delete') {
            $stmt = $db->prepare("DELETE FROM boarding_houses WHERE id=?");
            $stmt->execute([$id]);
            adminLog($db, 'listing_deleted', 'boarding_houses', $id);
            setFlash('success', 'Listing deleted.');
        }
    }
    header('Location: listings.php');
    exit;
}

$statusFilter = trim($_GET['status'] ?? '');
$search = trim($_GET['q'] ?? '');

$where = ['1=1'];
$params = [];
if ($statusFilter !== '') {
    $where[] = 'bh.status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(bh.name LIKE ? OR bh.city LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$cols = "bh.id, bh.name, bh.city, bh.status, bh.created_at, u.full_name AS owner_name, u.email AS owner_email, (SELECT COUNT(*) FROM reports rp WHERE rp.boarding_house_id = bh.id) AS report_count";
if ($hasRejectedReason) $cols .= ", bh.rejected_reason";

$sql = "SELECT $cols
  FROM boarding_houses bh
  JOIN users u ON u.id = bh.owner_id
  WHERE " . implode(' AND ', $where) . "
  ORDER BY bh.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll() ?: [];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="dash-shell">
  <?php adminSidebar('listings'); ?>
  <div class="dash-main">
    <?php adminTopbar(); ?>
    <div class="dash-content">
      <div class="dash-heading">
        <div>
          <h1 class="dash-title">Property Listings</h1>
          <div class="dash-breadcrumb">
            <a href="dashboard.php">Admin</a>
            <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
            <span>Property Listings</span>
          </div>
        </div>
      </div>

      <main>
      <div class="card">
        <div class="card-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
          <div>
            <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">Moderate Property Listings</h2>
            <div class="text-muted text-sm" style="margin-top:4px"><?= number_format(count($rows)) ?> result(s).</div>
          </div>

          <form method="GET" action="" class="card-filters">
            <input class="form-control" type="text" name="q" placeholder="Search listings/owner" value="<?= sanitize($search) ?>">
            <select name="status" class="form-control">
              <option value="">All status</option>
              <option value="active" <?= $statusFilter==='active'?'selected':'' ?>>Active</option>
              <option value="full" <?= $statusFilter==='full'?'selected':'' ?>>Full</option>
              <option value="inactive" <?= $statusFilter==='inactive'?'selected':'' ?>>Inactive</option>
            </select>
            <div class="filter-row">
              <button class="btn btn-ghost btn-sm" type="submit"><i class="fas fa-filter"></i> Filter</button>
              <a class="btn btn-ghost btn-sm" href="listings.php"><i class="fas fa-rotate-left"></i> Reset</a>
            </div>
          </form>
        </div>

        <div class="card-body">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Listing</th>
                  <th>Owner</th>
                  <th>Status</th><th>Reports</th>
                  <th>Created</th>
                  <th style="width:360px">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($rows as $r):
                $id = intval($r['id'] ?? 0);
                $status = (string)($r['status'] ?? 'active');
              ?>
                <tr>
                  <td>
                    <div class="font-bold"><?= sanitize($r['name'] ?? '') ?></div>
                    <div class="text-muted text-xs"><i class="fas fa-location-dot" style="margin-right:6px"></i><?= sanitize($r['city'] ?? '') ?></div>
                  </td>
                  <td>
                    <div class="font-bold"><?= sanitize($r['owner_name'] ?? '') ?></div>
                    <div class="text-muted text-xs"><?= sanitize($r['owner_email'] ?? '') ?></div>
                  </td>
                  <td>
                    <span class="badge" style="background:var(--bg);border:1px solid var(--border)"><?= sanitize($status) ?></span>
                  </td>                   <td>
                     <span class="badge" style="background:var(--bg);border:1px solid var(--border)"><?= intval($r['report_count'] ?? 0) ?></span>
                   </td>
                  <td class="text-muted text-sm"><?= sanitize(date('M d, Y', strtotime((string)($r['created_at'] ?? '')))) ?></td>
                  <td>
                    <div class="flex flex-wrap gap-2">
                      <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/pages/detail.php?id=<?= $id ?>"><i class="fas fa-eye"></i> View</a>

                      <form method="POST" action="" style="display:inline">
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="to" value="<?= $status === 'inactive' ? 'active' : 'inactive' ?>">
                        <button class="btn btn-ghost btn-sm" type="submit" data-confirm="Change listing status?">
                          <i class="fas <?= $status === 'inactive' ? 'fa-circle-check' : 'fa-eye-slash' ?>"></i>
                          <?= $status === 'inactive' ? 'Activate' : 'Deactivate' ?>
                        </button>
                      </form>



                      <form method="POST" action="" style="display:inline">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <button class="btn btn-ghost btn-sm" type="submit" data-confirm="Delete this listing? This cannot be undone."><i class="fas fa-trash"></i> Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </main>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>






