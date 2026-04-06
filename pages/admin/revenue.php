<?php
require_once __DIR__ . '/_common.php';

$pageTitle = 'Revenue';

$hasPayments = true;
try { $db->query("SELECT 1 FROM payments LIMIT 1"); } catch (Throwable $e) { $hasPayments = false; }

function adminMonthKeys(int $monthsBack): array {
    $monthsBack = max(2, min(24, $monthsBack));
    $keys = [];
    $labels = [];

    $dt = new DateTime('first day of this month');
    $dt->modify('-' . ($monthsBack - 1) . ' months');

    for ($i = 0; $i < $monthsBack; $i++) {
        $keys[] = $dt->format('Y-m');
        $labels[] = $dt->format('M');
        $dt->modify('+1 month');
    }

    return [$keys, $labels];
}

function adminFillMonthlySums(array $monthKeys, array $rows): array {
    $map = array_fill_keys($monthKeys, 0.0);
    foreach ($rows as $r) {
        $k = (string)($r['ym'] ?? '');
        if ($k !== '' && array_key_exists($k, $map)) {
            $map[$k] = (float)($r['s'] ?? 0);
        }
    }
    return array_values($map);
}

$totalRevenue = 0.0;
$revenueMonth = 0.0;
$revenue30d = 0.0;
$counts = ['approved' => 0, 'pending' => 0, 'rejected' => 0];
$byKind = [];
$byMethod = [];
$byPlan = [];
$monthly = ['labels' => [], 'values' => []];

if ($hasPayments) {
    try {
        $totalRevenue = (float)($db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status='approved'")->fetchColumn() ?: 0);
        $revenue30d = (float)($db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status='approved' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn() ?: 0);
        $revenueMonth = (float)($db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status='approved' AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")->fetchColumn() ?: 0);

        $counts['approved'] = intval($db->query("SELECT COUNT(*) FROM payments WHERE status='approved'")->fetchColumn() ?: 0);
        $counts['pending'] = intval($db->query("SELECT COUNT(*) FROM payments WHERE status='pending'")->fetchColumn() ?: 0);
        $counts['rejected'] = intval($db->query("SELECT COUNT(*) FROM payments WHERE status='rejected'")->fetchColumn() ?: 0);

        $byKind = $db->query("SELECT kind, COUNT(*) AS c, COALESCE(SUM(amount),0) AS s FROM payments WHERE status='approved' GROUP BY kind ORDER BY s DESC")->fetchAll() ?: [];
        $byMethod = $db->query("SELECT method, COUNT(*) AS c, COALESCE(SUM(amount),0) AS s FROM payments WHERE status='approved' GROUP BY method ORDER BY s DESC")->fetchAll() ?: [];
        $byPlan = $db->query("SELECT COALESCE(plan_type, plan, 'unknown') AS plan_key, COUNT(*) AS c, COALESCE(SUM(amount),0) AS s FROM payments WHERE status='approved' GROUP BY plan_key ORDER BY s DESC")->fetchAll() ?: [];

        [$monthKeys, $labels] = adminMonthKeys(12);
        $rows = $db->query("SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COALESCE(SUM(amount),0) AS s FROM payments WHERE status='approved' GROUP BY ym ORDER BY ym")->fetchAll() ?: [];
        $monthly = ['labels' => $labels, 'values' => adminFillMonthlySums($monthKeys, $rows)];
    } catch (Throwable $e) {
        // Keep defaults; page will show partials.
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="dash-shell">
  <?php adminSidebar('revenue'); ?>

  <div class="dash-main">
    <?php adminTopbar(); ?>

    <div class="dash-content">
      <div class="dash-heading">
        <div>
          <h1 class="dash-title">Revenue</h1>
          <div class="dash-breadcrumb">
            <a href="dashboard.php">Admin</a>
            <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
            <span>Revenue</span>
          </div>
        </div>
      </div>

      <main>
        <?php if (!$hasPayments): ?>
          <div class="flash flash-error"><i class="fas fa-exclamation-circle"></i> Missing <code>payments</code> table. Run <code>install.php</code>.</div>
        <?php else: ?>
          <div class="dash-cards" style="margin-bottom:18px">
            <div class="dash-card tone-success">
              <div class="dash-card-icon"><i class="fas fa-coins"></i></div>
              <div class="dash-card-meta">
                <div class="dash-card-label">Total Revenue</div>
                <div class="dash-card-count"><?= formatPrice($totalRevenue) ?></div>
                <div class="dash-card-note">Approved payments</div>
              </div>
            </div>

            <div class="dash-card tone-primary">
              <div class="dash-card-icon"><i class="fas fa-calendar"></i></div>
              <div class="dash-card-meta">
                <div class="dash-card-label">This Month</div>
                <div class="dash-card-count"><?= formatPrice($revenueMonth) ?></div>
                <div class="dash-card-note">Approved, current month</div>
              </div>
            </div>

            <div class="dash-card tone-secondary">
              <div class="dash-card-icon"><i class="fas fa-clock"></i></div>
              <div class="dash-card-meta">
                <div class="dash-card-label">Last 30 Days</div>
                <div class="dash-card-count"><?= formatPrice($revenue30d) ?></div>
                <div class="dash-card-note">Approved, rolling 30 days</div>
              </div>
            </div>

            <div class="dash-card tone-warning">
              <div class="dash-card-icon"><i class="fas fa-receipt"></i></div>
              <div class="dash-card-meta">
                <div class="dash-card-label">Transactions</div>
                <div class="dash-card-count"><?= number_format(intval($counts['approved'] ?? 0)) ?></div>
                <div class="dash-card-note"><?= number_format(intval($counts['pending'] ?? 0)) ?> pending · <?= number_format(intval($counts['rejected'] ?? 0)) ?> rejected</div>
              </div>
            </div>
          </div>

          <div class="card" style="margin-bottom:18px">
            <div class="card-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
              <div>
                <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">Revenue Over Time</h2>
                <div class="text-muted text-sm" style="margin-top:4px">Approved payments, last 12 months.</div>
              </div>
            </div>
            <div class="card-body">
              <div class="dash-chart" style="height:260px">
                <canvas id="chartRevenue"></canvas>
              </div>
            </div>
          </div>

          <div class="grid-2" style="gap:18px">
            <div class="card">
              <div class="card-header">
                <h2 style="margin:0;font-family:var(--font-display);font-size:1.1rem;font-weight:800">Revenue Streams</h2>
                <div class="text-muted text-sm" style="margin-top:4px">Breakdown by payment kind.</div>
              </div>
              <div class="card-body">
                <?php if (empty($byKind)): ?>
                  <div class="empty-state compact">
                    <i class="fas fa-coins"></i>
                    <h3>No revenue yet</h3>
                    <p class="text-muted">No approved payments found.</p>
                  </div>
                <?php else: ?>
                  <div class="table-wrap">
                    <table>
                      <thead>
                        <tr>
                          <th>Stream</th>
                          <th>Transactions</th>
                          <th>Amount</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($byKind as $r): ?>
                          <tr>
                            <td class="font-bold"><?= sanitize($r['kind'] ?? '') ?></td>
                            <td class="text-muted"><?= number_format(intval($r['c'] ?? 0)) ?></td>
                            <td class="font-bold"><?= formatPrice((float)($r['s'] ?? 0)) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="card">
              <div class="card-header">
                <h2 style="margin:0;font-family:var(--font-display);font-size:1.1rem;font-weight:800">Methods & Plans</h2>
                <div class="text-muted text-sm" style="margin-top:4px">Breakdown by payment method and plan.</div>
              </div>
              <div class="card-body">
                <div class="text-muted text-sm" style="margin-bottom:10px"><strong>Methods</strong></div>
                <?php if (empty($byMethod)): ?>
                  <div class="text-muted">No data</div>
                <?php else: ?>
                  <div class="table-wrap" style="margin-bottom:14px">
                    <table>
                      <thead>
                        <tr>
                          <th>Method</th>
                          <th>Transactions</th>
                          <th>Amount</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($byMethod as $r): ?>
                          <tr>
                            <td class="font-bold"><?= sanitize($r['method'] ?? '') ?></td>
                            <td class="text-muted"><?= number_format(intval($r['c'] ?? 0)) ?></td>
                            <td class="font-bold"><?= formatPrice((float)($r['s'] ?? 0)) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>

                <div class="text-muted text-sm" style="margin:6px 0 10px"><strong>Plans</strong></div>
                <?php if (empty($byPlan)): ?>
                  <div class="text-muted">No data</div>
                <?php else: ?>
                  <div class="table-wrap">
                    <table>
                      <thead>
                        <tr>
                          <th>Plan</th>
                          <th>Transactions</th>
                          <th>Amount</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($byPlan as $r): ?>
                          <tr>
                            <td class="font-bold"><?= sanitize(strtoupper((string)($r['plan_key'] ?? ''))) ?></td>
                            <td class="text-muted"><?= number_format(intval($r['c'] ?? 0)) ?></td>
                            <td class="font-bold"><?= formatPrice((float)($r['s'] ?? 0)) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </main>
    </div>
  </div>
</div>

<?php if ($hasPayments): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  var data = <?= json_encode($monthly, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  function withAlpha(color, a) {
    var m = String(color || '').match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/i);
    if (!m) return color;
    return 'rgba(' + m[1] + ',' + m[2] + ',' + m[3] + ',' + a + ')';
  }

  document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('chartRevenue');
    if (!el || !window.Chart) return;

    var styles = getComputedStyle(document.body);
    var primary = styles.getPropertyValue('--primary') || 'rgb(59,130,246)';
    var gridColor = withAlpha(styles.getPropertyValue('--border') || 'rgb(226,232,240)', 0.7);
    var textMuted = styles.getPropertyValue('--text-muted') || '#64748b';

    Chart.defaults.color = textMuted;
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;

    new Chart(el, {
      type: 'line',
      data: {
        labels: data.labels || [],
        datasets: [{
          label: 'Revenue',
          data: (data.values || []).map(function (n) { return Number(n) || 0; }),
          fill: true,
          tension: 0.35,
          borderColor: withAlpha(primary, 0.9),
          backgroundColor: withAlpha(primary, 0.12),
          pointRadius: 3,
          pointHoverRadius: 4,
          pointBackgroundColor: withAlpha(primary, 0.95),
          borderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { mode: 'index', intersect: false }
        },
        scales: {
          x: { grid: { display: false } },
          y: { beginAtZero: true, grid: { color: gridColor } }
        }
      }
    });
  });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
