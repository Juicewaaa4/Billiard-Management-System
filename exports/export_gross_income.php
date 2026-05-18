<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/util.php';

start_app_session();
require_role(['admin']);

$dtFromStr = (string)($_GET['dt_from'] ?? date('Y-m-01'));
$dtToStr   = (string)($_GET['dt_to'] ?? date('Y-m-t'));

$dtFrom = parse_date($dtFromStr);
$dtTo   = parse_date($dtToStr);

if (!$dtFrom || !$dtTo) {
    die("Invalid date range");
}

// Fetch all tables
$tablesStmt = db()->query("SELECT id, table_number, type FROM tables WHERE is_deleted = 0 ORDER BY type DESC, id ASC");
$tables = $tablesStmt->fetchAll(PDO::FETCH_ASSOC);

// We need to fetch all game_sessions and kubo_rentals in this date range
$startBound = $dtFrom . ' 00:00:00';
$endBound   = $dtTo . ' 23:59:59';

// Fetch Game Sessions (Regular, VIP, KTV/Kubo Karaoke)
$gsStmt = db()->prepare("
    SELECT table_id, end_time, total_amount 
    FROM game_sessions 
    WHERE is_voided = 0 AND end_time IS NOT NULL 
      AND end_time >= ? AND end_time <= ?
");
$gsStmt->execute([$startBound, $endBound]);
$sessions = $gsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Kubo Rentals (Kubo)
$krStmt = db()->prepare("
    SELECT table_id, end_time, payment_amount 
    FROM kubo_rentals 
    WHERE end_time IS NOT NULL 
      AND end_time >= ? AND end_time <= ?
");
$krStmt->execute([$startBound, $endBound]);
$rentals = $krStmt->fetchAll(PDO::FETCH_ASSOC);

// Group data by business date and table
$incomeData = [];
$dates = [];
$currentTs = strtotime($dtFrom);
$endTsObj = strtotime($dtTo);

while ($currentTs <= $endTsObj) {
    $d = date('Y-m-d', $currentTs);
    $dates[] = $d;
    $incomeData[$d] = [];
    foreach ($tables as $t) {
        $incomeData[$d][$t['id']] = 0.0;
    }
    $currentTs += 86400; // +1 day
}

// Process sessions
foreach ($sessions as $s) {
    $bDate = date('Y-m-d', strtotime($s['end_time']));
    if (isset($incomeData[$bDate][$s['table_id']])) {
        $incomeData[$bDate][$s['table_id']] += (float)$s['total_amount'];
    }
}

// Process kubo rentals
foreach ($rentals as $r) {
    $bDate = date('Y-m-d', strtotime($r['end_time']));
    if (isset($incomeData[$bDate][$r['table_id']])) {
        $incomeData[$bDate][$r['table_id']] += (float)$r['payment_amount'];
    }
}

$exportTitle = "Gross_Income_Report_{$dtFrom}_to_{$dtTo}.xls";

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $exportTitle . '"');
header("Pragma: no-cache");
header("Expires: 0");

$titleMonth = date('F Y', strtotime($dtFrom));
if (date('m', strtotime($dtFrom)) !== date('m', strtotime($dtTo))) {
    $titleMonth = date('F d, Y', strtotime($dtFrom)) . ' - ' . date('F d, Y', strtotime($dtTo));
} else if ($dtFrom !== date('Y-m-01', strtotime($dtFrom)) || $dtTo !== date('Y-m-t', strtotime($dtTo))) {
    $titleMonth = date('F d, Y', strtotime($dtFrom)) . ' to ' . date('F d, Y', strtotime($dtTo));
} else {
    $titleMonth = "THE MONTH OF " . strtoupper(date('F Y', strtotime($dtFrom)));
}

echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
echo '<head><meta charset="UTF-8">';
echo '<style>
    .hdr-main { text-align: right; font-size: 16px; font-weight: bold; }
    .hdr-sub { text-align: left; font-size: 12px; }
    .table-hdr { background-color: #548235; color: white; font-weight: bold; text-align: center; border: 1px solid #000; vertical-align: middle; }
    .cell-date { text-align: left; border: 1px solid #000; }
    .cell-amt { text-align: right; border: 1px solid #000; }
    .cell-amt-zero { text-align: right; border: 1px solid #000; color: #555; }
    .row-total { background-color: #FFC000; font-weight: bold; }
    .row-total td { border: 1px solid #000; }
    .sign-title { font-weight: bold; text-align: center; }
    .sign-name { font-weight: bold; text-align: center; text-decoration: underline; }
    .sign-pos { text-align: center; font-style: italic; }
</style>';
echo '</head><body>';

$colCount = count($tables) + 2; // Date + Tables + Total

echo '<table border="0" cellpadding="3" cellspacing="0" style="font-family: Calibri, sans-serif; font-size: 11px;">';

// Header row 1
echo '<tr>';
echo '<td colspan="' . $colCount . '" class="hdr-main">ZOEY\'S BILLIARD HOUSE GROSS INCOME PER TABLE REPORT</td>';
echo '</tr>';

// Header row 2
echo '<tr>';
echo '<td colspan="' . $colCount . '" class="hdr-sub">FOR ' . $titleMonth . '</td>';
echo '</tr>';

echo '<tr><td colspan="' . $colCount . '"></td></tr>'; // Spacer

// Table Headers
echo '<tr>';
echo '<td rowspan="2" class="table-hdr" style="width:100px;">DATE</td>';
echo '<td colspan="' . count($tables) . '" class="table-hdr">BILLIARDS</td>';
echo '<td rowspan="2" class="table-hdr" style="width:120px;">TOTAL</td>';
echo '</tr>';

echo '<tr>';
foreach ($tables as $t) {
    // Format name (e.g. "Table 1" -> "TABLE 1")
    $name = strtoupper($t['table_number']);
    echo '<td class="table-hdr" style="background-color: #70AD47; width:90px;">' . htmlspecialchars($name) . '</td>';
}
echo '</tr>';

$grandTotals = array_fill(0, count($tables), 0.0);
$superGrandTotal = 0.0;

foreach ($dates as $d) {
    $rowTotal = 0.0;
    
    // Grouping by week logic for border (optional, but let's just make regular rows)
    $dayNum = (int)date('N', strtotime($d)); // 1 (Mon) - 7 (Sun)
    $rowStyle = ($dayNum === 7) ? 'border-bottom: 2px solid #000;' : ''; // Thicker border end of week
    
    echo '<tr style="' . $rowStyle . '">';
    // Date column (e.g., 01-Apr-26)
    echo '<td class="cell-date">' . date('d-M-y', strtotime($d)) . '</td>';
    
    // Tables columns
    $i = 0;
    foreach ($tables as $t) {
        $amt = $incomeData[$d][$t['id']];
        $grandTotals[$i] += $amt;
        $rowTotal += $amt;
        
        if ($amt > 0) {
            echo '<td class="cell-amt" style="mso-number-format:\'\[$₱\]\\ \#\,\#\#0\.00\'">' . number_format($amt, 2, '.', '') . '</td>';
        } else {
            echo '<td class="cell-amt-zero" style="text-align:right;"> - </td>';
        }
        $i++;
    }
    
    $superGrandTotal += $rowTotal;
    
    // Row Total
    if ($rowTotal > 0) {
        echo '<td class="cell-amt row-total" style="mso-number-format:\'\[$₱\]\\ \#\,\#\#0\.00\'">' . number_format($rowTotal, 2, '.', '') . '</td>';
    } else {
        echo '<td class="cell-amt-zero row-total" style="text-align:right;"> - </td>';
    }
    
    echo '</tr>';
}

// Final Grand Total Row
echo '<tr class="row-total">';
echo '<td style="text-align:center;">TOTAL</td>';
for ($i = 0; $i < count($tables); $i++) {
    $amt = $grandTotals[$i];
    if ($amt > 0) {
        echo '<td class="cell-amt" style="mso-number-format:\'\[$₱\]\\ \#\,\#\#0\.00\'">' . number_format($amt, 2, '.', '') . '</td>';
    } else {
        echo '<td class="cell-amt-zero" style="text-align:right;"> - </td>';
    }
}
// Super Grand Total
echo '<td class="cell-amt" style="mso-number-format:\'\[$₱\]\\ \#\,\#\#0\.00\'">' . number_format($superGrandTotal, 2, '.', '') . '</td>';
echo '</tr>';

echo '</table>';

// Signatures
echo '<br><br><br>';
echo '<table border="0" style="font-family: Calibri, sans-serif; font-size: 11px;">';
echo '<tr>';
echo '<td colspan="3"></td>';
echo '<td colspan="3" class="sign-title">PREPARED BY:</td>';
echo '<td colspan="' . max(1, count($tables) - 6) . '"></td>';
echo '<td colspan="3" class="sign-title">CHECKED & NOTED BY:</td>';
echo '</tr>';
echo '<tr><td colspan="' . $colCount . '" style="height:40px;"></td></tr>'; // Space for signature

echo '<tr>';
echo '<td colspan="3"></td>';
echo '<td colspan="3" class="sign-name">TRECIA E. DE JESUS</td>';
echo '<td colspan="' . max(1, count($tables) - 6) . '"></td>';
echo '<td colspan="3" class="sign-name">ENRIQUE DM. MARTINEZ</td>';
echo '</tr>';

echo '<tr>';
echo '<td colspan="3"></td>';
echo '<td colspan="3" class="sign-pos">Manager</td>';
echo '<td colspan="' . max(1, count($tables) - 6) . '"></td>';
echo '<td colspan="3" class="sign-pos">Owner</td>';
echo '</tr>';

echo '</table>';

echo '</body></html>';
exit;
