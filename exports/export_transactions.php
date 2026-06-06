<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/util.php';

start_app_session();
require_role(['admin', 'cashier']);

try {
  db()->exec("ALTER TABLE game_sessions ADD COLUMN loyalty_hours INT NOT NULL DEFAULT 0");
} catch (Throwable $e) {}

$morningStart = '08:00';
$nightEnd = '02:30';
try {
  $ssStmt = db()->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('morning_shift_start','night_shift_end')");
  foreach ($ssStmt->fetchAll() as $ss) {
    if ($ss['setting_key'] === 'morning_shift_start') $morningStart = $ss['setting_value'];
    if ($ss['setting_key'] === 'night_shift_end') $nightEnd = $ss['setting_value'];
  }
} catch (Throwable $ignore) {}

$fromDateStr = trim((string) ($_GET['from_date'] ?? $_GET['from'] ?? date('Y-m-d')));
$fromTimeStr = trim((string) ($_GET['from_time'] ?? ''));
if ($fromTimeStr === '') $fromTimeStr = $morningStart;

$toDateStr = trim((string) ($_GET['to_date'] ?? $_GET['to'] ?? date('Y-m-d')));
$toTimeStr = trim((string) ($_GET['to_time'] ?? ''));
if ($toTimeStr === '') $toTimeStr = $nightEnd;

// Auto-adjust overnight shifts (e.g. 08:00 AM to 02:30 AM the next day)
// If the to_time is numerically earlier than from_time, it implies the shift crosses midnight.
// Therefore, the end boundary should be the next calendar day relative to the selected 'to' date.
if ($fromTimeStr > $toTimeStr) {
  $toDateStr = date('Y-m-d', strtotime($toDateStr . ' +1 day'));
}

$from = $fromDateStr ? date('Y-m-d H:i:s', strtotime("{$fromDateStr} {$fromTimeStr}")) : null;
$to = $toDateStr ? date('Y-m-d H:i:s', strtotime("{$toDateStr} {$toTimeStr}")) : null;

$customerId = (int) ($_GET['customer_id'] ?? 0);

$where = ["gs.end_time IS NOT NULL", "gs.is_voided = 0"];
$params = [];

if ($from) {
  $where[] = "gs.end_time >= ?";
  $params[] = $from;
}

if ($to) {
  $where[] = "gs.end_time <= ?";
  $params[] = $to;
}

if ($customerId > 0) {
  $where[] = "gs.customer_id = ?";
  $params[] = $customerId;
}

$sql = "
  SELECT
    gs.id AS session_id,
    t.table_number AS table_number,
    t.type AS table_type,
    gs.start_time,
    gs.end_time,
    gs.scheduled_end_time,
    gs.duration_seconds,
    COALESCE(c.name, NULLIF(gs.walk_in_name, ''), 'Walk-in') AS player_name,
    COALESCE(gs.total_amount, 0) AS total_cost,
    SUM(tx.payment) AS payment,
    SUM(tx.change_amount) AS change_amount,
    gs.karaoke_included,
    gs.is_promo,
    COALESCE(gs.loyalty_hours, 0) AS loyalty_hours,
    gs.rate_per_hour,
    MIN(u.username) AS cashier,
    COUNT(tx.id) AS tx_count
  FROM game_sessions gs
  JOIN tables t ON t.id = gs.table_id
  LEFT JOIN transactions tx ON gs.id = tx.session_id
  LEFT JOIN customers c ON c.id = gs.customer_id
  LEFT JOIN users u ON u.id = tx.created_by
  WHERE " . implode(' AND ', $where) . "
  GROUP BY gs.id
  ORDER BY gs.end_time DESC
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filenameFrom = $from ? date('Y-m-d_Hi', strtotime($from)) : 'Start';
$filenameTo = $to ? date('Y-m-d_Hi', strtotime($to)) : 'Present';

$shift = trim((string)($_GET['shift'] ?? ''));
$shiftLabels = ['morning' => 'Morning Shift', 'night' => 'Night Shift', 'both' => 'Full Day (Both Shifts)'];
$shiftLabel = $shiftLabels[$shift] ?? '';
$shiftSuffix = $shiftLabel !== '' ? '_' . str_replace(' ', '_', $shiftLabels[$shift]) : '';

$exportFilename = "Billiards_Transactions_{$filenameFrom}_to_{$filenameTo}{$shiftSuffix}.xls";

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $exportFilename . '"');

echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
echo '<head><meta charset="UTF-8"></head><body>';

echo '<table border="1" style="border-collapse: collapse; font-family: Calibri, sans-serif;">';

// Headers matching the screenshot exactly
echo '<tr>';
echo '<th style="background-color: #2F5496; color: white; font-weight: bold; text-align: center;">Transaction ID</th>';
echo '<th style="background-color: #2F5496; color: white; font-weight: bold; text-align: center;">Table</th>';
echo '<th style="background-color: #2F5496; color: white; font-weight: bold; text-align: center;">Player</th>';
echo '<th style="background-color: #2F5496; color: white; font-weight: bold; text-align: center;">Time Range</th>';
echo '<th style="background-color: #2F5496; color: white; font-weight: bold; text-align: center;">Duration</th>';
echo '<th style="background-color: #2F5496; color: white; font-weight: bold; text-align: center;">Total Cost (P)</th>';
echo '<th style="background-color: #2F5496; color: white; font-weight: bold; text-align: center;">Cashier</th>';
echo '<th style="background-color: #2F5496; color: white; font-weight: bold; text-align: center;">Transaction Date</th>';
echo '</tr>';

$rowIndex = 0;
foreach ($rows as $r) {
    $dur = (int) ($r['duration_seconds'] ?? 0);
    $h = intdiv($dur, 3600);
    $m = intdiv($dur % 3600, 60);
    $s = $dur % 60;
    $durationFmt = sprintf('%d:%02d:%02d', $h, $m, $s);
    
    $startTimeStr = date('g:i A', strtotime($r['start_time']));
    $schedEndTimeStr = !empty($r['scheduled_end_time']) ? date('g:i A', strtotime($r['scheduled_end_time'])) : date('g:i A', strtotime($r['end_time']));
    $gameTime = $startTimeStr . ' - ' . $schedEndTimeStr;
    
    // Exact format: '06/01/2026 10:38 AM
    $transactionDate = "'" . date('m/d/Y h:i A', strtotime($r['end_time']));
    
    $tableName = 'Table ' . preg_replace('/[^0-9]/', '', (string)$r['table_number']);
    if ($r['table_type'] === 'vip') $tableName = 'VIP Room ' . preg_replace('/[^0-9]/', '', (string)$r['table_number']);
    if ($r['table_type'] === 'ktv') $tableName = 'KTV Room ' . preg_replace('/[^0-9]/', '', (string)$r['table_number']);
    if (!empty($r['karaoke_included'])) $tableName .= ' (KTV)';
    
    $playerName = (string) $r['player_name'];
    if (!empty($r['is_promo'])) {
        $playerName .= ' + EB';
    }
    
    $cost = (float) $r['total_cost'];
    
    $bgColor = ($rowIndex % 2 === 0) ? '#D9E1F2' : '#FFFFFF';
    
    // Loyalty and promo overrides
    if (!empty($r['loyalty_hours'])) {
        $bgColor = '#fdba74';
    } elseif (!empty($r['is_promo'])) {
        $bgColor = '#fbcfe8';
    }

    echo '<tr style="background-color: ' . $bgColor . ';">';
    echo '<td style="text-align: center; border: 1px solid #ccc;">TX-' . htmlspecialchars((string) $r['session_id']) . '</td>';
    echo '<td style="text-align: center; border: 1px solid #ccc;">' . htmlspecialchars($tableName) . '</td>';
    echo '<td style="text-align: center; border: 1px solid #ccc;">' . htmlspecialchars($playerName) . '</td>';
    echo '<td style="text-align: center; border: 1px solid #ccc;">' . htmlspecialchars($gameTime) . '</td>';
    echo '<td style="text-align: center; border: 1px solid #ccc;">' . htmlspecialchars($durationFmt) . '</td>';
    echo '<td style="text-align: center; border: 1px solid #ccc;" x:num="' . $cost . '">' . $cost . '</td>';
    echo '<td style="text-align: center; border: 1px solid #ccc;">' . htmlspecialchars((string) $r['cashier']) . '</td>';
    echo '<td style="text-align: center; border: 1px solid #ccc;">' . htmlspecialchars($transactionDate) . '</td>';
    echo '</tr>';
    
    $rowIndex++;
}

echo '</table>';


// ==========================================
// VOIDED SESSIONS TABLE
// ==========================================
$voidWhere = ["gs.end_time IS NOT NULL", "gs.is_voided = 1"];
$voidParams = [];
if ($from) { $voidWhere[] = "gs.end_time >= ?"; $voidParams[] = $from; }
if ($to) { $voidWhere[] = "gs.end_time <= ?"; $voidParams[] = $to; }
if ($customerId > 0) { $voidWhere[] = "gs.customer_id = ?"; $voidParams[] = $customerId; }

$voidStmt = db()->prepare("
  SELECT 
    gs.id AS session_id,
    t.table_number,
    gs.start_time,
    gs.end_time,
    gs.void_reason,
    gs.total_amount,
    COALESCE(c.name, NULLIF(gs.walk_in_name, ''), 'Walk-in') AS player_name,
    u.username AS cashier
  FROM game_sessions gs
  JOIN tables t ON t.id = gs.table_id
  LEFT JOIN customers c ON c.id = gs.customer_id
  LEFT JOIN users u ON u.id = gs.created_by
  WHERE " . implode(' AND ', $voidWhere) . "
  ORDER BY gs.end_time DESC
");
$voidStmt->execute($voidParams);
$voidRows = $voidStmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($voidRows)) {
  echo '<br><br>';
  echo '<table border="1" style="font-family: Calibri, sans-serif; margin-top:20px;">';
  echo '<tr>';
  echo '<th style="background-color: #ef4444; color: white; font-weight: bold; font-size: 14px; padding: 5px;">VOIDED SESSIONS</th>' . str_repeat('<th style="background-color: #ef4444;"></th>', 7);
  echo '</tr>';
  echo '<tr>';
  echo '<th style="background-color: #fca5a5; font-weight: bold;">Session ID</th>';
  echo '<th style="background-color: #fca5a5; font-weight: bold;">Date Voided</th>';
  echo '<th style="background-color: #fca5a5; font-weight: bold;">Table</th>';
  echo '<th style="background-color: #fca5a5; font-weight: bold;">Player</th>';
  echo '<th style="background-color: #fca5a5; font-weight: bold;">Reason for Void</th>';
  echo '<th style="background-color: #fca5a5; font-weight: bold;">Cashier</th>';
  echo '<th style="background-color: #fca5a5; font-weight: bold;">Running Time Before Void</th>';
  echo '<th style="background-color: #fca5a5; font-weight: bold;">Amount Voided</th>';
  echo '</tr>';

  $totalVoidedAmount = 0;

  foreach ($voidRows as $vr) {
    $vEndTs = strtotime($vr['end_time']);
    $vStartTs = strtotime($vr['start_time']);
    $vDurSecs = max(0, $vEndTs - $vStartTs);
    $vh = floor($vDurSecs / 3600);
    $vm = floor(($vDurSecs % 3600) / 60);
    $vs = $vDurSecs % 60;
    $vDurFmt = sprintf('%02d:%02d:%02d', $vh, $vm, $vs);
    
    $totalVoidedAmount += (float)$vr['total_amount'];
    
    echo '<tr>';
    echo '<td style="text-align: center;">' . htmlspecialchars((string) $vr['session_id']) . '</td>';
    echo '<td style="text-align: center;">' . htmlspecialchars("'" . date('m/d/Y h:i A', $vEndTs)) . '</td>';
    echo '<td style="text-align: center;">' . htmlspecialchars((string) $vr['table_number']) . '</td>';
    echo '<td style="text-align: center;">' . htmlspecialchars((string) $vr['player_name']) . '</td>';
    echo '<td style="color: #ef4444; font-weight: bold;">' . htmlspecialchars((string) $vr['void_reason']) . '</td>';
    echo '<td style="text-align: center;">' . htmlspecialchars((string) $vr['cashier']) . '</td>';
    echo '<td style="text-align: center;">' . htmlspecialchars($vDurFmt) . '</td>';
    echo '<td style="text-align: right; color: #ef4444; font-weight: bold;">₱' . number_format((float) $vr['total_amount'], 2) . '</td>';
    echo '</tr>';
  }
  
  echo '<tr>';
  echo str_repeat('<td></td>', 6) . '<td style="text-align: right; font-weight: bold;">TOTAL VOIDED AMOUNT:</td>';
  echo '<td style="text-align: right; color: #ef4444; font-weight: bold; font-size: 14px;">₱' . number_format($totalVoidedAmount, 2) . '</td>';
  echo '</tr>';
  
  echo '</table>';
}

echo '</body></html>';
exit;
