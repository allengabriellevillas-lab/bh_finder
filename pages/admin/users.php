<?php
require_once __DIR__ . '/_common.php';

$pageTitle = 'Manage Users';

$hasUserActive = adminTableHasColumn($db, 'users', 'is_active');
$hasOwnerVerified = adminTableHasColumn($db, 'users', 'owner_verified');
$meId = intval($_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = intval($_POST['id'] ?? 0);

    if ($id > 0) {
        if ($action === 'toggle_active' && $hasUserActive) {
            if ($id === $meId) {
                setFlash('error', 'You cannot deactivate your own admin account.');
            } else {
                $to = intval($_POST['to'] ?? 1) ? 1 : 0;
                $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                $stmt->execute([$to, $id]);
                adminLog($db, $to ? 'user_activated' : 'user_deactivated', 'users', $id);
                setFlash('success', $to ? 'User activated.' : 'User deactivated.');
            }
        } elseif ($action === 'delete_user') {
            // Safety: never delete yourself.
            if ($id === $meId) {
                setFlash('error', 'You cannot delete your own admin account.');
            } else {
                // Safety: don\'t delete other admins.
                $role = $db->prepare("SELECT role FROM users WHERE id = ?");
                $role->execute([$id]);
                $r = (string)($role->fetchColumn() ?: '');
                if ($r === 'admin') {
                    setFlash('error', 'Cannot delete an admin account.');
                } else {
                    $del = $db->prepare("DELETE FROM users WHERE id = ?");
                    $del->execute([$id]);
                    adminLog($db, 'user_deleted', 'users', $id);
                    setFlash('success', 'User deleted.');
                }
            }
        } elseif ($action === 'verify_owner' && $hasOwnerVerified) {
            $stmt = $db->prepare("UPDATE users SET owner_verified = 1, owner_verified_at = NOW() WHERE id = ? AND role = 'owner'");
            $stmt->execute([$id]);
            adminLog($db, 'owner_verified', 'users', $id);
            setFlash('success', 'Owner verified.');
        }
    }

    header('Location: users.php');
    exit;
}

$roleFilter = trim($_GET['role'] ?? '');
$search = trim($_GET['q'] ?? '');
$onlyInactive = isset($_GET['inactive']) && $_GET['inactive'] === '1' && $hasUserActive;

$where = ['1=1'];
$params = [];
if ($roleFilter !== '') {
    $where[] = 'role = ?';
    $params[] = $roleFilter;
}
if ($search !== '') {
    $where[] = '(full_name LIKE ? OR email LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($onlyInactive) {
    $where[] = 'is_active = 0';
}

$cols = 'id, full_name, email, role, phone, created_at';
if ($hasUserActive) $cols .= ', is_active';
if ($hasOwnerVerified) $cols .= ', owner_verified, owner_verified_at';

$sql = "SELECT $cols FROM users WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll() ?: [];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <h1 class="page-title">Users</h1>
    <nav class="page-breadcrumb">
      <a href="dashboard.php">Admin</a>
      <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
      <span>Users</span>
    </nav>
  </div>
</div>

<div class="container" style="padding-bottom:60px">
  <div class="dashboard-layout">
    <?php adminSidebar('users'); ?>

    <main>
      <div class="card">
        <div class="card-header" style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">
          <div>
            <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">All Users</h2>
            <div class="text-muted text-sm" style="margin-top:4px"><?= number_format(count($users)) ?> result(s).</div>
          </div>

          <form method="GET" action="" class="flex items-center gap-2" style="flex-wrap:wrap">
            <input class="form-control" type="text" name="q" placeholder="Search name/email" value="<?= sanitize($search) ?>" style="min-width:220px">
            <select name="role" class="form-control" style="min-width:180px">
              <option value="">All roles</option>
              <option value="tenant" <?= $roleFilter==='tenant'?'selected':'' ?>>Tenant</option>
              <option value="owner" <?= $roleFilter==='owner'?'selected':'' ?>>Owner</option>
              <option value="admin" <?= $roleFilter==='admin'?'selected':'' ?>>Admin</option>
            </select>
            <?php if ($hasUserActive): ?>
              <label class="flex items-center gap-2 text-sm" style="user-select:none">
                <input type="checkbox" name="inactive" value="1" <?= $onlyInactive ? 'checked' : '' ?>>
                Inactive only
              </label>
            <?php endif; ?>
            <button class="btn btn-ghost btn-sm" type="submit"><i class="fas fa-filter"></i> Filter</button>
            <a class="btn btn-ghost btn-sm" href="users.php"><i class="fas fa-rotate-left"></i> Reset</a>
          </form>
        </div>

        <div class="card-body">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>User</th>
                  <th>Role</th>
                  <?php if ($hasUserActive): ?><th>Status</th><?php endif; ?>
                  <?php if ($hasOwnerVerified): ?><th>Owner Verification</th><?php endif; ?>
                  <th>Joined</th>
                  <th style="width:320px">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($users as $u):
                $id = intval($u['id'] ?? 0);
                $role = (string)($u['role'] ?? '');
                $isActive = $hasUserActive ? intval($u['is_active'] ?? 1) : 1;
                $isVerifiedOwner = $hasOwnerVerified ? intval($u['owner_verified'] ?? 0) : 0;
              ?>
                <tr>
                  <td>
                    <div class="font-bold"><?= sanitize($u['full_name'] ?? '') ?></div>
                    <div class="text-muted text-xs"><?= sanitize($u['email'] ?? '') ?></div>
                  </td>
                  <td><span class="badge" style="background:var(--bg);border:1px solid var(--border)"><?= sanitize($role) ?></span></td>
                  <?php if ($hasUserActive): ?>
                    <td>
                      <span class="badge" style="<?= $isActive ? 'background:rgba(27,122,74,0.12);color:var(--success)' : 'background:var(--error-bg);color:var(--error)' ?>">
                        <?= $isActive ? 'Active' : 'Inactive' ?>
                      </span>
                    </td>
                  <?php endif; ?>
                  <?php if ($hasOwnerVerified): ?>
                    <td>
                      <?php if ($role !== 'owner'): ?>
                        <span class="text-muted">—</span>
                      <?php else: ?>
                        <span class="badge" style="<?= $isVerifiedOwner ? 'background:rgba(27,122,74,0.12);color:var(--success)' : 'background:var(--warning-bg);color:var(--warning)' ?>">
                          <?= $isVerifiedOwner ? 'Verified' : 'Pending' ?>
                        </span>
                      <?php endif; ?>
                    </td>
                  <?php endif; ?>
                  <td class="text-muted text-sm"><?= sanitize(date('M d, Y', strtotime((string)($u['created_at'] ?? '')))) ?></td>
                  <td>
                    <div class="flex flex-wrap gap-2">
                      <?php if ($hasUserActive && $id !== $meId): ?>
                        <form method="POST" action="" style="display:inline">
                          <input type="hidden" name="action" value="toggle_active">
                          <input type="hidden" name="id" value="<?= $id ?>">
                          <input type="hidden" name="to" value="<?= $isActive ? 0 : 1 ?>">
                          <button class="btn btn-ghost btn-sm" type="submit" data-confirm="<?= $isActive ? 'Deactivate this account?' : 'Activate this account?' ?>">
                            <i class="fas <?= $isActive ? 'fa-user-slash' : 'fa-user-check' ?>"></i>
                            <?= $isActive ? 'Deactivate' : 'Activate' ?>
                          </button>
                        </form>
                      <?php endif; ?>

                      <?php if ($hasOwnerVerified && $role === 'owner' && !$isVerifiedOwner): ?>
                        <form method="POST" action="" style="display:inline">
                          <input type="hidden" name="action" value="verify_owner">
                          <input type="hidden" name="id" value="<?= $id ?>">
                          <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-user-check"></i> Verify Owner</button>
                        </form>
                      <?php endif; ?>

                      <?php if ($id !== $meId && $role !== 'admin'): ?>
                        <form method="POST" action="" style="display:inline">
                          <input type="hidden" name="action" value="delete_user">
                          <input type="hidden" name="id" value="<?= $id ?>">
                          <button class="btn btn-ghost btn-sm" type="submit" data-confirm="Delete this user? This cannot be undone."><i class="fas fa-trash"></i> Delete</button>
                        </form>
                      <?php endif; ?>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
