<?php
require_once __DIR__ . '/_common.php';

$pageTitle = 'Listing Management';

$hasApproval = adminTableHasColumn($db, 'boarding_houses', 'approval_status');
$hasApprovedBy = adminTableHasColumn($db, 'boarding_houses', 'approved_by');
$hasApprovedAt = adminTableHasColumn($db, 'boarding_houses', 'approved_at');
$hasRejectedReason = adminTableHasColumn($db, 'boarding_houses', 'rejected_reason');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        if ($action === 'approve' && $hasApproval) {
            $stmt = $db->prepare("UPDATE boarding_houses SET approval_status='approved', rejected_reason=NULL" . ($hasApprovedBy ? ", approved_by=?" : "") . ($hasApprovedAt ? ", approved_at=NOW()" : "") . " WHERE id=?");
            $params = [];
            if ($hasApprovedBy) $params[] = intval($_SESSION['user_id']);
            $params[] = $id;
            $stmt->execute($params);
            adminLog($db, 'listing_approved', 'boarding_houses', $id);
            setFlash('success', 'Listing approved.');
        } elseif ($action === 'reject' && $hasApproval) {
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') $reason = 'Rejected by admin.';
            $stmt = $db->prepare("UPDATE boarding_houses SET approval_status='rejected'" . ($hasRejectedReason ? ", rejected_reason=?" : "") . ($hasApprovedBy ? ", approved_by=?" : "") . ($hasApprovedAt ? ", approved_at=NOW()" : "") . " WHERE id=?");
            $params = [];
            if ($hasRejectedReason) $params[] = $reason;
            if ($hasApprovedBy) $params[] = intval($_SESSION['user_id']);
            $params[] = $id;
            $stmt->execute($params);
            adminLog($db, 'listing_rejected', 'boarding_houses', $id, ['reason' => $reason]);
            setFlash('success', 'Listing rejected.');
        } elseif ($action === 'toggle_status') {
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
$approvalFilter = trim($_GET['approval'] ?? '');
$search = trim($_GET['q'] ?? '');

$where = ['1=1'];
$params = [];
if ($statusFilter !== '') {
    $where[] = 'bh.status = ?';
    $params[] = $statusFilter;
}
if ($hasApproval && $approvalFilter !== '') {
    $where[] = 'bh.approval_status = ?';
    $params[] = $approvalFilter;
}
if ($search !== '') {
    $where[] = '(bh.name LIKE ? OR bh.city LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$cols = "bh.id, bh.name, bh.city, bh.status, bh.created_at, u.full_name AS owner_name, u.email AS owner_email";
if ($hasApproval) $cols .= ", bh.approval_status";
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

<div class="page-header">
  <div class="container">
    <h1 class="page-title">Listings</h1>
    <nav class="page-breadcrumb">
      <a href="dashboard.php">Admin</a>
      <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
      <span>Listings</span>
    </nav>
  </div>
</div>

<div class="container" style="padding-bottom:60px">
  <div class="dashboard-layout">
    <?php adminSidebar('listings'); ?>

    <main>
      <div class="card">
        <div class="card-header" style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">
          <div>
            <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">Moderate Listings</h2>
            <div class="text-muted text-sm" style="margin-top:4px"><?= number_format(count($rows)) ?> result(s).</div>
          </div>

          <form method="GET" action="" class="flex items-center gap-2" style="flex-wrap:wrap">
            <input class="form-control" type="text" name="q" placeholder="Search listings/owner" value="<?= sanitize($search) ?>" style="min-width:220px">
            <select name="status" class="form-control" style="min-width:160px">
              <option value="">All status</option>
              <option value="active" <?= $statusFilter==='active'?'selected':'' ?>>Active</option>
              <option value="full" <?= $statusFilter==='full'?'selected':'' ?>>Full</option>
              <option value="inactive" <?= $statusFilter==='inactive'?'selected':'' ?>>Inactive</option>
            </select>
            <?php if ($hasApproval): ?>
              <select name="approval" class="form-control" style="min-width:180px">
                <option value="">All approvals</option>
                <option value="pending" <?= $approvalFilter==='pending'?'selected':'' ?>>Pending</option>
                <option value="approved" <?= $approvalFilter==='approved'?'selected':'' ?>>Approved</option>
                <option value="rejected" <?= $approvalFilter==='rejected'?'selected':'' ?>>Rejected</option>
              </select>
            <?php endif; ?>
            <button class="btn btn-ghost btn-sm" type="submit"><i class="fas fa-filter"></i> Filter</button>
            <a class="btn btn-ghost btn-sm" href="listings.php"><i class="fas fa-rotate-left"></i> Reset</a>
          </form>
        </div>

        <div class="card-body">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Listing</th>
                  <th>Owner</th>
                  <th>Status</th>
                  <?php if ($hasApproval): ?><th>Approval</th><?php endif; ?>
                  <th>Created</th>
                  <th style="width:360px">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($rows as $r):
                $id = intval($r['id'] ?? 0);
                $status = (string)($r['status'] ?? 'active');
                $approval = $hasApproval ? (string)($r['approval_status'] ?? 'approved') : 'approved';
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
                  </td>
                  <?php if ($hasApproval): ?>
                    <td>
                      <?php
                        $aStyle = $approval === 'approved' ? 'background:rgba(27,122,74,0.12);color:var(--success)' : ($approval === 'rejected' ? 'background:var(--error-bg);color:var(--error)' : 'background:var(--warning-bg);color:var(--warning)');
                      ?>
                      <span class="badge" style="<?= $aStyle ?>"><?= sanitize($approval) ?></span>
                      <?php if ($approval === 'rejected' && $hasRejectedReason && !empty($r['rejected_reason'])): ?>
                        <div class="text-muted text-xs" style="margin-top:6px">Reason: <?= sanitize($r['rejected_reason']) ?></div>
                      <?php endif; ?>
                    </td>
                  <?php endif; ?>
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

                      <?php if ($hasApproval && $approval !== 'approved'): ?>
                        <form method="POST" action="" style="display:inline">
                          <input type="hidden" name="action" value="approve">
                          <input type="hidden" name="id" value="<?= $id ?>">
                          <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-check"></i> Approve</button>
                        </form>
                      <?php endif; ?>

                      <?php if ($hasApproval && $approval !== 'rejected'): ?>
                        <form method="POST" action="" style="display:inline">
                          <input type="hidden" name="action" value="reject">
                          <input type="hidden" name="id" value="<?= $id ?>">
                          <input type="hidden" name="reason" value="Rejected by admin.">
                          <button class="btn btn-ghost btn-sm" type="submit" data-confirm="Reject this listing?">
                            <i class="fas fa-ban"></i> Reject
                          </button>
                        </form>
                      <?php endif; ?>

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

          <?php if ($hasApproval): ?>
          <div class="text-muted text-xs mt-3">
            Tip: new owner listings can be created as <strong>pending</strong> and must be approved to show publicly.
          </div>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
