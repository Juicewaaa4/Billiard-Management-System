<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/util.php';

start_app_session();
require_login();


$role = current_user()['role'] ?? '';

// Metrics
$activeTables = (int) db()->query("SELECT COUNT(*) AS c FROM tables WHERE type='regular' AND status='in_use' AND is_deleted=0")->fetch()['c'];
$availableTables = (int) db()->query("SELECT COUNT(*) AS c FROM tables WHERE type='regular' AND status='available' AND is_deleted=0 AND is_disabled=0")->fetch()['c'];
$ongoingGames = (int) db()->query("SELECT COUNT(*) AS c FROM game_sessions gs JOIN tables t ON gs.table_id = t.id WHERE gs.end_time IS NULL AND t.type='regular'")->fetch()['c'];

$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedDateObj = new DateTime($selectedDate);

$stmt = db()->prepare("
  SELECT COALESCE(SUM(gs.total_amount),0) AS total
  FROM transactions tx
  JOIN game_sessions gs ON gs.id = tx.session_id
  WHERE gs.end_time IS NOT NULL
    AND DATE(gs.end_time) = ?
");
$stmt->execute([$selectedDate]);
$todayIncome = (float) $stmt->fetch()['total'];

$loyaltySummary = db()->query("
  SELECT
    COUNT(*) AS customers,
    COALESCE(SUM(loyalty_games),0) AS games,
    COALESCE(SUM(loyalty_vip_games),0) AS vip_games
  FROM customers
")->fetch();
$customerCount = (int) $loyaltySummary['customers'];

// Get games for selected date
$gamesForDate = 0;
$vipGamesForDate = 0;
$ktvGamesForDate = 0;

$gamesStmt = db()->prepare("
  SELECT
    COALESCE(SUM(CASE WHEN t.type='regular' THEN gs.games_earned ELSE 0 END),0) AS total_games,
    COUNT(CASE WHEN t.type='vip' THEN gs.id END) AS total_vip_games,
    COUNT(CASE WHEN t.type='ktv' THEN gs.id END) AS total_ktv_games
  FROM game_sessions gs
  JOIN tables t ON t.id = gs.table_id
  WHERE gs.end_time IS NOT NULL
    AND DATE(gs.end_time) = ?
");
$gamesStmt->execute([$selectedDate]);
$row = $gamesStmt->fetch();
$gamesForDate = (int) $row['total_games'];
$vipGamesForDate = (int) $row['total_vip_games'];
$ktvGamesForDate = (int) $row['total_ktv_games'];

$totalGames = $gamesForDate;
$totalVipGames = $vipGamesForDate;
$totalKtvGames = $ktvGamesForDate;

$cashierActiveSessions = [];
if ($role === 'cashier') {
  $cashierActiveSessions = db()->query("
    SELECT gs.*, t.table_number, t.type, c.name AS registered_name 
    FROM game_sessions gs 
    JOIN tables t ON gs.table_id = t.id 
    LEFT JOIN customers c ON c.id = gs.customer_id
    WHERE gs.end_time IS NULL 
    ORDER BY t.type ASC, t.table_number ASC
  ")->fetchAll(PDO::FETCH_ASSOC);
}

// Recent transactions for real-time check
// Income chart data — last 7 days ending on selected date
$chartStmt = db()->prepare("
  SELECT d, type, SUM(income) AS income FROM (
    SELECT DATE(gs.end_time) AS d, t.type, COALESCE(SUM(gs.total_amount),0) AS income
    FROM game_sessions gs
    JOIN tables t ON gs.table_id = t.id
    WHERE gs.end_time IS NOT NULL AND DATE(gs.end_time) BETWEEN DATE_SUB(?, INTERVAL 6 DAY) AND ?
    GROUP BY DATE(gs.end_time), t.type
    UNION ALL
    SELECT rental_date AS d, 'kubo' AS type, COALESCE(SUM(payment_amount),0) AS income
    FROM kubo_rentals
    WHERE status = 'completed' AND rental_date BETWEEN DATE_SUB(?, INTERVAL 6 DAY) AND ?
    GROUP BY rental_date
  ) combined
  GROUP BY d, type
  ORDER BY d ASC
");
$chartStmt->execute([$selectedDate, $selectedDate, $selectedDate, $selectedDate]);
$chartRows = $chartStmt->fetchAll();

// Fill missing days
$chartLabels = [];
$chartValuesReg = [];
$chartValuesVIP = [];
$chartValuesKTV = [];
$chartValuesKubo = []; // <-- added
$chartDataMapReg = [];
$chartDataMapVIP = [];
$chartDataMapKTV = [];
$chartDataMapKubo = []; // <-- added

foreach ($chartRows as $r) {
  if ($r['type'] === 'vip') {
    $chartDataMapVIP[$r['d']] = (float) $r['income'];
  } elseif ($r['type'] === 'ktv') {
    $chartDataMapKTV[$r['d']] = (float) $r['income'];
  } elseif ($r['type'] === 'kubo') {
    $chartDataMapKubo[$r['d']] = (float) $r['income'];
  } else {
    $chartDataMapReg[$r['d']] = (float) $r['income'];
  }
}
for ($i = 6; $i >= 0; $i--) {
  $day = date('Y-m-d', strtotime("-{$i} days", strtotime($selectedDate)));
  $chartLabels[] = date('M j', strtotime($day));
  $chartValuesReg[] = $chartDataMapReg[$day] ?? 0;
  $chartValuesVIP[] = $chartDataMapVIP[$day] ?? 0;
  $chartValuesKTV[] = $chartDataMapKTV[$day] ?? 0;
  $chartValuesKubo[] = $chartDataMapKubo[$day] ?? 0;
}

render_header('Dashboard', 'dashboard');
?>

<?php if ($role === 'admin'): ?>
  <div class="card" style="margin-bottom:14px;">
    <div class="row" style="align-items:center; gap:12px;">
      <div class="card__title" style="margin:0;">📅 Filter by Date</div>
      <form method="get" class="row" style="gap:10px; align-items:center;">
        <input type="date" name="date" value="<?php echo h($selectedDate); ?>" onchange="this.form.submit()"
          style="padding:6px 10px; border-radius:6px; border:1px solid var(--border); background:var(--bg); color:var(--text);">
      </form>
      <div class="spacer"></div>
      <div style="color:var(--muted); font-size:13px;">
        <?php if ($selectedDate === date('Y-m-d')): ?>
          Showing today's data
        <?php else: ?>
          Showing: <?php echo h($selectedDateObj->format('F j, Y')); ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="grid grid--cards">
  <div class="card col-3">
    <div class="card__title">Active Regular Tables</div>
    <div class="card__value"><?php echo (int) $activeTables; ?></div>
    <div class="card__sub"><?php echo (int) $availableTables; ?> available</div>
  </div>
  <div class="card col-3">
    <div class="card__title">Ongoing Games</div>
    <div class="card__value"><?php echo (int) $ongoingGames; ?></div>
    <div class="card__sub">Sessions not yet ended</div>
  </div>
  <?php if ($role === 'admin'): ?>
    <div class="card col-3">
      <div class="card__title">Daily Income</div>
      <div class="card__value">₱<?php echo number_format($todayIncome, 2); ?></div>
      <div class="card__sub">
        <?php if ($selectedDate === date('Y-m-d')): ?>
          Completed today
        <?php else: ?>
          <?php echo h($selectedDateObj->format('M j, Y')); ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
  <div class="card col-3">
    <div class="card__title">Games</div>
    <div class="card__value"><?php echo number_format($totalGames); ?></div>
    <div class="card__sub">
      <?php if ($selectedDate === date('Y-m-d')): ?>
        Regular tables
      <?php else: ?>
        On <?php echo h($selectedDateObj->format('M j, Y')); ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="card col-3">
    <div class="card__title" style="color:gold;">VIP Games</div>
    <div class="card__value" style="color:gold;"><?php echo number_format($totalVipGames); ?></div>
    <div class="card__sub">
      <?php if ($selectedDate === date('Y-m-d')): ?>
        VIP tables
      <?php else: ?>
        On <?php echo h($selectedDateObj->format('M j, Y')); ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="card col-3">
    <div class="card__title" style="color:#c084fc;">KTV Games</div>
    <div class="card__value" style="color:#c084fc;"><?php echo number_format($totalKtvGames); ?></div>
    <div class="card__sub">
      <?php if ($selectedDate === date('Y-m-d')): ?>
        KTV Rooms
      <?php else: ?>
        On <?php echo h($selectedDateObj->format('M j, Y')); ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($role === 'cashier'): ?>
  <div class="card__title" style="margin-top:24px;">Active Rented Tables</div>
  <div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(310px, 1fr)); gap: 14px; margin-top: 12px;">
    <!-- Active List -->
    <?php foreach ($cashierActiveSessions as $s):
      $typeColor = $s['type'] === 'vip' ? '#eab308' : ($s['type'] === 'ktv' ? '#c084fc' : '#38bdf8');
      $tableText = $s['table_number'];
      $isVip = $s['type'] === 'vip';
      $isKtv = $s['type'] === 'ktv';
      $cname = $s['registered_name'] ?: ($s['walk_in_name'] ?: 'Walk-in');
      ?>
      <div class="card table-card" style="border-left: 4px solid <?php echo $typeColor; ?>; position:relative; overflow:hidden;">
        <div
          style="position:absolute; top:0; right:0; width:80px; height:80px; background:radial-gradient(circle at top right, <?php echo $s['type'] === 'vip' ? 'rgba(245,158,11,0.06)' : ($s['type'] === 'ktv' ? 'rgba(168,85,247,0.06)' : 'rgba(56,189,248,0.06)'); ?>, transparent 70%); pointer-events:none;">
        </div>

        <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
          <div style="font-size:18px; font-weight:700; color:var(--text);">
            <?php echo h($tableText); ?>
            <?php if ($isVip): ?><span class="badge badge--vip" style="margin-left:6px; font-size:11px;">VIP</span><?php endif; ?>
            <?php if ($isKtv): ?><span class="badge" style="margin-left:6px; font-size:11px; background:rgba(168, 85, 247, 0.2); color:#c084fc;">KTV</span><?php endif; ?>
          </div>
          <div class="spacer"></div>
          <span class="badge badge--warn">In Use</span>
        </div>

        <!-- Active Game Info (Mirrors tables.php) -->
        <div style="background:var(--surface2); border-radius:8px; padding:12px; margin-bottom:12px; border:1px solid var(--border2);">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <span style="font-size:12px; color:var(--muted); text-transform:uppercase;">Time Remaining</span>
            <?php if (!empty($s['scheduled_end_time'])): ?>
              <span class="badge badge--warn" data-dashboard-countdown="<?php echo h($s['scheduled_end_time']); ?>"
                style="font-size:14px; font-weight:700;">--:--:--</span>
            <?php else: ?>
              <span class="badge">00:00:00</span>
            <?php endif; ?>
          </div>

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px; font-size:13px;">
            <div><span style="color:var(--muted);">Hours:</span>
              <strong><?php echo h(($s['hours_purchased'] ?? 0) . 'h'); ?></strong></div>
            <div><span style="color:var(--muted);">Paid:</span>
              <strong>₱<?php echo number_format((float) ($s['total_amount'] ?? 0), 2); ?></strong></div>
            <div style="grid-column:span 2;">
              <strong><?php echo h($cname); ?></strong>
              <?php if (!empty($s['karaoke_included'])): ?>
                <span style="color:#38bdf8; font-size:11px; margin-left:6px;">🎤 Karaoke</span>
              <?php endif; ?>
            </div>
            <div
              style="grid-column:span 2; display:flex; justify-content:space-between; margin-top:4px; padding-top:6px; border-top:1px solid var(--border2);">
              <div><span style="color:var(--muted); font-size:11px; text-transform:uppercase;">Start
                  Time</span><br><strong><?php echo date('h:i A', strtotime($s['start_time'])); ?></strong></div>
              <div style="text-align:right;"><span
                  style="color:var(--muted); font-size:11px; text-transform:uppercase;">Scheduled
                  End</span><br><strong><?php echo !empty($s['scheduled_end_time']) ? date('h:i A', strtotime($s['scheduled_end_time'])) : '--:-- --'; ?></strong>
              </div>
            </div>
          </div>

          <?php if (!empty($s['is_promo'])): ?>
            <div
              style="margin-top:2px; padding:6px 10px; background:linear-gradient(90deg, rgba(56,189,248,0.1), rgba(56,189,248,0.02)); border-radius:6px; border:1px solid rgba(56,189,248,0.2);">
              <span style="color:#38bdf8; font-size:12px; font-weight:700;">🏷️ 50% Promo Applied</span>
            </div>
          <?php endif; ?>
        </div>

        <div style="display:flex; gap:8px;">
          <button class="btn" type="button" style="flex:1; <?php echo $isKtv ? 'background:#a855f7; color:white; border:none;' : 'background:var(--primary); color:white; border:none;'; ?>"
            onclick="openExtendModal(<?php echo (int) $s['id']; ?>, '<?php echo h($tableText) . ($isVip ? ' (VIP)' : ($isKtv ? ' (KTV)' : '')); ?>', <?php echo (float) $s['rate_per_hour']; ?>, '<?php echo h($s['scheduled_end_time'] ?? ''); ?>', '<?php echo $s['type']; ?>')">Extend</button>
          <button class="btn btn--ghost" type="button" style="flex:1;"
            onclick="openEndModal(<?php echo (int) $s['id']; ?>, '<?php echo h($tableText) . ($isVip ? ' (VIP)' : ($isKtv ? ' (KTV)' : '')); ?>', '<?php echo $s['type']; ?>')">End
            Game</button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <script>
    document.querySelectorAll('[data-dashboard-countdown]').forEach(el => {
      const endTime = new Date(el.dataset.dashboardCountdown);
      function tick() {
        const now = new Date();
        let diff = Math.floor((endTime - now) / 1000);
        if (diff <= 0) {
          el.textContent = "TIME'S UP";
          el.className = 'badge badge--danger';
          return;
        }
        const h = Math.floor(diff / 3600);
        const m = Math.floor((diff % 3600) / 60);
        const s = diff % 60;
        el.textContent = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        setTimeout(tick, 1000);
      }
      tick();
    });
  </script>
  <?php include __DIR__ . '/includes/dashboard_modals.php'; ?>
<?php endif; ?>

<?php if ($role === 'admin'): ?>
  <div class="card" style="margin-top:14px;">
    <div class="row" style="align-items:center;">
      <div>
        <div class="card__title">📊 Income Overview</div>
        <div style="margin-top:6px; color:var(--muted);">Last 7 days ending
          <?php echo h($selectedDateObj->format('M j, Y')); ?></div>
      </div>
      <div class="spacer"></div>
      <a class="btn btn--ghost" href="reports.php" style="font-size:13px;">View Full Reports</a>
    </div>

    <div style="margin-top:16px; position:relative; height:280px;">
      <canvas id="incomeChart"></canvas>
    </div>
  </div>
<?php endif; ?>

<?php render_footer(); ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>

  <?php if ($role === 'admin'): ?>
    // Income Chart
    const ctx = document.getElementById('incomeChart').getContext('2d');

    const gradientReg = ctx.createLinearGradient(0, 0, 0, 280);
    gradientReg.addColorStop(0, 'rgba(59, 130, 246, 0.35)');
    gradientReg.addColorStop(1, 'rgba(59, 130, 246, 0.02)');

    const gradientVIP = ctx.createLinearGradient(0, 0, 0, 280);
    gradientVIP.addColorStop(0, 'rgba(234, 179, 8, 0.35)');
    gradientVIP.addColorStop(1, 'rgba(234, 179, 8, 0.02)');

    const gradientKTV = ctx.createLinearGradient(0, 0, 0, 280);
    gradientKTV.addColorStop(0, 'rgba(168, 85, 247, 0.35)');
    gradientKTV.addColorStop(1, 'rgba(168, 85, 247, 0.02)');

    const gradientKubo = ctx.createLinearGradient(0, 0, 0, 280);
    gradientKubo.addColorStop(0, 'rgba(34, 197, 94, 0.35)');
    gradientKubo.addColorStop(1, 'rgba(34, 197, 94, 0.02)');

    new Chart(ctx, {
      type: 'line',
      data: {
        labels: <?php echo json_encode($chartLabels); ?>,
        datasets: [
          {
            label: 'Regular Tables',
            data: <?php echo json_encode($chartValuesReg); ?>,
            borderColor: '#3b82f6',
            backgroundColor: gradientReg,
            borderWidth: 2,
            pointBackgroundColor: '#1e293b',
            pointBorderColor: '#3b82f6',
            pointBorderWidth: 2,
            pointRadius: 4,
            pointHoverRadius: 6,
            fill: true,
            tension: 0.4
          },
          {
            label: 'VIP Tables',
            data: <?php echo json_encode($chartValuesVIP); ?>,
            borderColor: '#eab308',
            backgroundColor: gradientVIP,
            borderWidth: 2,
            pointBackgroundColor: '#1e293b',
            pointBorderColor: '#eab308',
            pointBorderWidth: 2,
            pointRadius: 4,
            pointHoverRadius: 6,
            fill: true,
            tension: 0.4
          },
          {
            label: 'KTV Rooms',
            data: <?php echo json_encode($chartValuesKTV); ?>,
            borderColor: '#a855f7',
            backgroundColor: gradientKTV,
            borderWidth: 2,
            pointBackgroundColor: '#1e293b',
            pointBorderColor: '#a855f7',
            pointBorderWidth: 2,
            pointRadius: 4,
            pointHoverRadius: 6,
            fill: true,
            tension: 0.4
          },
          {
            label: 'Kubo',
            data: <?php echo json_encode($chartValuesKubo); ?>,
            borderColor: '#22c55e',
            backgroundColor: gradientKubo,
            borderWidth: 2,
            pointBackgroundColor: '#1e293b',
            pointBorderColor: '#22c55e',
            pointBorderWidth: 2,
            pointRadius: 4,
            pointHoverRadius: 6,
            fill: true,
            tension: 0.4
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { intersect: false, mode: 'index' },
        plugins: {
          legend: { display: true, position: 'top', labels: { color: 'var(--text)' } },
          tooltip: {
            backgroundColor: 'rgba(15,23,42,0.9)',
            titleColor: '#e2e8f0',
            bodyColor: '#fff',
            bodyFont: { size: 14, weight: 'bold' },
            padding: 12,
            cornerRadius: 8,
            displayColors: true,
            callbacks: {
              label: ctx => ' ' + ctx.dataset.label.replace(' (₱)', '') + ': ₱' + ctx.parsed.y.toLocaleString(undefined, { minimumFractionDigits: 2 })
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { color: 'rgba(148,163,184,0.7)', font: { size: 12 } }
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(148,163,184,0.1)' },
            ticks: {
              color: 'rgba(148,163,184,0.7)',
              font: { size: 12 },
              callback: v => '₱' + v.toLocaleString()
            }
          }
        }
      }
    });
  <?php endif; ?>
</script>
