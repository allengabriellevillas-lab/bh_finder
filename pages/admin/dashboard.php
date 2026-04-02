<?php
require_once __DIR__ . '/_common.php';

$pageTitle = 'Admin Dashboard';

$hasUserActive = adminTableHasColumn($db, 'users', 'is_active');
$hasOwnerVerified = adminTableHasColumn($db, 'users', 'owner_verified');
$hasApproval = adminTableHasColumn($db, 'boarding_houses', 'approval_status');
$hasViews = adminTableHasColumn($db, 'boarding_houses', 'views');
$hasOwnerVStatus = adminTableHasColumn($db, 'users', 'owner_verification_status');
$hasRoomSub = adminTableHasColumn($db, 'rooms', 'subscription_status');
$hasPayments = true;
try { $db->query("SELECT 1 FROM payments LIMIT 1"); } catch (Throwable $e) { $hasPayments = false; }

$totalUsers = intval($db->query("SELECT COUNT(*) FROM users")->fetchColumn() ?: 0);
$totalListings = intval($db->query("SELECT COUNT(*) FROM boarding_houses")->fetchColumn() ?: 0);
$activeListings = intval($db->query("SELECT COUNT(*) FROM boarding_houses WHERE status != 'inactive'")->fetchColumn() ?: 0);
$inactiveListings = intval($db->query("SELECT COUNT(*) FROM boarding_houses WHERE status = 'inactive'")->fetchColumn() ?: 0);

$totalRevenue = 0.0;
if ($hasPayments) {
    try {
        $totalRevenue = (float)($db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'approved'")->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $totalRevenue = 0.0;
    }
}

$totalRooms = 0;
$activeSubscriptions = 0;
try {
    $totalRooms = intval($db->query("SELECT COUNT(*) FROM rooms")->fetchColumn() ?: 0);
    if ($hasRoomSub) {
        $activeSubscriptions = intval($db->query("SELECT COUNT(*) FROM rooms WHERE subscription_status = 'active' AND (end_date IS NULL OR end_date >= CURDATE())")->fetchColumn() ?: 0);
    }
} catch (Throwable $e) {
    $totalRooms = 0;
    $activeSubscriptions = 0;
}

$verifiedOwnerCount = 0;
try {
    if ($hasOwnerVStatus) {
        $verifiedOwnerCount = intval($db->query("SELECT COUNT(*) FROM users WHERE role='owner' AND owner_verification_status = 'verified'")->fetchColumn() ?: 0);
    } elseif ($hasOwnerVerified) {
        $verifiedOwnerCount = intval($db->query("SELECT COUNT(*) FROM users WHERE role='owner' AND owner_verified = 1")->fetchColumn() ?: 0);
    }
} catch (Throwable $e) {
    $verifiedOwnerCount = 0;
}

$pendingOwners = 0;
if ($hasOwnerVerified) {
    $pendingOwners = intval($db->query("SELECT COUNT(*) FROM users WHERE role='owner' AND owner_verified = 0")->fetchColumn() ?: 0);
}

$pendingListings = 0;
if ($hasApproval) {
    $pendingListings = intval($db->query("SELECT COUNT(*) FROM boarding_houses WHERE approval_status = 'pending'")->fetchColumn() ?: 0);
}

$openReports = 0;
try {
    $openReports = intval($db->query("SELECT COUNT(*) FROM reports WHERE status = 'open'")->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $openReports = 0;
}

$recentUsers = $db->query("SELECT id, full_name, email, role, created_at" . ($hasUserActive ? ", is_active" : "") . " FROM users ORDER BY created_at DESC LIMIT 10")->fetchAll() ?: [];

$topViewed = [];
if ($hasViews) {
    $topViewed = $db->query("SELECT id, name, city, views FROM boarding_houses ORDER BY views DESC, created_at DESC LIMIT 10")->fetchAll() ?: [];
}

$topCards = [];
$topCards[] = ['label' => 'Total Users', 'count' => $totalUsers, 'icon' => 'fa-users', 'tone' => 'primary', 'note' => 'All time'];
$topCards[] = ['label' => 'Total Property Listings', 'count' => $totalListings, 'icon' => 'fa-building', 'tone' => 'secondary', 'note' => ($activeListings . ' active')];
$topCards[] = ['label' => 'Open Reports', 'count' => $openReports, 'icon' => 'fa-flag', 'tone' => 'warning', 'note' => 'Needs review'];
if ($hasPayments) {
    $topCards[] = ['label' => 'Total Revenue', 'value' => number_format($totalRevenue, 2), 'icon' => 'fa-coins', 'tone' => 'success', 'note' => 'Approved payments'];
} else {
    $topCards[] = ['label' => 'Inactive Property Listings', 'count' => $inactiveListings, 'icon' => 'fa-ban', 'tone' => 'error', 'note' => 'Currently inactive'];
}

$overviewItems = [
    ['label' => 'Active Property Listings', 'value' => $activeListings, 'tone' => 'success'],
    ['label' => 'Inactive Property Listings', 'value' => $inactiveListings, 'tone' => 'error'],
];
if ($hasOwnerVerified) $overviewItems[] = ['label' => 'Owners Pending', 'value' => $pendingOwners, 'tone' => 'warning'];
if ($hasApproval) $overviewItems[] = ['label' => 'Property Listings Pending', 'value' => $pendingListings, 'tone' => 'warning'];
if ($hasRoomSub) $overviewItems[] = ['label' => 'Active Subscriptions', 'value' => $activeSubscriptions, 'tone' => 'primary'];
$overviewItems[] = ['label' => 'Verified Owners', 'value' => $verifiedOwnerCount, 'tone' => 'secondary'];
$overviewItems[] = ['label' => 'Total Rooms', 'value' => $totalRooms, 'tone' => 'secondary'];

function adminMonthlyLabels(int $monthsBack): array {
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

function adminFillMonthlySeries(array $monthKeys, array $rows): array {
    $map = array_fill_keys($monthKeys, 0);
    foreach ($rows as $r) {
        $k = (string)($r['ym'] ?? '');
        if ($k !== '' && array_key_exists($k, $map)) {
            $map[$k] = intval($r['c'] ?? 0);
        }
    }
    return array_values($map);
}

$monthsBack = 8;
[$monthKeys, $monthLabels] = adminMonthlyLabels($monthsBack);
$monthIndex = array_flip($monthKeys);

$since = (new DateTime('first day of this month'))
    ->modify('-' . ($monthsBack - 1) . ' months')
    ->format('Y-m-d 00:00:00');

$usersMonthly = array_fill(0, $monthsBack, 0);
try {
    $stmt = $db->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c
      FROM users
      WHERE created_at >= ?
      GROUP BY ym
      ORDER BY ym");
    $stmt->execute([$since]);
    $usersMonthly = adminFillMonthlySeries($monthKeys, $stmt->fetchAll() ?: []);
} catch (Throwable $e) {
    $usersMonthly = array_fill(0, $monthsBack, 0);
}

$listingsStatusCounts = ['active' => 0, 'inactive' => 0, 'full' => 0];
try {
    $rows = $db->query("SELECT status, COUNT(*) AS c FROM boarding_houses GROUP BY status")->fetchAll() ?: [];
    foreach ($rows as $r) {
        $st = (string)($r['status'] ?? '');
        if (array_key_exists($st, $listingsStatusCounts)) {
            $listingsStatusCounts[$st] = intval($r['c'] ?? 0);
        }
    }
} catch (Throwable $e) {
    // keep defaults
}


$listingsMonthly = array_fill(0, $monthsBack, 0);
try {
    $stmt = $db->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c
      FROM boarding_houses
      WHERE created_at >= ?
      GROUP BY ym
      ORDER BY ym");
    $stmt->execute([$since]);
    $listingsMonthly = adminFillMonthlySeries($monthKeys, $stmt->fetchAll() ?: []);
} catch (Throwable $e) {
    $listingsMonthly = array_fill(0, $monthsBack, 0);
}$reportsByStatus = [
    'open' => array_fill(0, $monthsBack, 0),
    'resolved' => array_fill(0, $monthsBack, 0),
    'dismissed' => array_fill(0, $monthsBack, 0),
];
try {
    $stmt = $db->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, status, COUNT(*) AS c
      FROM reports
      WHERE created_at >= ?
      GROUP BY ym, status
      ORDER BY ym");
    $stmt->execute([$since]);
    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as $r) {
        $ym = (string)($r['ym'] ?? '');
        $st = (string)($r['status'] ?? '');
        if ($ym === '' || !array_key_exists($ym, $monthIndex)) continue;
        if (!array_key_exists($st, $reportsByStatus)) continue;
        $reportsByStatus[$st][$monthIndex[$ym]] = intval($r['c'] ?? 0);
    }
} catch (Throwable $e) {
    // keep defaults
}

$chartData = [
    'labels' => $monthLabels,
    'users' => $usersMonthly,
    'listingsMonthly' => $listingsMonthly,
    'listingsStatus' => [
        'labels' => ['Active', 'Inactive', 'Full'],
        'data' => [
            intval($listingsStatusCounts['active'] ?? 0),
            intval($listingsStatusCounts['inactive'] ?? 0),
            intval($listingsStatusCounts['full'] ?? 0),
        ],
    ],
    'reports' => [
        'open' => array_map('intval', $reportsByStatus['open'] ?? []),
        'resolved' => array_map('intval', $reportsByStatus['resolved'] ?? []),
        'dismissed' => array_map('intval', $reportsByStatus['dismissed'] ?? []),
    ],
];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="dash-shell">
  <?php adminSidebar('dashboard'); ?>
  <div class="dash-main">
    <?php adminTopbar(); ?>
    <div class="dash-content">
      <div class="dash-heading">
        <div>
          <div class="dash-pretitle">Pages <span>/</span> Dashboard</div>
          <h1 class="dash-title">Dashboard</h1>
          <div class="dash-subtitle">Overview of users, listings, and reports.</div>
        </div>
      </div>

      <main>
        <div class="dash-metric-grid">
          <?php foreach ($topCards as $c): ?>
            <div class="dash-metric card">
              <div class="dash-metric-meta">
                <div class="dash-metric-label"><?= sanitize($c['label']) ?></div>
                <?php if (array_key_exists('count', $c)): ?>
                  <div class="dash-metric-value" data-count="<?= intval($c['count']) ?>">0</div>
                <?php else: ?>
                  <div class="dash-metric-value"><?= sanitize((string)($c['value'] ?? '')) ?></div>
                <?php endif; ?>
                <div class="dash-metric-note"><?= sanitize($c['note'] ?? '') ?></div>
              </div>
              <div class="dash-metric-icon dash-metric-<?= sanitize($c['tone'] ?? 'primary') ?>">
                <i class="fas <?= sanitize($c['icon'] ?? 'fa-chart-line') ?>"></i>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="dash-3col mt-4">
          <div class="card">
            <div class="card-header dash-card-header">
              <div>
                <h2 class="dash-card-title">User Activity</h2>
                <div class="dash-card-subtitle">Registration trends (last <?= intval($monthsBack) ?> months)</div>
              </div>
            </div>
            <div class="card-body">
              <div class="dash-chart" aria-label="User registrations chart">
                <canvas id="chartUsers"></canvas>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-header dash-card-header">
              <div>
                <h2 class="dash-card-title">Property Listings</h2>
                <div class="dash-card-subtitle">New listings (last <?= intval($monthsBack) ?> months)</div>
              </div>
            </div>
            <div class="card-body">
              <div class="dash-chart" aria-label="Property listings status chart">
                <canvas id="chartListings"></canvas>
              
              <div class="dash-mini-stats" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                <span class="badge" style="background:rgba(var(--primary-rgb),0.10);color:var(--primary);border:1px solid var(--border)">Active: <?= intval($listingsStatusCounts['active'] ?? 0) ?></span>
                <span class="badge" style="background:rgba(198,40,40,0.10);color:var(--error);border:1px solid var(--border)">Inactive: <?= intval($listingsStatusCounts['inactive'] ?? 0) ?></span>
                <span class="badge" style="background:rgba(var(--accent-rgb),0.12);color:var(--accent);border:1px solid var(--border)">Full: <?= intval($listingsStatusCounts['full'] ?? 0) ?></span>
              </div></div>
            </div>
          </div>

          <div class="card">
            <div class="card-header dash-card-header">
              <div>
                <h2 class="dash-card-title">Reports</h2>
                <div class="dash-card-subtitle">By status (last <?= intval($monthsBack) ?> months)</div>
              </div>
            </div>
            <div class="card-body">
              <div class="dash-chart" aria-label="Reports chart">
                <canvas id="chartReports"></canvas>
              </div>
            </div>
          </div>
        </div>

        <div class="dash-2col mt-4">
          <div class="card">
            <div class="card-header dash-card-header">
              <div>
                <h2 class="dash-card-title">Recent Registrations</h2>
                <div class="dash-card-subtitle">Last 10 users created</div>
              </div>
              <a class="btn btn-ghost btn-sm" href="users.php"><i class="fas fa-users"></i> Manage Users</a>
            </div>
            <div class="card-body">
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Date</th><?= $hasUserActive ? '<th>Status</th>' : '' ?></tr></thead>
                  <tbody>
                  <?php foreach ($recentUsers as $u): ?>
                    <tr>
                      <td class="font-bold"><?= sanitize($u['full_name'] ?? '') ?></td>
                      <td><?= sanitize($u['email'] ?? '') ?></td>
                      <td><span class="badge" style="background:var(--bg);border:1px solid var(--border)"><?= sanitize($u['role'] ?? '') ?></span></td>
                      <td class="text-muted text-sm"><?= sanitize(date('M d, Y', strtotime((string)($u['created_at'] ?? '')))) ?></td>
                      <?php if ($hasUserActive): ?>
                        <td>
                          <span class="badge" style="<?= intval($u['is_active'] ?? 1) ? 'background:rgba(27,122,74,0.12);color:var(--success)' : 'background:var(--error-bg);color:var(--error)' ?>">
                            <?= intval($u['is_active'] ?? 1) ? 'Active' : 'Inactive' ?>
                          </span>
                        </td>
                      <?php endif; ?>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-header dash-card-header">
              <div>
                <h2 class="dash-card-title">Overview</h2>
                <div class="dash-card-subtitle">Quick counts and status</div>
              </div>
              <a class="btn btn-ghost btn-sm" href="reports.php"><i class="fas fa-flag"></i> View Reports</a>
            </div>
            <div class="card-body">
              <div class="dash-overview">
                <?php foreach ($overviewItems as $it): ?>
                  <div class="dash-overview-item">
                    <div class="dash-overview-label">
                      <span class="dash-pill dash-pill-<?= sanitize($it['tone'] ?? 'primary') ?>"></span>
                      <span><?= sanitize($it['label']) ?></span>
                    </div>
                    <strong><?= intval($it['value'] ?? 0) ?></strong>
                  </div>
                <?php endforeach; ?>
              </div>

              <div class="dash-overview-links">
                <a class="btn btn-outline btn-sm" href="owners.php"><i class="fas fa-user-check"></i> Owners</a>
                <a class="btn btn-outline btn-sm" href="listings.php"><i class="fas fa-building"></i> Property Listings</a>
                <a class="btn btn-outline btn-sm" href="payments.php"><i class="fas fa-receipt"></i> Payments</a>
              </div>
            </div>
          </div>
        </div>

        <?php if ($hasViews): ?>
        <div class="card mt-4">
          <div class="card-header dash-card-header">
            <div>
              <h2 class="dash-card-title">Most Viewed Property Listings</h2>
              <div class="dash-card-subtitle">Top 10 listings by views</div>
            </div>
          </div>
          <div class="card-body">
            <?php if (empty($topViewed)): ?>
              <div class="text-muted">No view data yet.</div>
            <?php else: ?>
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Listing</th><th>City</th><th>Views</th><th></th></tr></thead>
                  <tbody>
                  <?php foreach ($topViewed as $r): ?>
                    <tr>
                      <td class="font-bold"><?= sanitize($r['name'] ?? '') ?></td>
                      <td><?= sanitize($r['city'] ?? '') ?></td>
                      <td><?= intval($r['views'] ?? 0) ?></td>
                      <td><a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/pages/detail.php?id=<?= intval($r['id'] ?? 0) ?>"><i class="fas fa-eye"></i> View</a></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </main>
    </div>
  </div>
</div>

<script type="application/json" id="adminDashChartData"><?= json_encode($chartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  function readChartData() {
    var el = document.getElementById('adminDashChartData');
    if (!el) return null;
    try { return JSON.parse(el.textContent || '{}'); } catch (e) { return null; }
  }

  function withAlpha(color, alpha) {
    if (!color) return color;
    color = String(color).trim();
    if (color.startsWith('rgba(')) return color;
    if (color.startsWith('rgb(')) return color.replace('rgb(', 'rgba(').replace(')', ', ' + alpha + ')');
    if (!color.startsWith('#')) return color;

    var hex = color.slice(1);
    if (hex.length === 3) {
      hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
    }
    if (hex.length !== 6) return color;

    var r = parseInt(hex.slice(0, 2), 16);
    var g = parseInt(hex.slice(2, 4), 16);
    var b = parseInt(hex.slice(4, 6), 16);
    return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')';
  }

  function cssVar(name, fallback) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(name);
    v = (v || '').trim();
    return v || fallback;
  }

  document.addEventListener('DOMContentLoaded', function () {
    var data = readChartData();
    if (!data || !window.Chart) return;

    var primary = cssVar('--primary', '#0058C8');
    var secondary = cssVar('--secondary', '#083860');
    var accent = cssVar('--accent', '#F88800');
    var success = cssVar('--success', '#2E7D32');
    var error = cssVar('--error', '#C62828');
    var border = cssVar('--border', '#E6ECF3');
    var textMuted = cssVar('--text-muted', '#5B6475');

    Chart.defaults.color = textMuted;
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;

    var gridColor = withAlpha(border, 0.9);

    var usersEl = document.getElementById('chartUsers');
    if (usersEl) {
      new Chart(usersEl, {
        type: 'line',
        data: {
          labels: data.labels || [],
          datasets: [{
            label: 'New users',
            data: (data.users || []).map(function (n) { return Number(n) || 0; }),
            borderColor: primary,
            backgroundColor: withAlpha(primary, 0.12),
            pointBackgroundColor: primary,
            pointRadius: 3,
            tension: 0.35,
            fill: true
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
            y: {
              beginAtZero: true,
              ticks: { precision: 0 },
              grid: { color: gridColor }
            }
          }
        }
      });
    }
    var listingsEl = document.getElementById('chartListings');
    if (listingsEl) {
      new Chart(listingsEl, {
        type: 'bar',
        data: {
          labels: data.labels || [],
          datasets: [{
            label: 'New property listings',
            data: (data.listingsMonthly || []).map(function (n) { return Number(n) || 0; }),
            backgroundColor: withAlpha(secondary, 0.30),
            borderColor: withAlpha(secondary, 0.70),
            borderWidth: 1,
            borderRadius: 8
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
            y: {
              beginAtZero: true,
              ticks: { precision: 0 },
              grid: { color: gridColor }
            }
          }
        }
      });
    }var reportsEl = document.getElementById('chartReports');
    if (reportsEl) {
      var rp = data.reports || {};
      new Chart(reportsEl, {
        type: 'bar',
        data: {
          labels: data.labels || [],
          datasets: [
            {
              label: 'Open',
              data: (rp.open || []).map(function (n) { return Number(n) || 0; }),
              backgroundColor: withAlpha(accent, 0.65),
              borderColor: withAlpha(accent, 0.95),
              borderWidth: 1,
              borderRadius: 8,
              stack: 'reports'
            },
            {
              label: 'Resolved',
              data: (rp.resolved || []).map(function (n) { return Number(n) || 0; }),
              backgroundColor: withAlpha(success, 0.55),
              borderColor: withAlpha(success, 0.9),
              borderWidth: 1,
              borderRadius: 8,
              stack: 'reports'
            },
            {
              label: 'Dismissed',
              data: (rp.dismissed || []).map(function (n) { return Number(n) || 0; }),
              backgroundColor: withAlpha(secondary, 0.35),
              borderColor: withAlpha(secondary, 0.7),
              borderWidth: 1,
              borderRadius: 8,
              stack: 'reports'
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10 } },
            tooltip: { mode: 'index', intersect: false }
          },
          scales: {
            x: { stacked: true, grid: { display: false } },
            y: {
              stacked: true,
              beginAtZero: true,
              ticks: { precision: 0 },
              grid: { color: gridColor }
            }
          }
        }
      });
    }
  });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>










