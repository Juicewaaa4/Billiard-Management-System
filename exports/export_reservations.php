<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/util.php';

start_app_session();
require_role(['admin']); // Restricted to Admin only

$from = parse_date((string)($_GET['from'] ?? date('Y-m-d')));
$to = parse_date((string)($_GET['to'] ?? date('Y-m-d')));
$status = (string)($_GET['status'] ?? 'all');
$shiftFilter = (string)($_GET['shift'] ?? 'both'); // 'morning', 'evening', 'both'

// Configurable shift start times — from URL or saved in database
$morningStart = (string)($_GET['morning_start'] ?? '');
$eveningStart = (string)($_GET['evening_start'] ?? '');

// Fallback: load from database if not in URL
if ($morningStart === '' || $eveningStart === '') {
  try {
    $ssStmt = db()->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('morning_shift_start','evening_shift_start')");
    foreach ($ssStmt->fetchAll() as $ss) {
      if ($ss['setting_key'] === 'morning_shift_start' && $morningStart === '') $morningStart = $ss['setting_value'];
      if ($ss['setting_key'] === 'evening_shift_start' && $eveningStart === '') $eveningStart = $ss['setting_value'];
    }
  } catch (Throwable $ignore) {}
}
if ($morningStart === '') $morningStart = '08:00';
if ($eveningStart === '') $eveningStart = '16:30';

// Convert shift start times to hour+minute for comparison
$morningStartParts = explode(':', $morningStart);
$morningStartMinutes = ((int)$morningStartParts[0]) * 60 + ((int)($morningStartParts[1] ?? 0));

$eveningStartParts = explode(':', $eveningStart);
$eveningStartMinutes = ((int)$eveningStartParts[0]) * 60 + ((int)($eveningStartParts[1] ?? 0));

$where = ["1=1"];
$params = [];

if ($from) { $where[] = "DATE(r.reservation_date) >= ?"; $params[] = $from; }
if ($to) { $where[] = "DATE(r.reservation_date) <= ?"; $params[] = $to; }
if ($status !== 'all') { $where[] = "r.status = ?"; $params[] = $status; }

$stmt = db()->prepare("
  SELECT 
    r.*, 
    t.table_number, 
    t.type AS table_type, 
    t.rate_per_hour,
    u.username AS cashier_name
  FROM reservations r 
  JOIN tables t ON t.id = r.table_id 
  LEFT JOIN users u ON u.id = r.created_by
  WHERE " . implode(' AND ', $where) . " 
  ORDER BY r.reservation_date ASC, r.start_time ASC
");
$stmt->execute($params);
$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build shift label for filename
$shiftLabel = $shiftFilter === 'morning' ? 'Morning' : ($shiftFilter === 'evening' ? 'Evening' : 'Both');
$filenameFrom = $from ? date('Y-m-d', strtotime($from)) : 'Start';
$filenameTo = $to ? date('Y-m-d', strtotime($to)) : 'Present';
$exportFilename = "Reservations_{$shiftLabel}_{$filenameFrom}_to_{$filenameTo}.xls";

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $exportFilename . '"');
header("Pragma: no-cache");
header("Expires: 0");

// Format shift time for display
$morningStartDisplay = date('g:i A', strtotime($morningStart));
$eveningStartDisplay = date('g:i A', strtotime($eveningStart));

// Determine which shift a reservation belongs to based on its start_time
function getShiftLabel(string $startTime, int $morningStartMin, int $eveningStartMin): string {
    $ts = strtotime($startTime);
    $hourMin = ((int)date('G', $ts)) * 60 + ((int)date('i', $ts));
    
    if ($eveningStartMin > $morningStartMin) {
        // Normal case: morning 8:00, evening 16:30
        if ($hourMin >= $morningStartMin && $hourMin < $eveningStartMin) {
            return 'MORNING';
        } else {
            return 'EVENING';
        }
    } else {
        // Edge case: evening wraps around midnight
        if ($hourMin >= $morningStartMin && $hourMin < $eveningStartMin) {
            return 'MORNING';
        } else {
            return 'EVENING';
        }
    }
}

// Grouping structure
$shifts = [
    'MORNING' => ['vip' => [], 'ktv' => [], 'regular' => []],
    'EVENING' => ['vip' => [], 'ktv' => [], 'regular' => []]
];

$grandTotalVip = 0;
$grandTotalKtv = 0;
$grandTotalRegular = 0;

foreach ($reservations as $r) {
    // Calculate properties
    $startTimeTs = strtotime($r['start_time']);
    $durHoursFloat = (float)$r['duration_hours'];
    $durationSeconds = $durHoursFloat * 3600;
    $endTimeTs = (int)($startTimeTs + $durationSeconds);
    
    $timeRange = date('g:i A', $startTimeTs) . ' - ' . date('g:i A', $endTimeTs);
    
    $h = (int)floor($durHoursFloat);
    $m = (int)floor(($durHoursFloat - $h) * 60);
    $totalTimeFmt = sprintf("%d:%02d:00", $h, $m);
    
    $totalCost = $durHoursFloat * (float)$r['rate_per_hour'];
    $isKtv = ($r['table_type'] === 'ktv') ? 'Yes' : 'No';
    $cashier = $r['cashier_name'] ? ucwords(str_replace('_', ' ', $r['cashier_name'])) : 'Unknown';
    $transactionDate = "'" . date('m/d/Y h:i A', strtotime($r['created_at']));
    
    // Determine shift using configurable times
    $shiftForRow = getShiftLabel($r['start_time'], $morningStartMinutes, $eveningStartMinutes);
    
    // Skip rows not in the requested shift filter
    if ($shiftFilter === 'morning' && $shiftForRow !== 'MORNING') continue;
    if ($shiftFilter === 'evening' && $shiftForRow !== 'EVENING') continue;
    
    $typeGroup = $r['table_type']; // 'vip', 'ktv', 'regular'
    
    // Store in structured array
    $processed = [
        'id' => 'R-' . $r['id'],
        'customer' => $r['customer_name'],
        'table' => $r['table_type'] === 'vip' ? 'VIP Room' : ($r['table_type'] === 'ktv' ? 'KTV Room' : 'Regular Table'),
        'time_range' => $timeRange,
        'total_time' => $totalTimeFmt,
        'downpayment' => (float)$r['down_payment'],
        'total_cost' => $totalCost,
        'ktv' => $isKtv,
        'cashier' => $cashier,
        'transaction_date' => $transactionDate
    ];
    
    $shifts[$shiftForRow][$typeGroup][] = $processed;
    
    // Add to grand totals
    if ($typeGroup === 'vip') $grandTotalVip += $totalCost;
    elseif ($typeGroup === 'ktv') $grandTotalKtv += $totalCost;
    else $grandTotalRegular += $totalCost;
}

echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
echo '<head><meta charset="UTF-8"></head><body>';
echo '<table border="1" style="font-family: Calibri, sans-serif;">';

// Helper function to print columns header
function print_columns_header() {
    echo '<tr>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: center;">Reservation ID</th>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: center;">Customer</th>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: center;">Table / Room</th>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: center;">Time Range</th>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: center;">Total Time</th>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: center;">Downpayment (P)</th>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: right;">Total Cost (P)</th>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: center;">KTV</th>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: center;">Cashier</th>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: center;">TransactionDate</th>';
    echo '</tr>';
}

// Helper function to print a group of rows
function print_group_rows($groupLabel, $shiftLabel, $rows) {
    if (empty($rows)) {
        echo '<tr>';
        echo '<td style="font-weight: bold;">' . $groupLabel . '</td>';
        if ($shiftLabel !== null) {
            echo '<td style="font-weight: bold;">' . $shiftLabel . '</td>';
            echo '<td colspan="8"></td>';
        } else {
            echo '<td colspan="9"></td>';
        }
        echo '</tr>';
        return;
    }

    echo '<tr>';
    echo '<td style="font-weight: bold;">' . $groupLabel . '</td>';
    if ($shiftLabel !== null) {
        echo '<td style="font-weight: bold;">' . $shiftLabel . '</td>';
        echo '<td colspan="8"></td>';
    } else {
        echo '<td colspan="9"></td>';
    }
    echo '</tr>';
    
    print_columns_header();
    
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td style="text-align: center;">' . htmlspecialchars((string)$row['id']) . '</td>';
        echo '<td>' . htmlspecialchars((string)$row['customer']) . '</td>';
        echo '<td style="text-align: center;">' . htmlspecialchars((string)$row['table']) . '</td>';
        echo '<td style="text-align: center;">' . htmlspecialchars((string)$row['time_range']) . '</td>';
        echo '<td style="text-align: center;">' . htmlspecialchars((string)$row['total_time']) . '</td>';
        echo '<td style="text-align: center;">' . number_format($row['downpayment'], 0) . '</td>';
        echo '<td style="text-align: right;">' . number_format($row['total_cost'], 0) . '</td>';
        echo '<td style="text-align: center;">' . $row['ktv'] . '</td>';
        echo '<td style="text-align: center;">' . htmlspecialchars((string)$row['cashier']) . '</td>';
        echo '<td style="text-align: center;">' . htmlspecialchars((string)$row['transaction_date']) . '</td>';
        echo '</tr>';
    }
}

// Determine which shifts to render
$shiftsToRender = [];
if ($shiftFilter === 'morning') {
    $shiftsToRender = ['MORNING'];
} elseif ($shiftFilter === 'evening') {
    $shiftsToRender = ['EVENING'];
} else {
    $shiftsToRender = ['MORNING', 'EVENING'];
}

foreach ($shiftsToRender as $shift) {
    $shiftTimeLabel = $shift === 'MORNING' 
        ? "MORNING SHIFT ({$morningStartDisplay} - {$eveningStartDisplay})" 
        : "EVENING SHIFT ({$eveningStartDisplay} - {$morningStartDisplay})";
    
    // 1st Row: RESERVATION + Shift info
    echo '<tr>';
    echo '<td colspan="10" style="background-color: #dff0d8; text-align: center; font-weight: bold;">RESERVATION — ' . $shiftTimeLabel . '</td>';
    echo '</tr>';
    
    // Groups — no need to repeat shift label since it's in the header
    print_group_rows('VIP ROOM', null, $shifts[$shift]['vip']);
    print_group_rows('KTV ROOM', null, $shifts[$shift]['ktv']);
    print_group_rows('REGULAR TABLE', null, $shifts[$shift]['regular']);
    
    // Shift Total
    $shiftTotal = 0;
    foreach ($shifts[$shift] as $typeGrp) {
        foreach ($typeGrp as $r) {
            $shiftTotal += $r['total_cost'];
        }
    }
    
    echo '<tr><td colspan="10" style="border:none; height:10px;"></td></tr>';
    
    echo '<tr>';
    echo '<td colspan="5"></td>';
    echo '<td style="background-color: #fcf8e3; font-weight: bold; text-align: center;">TOTAL</td>';
    echo '<td style="background-color: #fcf8e3; font-weight: bold; text-align: right;">' . number_format($shiftTotal, 2) . '</td>';
    echo '<td colspan="3"></td>';
    echo '</tr>';
    
    echo '<tr><td colspan="10" style="border:none; height:30px;"></td></tr>';
}

// Grand Totals at the very bottom
echo '<tr>';
echo '<td colspan="5"></td>';
echo '<td style="font-weight: bold; color: #e67e22; text-align: right;">VIP GRAND TOTAL:</td>';
echo '<td style="font-weight: bold; color: #e67e22; text-align: right;">' . number_format($grandTotalVip, 0) . '</td>';
echo '<td colspan="3"></td>';
echo '</tr>';

echo '<tr>';
echo '<td colspan="5"></td>';
echo '<td style="font-weight: bold; color: #e67e22; text-align: right;">KTV GRAND TOTAL:</td>';
echo '<td style="font-weight: bold; color: #e67e22; text-align: right;">' . number_format($grandTotalKtv, 0) . '</td>';
echo '<td colspan="3"></td>';
echo '</tr>';

if ($grandTotalRegular > 0) {
    echo '<tr>';
    echo '<td colspan="5"></td>';
    echo '<td style="font-weight: bold; color: #e67e22; text-align: right;">REGULAR GRAND TOTAL:</td>';
    echo '<td style="font-weight: bold; color: #e67e22; text-align: right;">' . number_format($grandTotalRegular, 0) . '</td>';
    echo '<td colspan="3"></td>';
    echo '</tr>';
}

$grandTotal = $grandTotalVip + $grandTotalKtv + $grandTotalRegular;
echo '<tr>';
echo '<td colspan="5"></td>';
echo '<td style="font-weight: bold; color: #22c55e; font-size: 14px; text-align: right;">GRAND TOTAL:</td>';
echo '<td style="font-weight: bold; color: #22c55e; font-size: 14px; text-align: right;">' . number_format($grandTotal, 2) . '</td>';
echo '<td colspan="3"></td>';
echo '</tr>';

echo '</table>';
echo '</body></html>';
exit;
