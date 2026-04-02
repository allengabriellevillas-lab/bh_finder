<?php
require_once __DIR__ . '/_common.php';

$pageTitle = 'Search Logs';

$hasSearch = true;
try { $db->query("SELECT 1 FROM search_logs LIMIT 1"); } catch (Throwable $e) { $hasSearch = false; }

$channel = trim($_GET['channel'] ?? '');
if (!in_array($channel, ['web','api',''], true)) $channel = '';

$rows = [];
$topTerms = [];
$topCities = [];
$topTypes = [];

if ($hasSearch) {
    $where = '1=1';
    $params = [];
    if ($channel !== '') { $where = 'channel = ?'; $params[] = $channel; }

    $stmt = $db->prepare("SELECT * FROM search_logs WHERE $where ORDER BY created_at DESC LIMIT 100");
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $w2 = $channel !== '' ? "WHERE channel = " . $db->quote($channel) : '';

    $topTerms = $db->query("SELECT COALESCE(NULLIF(TRIM(search), ''), '[empty]') AS term, COUNT(*) AS c
      FROM search_logs $w2
      GROUP BY term
      ORDER BY c DESC
      LIMIT 10")->fetchAll() ?: [];

    $topCities = $db->query("SELECT COALESCE(NULLIF(TRIM(city), ''), '[empty]') AS city, COUNT(*) AS c
      FROM search_logs $w2
      GROUP BY city
      ORDER BY c DESC
      LIMIT 10")->fetchAll() ?: [];

    $topTypes = $db->query("SELECT COALESCE(NULLIF(TRIM(accommodation_type), ''), '[empty]') AS t, COUNT(*) AS c
      FROM search_logs $w2
      GROUP BY t
      ORDER BY c DESC
      LIMIT 10")->fetchAll() ?: [];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="dash-shell">
  <?php adminSidebar('searches'); ?>
  <div class="dash-main">
    <?php adminTopbar(); ?>
    <div class="dash-content">
      <div class="dash-heading">
        <div>
          <h1 class="dash-title">Search Logs</h1>
          <div class="dash-breadcrumb">
            <a href="dashboard.php">Admin</a>
            <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
            <span>Search Logs</span>
          </div>
        </div>
      </div>

      <main>
      <div class="card">
        <div class="card-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
          <div>
            <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">Monitoring</h2>
            <div class="text-muted text-sm" style="margin-top:4px"><?= $hasSearch ? 'Showing latest 100.' : 'Table missing.' ?></div>
          </div>

          <form method="GET" action="" class="card-filters">
            <select name="channel" class="form-control">
              <option value="" <?= $channel===''?'selected':'' ?>>All channels</option>
              <option value="web" <?= $channel==='web'?'selected':'' ?>>Web</option>
              <option value="api" <?= $channel==='api'?'selected':'' ?>>API</option>
            </select>
            <div class="filter-row">
              <button class="btn btn-ghost btn-sm" type="submit"><i class="fas fa-filter"></i> Filter</button>
              <a class="btn btn-ghost btn-sm" href="searches.php"><i class="fas fa-rotate-left"></i> Reset</a>
            </div>
          </form>
        </div>

        <div class="card-body">
          <?php if (!$hasSearch): ?>
            <div class="flash flash-error"><i class="fas fa-exclamation-circle"></i> Missing <code>search_logs</code> table. Run <code>install.php</code>.</div>
          <?php else: ?>
            <div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(240px,1fr))">
              <div class="stat-card" style="align-items:flex-start">
                <div style="width:100%">
                  <div class="text-muted text-xs">Top search terms</div>
                  <?php foreach ($topTerms as $t): ?>
                    <div class="flex" style="justify-content:space-between;gap:8px;margin-top:6px">
                      <span class="text-sm"><?= sanitize($t['term'] ?? '') ?></span>
                      <span class="text-muted text-sm"><?= intval($t['c'] ?? 0) ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="stat-card" style="align-items:flex-start">
                <div style="width:100%">
                  <div class="text-muted text-xs">Top cities</div>
                  <?php foreach ($topCities as $c): ?>
                    <div class="flex" style="justify-content:space-between;gap:8px;margin-top:6px">
                      <span class="text-sm"><?= sanitize($c['city'] ?? '') ?></span>
                      <span class="text-muted text-sm"><?= intval($c['c'] ?? 0) ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="stat-card" style="align-items:flex-start">
                <div style="width:100%">
                  <div class="text-muted text-xs">Top types</div>
                  <?php foreach ($topTypes as $t): ?>
                    <div class="flex" style="justify-content:space-between;gap:8px;margin-top:6px">
                      <span class="text-sm"><?= sanitize($t['t'] ?? '') ?></span>
                      <span class="text-muted text-sm"><?= intval($t['c'] ?? 0) ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <div class="card mt-4" style="box-shadow:none;border:1px solid var(--border)">
              <div class="card-header">
                <h3 style="margin:0;font-family:var(--font-display);font-size:1.05rem;font-weight:800">Recent Search Activity</h3>
              </div>
              <div class="card-body">
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>When</th><th>Channel</th><th>Search</th><th>City</th><th>Min</th><th>Max</th><th>Type</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                      <tr>
                        <td class="text-muted text-sm"><?= sanitize(date('M d, Y H:i', strtotime((string)($r['created_at'] ?? '')))) ?></td>
                        <td><span class="badge" style="background:var(--bg);border:1px solid var(--border)"><?= sanitize($r['channel'] ?? '') ?></span></td>
                        <td><?= sanitize($r['search'] ?? '') ?></td>
                        <td><?= sanitize($r['city'] ?? '') ?></td>
                        <td><?= sanitize($r['min_price'] ?? '') ?></td>
                        <td><?= sanitize($r['max_price'] ?? '') ?></td>
                        <td><?= sanitize($r['accommodation_type'] ?? '') ?></td>
                        <td class="text-muted text-xs"><?= sanitize($r['ip'] ?? '') ?></td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

          <?php endif; ?>
        </div>
      </div>
    </main>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>


