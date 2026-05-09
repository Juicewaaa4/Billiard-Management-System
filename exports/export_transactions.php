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

$fromDateStr = trim((string) ($_GET['from_date'] ?? $_GET['from'] ?? date('Y-m-d')));
$fromTimeStr = trim((string) ($_GET['from_time'] ?? ''));
if ($fromTimeStr === '') $fromTimeStr = '00:00';

$toDateStr = trim((string) ($_GET['to_date'] ?? $_GET['to'] ?? date('Y-m-d')));
$toTimeStr = trim((string) ($_GET['to_time'] ?? ''));
if ($toTimeStr === '') $toTimeStr = '23:59';

// Auto-adjust overnight shifts (e.g. 08:00 AM to 05:00 AM the next day)
// If the user selects the same calendar date for both fields but the to_time is earlier than from_time,
// it logically implies they meant 5:00 AM of the NEXT morning.
if ($fromDateStr === $toDateStr && $fromTimeStr > $toTimeStr) {
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

// Shift & time range info header
if ($shiftLabel !== '' || $from || $to) {
  echo '<table border="0" style="margin-bottom:8px;">';
  if ($shiftLabel !== '') {
    echo '<tr><td colspan="15" style="font-size:16px; font-weight:bold; color:#1e293b;">' . htmlspecialchars($shiftLabel) . '</td></tr>';
  }
  $fromDisplay = $from ? date('M j, Y g:i A', strtotime($from)) : '';
  $toDisplay = $to ? date('M j, Y g:i A', strtotime($to)) : '';
  if ($fromDisplay || $toDisplay) {
    echo '<tr><td colspan="15" style="font-size:12px; color:#64748b;">Period: ' . htmlspecialchars($fromDisplay) . ' — ' . htmlspecialchars($toDisplay) . '</td></tr>';
  }
  echo '<tr><td colspan="15"></td></tr>';
  echo '</table>';
}

$morningStart = '08:00';
$eveningStart = '16:30';
try {
  $ssStmt = db()->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('morning_shift_start','evening_shift_start')");
  foreach ($ssStmt->fetchAll() as $ss) {
    if ($ss['setting_key'] === 'morning_shift_start') $morningStart = $ss['setting_value'];
    if ($ss['setting_key'] === 'evening_shift_start') $eveningStart = $ss['setting_value'];
  }
} catch (Throwable $ignore) {}
$mStartMins = ((int)explode(':', $morningStart)[0]) * 60 + ((int)(explode(':', $morningStart)[1] ?? 0));
$eStartMins = ((int)explode(':', $eveningStart)[0]) * 60 + ((int)(explode(':', $eveningStart)[1] ?? 0));

function getShiftTrans($startTime, $mMin, $eMin) {
    $ts = strtotime($startTime);
    $hm = ((int)date('G', $ts)) * 60 + ((int)date('i', $ts));
    if ($eMin > $mMin) {
        return ($hm >= $mMin && $hm < $eMin) ? 'MORNING' : 'EVENING';
    } else {
        return ($hm >= $mMin || $hm < $eMin) ? 'MORNING' : 'EVENING';
    }
}

$shifts = [
  'MORNING' => ['rows' => [], 'breakdown' => []],
  'EVENING' => ['rows' => [], 'breakdown' => []]
];

for($i=1; $i<=8; $i++) {
   $shifts['MORNING']['breakdown']["TABLE $i"] = 0;
   $shifts['EVENING']['breakdown']["TABLE $i"] = 0;
}
$shifts['MORNING']['breakdown']["VIP ROOM"] = 0;
$shifts['MORNING']['breakdown']["KTV ROOM"] = 0;
$shifts['EVENING']['breakdown']["VIP ROOM"] = 0;
$shifts['EVENING']['breakdown']["KTV ROOM"] = 0;

foreach ($rows as $r) {
   $shift = getShiftTrans($r['start_time'], $mStartMins, $eStartMins);
   
   $tName = strtoupper($r['table_number']);
   $tType = $r['table_type'];
   
   if ($tType === 'vip') $key = 'VIP ROOM';
   elseif ($tType === 'ktv') $key = 'KTV ROOM';
   else $key = 'TABLE ' . preg_replace('/[^0-9]/', '', $tName);
   
   if (!isset($shifts[$shift]['breakdown'][$key])) {
       $shifts[$shift]['breakdown'][$key] = 0;
   }
   
   $shifts[$shift]['breakdown'][$key] += (float)$r['total_cost'];
   
   if ($tType === 'regular') {
       // Only regular tables go to the main list
       $shifts[$shift]['rows'][] = $r;
   }
}

echo '<table border="0" style="border-collapse: collapse;">';

$headersHtml = '
    <th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center; border: 1px solid #ccc;">Session ID</th>
    <th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center; border: 1px solid #ccc;">Table</th>
    <th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center; border: 1px solid #ccc;">Promo Applied</th>
    <th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center; border: 1px solid #ccc;">Start Time</th>
    <th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center; border: 1px solid #ccc;">Expected End Time</th>
    <th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center; border: 1px solid #ccc;">Transaction Date</th>
    <th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center; border: 1px solid #ccc;">Extended?</th>
    <th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center; border: 1px solid #ccc;">Duration (HH:MM:SS)</th>
    <th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center; border: 1px solid #ccc;">Player Name</th>
    <th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center; border: 1px solid #ccc;">Cashier</th>
    <th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: right; border: 1px solid #ccc;">Total Cost</th>
    <th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: right; border: 1px solid #ccc;">Rate per Hour</th>';

$excelRow = 2; // Approximate row where data starts

foreach (['MORNING', 'EVENING'] as $shiftKey) {
   $sRows = $shifts[$shiftKey]['rows'];
   $bDown = $shifts[$shiftKey]['breakdown'];
   $bKeys = array_keys($bDown);
   
   $numBreakdownRows = count($bKeys) + 2; // header + data + total
   $numMainRows = count($sRows) + 1; // data + total
   
   $maxRows = max($numMainRows, $numBreakdownRows);
   
   echo '<tr>' . $headersHtml . '<td style="border:none; width:20px;"></td><td colspan="2" style="border:none;"></td></tr>';
   $excelRow++;
   
   $shiftTotalCost = 0;
   $totalFormulaStart = $excelRow + 1; // Row where breakdown data begins (+1 for the header)
   $totalFormulaEnd = $excelRow + count($bKeys); 
   
   for ($i = 0; $i < $maxRows; $i++) {
      echo '<tr>';
      
      // -- MAIN TABLE COLUMNS (12 cols) --
      if ($i < count($sRows)) {
         $r = $sRows[$i];
         $dur = (int) ($r['duration_seconds'] ?? 0);
         $h = intdiv($dur, 3600);
         $m = intdiv($dur % 3600, 60);
         $s = $dur % 60;
         $durationFmt = sprintf('%02d:%02d:%02d', $h, $m, $s);
         
         $startTime = "'" . date('m/d/Y h:i A', strtotime($r['start_time']));
         $endTime = "'" . date('m/d/Y h:i A', strtotime($r['end_time']));
         $expectedEnd = !empty($r['scheduled_end_time']) ? "'" . date('m/d/Y h:i A', strtotime($r['scheduled_end_time'])) : 'N/A';
         $tableName = (string) $r['table_number'];
         if (!empty($r['karaoke_included'])) $tableName .= ' (With Karaoke)';
         $isExtended = ($r['tx_count'] > 1) ? 'Yes' : 'No';
         $promoStr = !empty($r['is_promo']) ? 'Early Bird (50%)' : 'None';
         $cost = (float) $r['total_cost'];
         
         $shiftTotalCost += $cost;
         
         // Row colors
         $bgStyle = "";
         if (!empty($r['loyalty_hours'])) {
             $bgStyle = "background-color: #fdba74;"; // LOYALTY CARD (orange)
         } elseif (!empty($r['is_promo'])) {
             $bgStyle = "background-color: #fbcfe8;"; // EB PROMO (pink)
         }
         
         echo '<td style="text-align: center; border: 1px solid #ccc; ' . $bgStyle . '">' . htmlspecialchars((string) $r['session_id']) . '</td>';
         echo '<td style="text-align: center; border: 1px solid #ccc; ' . $bgStyle . '">' . htmlspecialchars($tableName) . '</td>';
         echo '<td style="text-align: center; border: 1px solid #ccc; ' . $bgStyle . '">' . htmlspecialchars($promoStr) . '</td>';
         echo '<td style="text-align: center; border: 1px solid #ccc; ' . $bgStyle . '">' . htmlspecialchars($startTime) . '</td>';
         echo '<td style="text-align: center; border: 1px solid #ccc; ' . $bgStyle . '">' . htmlspecialchars($expectedEnd) . '</td>';
         echo '<td style="text-align: center; border: 1px solid #ccc; ' . $bgStyle . '">' . htmlspecialchars($endTime) . '</td>';
         echo '<td style="text-align: center; border: 1px solid #ccc; ' . $bgStyle . '">' . htmlspecialchars($isExtended) . '</td>';
         echo '<td style="text-align: center; border: 1px solid #ccc; ' . $bgStyle . '">' . htmlspecialchars($durationFmt) . '</td>';
         echo '<td style="text-align: center; border: 1px solid #ccc; ' . $bgStyle . '">' . htmlspecialchars((string) $r['player_name']) . '</td>';
         echo '<td style="text-align: center; border: 1px solid #ccc; ' . $bgStyle . '">' . htmlspecialchars((string) $r['cashier']) . '</td>';
         echo '<td style="text-align: right; border: 1px solid #ccc; ' . $bgStyle . '">₱' . number_format($cost, 2) . '</td>';
         echo '<td style="text-align: right; border: 1px solid #ccc; ' . $bgStyle . '">₱' . number_format((float) $r['rate_per_hour'], 2) . '</td>';
         
      } elseif ($i === count($sRows)) {
         echo '<td colspan="10" style="text-align: right; font-weight: bold; background-color: #fcd5b4; border: 1px solid #ccc;">TOTAL</td>';
         echo '<td style="text-align: right; font-weight: bold; background-color: #fcd5b4; border: 1px solid #ccc;">' . number_format($shiftTotalCost, 2) . '</td>';
         echo '<td style="border: 1px solid #ccc; background-color: #fcd5b4;"></td>';
      } else {
         echo '<td colspan="12" style="border:none;"></td>';
      }
      
      // -- SPACER --
      echo '<td style="border:none; width:20px;"></td>';
      
      // -- BREAKDOWN COLUMNS --
      if ($i === 0) {
         echo '<td colspan="2" style="font-weight:bold; text-align:center; border: 1px solid #ccc;">' . $shiftKey . ' BREAKDOWN</td>';
      } elseif ($i <= count($bKeys)) {
         $k = $bKeys[$i - 1];
         $v = $bDown[$k];
         echo '<td style="border: 1px solid #ccc; text-align: center;">' . htmlspecialchars($k) . '</td>';
         echo '<td style="border: 1px solid #ccc; text-align: center;" x:num="' . $v . '">' . number_format($v, 2, '.', '') . '</td>';
      } elseif ($i === count($bKeys) + 1) {
         echo '<td style="font-weight:bold; text-align: center; background-color:#fcd5b4; border: 1px solid #ccc;">TOTAL</td>';
         echo '<td style="font-weight:bold; text-align: center; background-color:#fcd5b4; border: 1px solid #ccc;" x:num="' . array_sum($bDown) . '" x:fmla="=SUM(O'.$totalFormulaStart.':O'.$totalFormulaEnd.')">' . number_format(array_sum($bDown), 2, '.', '') . '</td>';
      } else {
         echo '<td colspan="2" style="border:none;"></td>';
      }
      
      echo '</tr>';
      $excelRow++;
   }
   
   // Empty row between shifts
   echo '<tr><td colspan="15" style="border:none; height:20px;"></td></tr>';
   $excelRow++;
}

echo '<tr><td colspan="12" style="border:none;"></td><td style="border:none;"></td><td style="background-color:#9ca3af; border:1px solid #ccc;">NO TRANSACTION</td><td style="border:1px solid #ccc;"></td></tr>';
echo '<tr><td colspan="12" style="border:none;"></td><td style="border:none;"></td><td style="background-color:#fbcfe8; border:1px solid #ccc;">EB PROMO</td><td style="border:1px solid #ccc;"></td></tr>';
echo '<tr><td colspan="12" style="border:none;"></td><td style="border:none;"></td><td style="background-color:#fef08a; border:1px solid #ccc;">NO/WRONG UPDATE</td><td style="border:1px solid #ccc;"></td></tr>';
echo '<tr><td colspan="12" style="border:none;"></td><td style="border:none;"></td><td style="background-color:#60a5fa; border:1px solid #ccc;">FOR ADJUSTMENT</td><td style="border:1px solid #ccc;"></td></tr>';
echo '<tr><td colspan="12" style="border:none;"></td><td style="border:none;"></td><td style="background-color:#fdba74; border:1px solid #ccc;">LOYALTY CARD</td><td style="border:1px solid #ccc;"></td></tr>';

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
  echo '<th colspan="8" style="background-color: #ef4444; color: white; font-weight: bold; font-size: 14px; padding: 5px;">VOIDED SESSIONS</th>';
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
  echo '<td colspan="7" style="text-align: right; font-weight: bold;">TOTAL VOIDED AMOUNT:</td>';
  echo '<td style="text-align: right; color: #ef4444; font-weight: bold; font-size: 14px;">₱' . number_format($totalVoidedAmount, 2) . '</td>';
  echo '</tr>';
  
  echo '</table>';
}

echo '</body></html>';
exit;
