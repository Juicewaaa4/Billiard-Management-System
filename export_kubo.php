<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/util.php';

start_app_session();
require_role(['admin']); // Restricted to Admin only

$from = parse_date((string)($_GET['from'] ?? date('Y-m-d')));
$to = parse_date((string)($_GET['to'] ?? date('Y-m-d')));

$where = ["1=1"];
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
echo '<table border="1" cellpadding="5">';

// Header
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

$totalPayment = 0;

foreach ($rentals as $r) {
  $endedAt = $r['end_time'] ? date('h:i A', strtotime($r['end_time'])) : '--:-- --';
  $status = ucfirst($r['status']);
  $totalPayment += (float)$r['payment_amount'];
  
  echo '<tr>';
  echo '<td style="text-align: center;">' . $r['id'] . '</td>';
  echo '<td>' . htmlspecialchars((string)$r['table_number']) . '</td>';
  echo '<td>' . htmlspecialchars((string)$r['customer_name']) . '</td>';
  echo '<td style="text-align: right;">₱' . number_format((float)$r['payment_amount'], 2) . '</td>';
  echo '<td style="text-align: center;">' . htmlspecialchars((string)$r['rental_date']) . '</td>';
  echo '<td style="text-align: center;">' . date('h:i A', strtotime($r['created_at'])) . '</td>';
  echo '<td style="text-align: center;">' . $endedAt . '</td>';
  echo '<td style="text-align: center;">' . $status . '</td>';
  echo '</tr>';
}

echo '<tr>';
echo '<td colspan="3" style="text-align: right; font-weight: bold;">TOTAL:</td>';
echo '<td style="text-align: right; font-weight: bold; color: #22c55e;">₱' . number_format($totalPayment, 2) . '</td>';
echo '<td colspan="4"></td>';
echo '</tr>';

echo '</table>';
echo '</body></html>';
exit;
