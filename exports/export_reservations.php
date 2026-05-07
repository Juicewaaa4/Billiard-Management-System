<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/util.php';

start_app_session();
require_role(['admin']); // Restricted to Admin only

$from = parse_date((string) ($_GET['from'] ?? date('Y-m-d')));
$to = parse_date((string) ($_GET['to'] ?? date('Y-m-d')));
$status = (string) ($_GET['status'] ?? 'all');
$shiftFilter = (string) ($_GET['shift'] ?? 'both'); // 'morning', 'evening', 'both'

// Configurable shift start times — from URL or saved in database
$morningStart = (string) ($_GET['morning_start'] ?? '');
$eveningStart = (string) ($_GET['evening_start'] ?? '');
$nightEnd = (string) ($_GET['night_end'] ?? '');

// Fallback: load from database if not in URL
if ($morningStart === '' || $eveningStart === '' || $nightEnd === '') {
    try {
        $ssStmt = db()->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('morning_shift_start','evening_shift_start', 'night_shift_end')");
        foreach ($ssStmt->fetchAll() as $ss) {
            if ($ss['setting_key'] === 'morning_shift_start' && $morningStart === '')
                $morningStart = $ss['setting_value'];
            if ($ss['setting_key'] === 'evening_shift_start' && $eveningStart === '')
                $eveningStart = $ss['setting_value'];
            if ($ss['setting_key'] === 'night_shift_end' && $nightEnd === '')
                $nightEnd = $ss['setting_value'];
        }
    } catch (Throwable $ignore) {
    }
}
if ($morningStart === '')
    $morningStart = '08:00';
if ($eveningStart === '')
    $eveningStart = '16:30';
if ($nightEnd === '')
    $nightEnd = '02:30';

// Convert shift start times to hour+minute for comparison
$morningStartParts = explode(':', $morningStart);
$morningStartMinutes = ((int) $morningStartParts[0]) * 60 + ((int) ($morningStartParts[1] ?? 0));

$eveningStartParts = explode(':', $eveningStart);
$eveningStartMinutes = ((int) $eveningStartParts[0]) * 60 + ((int) ($eveningStartParts[1] ?? 0));

$whereRes = [];
$paramsRes = [];
if ($from) {
    $whereRes[] = "DATE(r.reservation_date) >= ?";
    $paramsRes[] = $from;
}
if ($to) {
    $whereRes[] = "DATE(r.reservation_date) <= ?";
    $paramsRes[] = $to;
}
$whereResStr = count($whereRes) ? " AND " . implode(" AND ", $whereRes) : "";

$noShowsStmt = db()->prepare("
    SELECT r.*, c.name as customer_name, t.table_number, t.type as table_type, u.username as cashier_name
    FROM reservations r
    JOIN tables t ON t.id = r.table_id
    LEFT JOIN customers c ON c.id = r.customer_id
    LEFT JOIN users u ON u.id = r.created_by
    WHERE r.status = 'no_show' $whereResStr
");
$noShowsStmt->execute($paramsRes);
$noShows = $noShowsStmt->fetchAll(PDO::FETCH_ASSOC);

$whereGs = [];
$paramsGs = [];
if ($from) {
    $whereGs[] = "DATE(gs.start_time) >= ?";
    $paramsGs[] = $from;
}
if ($to) {
    $whereGs[] = "DATE(gs.start_time) <= ?";
    $paramsGs[] = $to;
}
$whereGsStr = count($whereGs) ? " AND " . implode(" AND ", $whereGs) : "";

$sessionsStmt = db()->prepare("
    SELECT gs.*, r.down_payment, c.name as c_name, t.table_number, t.type as table_type, u.username as cashier_name
    FROM game_sessions gs
    JOIN tables t ON t.id = gs.table_id
    LEFT JOIN reservations r ON r.id = gs.reservation_id
    LEFT JOIN customers c ON c.id = gs.customer_id
    LEFT JOIN users u ON u.id = gs.created_by
    WHERE gs.is_voided = 0 AND (t.type IN ('vip', 'ktv') OR gs.reservation_id IS NOT NULL) $whereGsStr
");
$sessionsStmt->execute($paramsGs);
$sessions = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);

$allRows = [];

// Determine which shift a reservation belongs to based on its start_time
function getShiftLabel(string $startTime, int $morningStartMin, int $eveningStartMin): string
{
    $ts = strtotime($startTime);
    $hourMin = ((int) date('G', $ts)) * 60 + ((int) date('i', $ts));

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

foreach ($noShows as $ns) {
    $startTimeTs = strtotime($ns['start_time']);
    $durSecs = (float) $ns['duration_hours'] * 3600;
    $endTimeTs = (int) ($startTimeTs + $durSecs);

    $h = intdiv((int) $durSecs, 3600);
    $m = intdiv((int) $durSecs % 3600, 60);
    $s = $durSecs % 60;
    $totalTimeFmt = sprintf('%02d:%02d:%02d', $h, $m, $s);

    $shiftForRow = getShiftLabel($ns['start_time'], $morningStartMinutes, $eveningStartMinutes);
    if ($shiftFilter === 'morning' && $shiftForRow !== 'MORNING')
        continue;
    if ($shiftFilter === 'evening' && $shiftForRow !== 'EVENING')
        continue;

    $allRows[] = [
        'id' => 'R-' . $ns['id'],
        'customer' => $ns['customer_name'] ?: 'Unknown',
        'table_type' => $ns['table_type'],
        'table' => $ns['table_type'] === 'vip' ? 'VIP Room' : ($ns['table_type'] === 'ktv' ? 'KTV Room' : 'Regular Table'),
        'time_range' => "'" . date('g:i A', $startTimeTs) . ' - ' . date('g:i A', $endTimeTs),
        'total_time' => $totalTimeFmt,
        'downpayment' => (float) $ns['down_payment'],
        'total_cost' => 0, // No show means they didn't pay the rest
        'ktv' => $ns['table_type'] === 'ktv' ? 'Yes' : 'No',
        'cashier' => $ns['cashier_name'] ? ucwords(str_replace('_', ' ', $ns['cashier_name'])) : 'Unknown',
        'transaction_date' => "'" . date('m/d/Y h:i A', strtotime($ns['created_at'])),
        'shift' => $shiftForRow
    ];
}

foreach ($sessions as $s) {
    $startTimeTs = strtotime($s['start_time']);
    $endTime = $s['end_time'] ?? $s['scheduled_end_time'];
    $endTimeTs = strtotime($endTime);
    $durSecs = (int) $s['duration_seconds'];
    if ($durSecs == 0) {
        $durSecs = max(0, $endTimeTs - $startTimeTs);
    }

    $h = intdiv((int) $durSecs, 3600);
    $m = intdiv((int) $durSecs % 3600, 60);
    $s = $durSecs % 60;
    $totalTimeFmt = sprintf('%02d:%02d:%02d', $h, $m, $s);

    $shiftForRow = getShiftLabel($s['start_time'], $morningStartMinutes, $eveningStartMinutes);
    if ($shiftFilter === 'morning' && $shiftForRow !== 'MORNING')
        continue;
    if ($shiftFilter === 'evening' && $shiftForRow !== 'EVENING')
        continue;

    $allRows[] = [
        'id' => ($s['reservation_id'] ? 'R-' . $s['reservation_id'] : 'S-' . $s['id']),
        'customer' => $s['c_name'] ?: ($s['walk_in_name'] ?: 'Walk-in'),
        'table_type' => $s['table_type'],
        'table' => $s['table_type'] === 'vip' ? 'VIP Room' : ($s['table_type'] === 'ktv' ? 'KTV Room' : 'Regular Table'),
        'time_range' => "'" . date('g:i A', $startTimeTs) . ' - ' . date('g:i A', $endTimeTs),
        'total_time' => $totalTimeFmt,
        'downpayment' => (float) ($s['down_payment'] ?? 0),
        'total_cost' => (float) $s['total_amount'],
        'ktv' => $s['table_type'] === 'ktv' ? 'Yes' : 'No',
        'cashier' => $s['cashier_name'] ? ucwords(str_replace('_', ' ', $s['cashier_name'])) : 'Unknown',
        'transaction_date' => "'" . date('m/d/Y h:i A', strtotime($s['end_time'] ?? $s['created_at'])),
        'shift' => $shiftForRow
    ];
}

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
$nightEndDisplay = date('g:i A', strtotime($nightEnd));

// Grouping structure
$shifts = [
    'MORNING' => ['vip' => [], 'ktv' => [], 'regular' => []],
    'EVENING' => ['vip' => [], 'ktv' => [], 'regular' => []]
];

$grandTotalVip = 0;
$grandTotalKtv = 0;
$grandTotalRegular = 0;

foreach ($allRows as $processed) {
    $typeGroup = $processed['table_type']; // 'vip', 'ktv', 'regular'
    $shiftForRow = $processed['shift'];

    $shifts[$shiftForRow][$typeGroup][] = $processed;

    // Add to grand totals
    if ($typeGroup === 'vip')
        $grandTotalVip += $processed['total_cost'];
    elseif ($typeGroup === 'ktv')
        $grandTotalKtv += $processed['total_cost'];
    else
        $grandTotalRegular += $processed['total_cost'];
}

echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
echo '<head><meta charset="UTF-8"></head><body>';
echo '<table border="1" style="font-family: Calibri, sans-serif;">';

// Helper function to print columns header
function print_columns_header()
{
    echo '<tr>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: center; border: 1px solid #000;">Reservation ID</th>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: center; border: 1px solid #000;">Customer</th>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: center; border: 1px solid #000;">Table / Room</th>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: center; border: 1px solid #000;">Time Range</th>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: center; border: 1px solid #000;">Total Time</th>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: center; border: 1px solid #000;">Downpayment (P)</th>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: center; border: 1px solid #000;">Total Cost (P)</th>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: center; border: 1px solid #000;">KTV</th>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: center; border: 1px solid #000;">Cashier</th>';
    echo '<th style="background-color: #5cb85c; color: white; font-weight: bold; text-align: center; border: 1px solid #000;">TransactionDate</th>';
    echo '</tr>';
}

// Helper function to print a group of rows
function print_group_rows($groupLabel, $shiftLabel, $rows, $printHeaders = false)
{
    echo '<tr>';
    echo '<td style="font-weight: bold; border: 1px solid #000; text-align: center;">' . $groupLabel . '</td>';
    if ($shiftLabel !== null) {
        echo '<td style="font-weight: bold; border: 1px solid #000; text-align: center;">' . $shiftLabel . '</td>';
        echo '<td colspan="8" style="border: 1px solid #000;"></td>';
    } else {
        echo '<td colspan="9" style="border: 1px solid #000;"></td>';
    }
    echo '</tr>';

    if ($printHeaders) {
        print_columns_header();
    }

    if (empty($rows)) {
        echo '<tr><td colspan="10" style="border: 1px solid #000;"></td></tr>';
        return;
    }

    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td style="text-align: center; border: 1px solid #000;">' . htmlspecialchars((string) $row['id']) . '</td>';
        echo '<td style="text-align: center; border: 1px solid #000;">' . htmlspecialchars((string) $row['customer']) . '</td>';
        echo '<td style="text-align: center; border: 1px solid #000;">' . htmlspecialchars((string) $row['table']) . '</td>';
        echo '<td style="text-align: center; border: 1px solid #000;">' . htmlspecialchars((string) $row['time_range']) . '</td>';
        echo '<td style="text-align: center; border: 1px solid #000;">' . htmlspecialchars((string) $row['total_time']) . '</td>';
        echo '<td style="text-align: center; border: 1px solid #000;">' . number_format($row['downpayment'], 0) . '</td>';
        echo '<td style="text-align: center; border: 1px solid #000;">' . number_format($row['total_cost'], 0) . '</td>';
        echo '<td style="text-align: center; border: 1px solid #000;">' . $row['ktv'] . '</td>';
        echo '<td style="text-align: center; border: 1px solid #000;">' . htmlspecialchars((string) $row['cashier']) . '</td>';
        echo '<td style="text-align: center; border: 1px solid #000;">' . htmlspecialchars((string) $row['transaction_date']) . '</td>';
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
        : "EVENING SHIFT ({$eveningStartDisplay} - {$nightEndDisplay} Next Day)";

    // 1st Row: RESERVATION + Shift info
    echo '<tr>';
    echo '<td colspan="10" style="background-color: #dff0d8; text-align: center; font-weight: bold; border: 1px solid #000;">RESERVATION</td>';
    echo '</tr>';

    // Groups — print headers only once per shift
    print_group_rows('VIP ROOM', $shift, $shifts[$shift]['vip'], true);
    print_group_rows('KTV ROOM', null, $shifts[$shift]['ktv'], false);

    if (!empty($shifts[$shift]['regular'])) {
        print_group_rows('REGULAR TABLE', null, $shifts[$shift]['regular'], false);
    }

    // Shift Total
    $shiftTotal = 0;
    foreach ($shifts[$shift] as $typeGrp) {
        foreach ($typeGrp as $r) {
            $shiftTotal += $r['total_cost'];
        }
    }

    echo '<tr>';
    echo '<td colspan="5" style="border: none;"></td>';
    echo '<td style="background-color: #fcd5b4; font-weight: bold; text-align: center; border: 1px solid #000;">TOTAL</td>';
    echo '<td style="background-color: #fcd5b4; font-weight: bold; text-align: center; border: 1px solid #000;">' . number_format($shiftTotal, 2) . '</td>';
    echo '<td colspan="3" style="border: none;"></td>';
    echo '</tr>';

    echo '<tr><td colspan="10" style="border:none; height:30px;"></td></tr>';
}

// Grand Totals at the very bottom
echo '<tr>';
echo '<td colspan="5" style="border: none;"></td>';
echo '<td style="font-weight: bold; color: #e67e22; text-align: right; border: 1px solid #000;">VIP GRAND TOTAL:</td>';
echo '<td style="font-weight: bold; color: #e67e22; text-align: center; border: 1px solid #000;">' . number_format($grandTotalVip, 0) . '</td>';
echo '<td colspan="3" style="border: none;"></td>';
echo '</tr>';

echo '<tr>';
echo '<td colspan="5" style="border: none;"></td>';
echo '<td style="font-weight: bold; color: #e67e22; text-align: right; border: 1px solid #000;">KTV GRAND TOTAL:</td>';
echo '<td style="font-weight: bold; color: #e67e22; text-align: center; border: 1px solid #000;">' . number_format($grandTotalKtv, 0) . '</td>';
echo '<td colspan="3" style="border: none;"></td>';
echo '</tr>';

if ($grandTotalRegular > 0) {
    echo '<tr>';
    echo '<td colspan="5" style="border: none;"></td>';
    echo '<td style="font-weight: bold; color: #e67e22; text-align: right; border: 1px solid #000;">REGULAR GRAND TOTAL:</td>';
    echo '<td style="font-weight: bold; color: #e67e22; text-align: center; border: 1px solid #000;">' . number_format($grandTotalRegular, 0) . '</td>';
    echo '<td colspan="3" style="border: none;"></td>';
    echo '</tr>';
}

echo '</table>';
echo '</body></html>';
exit;
