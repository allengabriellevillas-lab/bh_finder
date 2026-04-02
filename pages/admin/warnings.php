<?php
require_once __DIR__ . '/_common.php';

$pageTitle = 'Warnings';

$hasWarnings = true;
try { $db->query("SELECT 1 FROM admin_warnings LIMIT 1"); } catch (Throwable $e) { $hasWarnings = false; }

$owners = [];
try {
    $owners = $db->query("SELECT id, full_name, email FROM users WHERE role='owner' ORDER BY full_name ASC")->fetchAll() ?: [];
} catch (Throwable $e) {
    $owners = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasWarnings) {
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'create') {
            $ownerId = intval($_POST['owner_id'] ?? 0);
            $msg = trim((string)($_POST['message'] ?? ''));
            if ($ownerId <= 0 || $msg === '') throw new RuntimeException('Owner and message are required.');

            $stmt = $db->prepare("INSERT INTO admin_warnings (owner_id, message, is_active, created_by) VALUES (?,?,1,?)");
            $stmt->execute([$ownerId, $msg, intval($_SESSION['user_id'])]);
            adminLog($db, 'warning_created', 'admin_warnings', intval($db->lastInsertId() ?: 0), ['owner_id' => $ownerId]);
            setFlash('success', 'Warning created.');
        }

        if ($action === 'deactivate') {
            $id = intval($_POST['id'] ?? 0);
            if ($id > 0) {
                $db->prepare("UPDATE admin_warnings SET is_active = 0 WHERE id = ?")
                  ->execute([$id]);
                adminLog($db, 'warning_deactivated', 'admin_warnings', $id);
                setFlash('success', 'Warning deactivated.');
            }
        }
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
    }

    header('Location: warnings.php');
    exit;
}

$rows = [];
if ($hasWarnings) {
    try {
        $rows = $db->query("SELECT w.*, u.full_name, u.email
          FROM admin_warnings w
          JOIN users u ON u.id = w.owner_id
          ORDER BY w.is_active DESC, w.created_at DESC")->fetchAll() ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="dash-shell">
  <?php adminSidebar('warnings'); ?>
  <div class="dash-main">
    <?php adminTopbar(); ?>
    <div class="dash-content">
      <div class="dash-heading">
        <div>
          <h1 class="dash-title">Warnings</h1>
          <div class="dash-breadcrumb">
            <a href="dashboard.php">Admin</a>
            <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
            <span>Warnings</span>
          </div>
        </div>
      </div>

      <main>
        <div class="card">
          <div class="card-header">
            <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">Anti-Scam: Admin Warnings</h2>
          </div>
          <div class="card-body">
            <?php if (!$hasWarnings): ?>
              <div class="flash flash-error"><i class="fas fa-exclamation-circle"></i> Missing <code>admin_warnings</code> table. Run <code>install.php</code>.</div>
            <?php else: ?>
              <form method="POST" action="" class="card" style="box-shadow:none;border:1px solid var(--border)">
                <div class="card-header"><h3 style="margin:0;font-family:var(--font-display);font-size:1.05rem;font-weight:800">Create Warning</h3></div>
                <div class="card-body">
                  <input type="hidden" name="action" value="create">
                  <div class="form-row">
                    <div class="form-group" style="min-width:260px;flex:1">
                      <label class="form-label">Owner</label>
                      <select class="form-control" name="owner_id" required>
                        <option value="">Select owner</option>
                        <?php foreach ($owners as $o): ?>
                          <option value="<?= intval($o['id'] ?? 0) ?>"><?= sanitize($o['full_name'] ?? '') ?> (<?= sanitize($o['email'] ?? '') ?>)</option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="form-group" style="flex:2">
                      <label class="form-label">Message</label>
                      <input class="form-control" name="message" placeholder="e.g. Multiple reports of misleading info" required>
                    </div>
                  </div>
                  <button class="btn btn-primary" type="submit"><i class="fas fa-triangle-exclamation"></i> Create Warning</button>
                </div>
              </form>

              <div class="table-wrap mt-4">
                <table>
                  <thead>
                    <tr>
                      <th>Owner</th>
                      <th>Message</th>
                      <th>Status</th>
                      <th>Created</th>
                      <th style="width:140px">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rows as $r):
                      $id = intval($r['id'] ?? 0);
                      $active = intval($r['is_active'] ?? 1) === 1;
                    ?>
                      <tr>
                        <td>
                          <div class="font-bold"><?= sanitize($r['full_name'] ?? '') ?></div>
                          <div class="text-muted text-xs"><?= sanitize($r['email'] ?? '') ?></div>
                        </td>
                        <td style="white-space:pre-wrap"><?= sanitize($r['message'] ?? '') ?></td>
                        <td>
                          <span class="badge" style="background:var(--bg);border:1px solid var(--border)"><?= $active ? 'Active' : 'Inactive' ?></span>
                        </td>
                        <td class="text-muted text-sm"><?= !empty($r['created_at']) ? sanitize(date('M d, Y H:i', strtotime((string)$r['created_at']))) : '' ?></td>
                        <td>
                          <?php if ($active): ?>
                            <form method="POST" action="" style="display:inline">
                              <input type="hidden" name="action" value="deactivate">
                              <input type="hidden" name="id" value="<?= $id ?>">
                              <button class="btn btn-ghost btn-sm" type="submit" data-confirm="Deactivate this warning?"><i class="fas fa-check"></i> Resolve</button>
                            </form>
                          <?php else: ?>
                            <span class="text-muted text-sm">—</span>
                          <?php endif; ?>
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
