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

$activeKubo = (int) db()->query("SELECT COUNT(DISTINCT kr.table_id) AS c FROM kubo_rentals kr JOIN tables t ON kr.table_id = t.id WHERE kr.status='active' AND kr.is_voided=0 AND t.type='kubo'")->fetch()['c'];
$totalKubo = (int) db()->query("SELECT COUNT(*) AS c FROM tables WHERE type='kubo' AND is_deleted=0 AND is_disabled=0")->fetch()['c'];
$availableKubo = max(0, $totalKubo - $activeKubo);

$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedDateObj = new DateTime($selectedDate);

$stmt = db()->prepare("
  SELECT COALESCE(SUM(gs.total_amount),0) AS total
  FROM transactions tx
  JOIN game_sessions gs ON gs.id = tx.session_id
  WHERE gs.end_time IS NOT NULL AND gs.is_voided = 0
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
  WHERE gs.end_time IS NOT NULL AND gs.is_voided = 0
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
$cashierActiveKubos = [];
$cashierAvailTables = [];
$cashierAvailVip = [];
$cashierAvailKtv = [];
$cashierAvailKubos = [];
$cashierCustomers = [];
$cashierReservations = [];
if ($role === 'cashier') {
  $cashierActiveSessions = db()->query("
    SELECT gs.*, t.table_number, t.type, c.name AS registered_name 
    FROM game_sessions gs 
    JOIN tables t ON gs.table_id = t.id 
    LEFT JOIN customers c ON c.id = gs.customer_id
    WHERE gs.end_time IS NULL AND t.type != 'kubo'
    ORDER BY FIELD(t.type, 'regular', 'vip', 'ktv') ASC, t.table_number ASC
  ")->fetchAll(PDO::FETCH_ASSOC);

  $cashierActiveKubos = db()->query("
    SELECT kr.*, t.table_number, t.type, t.rate_per_hour,
      (SELECT gs2.id FROM game_sessions gs2 WHERE gs2.table_id = t.id AND gs2.end_time IS NULL AND gs2.karaoke_included = 1 LIMIT 1) AS karaoke_session_id,
      (SELECT gs2.scheduled_end_time FROM game_sessions gs2 WHERE gs2.table_id = t.id AND gs2.end_time IS NULL AND gs2.karaoke_included = 1 LIMIT 1) AS karaoke_end_time,
      (SELECT gs2.hours_purchased FROM game_sessions gs2 WHERE gs2.table_id = t.id AND gs2.end_time IS NULL AND gs2.karaoke_included = 1 LIMIT 1) AS karaoke_hours,
      (SELECT gs2.total_amount FROM game_sessions gs2 WHERE gs2.table_id = t.id AND gs2.end_time IS NULL AND gs2.karaoke_included = 1 LIMIT 1) AS karaoke_amount
    FROM kubo_rentals kr
    JOIN tables t ON kr.table_id = t.id
    WHERE kr.status = 'active' AND kr.is_voided = 0
    ORDER BY t.table_number ASC
  ")->fetchAll(PDO::FETCH_ASSOC);

  // Available tables for modals
  $cashierAvailTables = db()->query("SELECT id, table_number, rate_per_hour FROM tables WHERE type='regular' AND is_deleted=0 AND is_disabled=0 AND status='available' ORDER BY table_number ASC")->fetchAll(PDO::FETCH_ASSOC);
  $cashierAvailVip = db()->query("SELECT id, table_number, rate_per_hour FROM tables WHERE type='vip' AND is_deleted=0 AND is_disabled=0 AND status='available' ORDER BY table_number ASC")->fetchAll(PDO::FETCH_ASSOC);
  $cashierAvailKtv = db()->query("SELECT id, table_number, rate_per_hour FROM tables WHERE type='ktv' AND is_deleted=0 AND is_disabled=0 AND status='available' ORDER BY table_number ASC")->fetchAll(PDO::FETCH_ASSOC);
  $cashierAvailKubos = db()->query("
    SELECT t.id, t.table_number, t.rate_per_hour FROM tables t
    WHERE t.type='kubo' AND t.is_deleted=0 AND t.is_disabled=0
    AND t.id NOT IN (SELECT table_id FROM kubo_rentals WHERE status='active' AND is_voided=0)
    ORDER BY t.table_number ASC
  ")->fetchAll(PDO::FETCH_ASSOC);
  $cashierAllTables = db()->query("SELECT id, table_number, type FROM tables WHERE is_deleted = 0 AND type != 'kubo' ORDER BY CASE type WHEN 'regular' THEN 1 WHEN 'vip' THEN 2 WHEN 'ktv' THEN 3 END, table_number ASC")->fetchAll(PDO::FETCH_ASSOC);
  $cashierCustomers = db()->query("SELECT id, name, contact, loyalty_games FROM customers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
  $cashierReservations = db()->query("
    SELECT r.*, t.table_number, t.type AS table_type, t.rate_per_hour
    FROM reservations r JOIN tables t ON t.id = r.table_id
    WHERE r.status = 'pending' AND r.reservation_date >= CURDATE()
    ORDER BY r.reservation_date ASC, r.start_time ASC
    LIMIT 50
  ")->fetchAll(PDO::FETCH_ASSOC);
}

// Recent transactions for real-time check
// Income chart data — last 7 days ending on selected date
$chartStmt = db()->prepare("
  SELECT d, type, SUM(income) AS income FROM (
    SELECT DATE(gs.end_time) AS d, t.type, COALESCE(SUM(gs.total_amount),0) AS income
    FROM game_sessions gs
    JOIN tables t ON gs.table_id = t.id
    WHERE gs.end_time IS NOT NULL AND gs.is_voided = 0 AND DATE(gs.end_time) BETWEEN DATE_SUB(?, INTERVAL 6 DAY) AND ?
    GROUP BY DATE(gs.end_time), t.type
    UNION ALL
    SELECT DATE(end_time) AS d, 'kubo' AS type, COALESCE(SUM(payment_amount),0) AS income
    FROM kubo_rentals
    WHERE status = 'completed' AND is_voided = 0 AND DATE(end_time) BETWEEN DATE_SUB(?, INTERVAL 6 DAY) AND ?
    GROUP BY DATE(end_time)
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

$flash = flash_get();
render_header('Dashboard', 'dashboard');
?>

<?php if ($flash): ?>
  <div class="alert alert--<?php echo h($flash['type']); ?>" style="margin-bottom:14px;">
    <?php echo h($flash['message']); ?>
  </div>
<?php endif; ?>

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

<?php if ($role === 'admin'): ?>
<div class="grid grid--cards">
  <div class="card col-3">
    <div class="card__title">Active Regular Tables</div>
    <div class="card__value"><?php echo (int) $activeTables; ?></div>
    <div class="card__sub"><?php echo (int) $availableTables; ?> available</div>
  </div>
  <div class="card col-3">
    <div class="card__title" style="color:#22c55e;">Available Kubo</div>
    <div class="card__value" style="color:#22c55e;"><?php echo (int) $availableKubo; ?></div>
    <div class="card__sub"><?php echo (int) $totalKubo; ?> total (<?php echo (int) $activeKubo; ?> active)</div>
  </div>
  <div class="card col-3">
    <div class="card__title">Ongoing Games</div>
    <div class="card__value"><?php echo (int) $ongoingGames; ?></div>
    <div class="card__sub">Sessions not yet ended</div>
  </div>
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
<?php endif; ?>

<?php if ($role === 'cashier'): ?>
  <?php
    $totalActive = count($cashierActiveSessions) + count($cashierActiveKubos);
    $grouped = ['regular' => [], 'vip' => [], 'ktv' => []];
    foreach ($cashierActiveSessions as $s) {
      $grouped[$s['type']][] = $s;
    }
  ?>

  <!-- Quick Action Buttons -->
  <div class="card" style="margin-bottom:14px;">
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <div style="font-size:15px; font-weight:700; color:var(--text);">⚡ Quick Actions</div>
      <div class="spacer"></div>
      <button type="button" class="btn" onclick="openPickerModal('regular')" style="background:#38bdf8; color:white; border:none; padding:8px 18px; font-size:13px; font-weight:600;">🎱 Start Table Game</button>
      <button type="button" class="btn" onclick="openPickerModal('vip')" style="background:#eab308; color:white; border:none; padding:8px 18px; font-size:13px; font-weight:600;">⭐ Start VIP</button>
      <button type="button" class="btn" onclick="openPickerModal('ktv')" style="background:#a855f7; color:white; border:none; padding:8px 18px; font-size:13px; font-weight:600;">🎤 Start KTV</button>
      <button type="button" class="btn" onclick="openPickerModal('kubo')" style="background:#22c55e; color:white; border:none; padding:8px 18px; font-size:13px; font-weight:600;">🏡 Rent Kubo</button>
      <button type="button" class="btn" onclick="openReservationsModal()" style="background:#6366f1; color:white; border:none; padding:8px 18px; font-size:13px; font-weight:600;">📅 Reservations</button>
    </div>
  </div>

  <div class="card" style="padding:0; overflow:hidden;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:12px;">
      <div style="font-size:17px; font-weight:700; color:var(--text);">📋 All Active Sessions</div>
      <?php if ($totalActive > 0): ?>
        <span class="badge" style="background:var(--primary); color:#fff; font-size:12px;"><?php echo $totalActive; ?> Active</span>
      <?php endif; ?>
      <div class="spacer"></div>
      <div style="font-size:12px; color:var(--muted);">Auto-refreshes with timer</div>
    </div>

    <?php if ($totalActive === 0): ?>
      <div style="text-align:center; padding:48px 20px; color:var(--muted);">
        <div style="font-size:40px; margin-bottom:12px; opacity:0.5;">🎱</div>
        <div style="font-size:15px; font-weight:600;">No active sessions right now</div>
        <div style="font-size:13px; margin-top:4px;">Start a game using the Quick Actions above.</div>
      </div>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="table" style="margin:0; border:none; font-size:13px;">
          <thead>
            <tr style="background:var(--surface2);">
              <th style="padding:10px 14px; white-space:nowrap;">Table</th>
              <th style="padding:10px 14px; white-space:nowrap;">Type</th>
              <th style="padding:10px 14px; white-space:nowrap;">Customer</th>
              <th style="padding:10px 14px; white-space:nowrap;">Hours</th>
              <th style="padding:10px 14px; white-space:nowrap;">Paid</th>
              <th style="padding:10px 14px; white-space:nowrap;">Start</th>
              <th style="padding:10px 14px; white-space:nowrap;">End</th>
              <th style="padding:10px 14px; white-space:nowrap; text-align:center;">Time Left</th>
              <th style="padding:10px 14px; white-space:nowrap; text-align:center;">Actions</th>
            </tr>
          </thead>
          <tbody>

          <?php // ── REGULAR TABLES ──
          if (!empty($grouped['regular'])): ?>
            <tr><td colspan="9" style="background:rgba(56,189,248,0.08); padding:6px 14px; font-size:12px; font-weight:700; color:#38bdf8; border-bottom:1px solid rgba(56,189,248,0.15);">🎱 Regular Tables (<?php echo count($grouped['regular']); ?>)</td></tr>
            <?php foreach ($grouped['regular'] as $s):
              $cname = $s['registered_name'] ?: ($s['walk_in_name'] ?: 'Walk-in');
            ?>
              <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:10px 14px; font-weight:700;"><?php echo h($s['table_number']); ?></td>
                <td style="padding:10px 14px;"><span class="badge" style="font-size:10px; background:rgba(56,189,248,0.15); color:#38bdf8;">Regular</span></td>
                <td style="padding:10px 14px; font-weight:600;"><?php echo h($cname); ?><?php if (!empty($s['is_promo'])): ?> <span style="color:#38bdf8; font-size:10px;">🏷️50%</span><?php endif; ?></td>
                <td style="padding:10px 14px;"><?php echo h(($s['hours_purchased'] ?? 0) . 'h'); ?></td>
                <td style="padding:10px 14px; color:#22c55e; font-weight:600;">₱<?php echo number_format((float)($s['total_amount'] ?? 0), 2); ?></td>
                <td style="padding:10px 14px;"><?php echo date('h:i A', strtotime($s['start_time'])); ?></td>
                <td style="padding:10px 14px;"><?php echo !empty($s['scheduled_end_time']) ? date('h:i A', strtotime($s['scheduled_end_time'])) : '--'; ?></td>
                <td style="padding:10px 14px; text-align:center;">
                  <?php if (!empty($s['scheduled_end_time'])): ?>
                    <span class="badge badge--warn" data-dashboard-countdown="<?php echo h($s['scheduled_end_time']); ?>" style="font-size:13px; font-weight:700; min-width:70px; display:inline-block;">--:--:--</span>
                  <?php else: ?>
                    <span class="badge">--:--</span>
                  <?php endif; ?>
                </td>
                <td style="padding:10px 14px; text-align:center; white-space:nowrap;">
                  <button class="btn btn--danger" type="button" style="padding:4px 12px; font-size:11px; background:#ef4444; border:none; color:white;"
                    onclick="voidGame(<?php echo (int)$s['id']; ?>, '<?php echo h($s['table_number']); ?>')">Void</button>
                  <button class="btn btn--ok" type="button" style="padding:4px 12px; font-size:11px; background:#f59e0b; border:none; color:white;"
                    onclick="openLoyaltyModal(<?php echo (int)$s['id']; ?>, '<?php echo h($s['table_number']); ?>', 'regular')">+Loyalty</button>
                  <button class="btn" type="button" style="padding:4px 12px; font-size:11px; background:var(--primary); color:white; border:none;"
                    onclick="openExtendModal(<?php echo (int)$s['id']; ?>, '<?php echo h($s['table_number']); ?>', <?php echo (float)$s['rate_per_hour']; ?>, '<?php echo h($s['scheduled_end_time'] ?? ''); ?>', 'regular')">Extend</button>
                  <button class="btn btn--ghost" type="button" style="padding:4px 12px; font-size:11px;"
                    onclick="openEndModal(<?php echo (int)$s['id']; ?>, '<?php echo h($s['table_number']); ?>', 'regular')">End</button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>

          <?php // ── VIP TABLES ──
          if (!empty($grouped['vip'])): ?>
            <tr><td colspan="9" style="background:rgba(234,179,8,0.08); padding:6px 14px; font-size:12px; font-weight:700; color:#eab308; border-bottom:1px solid rgba(234,179,8,0.15);">⭐ VIP Tables (<?php echo count($grouped['vip']); ?>)</td></tr>
            <?php foreach ($grouped['vip'] as $s):
              $cname = $s['registered_name'] ?: ($s['walk_in_name'] ?: 'Walk-in');
            ?>
              <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:10px 14px; font-weight:700;"><?php echo h($s['table_number']); ?></td>
                <td style="padding:10px 14px;"><span class="badge badge--vip" style="font-size:10px;">VIP</span></td>
                <td style="padding:10px 14px; font-weight:600;"><?php echo h($cname); ?><?php if (!empty($s['karaoke_included'])): ?> <span style="font-size:10px; color:#c084fc;">🎤</span><?php endif; ?></td>
                <td style="padding:10px 14px;"><?php echo h(($s['hours_purchased'] ?? 0) . 'h'); ?></td>
                <td style="padding:10px 14px; color:#22c55e; font-weight:600;">₱<?php echo number_format((float)($s['total_amount'] ?? 0), 2); ?></td>
                <td style="padding:10px 14px;"><?php echo date('h:i A', strtotime($s['start_time'])); ?></td>
                <td style="padding:10px 14px;"><?php echo !empty($s['scheduled_end_time']) ? date('h:i A', strtotime($s['scheduled_end_time'])) : '--'; ?></td>
                <td style="padding:10px 14px; text-align:center;">
                  <?php if (!empty($s['scheduled_end_time'])): ?>
                    <span class="badge badge--warn" data-dashboard-countdown="<?php echo h($s['scheduled_end_time']); ?>" style="font-size:13px; font-weight:700; min-width:70px; display:inline-block;">--:--:--</span>
                  <?php else: ?>
                    <span class="badge">--:--</span>
                  <?php endif; ?>
                </td>
                <td style="padding:10px 14px; text-align:center; white-space:nowrap;">
                  <button class="btn btn--danger" type="button" style="padding:4px 12px; font-size:11px; background:#ef4444; border:none; color:white;"
                    onclick="voidGame(<?php echo (int)$s['id']; ?>, '<?php echo h($s['table_number']); ?> (VIP)')">Void</button>
                  <button class="btn btn--ok" type="button" style="padding:4px 12px; font-size:11px; background:#f59e0b; border:none; color:white;"
                    onclick="openLoyaltyModal(<?php echo (int)$s['id']; ?>, '<?php echo h($s['table_number']); ?> (VIP)', 'vip')">+Loyalty</button>
                  <button class="btn" type="button" style="padding:4px 12px; font-size:11px; background:#eab308; color:white; border:none;"
                    onclick="openExtendModal(<?php echo (int)$s['id']; ?>, '<?php echo h($s['table_number']); ?> (VIP)', <?php echo (float)$s['rate_per_hour']; ?>, '<?php echo h($s['scheduled_end_time'] ?? ''); ?>', 'vip')">Extend</button>
                  <button class="btn btn--ghost" type="button" style="padding:4px 12px; font-size:11px;"
                    onclick="openEndModal(<?php echo (int)$s['id']; ?>, '<?php echo h($s['table_number']); ?> (VIP)', 'vip')">End</button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>

          <?php // ── KTV ROOMS ──
          if (!empty($grouped['ktv'])): ?>
            <tr><td colspan="9" style="background:rgba(168,85,247,0.08); padding:6px 14px; font-size:12px; font-weight:700; color:#c084fc; border-bottom:1px solid rgba(168,85,247,0.15);">🎤 KTV Rooms (<?php echo count($grouped['ktv']); ?>)</td></tr>
            <?php foreach ($grouped['ktv'] as $s):
              $cname = $s['registered_name'] ?: ($s['walk_in_name'] ?: 'Walk-in');
            ?>
              <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:10px 14px; font-weight:700;"><?php echo h($s['table_number']); ?></td>
                <td style="padding:10px 14px;"><span class="badge" style="font-size:10px; background:rgba(168,85,247,0.2); color:#c084fc;">KTV</span></td>
                <td style="padding:10px 14px; font-weight:600;"><?php echo h($cname); ?></td>
                <td style="padding:10px 14px;"><?php echo h(($s['hours_purchased'] ?? 0) . 'h'); ?></td>
                <td style="padding:10px 14px; color:#22c55e; font-weight:600;">₱<?php echo number_format((float)($s['total_amount'] ?? 0), 2); ?></td>
                <td style="padding:10px 14px;"><?php echo date('h:i A', strtotime($s['start_time'])); ?></td>
                <td style="padding:10px 14px;"><?php echo !empty($s['scheduled_end_time']) ? date('h:i A', strtotime($s['scheduled_end_time'])) : '--'; ?></td>
                <td style="padding:10px 14px; text-align:center;">
                  <?php if (!empty($s['scheduled_end_time'])): ?>
                    <span class="badge badge--warn" data-dashboard-countdown="<?php echo h($s['scheduled_end_time']); ?>" style="font-size:13px; font-weight:700; min-width:70px; display:inline-block;">--:--:--</span>
                  <?php else: ?>
                    <span class="badge">--:--</span>
                  <?php endif; ?>
                </td>
                <td style="padding:10px 14px; text-align:center; white-space:nowrap;">
                  <button class="btn btn--danger" type="button" style="padding:4px 12px; font-size:11px; background:#ef4444; border:none; color:white;"
                    onclick="voidGame(<?php echo (int)$s['id']; ?>, '<?php echo h($s['table_number']); ?> (KTV)')">Void</button>
                  <button class="btn btn--ok" type="button" style="padding:4px 12px; font-size:11px; background:#f59e0b; border:none; color:white;"
                    onclick="openLoyaltyModal(<?php echo (int)$s['id']; ?>, '<?php echo h($s['table_number']); ?> (KTV)', 'ktv')">+Loyalty</button>
                  <button class="btn" type="button" style="padding:4px 12px; font-size:11px; background:#a855f7; color:white; border:none;"
                    onclick="openExtendModal(<?php echo (int)$s['id']; ?>, '<?php echo h($s['table_number']); ?> (KTV)', <?php echo (float)$s['rate_per_hour']; ?>, '<?php echo h($s['scheduled_end_time'] ?? ''); ?>', 'ktv')">Extend</button>
                  <button class="btn btn--ghost" type="button" style="padding:4px 12px; font-size:11px;"
                    onclick="openEndModal(<?php echo (int)$s['id']; ?>, '<?php echo h($s['table_number']); ?> (KTV)', 'ktv')">End</button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>

          <?php // ── KUBO RENTALS ──
          if (!empty($cashierActiveKubos)): ?>
            <tr><td colspan="9" style="background:rgba(34,197,94,0.08); padding:6px 14px; font-size:12px; font-weight:700; color:#22c55e; border-bottom:1px solid rgba(34,197,94,0.15);">🏡 Kubo (<?php echo count($cashierActiveKubos); ?>)</td></tr>
            <?php foreach ($cashierActiveKubos as $k): ?>
              <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:10px 14px; font-weight:700;"><?php echo h($k['table_number']); ?></td>
                <td style="padding:10px 14px;"><span class="badge" style="font-size:10px; background:rgba(34,197,94,0.15); color:#22c55e;">Kubo</span></td>
                <td style="padding:10px 14px; font-weight:600;">
                  <?php echo h($k['customer_name']); ?>
                  <?php if (!empty($k['karaoke_session_id'])): ?>
                    <span style="font-size:10px; color:#c084fc;">🎤 Karaoke</span>
                  <?php endif; ?>
                </td>
                <td style="padding:10px 14px; color:var(--muted);">—</td>
                <td style="padding:10px 14px; color:#22c55e; font-weight:600;">₱<?php echo number_format((float)($k['payment_amount'] ?? 0), 2); ?></td>
                <td style="padding:10px 14px;"><?php echo date('h:i A', strtotime($k['created_at'])); ?></td>
                <td style="padding:10px 14px; color:var(--muted);">—</td>
                <td style="padding:10px 14px; text-align:center;">
                  <?php if (!empty($k['karaoke_end_time'])): ?>
                    <span class="badge" data-dashboard-countdown="<?php echo h($k['karaoke_end_time']); ?>" style="font-size:13px; font-weight:700; min-width:70px; display:inline-block; background:rgba(168,85,247,0.15); color:#9333ea;">--:--:--</span>
                  <?php else: ?>
                    <span class="badge" style="background:rgba(34,197,94,0.15); color:#22c55e;">Active</span>
                  <?php endif; ?>
                </td>
                <td style="padding:10px 14px; text-align:center; white-space:nowrap;">
                  <?php if (empty($k['karaoke_session_id'])): ?>
                    <button class="btn btn--ok" type="button" style="padding:4px 12px; font-size:11px; background:#a855f7; border:none; color:white;"
                      onclick="openDshStartKaraokeModal(<?php echo (int)$k['table_id']; ?>, '<?php echo h($k['table_number']); ?>', <?php echo (float)($k['rate_per_hour'] > 0 ? $k['rate_per_hour'] : 100); ?>, '<?php echo h(addslashes($k['customer_name'])); ?>')">+Karaoke</button>
                  <?php endif; ?>
                  <button class="btn btn--danger" type="button" style="padding:4px 12px; font-size:11px; background:#ef4444; border:none; color:white;"
                    onclick="openDshVoidKuboModal(<?php echo (int)$k['id']; ?>, '<?php echo h($k['table_number']); ?>')">Void</button>
                  <button class="btn btn--ghost" type="button" style="padding:4px 12px; font-size:11px;"
                    onclick="openDshEndKuboModal(<?php echo (int)$k['id']; ?>, '<?php echo h($k['table_number']); ?>')">End</button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>

          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <script>
    document.querySelectorAll('[data-dashboard-countdown]').forEach(el => {
      const endTimeStr = el.dataset.dashboardCountdown.replace(' ', 'T');
      const endTime = new Date(endTimeStr);
      function tick() {
        const now = new Date(Date.now() + (window.TIME_OFFSET || 0));
        let diff = Math.floor((endTime - now) / 1000);
        if (diff <= 0) {
          el.textContent = "TIME'S UP";
          el.className = 'badge badge--danger';
          el.style.minWidth = '70px';
          return;
        }
        const h = Math.floor(diff / 3600);
        const m = Math.floor((diff % 3600) / 60);
        const s = diff % 60;
        el.textContent = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        
        // Visual 10-minute warning
        if (diff <= 600) {
           el.style.backgroundColor = '#f59e0b';
           el.style.color = '#fff';
        }
        
        setTimeout(tick, 1000);
      }
      tick();
    });
  </script>
  <?php include __DIR__ . '/includes/dashboard_modals.php'; ?>
  <?php include __DIR__ . '/includes/cashier_modals.php'; ?>
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

<script src="assets/js/chart.umd.min.js"></script>
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
