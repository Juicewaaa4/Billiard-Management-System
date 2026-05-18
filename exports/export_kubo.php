<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/util.php';

start_app_session();
require_role(['admin']); // Restricted to Admin only

$from = parse_date((string)($_GET['from'] ?? date('Y-m-d')));
$to = parse_date((string)($_GET['to'] ?? date('Y-m-d')));

$where = ["kr.is_voided = 0"];
$params = [];

if ($from) { $where[] = "DATE(kr.rental_date) >= ?"; $params[] = $from; }
if ($to) { $where[] = "DATE(kr.rental_date) <= ?"; $params[] = $to; }

$stmt = db()->prepare("
  SELECT 
    kr.*, t.table_number 
  FROM kubo_rentals kr 
  JOIN tables t ON t.id = kr.table_id 
  WHERE " . implode(' AND ', $where) . " 
  ORDER BY kr.created_at DESC
");
$stmt->execute($params);
$rentals = $stmt->fetchAll(PDO::FETCH_ASSOC);

$whereKaraoke = ["t.type = 'kubo'", "gs.is_voided = 0"];
$paramsKaraoke = [];

if ($from) { $whereKaraoke[] = "DATE(gs.start_time) >= ?"; $paramsKaraoke[] = $from; }
if ($to) { $whereKaraoke[] = "DATE(gs.start_time) <= ?"; $paramsKaraoke[] = $to; }

$stmtK = db()->prepare("
  SELECT 
    gs.*, t.table_number 
  FROM game_sessions gs 
  JOIN tables t ON t.id = gs.table_id 
  WHERE " . implode(' AND ', $whereKaraoke) . " 
  ORDER BY gs.start_time DESC
");
$stmtK->execute($paramsKaraoke);
$karaokes = $stmtK->fetchAll(PDO::FETCH_ASSOC);

// Return HTML XLS Format
$filenameFrom = $from ? date('Y-m-d', strtotime($from)) : 'Start';
$filenameTo = $to ? date('Y-m-d', strtotime($to)) : 'Present';
$exportFilename = "Kubo_Transactions_{$filenameFrom}_to_{$filenameTo}.xls";

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $exportFilename . '"');
header("Pragma: no-cache");
header("Expires: 0");

echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
echo '<head><meta charset="UTF-8"></head><body>';

// ======== SECTION 1: KUBO RENTALS ========
echo '<h2>KUBO RENTALS</h2>';
echo '<table border="1" cellpadding="5">';
echo '<tr>';
echo '<th style="background-color: #22c55e; color: white; font-weight: bold; text-align: center;">ID</th>';
echo '<th style="background-color: #22c55e; color: white; font-weight: bold; text-align: center;">Kubo Name</th>';
echo '<th style="background-color: #22c55e; color: white; font-weight: bold; text-align: center;">Customer Name</th>';
echo '<th style="background-color: #22c55e; color: white; font-weight: bold; text-align: right;">Payment Amount</th>';
echo '<th style="background-color: #22c55e; color: white; font-weight: bold; text-align: center;">Rental Date</th>';
echo '<th style="background-color: #22c55e; color: white; font-weight: bold; text-align: center;">Time Started</th>';
echo '<th style="background-color: #22c55e; color: white; font-weight: bold; text-align: center;">Time Ended</th>';
echo '<th style="background-color: #22c55e; color: white; font-weight: bold; text-align: center;">Status</th>';
echo '</tr>';

$totalKubo = 0;

foreach ($rentals as $r) {
  $endedAt = $r['end_time'] ? ("'" . date('h:i A', strtotime($r['end_time']))) : "'Active";
  $status = ucfirst($r['status']);
  $totalKubo += (float)$r['payment_amount'];
  
  echo '<tr>';
  echo '<td style="text-align: center;">' . $r['id'] . '</td>';
  echo '<td>' . htmlspecialchars((string)$r['table_number']) . '</td>';
  echo '<td>' . htmlspecialchars((string)$r['customer_name']) . '</td>';
  echo '<td style="text-align: right;">₱' . number_format((float)$r['payment_amount'], 2) . '</td>';
  echo '<td style="text-align: center;">' . htmlspecialchars((string)$r['rental_date']) . '</td>';
  echo '<td style="text-align: center;">\'' . date('h:i A', strtotime($r['created_at'])) . '</td>';
  echo '<td style="text-align: center;">' . $endedAt . '</td>';
  echo '<td style="text-align: center;">' . $status . '</td>';
  echo '</tr>';
}

echo '<tr>';
echo '<td colspan="3" style="text-align: right; font-weight: bold;">SUBTOTAL (RENTALS):</td>';
echo '<td style="text-align: right; font-weight: bold; color: #22c55e;">₱' . number_format($totalKubo, 2) . '</td>';
echo '<td colspan="4"></td>';
echo '</tr>';
echo '</table>';

echo '<br><br>';

// ======== SECTION 2: KARAOKE SESSIONS ========
echo '<h2>KARAOKE SESSIONS</h2>';
echo '<table border="1" cellpadding="5">';
echo '<tr>';
echo '<th style="background-color: #a855f7; color: white; font-weight: bold; text-align: center;">ID</th>';
echo '<th style="background-color: #a855f7; color: white; font-weight: bold; text-align: center;">Kubo Name</th>';
echo '<th style="background-color: #a855f7; color: white; font-weight: bold; text-align: center;">Customer Name</th>';
echo '<th style="background-color: #a855f7; color: white; font-weight: bold; text-align: right;">Total Paid</th>';
echo '<th style="background-color: #a855f7; color: white; font-weight: bold; text-align: center;">Hours</th>';
echo '<th style="background-color: #a855f7; color: white; font-weight: bold; text-align: center;">Date</th>';
echo '<th style="background-color: #a855f7; color: white; font-weight: bold; text-align: center;">Time Started</th>';
echo '<th style="background-color: #a855f7; color: white; font-weight: bold; text-align: center;">Time Ended</th>';
echo '</tr>';

$totalKaraoke = 0;

foreach ($karaokes as $k) {
  $endedAt = $k['end_time'] ? ("'" . date('h:i A', strtotime($k['end_time']))) : "'Active";
  $totalKaraoke += (float)$k['total_amount'];
  $cName = $k['walk_in_name'] ?: 'Walk-in';
  
  echo '<tr>';
  echo '<td style="text-align: center;">' . $k['id'] . '</td>';
  echo '<td>' . htmlspecialchars((string)$k['table_number']) . '</td>';
  echo '<td>' . htmlspecialchars((string)$cName) . '</td>';
  echo '<td style="text-align: right;">₱' . number_format((float)$k['total_amount'], 2) . '</td>';
  echo '<td style="text-align: center;">' . (float)$k['hours_purchased'] . 'h</td>';
  echo '<td style="text-align: center;">' . date('Y-m-d', strtotime($k['start_time'])) . '</td>';
  echo '<td style="text-align: center;">\'' . date('h:i A', strtotime($k['start_time'])) . '</td>';
  echo '<td style="text-align: center;">' . $endedAt . '</td>';
  echo '</tr>';
}

echo '<tr>';
echo '<td colspan="3" style="text-align: right; font-weight: bold;">SUBTOTAL (KARAOKE):</td>';
echo '<td style="text-align: right; font-weight: bold; color: #a855f7;">₱' . number_format($totalKaraoke, 2) . '</td>';
echo '<td colspan="4"></td>';
echo '</tr>';
echo '</table>';

echo '<br><br>';

// ======== GRAND TOTAL ========
$grandTotal = $totalKubo + $totalKaraoke;
echo '<table border="1" cellpadding="5">';
echo '<tr>';
echo '<td style="font-weight: bold; font-size: 16px; background-color: #f1f5f9;">GRAND TOTAL INCOME:</td>';
echo '<td style="font-weight: bold; font-size: 16px; color: #dc2626; background-color: #f1f5f9;">₱' . number_format($grandTotal, 2) . '</td>';
echo '</tr>';
echo '</table>';

echo '</body></html>';
exit;
