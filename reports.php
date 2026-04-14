<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/util.php';

start_app_session();
require_role(['admin']);

// ── Auto-create settings table & seed defaults ──
try {
  db()->exec("
    CREATE TABLE IF NOT EXISTS app_settings (
      setting_key VARCHAR(50) PRIMARY KEY,
      setting_value VARCHAR(255) NOT NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
  ");
  db()->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('tx_from_time', '08:00'),
    ('tx_to_time', '05:00'),
    ('dt_op_start', '08:00'),
    ('dt_op_end', '00:00')
  ");
} catch (Throwable $ignore) {}

// ── Load saved time settings ──
$savedTxFrom = '08:00';
$savedTxTo = '05:00';
$savedDtStart = '08:00';
$savedDtEnd = '00:00';
try {
  $ssStmt = db()->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('tx_from_time','tx_to_time','dt_op_start','dt_op_end')");
  foreach ($ssStmt->fetchAll() as $ss) {
    if ($ss['setting_key'] === 'tx_from_time') $savedTxFrom = $ss['setting_value'];
    if ($ss['setting_key'] === 'tx_to_time') $savedTxTo = $ss['setting_value'];
    if ($ss['setting_key'] === 'dt_op_start') $savedDtStart = $ss['setting_value'];
    if ($ss['setting_key'] === 'dt_op_end') $savedDtEnd = $ss['setting_value'];
  }
} catch (Throwable $ignore) {}

// ── AJAX: Save time settings ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_report_settings') {
  header('Content-Type: application/json');
  try {
    $updates = [
      'tx_from_time' => trim((string)($_POST['tx_from_time'] ?? '')),
      'tx_to_time'   => trim((string)($_POST['tx_to_time'] ?? '')),
      'dt_op_start'  => trim((string)($_POST['dt_op_start'] ?? '')),
      'dt_op_end'    => trim((string)($_POST['dt_op_end'] ?? '')),
    ];
    foreach ($updates as $key => $val) {
      if ($val !== '' && preg_match('/^\d{2}:\d{2}$/', $val)) {
        db()->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
          ->execute([$key, $val]);
      }
    }
    echo json_encode(['ok' => true]);
  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

$range = (string)($_GET['range'] ?? 'daily');
if (!in_array($range, ['daily', 'weekly'], true)) $range = 'daily';

if ($range === 'daily') {
  $incomeRows = db()->query("
    SELECT d, SUM(total) AS total FROM (
      SELECT DATE(end_time) AS d, COALESCE(SUM(total_amount),0) AS total
      FROM game_sessions
      WHERE end_time IS NOT NULL AND end_time >= DATE_SUB(NOW(), INTERVAL 14 DAY)
      GROUP BY DATE(end_time)
      UNION ALL
      SELECT rental_date AS d, COALESCE(SUM(payment_amount),0) AS total
      FROM kubo_rentals
      WHERE status = 'completed' AND rental_date >= DATE_SUB(NOW(), INTERVAL 14 DAY)
      GROUP BY rental_date
    ) combined
    GROUP BY d
    ORDER BY d DESC
  ")->fetchAll();
} else {
  $incomeRows = db()->query("
    SELECT yw, MIN(week_start) AS week_start, SUM(total) AS total FROM (
      SELECT YEARWEEK(end_time, 1) AS yw, MIN(DATE(end_time)) AS week_start, COALESCE(SUM(total_amount),0) AS total
      FROM game_sessions
      WHERE end_time IS NOT NULL AND end_time >= DATE_SUB(NOW(), INTERVAL 12 WEEK)
      GROUP BY YEARWEEK(end_time, 1)
      UNION ALL
      SELECT YEARWEEK(rental_date, 1) AS yw, MIN(rental_date) AS week_start, COALESCE(SUM(payment_amount),0) AS total
      FROM kubo_rentals
      WHERE status = 'completed' AND rental_date >= DATE_SUB(NOW(), INTERVAL 12 WEEK)
      GROUP BY YEARWEEK(rental_date, 1)
    ) combined
    GROUP BY yw
    ORDER BY yw DESC
  ")->fetchAll();
}

$mostUsed = db()->query("
  SELECT t.table_number, COUNT(*) AS sessions_count
  FROM game_sessions gs
  JOIN tables t ON t.id = gs.table_id
  WHERE gs.end_time IS NOT NULL
    AND gs.end_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
  GROUP BY gs.table_id
  ORDER BY sessions_count DESC
  LIMIT 10
")->fetchAll();

$dtFrom = (string)($_GET['dt_from'] ?? date('Y-m-d'));
$dtTo = (string)($_GET['dt_to'] ?? date('Y-m-d'));
$dtStart = (string)($_GET['dt_start'] ?? $savedDtStart);
$dtEnd = (string)($_GET['dt_end'] ?? $savedDtEnd);

$startTs = strtotime($dtStart);
$endTs = strtotime($dtEnd);
if ($dtEnd === '00:00' || $dtEnd === '24:00') {
    // Treat as end of day
    $endTs = strtotime('23:59:59');
    if ($startTs > $endTs) $endTs += 86400; // If they put start=08:00, end=00:00, meant 08:00 to 24:00
} else if ($endTs <= $startTs) {
    $endTs += 86400;
}
$opSecondsDaily = max(0, $endTs - $startTs);

$fromTs = strtotime($dtFrom);
$toTs = strtotime($dtTo);
$days = max(1, floor(($toTs - $fromTs) / 86400) + 1);
$opSeconds = $opSecondsDaily * $days;

$dtStmt = db()->prepare("
    SELECT t.id, t.table_number, t.type,
           COALESCE(SUM(gs.duration_seconds), 0) AS played_seconds
    FROM tables t
    LEFT JOIN game_sessions gs ON gs.table_id = t.id AND DATE(gs.start_time) >= ? AND DATE(gs.start_time) <= ?
    WHERE t.is_deleted = 0 AND t.type != 'kubo'
    GROUP BY t.id, t.table_number, t.type
    ORDER BY played_seconds ASC
");
$dtStmt->execute([$dtFrom, $dtTo]);
$dtRows = $dtStmt->fetchAll();


render_header('Reports', 'reports');
?>

<div class="grid" style="grid-template-columns: 1fr; gap:14px;">
  <div class="card">
    <div class="row">
      <div>
        <div class="card__title">Transaction Export (Excel/CSV)</div>
        <div style="margin-top:6px;color:var(--muted);">Export completed transactions as CSV. Open in Excel.</div>
      </div>
    </div>

    <form method="get" action="export_transactions.php" class="row" style="margin-top:12px; gap:10px;">
      <div class="field" style="min-width:140px;">
        <div class="label">From Date</div>
        <input type="date" name="from_date" value="<?php echo h((string)($_GET['from_date'] ?? date('Y-m-d'))); ?>">
      </div>
      <div class="field" style="min-width:120px;">
        <div class="label">From Time</div>
        <input type="time" name="from_time" id="txFromTime" value="<?php echo h((string)($_GET['from_time'] ?? $savedTxFrom)); ?>">
      </div>
      <div class="field" style="min-width:140px;">
        <div class="label">To Date</div>
        <input type="date" name="to_date" value="<?php echo h((string)($_GET['to_date'] ?? date('Y-m-d'))); ?>">
      </div>
      <div class="field" style="min-width:120px;">
        <div class="label">To Time</div>
        <input type="time" name="to_time" id="txToTime" value="<?php echo h((string)($_GET['to_time'] ?? $savedTxTo)); ?>">
      </div>
      <div class="field" style="align-self:end;">
        <button class="btn" type="submit">Export to Excel</button>
      </div>
    </form>
    <div id="txSaveStatus" style="margin-top:6px; font-size:11px; color:#22c55e; display:none;">✓ Saved</div>
  </div>
</div>

<div class="grid" style="grid-template-columns: 1fr; gap:14px;">
  <div class="card">
    <div class="row">
      <div>
        <div class="card__title">Income Report</div>
        <div style="margin-top:6px;color:var(--muted);">Daily or weekly totals (based on completed sessions).</div>
      </div>
      <div class="spacer"></div>
      <a class="btn <?php echo $range === 'daily' ? '' : 'btn--ghost'; ?>" href="reports.php?range=daily">Daily</a>
      <a class="btn <?php echo $range === 'weekly' ? '' : 'btn--ghost'; ?>" href="reports.php?range=weekly">Weekly</a>
    </div>

    <div style="overflow:auto; margin-top:12px;">
      <table class="table">
        <thead>
          <tr>
            <th><?php echo $range === 'daily' ? 'Date' : 'Week'; ?></th>
            <th>Total Income</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($incomeRows as $r): ?>
            <tr>
              <td>
                <?php echo $range === 'daily'
                  ? h((string)$r['d'])
                  : ('Week of ' . h((string)$r['week_start'])); ?>
              </td>
              <td><strong><?php echo money((float)$r['total']); ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="grid grid--cards">
    <div class="card col-6">
      <div class="card__title">Most Used Tables (Last 30 days)</div>
      <div style="overflow:auto; margin-top:12px;">
        <table class="table">
          <thead>
            <tr>
              <th>Table</th>
              <th>Sessions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($mostUsed as $r): ?>
              <tr>
                <td><strong><?php echo h($r['table_number']); ?></strong></td>
                <td><?php echo (int)$r['sessions_count']; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>


    <div class="card col-6">
      <div class="card__title">Dead Time Summary</div>
      <div style="margin-top:6px;color:var(--muted);">Compute inactive hours per table.</div>
      
      <form method="get" action="reports.php" class="row" style="margin-top:12px; gap:10px; align-items:flex-end;">
        <div class="field" style="flex:1;">
          <div class="label">From Date</div>
          <input type="date" name="dt_from" value="<?php echo h($dtFrom); ?>">
        </div>
        <div class="field" style="flex:1;">
          <div class="label">To Date</div>
          <input type="date" name="dt_to" value="<?php echo h($dtTo); ?>">
        </div>
        <div class="field" style="flex:1;">
          <div class="label">Op. Start</div>
          <input type="time" name="dt_start" id="dtOpStart" value="<?php echo h($dtStart); ?>">
        </div>
        <div class="field" style="flex:1;">
          <div class="label">Op. End</div>
          <input type="time" name="dt_end" id="dtOpEnd" value="<?php echo h($dtEnd); ?>">
        </div>
        <div class="field">
          <button class="btn" type="submit">Filter</button>
        </div>
      </form>
      <div id="dtSaveStatus" style="margin-top:6px; font-size:11px; color:#22c55e; display:none;">✓ Saved</div>

      <form method="get" action="export_deadtime.php" style="margin-top:8px;">
        <input type="hidden" name="dt_from" value="<?php echo h($dtFrom); ?>">
        <input type="hidden" name="dt_to" value="<?php echo h($dtTo); ?>">
        <input type="hidden" name="dt_start" value="<?php echo h($dtStart); ?>">
        <input type="hidden" name="dt_end" value="<?php echo h($dtEnd); ?>">
        <button class="btn btn--ghost btn--block" type="submit">📥 Export to Excel</button>
      </form>

      <div style="overflow:auto; margin-top:12px; max-height:250px;">
        <table class="table">
          <thead>
            <tr>
              <th>Table</th>
              <th>Played Hrs</th>
              <th>Dead Time</th>
              <th>Util (%)</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $totP = 0; $totD = 0;
            foreach ($dtRows as $r): 
               $playedSecs = (int)$r['played_seconds'];
               $deadSecs = max(0, $opSeconds - $playedSecs);
               
               $totP += $playedSecs;
               $totD += $deadSecs;

               $playedHrs = floor($playedSecs / 3600);
               $playedMins = round(($playedSecs % 3600) / 60);
               $deadHrs = floor($deadSecs / 3600);
               $deadMins = round(($deadSecs % 3600) / 60);
               
               if ($playedMins == 60) { $playedHrs++; $playedMins = 0; }
               if ($deadMins == 60) { $deadHrs++; $deadMins = 0; }
               
               $util = $opSeconds > 0 ? round(($playedSecs / $opSeconds) * 100, 1) : 0;

               $badge = '';
               if ($r['type'] === 'vip') $badge = '<span class="badge badge--vip" style="font-size:10px;">VIP</span>';
               else if ($r['type'] === 'ktv') $badge = '<span class="badge" style="font-size:10px; background:rgba(168, 85, 247, 0.2); color:#c084fc;">KTV</span>';
            ?>
              <tr>
                <td><strong><?php echo h($r['table_number']); ?></strong> <?php echo $badge; ?></td>
                <td><?php echo "{$playedHrs}h {$playedMins}m"; ?></td>
                <td style="color:var(--danger); font-weight:700;"><?php echo "{$deadHrs}h {$deadMins}m"; ?></td>
                <td><?php echo $util . '%'; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <?php 
             $totPHrs = floor($totP / 3600);
             $totPMins = round(($totP % 3600) / 60);
             if ($totPMins == 60) { $totPHrs++; $totPMins = 0; }

             $totDHrs = floor($totD / 3600);
             $totDMins = round(($totD % 3600) / 60);
             if ($totDMins == 60) { $totDHrs++; $totDMins = 0; }

             $totOp = $opSeconds * count($dtRows);
             $oUtil = $totOp > 0 ? round(($totP / $totOp) * 100, 1) : 0;
          ?>
          <tfoot>
            <tr style="background:#f1f5f9; font-weight:700;">
              <td style="text-align:right;">TOTAL</td>
              <td><?php echo "{$totPHrs}h {$totPMins}m"; ?></td>
              <td style="color:var(--danger);"><?php echo "{$totDHrs}h {$totDMins}m"; ?></td>
              <td><?php echo $oUtil . '%'; ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

  </div>
</div>

<script>
// Auto-save time settings to database
function saveReportSettings(statusElId) {
  const txFrom = document.getElementById('txFromTime');
  const txTo   = document.getElementById('txToTime');
  const dtStart = document.getElementById('dtOpStart');
  const dtEnd   = document.getElementById('dtOpEnd');
  const statusEl = document.getElementById(statusElId);

  const body = new URLSearchParams();
  body.set('action', 'save_report_settings');
  if (txFrom) body.set('tx_from_time', txFrom.value);
  if (txTo)   body.set('tx_to_time', txTo.value);
  if (dtStart) body.set('dt_op_start', dtStart.value);
  if (dtEnd)   body.set('dt_op_end', dtEnd.value);

  fetch('reports.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: body.toString()
  })
  .then(r => r.json())
  .then(data => {
    if (statusEl) {
      statusEl.textContent = data.ok ? '✓ Time saved' : '✗ Error saving';
      statusEl.style.color = data.ok ? '#22c55e' : '#ef4444';
      statusEl.style.display = 'block';
      setTimeout(() => { statusEl.style.display = 'none'; }, 2500);
    }
  })
  .catch(() => {
    if (statusEl) {
      statusEl.textContent = '✗ Save failed';
      statusEl.style.color = '#ef4444';
      statusEl.style.display = 'block';
      setTimeout(() => { statusEl.style.display = 'none'; }, 2500);
    }
  });
}

// Attach auto-save to Transaction Export time pickers
['txFromTime', 'txToTime'].forEach(id => {
  const el = document.getElementById(id);
  if (el) el.addEventListener('change', () => saveReportSettings('txSaveStatus'));
});

// Attach auto-save to Dead Time time pickers
['dtOpStart', 'dtOpEnd'].forEach(id => {
  const el = document.getElementById(id);
  if (el) el.addEventListener('change', () => saveReportSettings('dtSaveStatus'));
});
</script>

<?php render_footer(); ?>
