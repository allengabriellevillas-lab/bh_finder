<?php
require_once __DIR__ . '/_common.php';

$pageTitle = 'System Settings';

$hasSettings = true;
try { $db->query("SELECT 1 FROM settings LIMIT 1"); } catch (Throwable $e) { $hasSettings = false; }

$defaults = [
    'owner_verification_required' => '1',
    'listing_approval_required' => '1',
    'service_fee_percentage' => '5',
    'referral_discount_codes' => '',
    'owner_subscription_days' => '30',
    'owner_subscription_basic_max_properties' => '2',
    'owner_subscription_amount_basic' => '999',
    'owner_subscription_amount_pro' => '1999',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasSettings) {
    $action = $_POST['action'] ?? '';

    if ($action === 'init_defaults') {
        $stmt = $db->prepare("INSERT INTO settings (`key`,`value`,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `value`=`value`");
        foreach ($defaults as $k => $v) {
            $stmt->execute([$k, $v, intval($_SESSION['user_id'])]);
        }
        adminLog($db, 'settings_defaults_initialized', 'settings', null);
        setFlash('success', 'Default settings ensured.');
        header('Location: settings.php');
        exit;
    }

    if ($action === 'save') {
        $key = trim((string)($_POST['key'] ?? ''));
        $value = trim((string)($_POST['value'] ?? ''));
        if ($key === '' || !preg_match('/^[a-z0-9_\-\.]+$/', $key)) {
            setFlash('error', 'Invalid key.');
        } else {
            $stmt = $db->prepare("INSERT INTO settings (`key`,`value`,updated_by) VALUES (?,?,?)
              ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_by=VALUES(updated_by), updated_at=NOW()");
            $stmt->execute([$key, $value, intval($_SESSION['user_id'])]);
            adminLog($db, 'setting_updated', 'settings', null, ['key' => $key]);
            setFlash('success', 'Setting saved.');
        }
        header('Location: settings.php');
        exit;
    }

    if ($action === 'delete') {
        $key = trim((string)($_POST['key'] ?? ''));
        if ($key !== '') {
            $stmt = $db->prepare("DELETE FROM settings WHERE `key`=?");
            $stmt->execute([$key]);
            adminLog($db, 'setting_deleted', 'settings', null, ['key' => $key]);
            setFlash('success', 'Setting deleted.');
        }
        header('Location: settings.php');
        exit;
    }
}

$rows = $hasSettings ? ($db->query("SELECT * FROM settings ORDER BY `key` ASC")->fetchAll() ?: []) : [];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="dash-shell">
  <?php adminSidebar('settings'); ?>
  <div class="dash-main">
    <?php adminTopbar(); ?>
    <div class="dash-content">
      <div class="dash-heading">
        <div>
          <h1 class="dash-title">Settings</h1>
          <div class="dash-breadcrumb">
            <a href="dashboard.php">Admin</a>
            <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
            <span>Settings</span>
          </div>
        </div>
      </div>

      <main>
      <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
          <div>
            <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">System Settings</h2>
            <div class="text-muted text-sm" style="margin-top:4px">Key-value config for admin controls.</div>
          </div>
          <?php if ($hasSettings): ?>
            <form method="POST" action="" style="display:inline">
              <input type="hidden" name="action" value="init_defaults">
              <button class="btn btn-ghost btn-sm" type="submit"><i class="fas fa-wand-magic-sparkles"></i> Ensure Defaults</button>
            </form>
          <?php endif; ?>
        </div>

        <div class="card-body">
          <?php if (!$hasSettings): ?>
            <div class="flash flash-error"><i class="fas fa-exclamation-circle"></i> Missing <code>settings</code> table. Run <code>install.php</code>.</div>
          <?php else: ?>
            <div class="card" style="box-shadow:none;border:1px solid var(--border)">
              <div class="card-header"><h3 style="margin:0;font-family:var(--font-display);font-size:1.05rem;font-weight:800">Add / Update</h3></div>
              <div class="card-body">
                <form method="POST" action="" data-validate>
                  <input type="hidden" name="action" value="save">
                  <div class="form-row">
                    <div class="form-group">
                      <label class="form-label">Key <span class="required">*</span></label>
                      <input class="form-control" name="key" required placeholder="listing_approval_required">
                    </div>
                    <div class="form-group">
                      <label class="form-label">Value</label>
                      <input class="form-control" name="value" placeholder="1">
                    </div>
                  </div>
                  <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save</button>
                </form>
              </div>
            </div>

            <div class="table-wrap mt-4">
              <table>
                <thead><tr><th>Key</th><th>Value</th><th>Updated</th><th style="width:160px">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                  <tr>
                    <td><code><?= sanitize($r['key'] ?? '') ?></code></td>
                    <td style="white-space:pre-wrap"><?= sanitize($r['value'] ?? '') ?></td>
                    <td class="text-muted text-sm"><?= !empty($r['updated_at']) ? sanitize(date('M d, Y H:i', strtotime((string)$r['updated_at']))) : 'â€”' ?></td>
                    <td>
                      <form method="POST" action="" style="display:inline">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="key" value="<?= sanitize($r['key'] ?? '') ?>">
                        <button class="btn btn-ghost btn-sm" type="submit" data-confirm="Delete this setting?"><i class="fas fa-trash"></i> Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="text-muted text-xs mt-3">
              Suggested keys: <code>owner_verification_required</code>, <code>listing_approval_required</code>, <code>service_fee_percentage</code>, <code>owner_subscription_days</code>, <code>owner_subscription_amount_basic</code>, <code>owner_subscription_amount_pro</code>, <code>owner_subscription_basic_max_properties</code>.
            </div>
          <?php endif; ?>
        </div>
      </div>
    </main>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

