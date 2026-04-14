<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/util.php';

start_app_session();
require_role(['admin', 'cashier']);


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

$where = ["gs.end_time IS NOT NULL"];
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
$exportFilename = "Billiards_Transactions_{$filenameFrom}_to_{$filenameTo}.xls";

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $exportFilename . '"');

echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
echo '<head><meta charset="UTF-8"></head><body>';
echo '<table border="1">';

// Header row with green background and bold text
echo '<tr>';
echo '<th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center;">Session ID</th>';
echo '<th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center;">Transaction Date</th>';
echo '<th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center;">Table</th>';
echo '<th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center;">Promo Applied</th>';
echo '<th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center;">Start Time</th>';
echo '<th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center;">Expected End Time</th>';
echo '<th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center;">Actual End Time</th>';
echo '<th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center;">Extended?</th>';
echo '<th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center;">Duration (HH:MM:SS)</th>';
echo '<th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center;">Player Name</th>';
echo '<th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: center;">Cashier</th>';
echo '<th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: right;">Total Cost</th>';
echo '<th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: right;">Payment</th>';
echo '<th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: right;">Change</th>';
echo '<th style="background-color: #66BB6A; color: white; font-weight: bold; text-align: right;">Rate per Hour</th>';
echo '</tr>';

$totalRegular = 0;
$totalVip = 0;
$totalKtv = 0;
$grandTotalCost = 0;
$grandTotalPayment = 0;

foreach ($rows as $r) {
  $dur = (int) ($r['duration_seconds'] ?? 0);
  $h = intdiv($dur, 3600);
  $m = intdiv($dur % 3600, 60);
  $s = $dur % 60;
  $durationFmt = sprintf('%02d:%02d:%02d', $h, $m, $s);

  // Format dates for Excel compatibility (12-hour AM/PM, user-friendly)
  $startTime = date('m/d/Y h:i:s A', strtotime($r['start_time']));
  $endTime = date('m/d/Y h:i:s A', strtotime($r['end_time']));

  $tableName = (string) $r['table_number'];
  if (!empty($r['karaoke_included'])) {
    $tableName .= ' (With Karaoke)';
  }
  $isExtended = ($r['tx_count'] > 1) ? 'Yes' : 'No';
  $expectedEnd = !empty($r['scheduled_end_time']) ? date('m/d/Y h:i A', strtotime($r['scheduled_end_time'])) : 'N/A';
  $promoStr = !empty($r['is_promo']) ? 'Early Bird (50%)' : 'None';

  $transDate = date('m/d/Y g:i A', strtotime($r['end_time']));
  
  $payment = (float) $r['payment'];
  $cost = (float) $r['total_cost'];
  
  $grandTotalPayment += $payment;
  $grandTotalCost += $cost;

  if ($r['table_type'] === 'regular') $totalRegular += $payment;
  elseif ($r['table_type'] === 'vip') $totalVip += $payment;
  elseif ($r['table_type'] === 'ktv') $totalKtv += $payment;

  echo '<tr>';
  echo '<td style="text-align: center;">' . htmlspecialchars((string) $r['session_id']) . '</td>';
  echo '<td style="text-align: center;">' . htmlspecialchars($transDate) . '</td>';
  echo '<td style="text-align: center;">' . htmlspecialchars($tableName) . '</td>';
  echo '<td style="text-align: center;">' . htmlspecialchars($promoStr) . '</td>';
  echo '<td style="text-align: center;">' . htmlspecialchars($startTime) . '</td>';
  echo '<td style="text-align: center;">' . htmlspecialchars($expectedEnd) . '</td>';
  echo '<td style="text-align: center;">' . htmlspecialchars($endTime) . '</td>';
  echo '<td style="text-align: center;">' . htmlspecialchars($isExtended) . '</td>';
  echo '<td style="text-align: center;">' . htmlspecialchars($durationFmt) . '</td>';
  echo '<td style="text-align: center;">' . htmlspecialchars((string) $r['player_name']) . '</td>';
  echo '<td style="text-align: center;">' . htmlspecialchars((string) $r['cashier']) . '</td>';
  echo '<td style="text-align: right;">₱' . number_format($cost, 2) . '</td>';
  echo '<td style="text-align: right;">₱' . number_format($payment, 2) . '</td>';
  echo '<td style="text-align: right;">₱' . number_format((float) $r['change_amount'], 2) . '</td>';
  echo '<td style="text-align: right;">₱' . number_format((float) $r['rate_per_hour'], 2) . '</td>';
  echo '</tr>';
}

echo '<tr><td colspan="15" style="border:none; height:20px;"></td></tr>';

// Breakdown Summary
echo '<tr>';
echo '<td colspan="11" style="text-align: right; font-weight: bold; font-style: italic;">Regular Tables Total Payment:</td>';
echo '<td colspan="2" style="text-align: right; font-weight: bold; color: #38bdf8;">₱' . number_format($totalRegular, 2) . '</td>';
echo '<td colspan="2"></td>';
echo '</tr>';

echo '<tr>';
echo '<td colspan="11" style="text-align: right; font-weight: bold; font-style: italic;">VIP Tables Total Payment:</td>';
echo '<td colspan="2" style="text-align: right; font-weight: bold; color: #a855f7;">₱' . number_format($totalVip, 2) . '</td>';
echo '<td colspan="2"></td>';
echo '</tr>';

echo '<tr>';
echo '<td colspan="11" style="text-align: right; font-weight: bold; font-style: italic;">KTV Rooms Total Payment:</td>';
echo '<td colspan="2" style="text-align: right; font-weight: bold; color: #fbbf24;">₱' . number_format($totalKtv, 2) . '</td>';
echo '<td colspan="2"></td>';
echo '</tr>';

echo '<tr>';
echo '<td colspan="11" style="text-align: right; font-weight: bold; font-size: 14px;">GRAND TOTAL PAYMENT:</td>';
echo '<td colspan="2" style="text-align: right; font-weight: bold; font-size: 14px; color: #22c55e;">₱' . number_format($grandTotalPayment, 2) . '</td>';
echo '<td colspan="2"></td>';
echo '</tr>';

echo '</table>';
echo '</body></html>';
exit;
