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
    ('dt_op_end', '00:00'),
    ('morning_shift_start', '08:00'),
    ('evening_shift_start', '16:30'),
    ('night_shift_end', '02:30')
  ");
} catch (Throwable $ignore) {}

// ── Load saved time settings ──
$savedTxFrom = '08:00';
$savedTxTo = '05:00';
$savedDtStart = '08:00';
$savedDtEnd = '00:00';
$savedMorning = '08:00';
$savedEvening = '16:30';
$savedNightEnd = '02:30';
try {
  $ssStmt = db()->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('tx_from_time','tx_to_time','dt_op_start','dt_op_end','morning_shift_start','evening_shift_start','night_shift_end')");
  foreach ($ssStmt->fetchAll() as $ss) {
    if ($ss['setting_key'] === 'tx_from_time') $savedTxFrom = $ss['setting_value'];
    if ($ss['setting_key'] === 'tx_to_time') $savedTxTo = $ss['setting_value'];
    if ($ss['setting_key'] === 'dt_op_start') $savedDtStart = $ss['setting_value'];
    if ($ss['setting_key'] === 'dt_op_end') $savedDtEnd = $ss['setting_value'];
    if ($ss['setting_key'] === 'morning_shift_start') $savedMorning = $ss['setting_value'];
    if ($ss['setting_key'] === 'evening_shift_start') $savedEvening = $ss['setting_value'];
    if ($ss['setting_key'] === 'night_shift_end') $savedNightEnd = $ss['setting_value'];
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
      'morning_shift_start' => trim((string)($_POST['morning_shift_start'] ?? '')),
      'evening_shift_start' => trim((string)($_POST['evening_shift_start'] ?? '')),
      'night_shift_end'     => trim((string)($_POST['night_shift_end'] ?? '')),
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

// ── Fetch Voided Sessions ──
$voidStmt = db()->query("
  SELECT gs.*, t.table_number, u.username as cashier_name
  FROM game_sessions gs
  JOIN tables t ON t.id = gs.table_id
  LEFT JOIN users u ON u.id = gs.created_by
  WHERE gs.is_voided = 1
  ORDER BY gs.end_time DESC
  LIMIT 20
");
$voidRows = $voidStmt->fetchAll();

render_header('Reports', 'reports');
?>

<div class="grid" style="grid-template-columns: 1fr; gap:14px;">
  <div class="card">
    <div class="row">
      <div>
        <div class="card__title">Transaction Export (Excel)</div>
        <div style="margin-top:6px;color:var(--muted);">Export completed transactions per shift. Set the shift times below.</div>
      </div>
    </div>

    <!-- Date Selector -->
    <div class="row" style="margin-top:14px; gap:10px; align-items:flex-end; flex-wrap:wrap;">
      <div class="field" style="min-width:150px;">
        <div class="label">Date</div>
        <input type="date" id="txExportDate" value="<?php echo h((string)($_GET['from_date'] ?? date('Y-m-d'))); ?>">
      </div>
    </div>

    <!-- Shift Settings -->
    <div style="margin-top:16px; padding-top:14px; border-top:1px solid var(--border);">
      <div style="font-size:13px; font-weight:700; color:var(--text); margin-bottom:10px;">⏰ Shift Settings</div>
      <div class="row" style="gap:10px; align-items:flex-end; flex-wrap:wrap;">
        <div class="field" style="min-width:140px;">
          <div class="label">Morning Shift Start</div>
          <input type="time" id="rptMorningStart" value="<?php echo h($savedMorning); ?>" style="font-size:13px;">
        </div>
        <div class="field" style="min-width:140px;">
          <div class="label">Night Shift Start</div>
          <input type="time" id="rptEveningStart" value="<?php echo h($savedEvening); ?>" style="font-size:13px;">
        </div>
        <div class="field" style="min-width:140px;">
          <div class="label">Night Shift End</div>
          <input type="time" id="rptNightEnd" value="<?php echo h($savedNightEnd); ?>" style="font-size:13px;">
        </div>
      </div>
      <div style="margin-top:8px; font-size:11px; color:var(--muted);">
        ☀️ Morning: <strong id="rptMorningLabel"><?php echo date('g:i A', strtotime($savedMorning)); ?></strong> – <strong id="rptMorningEndLabel"><?php echo date('g:i A', strtotime($savedEvening)); ?></strong>
        &nbsp;|&nbsp;
        🌙 Night: <strong id="rptNightLabel"><?php echo date('g:i A', strtotime($savedEvening)); ?></strong> – <strong id="rptNightEndLabel"><?php echo date('g:i A', strtotime($savedNightEnd)); ?></strong> (next day)
        &nbsp;— Times auto-save on change.
      </div>
      <div id="shiftSaveStatus" style="margin-top:4px; font-size:11px; color:#22c55e; display:none;">✓ Saved</div>
    </div>

    <!-- Shift Export Buttons -->
    <div class="row" style="margin-top:14px; gap:10px; flex-wrap:wrap;">
      <button class="btn" type="button" onclick="exportShiftTransactions('morning')" style="background:#38bdf8; color:white; border:none; font-size:13px; padding:10px 20px;">
        ☀️ Export Morning Shift
      </button>
      <button class="btn" type="button" onclick="exportShiftTransactions('evening')" style="background:#6366f1; color:white; border:none; font-size:13px; padding:10px 20px;">
        🌙 Export Night Shift
      </button>
      <button class="btn" type="button" onclick="exportShiftTransactions('both')" style="background:#22c55e; color:white; border:none; font-size:13px; padding:10px 20px;">
        📊 Export Both (Full Day)
      </button>
    </div>
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

      <form method="get" action="exports/export_deadtime.php" style="margin-top:8px;">
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

  <!-- Voided Sessions Section -->
  <div class="card" style="margin-top:14px; border-left:4px solid #ef4444;">
    <div class="row">
      <div>
        <div class="card__title" style="color:#ef4444;">Voided Sessions (Recent)</div>
        <div style="margin-top:6px;color:var(--muted);">Sessions that were cancelled or placed on the wrong table.</div>
      </div>
    </div>
    <div style="overflow:auto; margin-top:12px; max-height:250px;">
      <table class="table">
        <thead>
          <tr>
            <th>Date & Time</th>
            <th>Table</th>
            <th>Cashier</th>
            <th>Reason</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($voidRows)): ?>
            <tr><td colspan="4" style="text-align:center; color:var(--muted);">No voided sessions recently.</td></tr>
          <?php else: ?>
            <?php foreach ($voidRows as $v): ?>
              <tr>
                <td><?php echo date('M j, Y g:i A', strtotime($v['end_time'])); ?></td>
                <td><strong><?php echo h($v['table_number']); ?></strong></td>
                <td><?php echo h($v['cashier_name'] ?? 'Unknown'); ?></td>
                <td style="color:#ef4444; font-weight:500;"><?php echo h($v['void_reason'] ?: 'No Reason'); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script>
// Format time string (HH:MM) to 12-hour AM/PM
function fmt12(timeStr) {
  const [h, m] = timeStr.split(':').map(Number);
  const ampm = h >= 12 ? 'PM' : 'AM';
  const hr = h % 12 || 12;
  return hr + ':' + String(m).padStart(2, '0') + ' ' + ampm;
}

// Update shift labels when time inputs change
function updateShiftLabels() {
  const mStart = document.getElementById('rptMorningStart');
  const eStart = document.getElementById('rptEveningStart');
  const nEnd   = document.getElementById('rptNightEnd');
  if (mStart) document.getElementById('rptMorningLabel').textContent = fmt12(mStart.value);
  if (eStart) {
    document.getElementById('rptMorningEndLabel').textContent = fmt12(eStart.value);
    document.getElementById('rptNightLabel').textContent = fmt12(eStart.value);
  }
  if (nEnd) document.getElementById('rptNightEndLabel').textContent = fmt12(nEnd.value);
}

// Export transactions for a specific shift
function exportShiftTransactions(shift) {
  const dateVal = document.getElementById('txExportDate').value;
  const morningStart = document.getElementById('rptMorningStart').value;
  const eveningStart = document.getElementById('rptEveningStart').value;
  const nightEnd     = document.getElementById('rptNightEnd').value;

  let fromDate, fromTime, toDate, toTime, shiftLabel;

  if (shift === 'morning') {
    fromDate = dateVal;
    fromTime = morningStart;
    toDate   = dateVal;
    toTime   = eveningStart;
    shiftLabel = 'morning';
  } else if (shift === 'evening') {
    fromDate = dateVal;
    fromTime = eveningStart;
    toDate   = dateVal;  // overnight auto-adjusted by export script
    toTime   = nightEnd;
    shiftLabel = 'night';
  } else {
    // both — full day from morning start to night end
    fromDate = dateVal;
    fromTime = morningStart;
    toDate   = dateVal;  // overnight auto-adjusted by export script
    toTime   = nightEnd;
    shiftLabel = 'both';
  }

  const url = 'exports/export_transactions.php'
    + '?from_date=' + encodeURIComponent(fromDate)
    + '&from_time=' + encodeURIComponent(fromTime)
    + '&to_date='   + encodeURIComponent(toDate)
    + '&to_time='   + encodeURIComponent(toTime)
    + '&shift='     + encodeURIComponent(shiftLabel);

  window.open(url, '_blank');
}

// Auto-save ALL settings to database
function saveReportSettings(statusElId) {
  const statusEl = document.getElementById(statusElId);

  const body = new URLSearchParams();
  body.set('action', 'save_report_settings');

  // Dead Time settings
  const dtStart = document.getElementById('dtOpStart');
  const dtEnd   = document.getElementById('dtOpEnd');
  if (dtStart) body.set('dt_op_start', dtStart.value);
  if (dtEnd)   body.set('dt_op_end', dtEnd.value);

  // Shift settings
  const mStart = document.getElementById('rptMorningStart');
  const eStart = document.getElementById('rptEveningStart');
  const nEnd   = document.getElementById('rptNightEnd');
  if (mStart) body.set('morning_shift_start', mStart.value);
  if (eStart) body.set('evening_shift_start', eStart.value);
  if (nEnd)   body.set('night_shift_end', nEnd.value);

  fetch('reports.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: body.toString()
  })
  .then(r => r.json())
  .then(data => {
    if (statusEl) {
      statusEl.textContent = data.ok ? '✓ Saved' : '✗ Error saving';
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

// Attach auto-save + label update to Shift Settings
['rptMorningStart', 'rptEveningStart', 'rptNightEnd'].forEach(id => {
  const el = document.getElementById(id);
  if (el) el.addEventListener('change', () => { updateShiftLabels(); saveReportSettings('shiftSaveStatus'); });
});

// Attach auto-save to Dead Time time pickers
['dtOpStart', 'dtOpEnd'].forEach(id => {
  const el = document.getElementById(id);
  if (el) el.addEventListener('change', () => saveReportSettings('dtSaveStatus'));
});
</script>

<?php render_footer(); ?>
