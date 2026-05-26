<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/util.php';

start_app_session();
require_role(['admin']);

$dtFromStr = (string)($_GET['dt_from'] ?? date('Y-m-d', strtotime('-7 days')));
$dtToStr   = (string)($_GET['dt_to'] ?? date('Y-m-d', strtotime('-1 days')));
$reportType = (string)($_GET['type'] ?? 'monthly'); // 'weekly' or 'monthly'

$dtFrom = parse_date($dtFromStr);
$dtTo   = parse_date($dtToStr);

if (!$dtFrom || !$dtTo) {
    die("Invalid date range");
}

// Fetch all non-kubo tables (regular, vip, ktv) ordered nicely
$regularTables = db()->query("SELECT id, table_number, type FROM tables WHERE is_deleted = 0 AND type IN ('regular','vip','ktv') ORDER BY CASE type WHEN 'regular' THEN 1 WHEN 'vip' THEN 2 WHEN 'ktv' THEN 3 END, id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Date bounds
$startBound = $dtFrom . ' 00:00:00';
$endBound   = $dtTo . ' 23:59:59';

// Fetch all game sessions (regular, vip, ktv, and kubo karaoke) in range
$gsStmt = db()->prepare("
    SELECT table_id, end_time, total_amount, t.type AS table_type
    FROM game_sessions gs
    JOIN tables t ON t.id = gs.table_id
    WHERE gs.is_voided = 0 AND gs.end_time IS NOT NULL
      AND gs.end_time >= ? AND gs.end_time <= ?
");
$gsStmt->execute([$startBound, $endBound]);
$sessions = $gsStmt->fetchAll(PDO::FETCH_ASSOC);

// Build date list
$dates = [];
$currentTs = strtotime($dtFrom);
$endTs = strtotime($dtTo);
while ($currentTs <= $endTs) {
    $dates[] = date('Y-m-d', $currentTs);
    $currentTs += 86400;
}

// Initialize income data arrays per date
$incomeByDate = [];    // [date][table_id] = amount
foreach ($dates as $d) {
    $incomeByDate[$d] = [];
    foreach ($regularTables as $t) {
        $incomeByDate[$d][$t['id']] = 0.0;
    }
}

// Process game sessions
foreach ($sessions as $s) {
    $d = date('Y-m-d', strtotime($s['end_time']));
    if (!isset($incomeByDate[$d])) continue;
    $tid = (int)$s['table_id'];
    if (isset($incomeByDate[$d][$tid])) {
        $incomeByDate[$d][$tid] += (float)$s['total_amount'];
    }
}

// Title
if ($reportType === 'weekly') {
    $titleLine = 'FOR THE WEEK OF ' . strtoupper(date('M d', strtotime($dtFrom))) . ' - ' . strtoupper(date('M d, Y', strtotime($dtTo)));
    $fileTitle = 'Weekly_Gross_Income_' . $dtFrom . '_to_' . $dtTo . '.xls';
} else {
    if ($dtFrom === date('Y-m-01', strtotime($dtFrom)) && $dtTo === date('Y-m-t', strtotime($dtFrom))) {
        $titleLine = 'FOR THE MONTH OF ' . strtoupper(date('F Y', strtotime($dtFrom)));
    } else {
        $titleLine = 'FOR THE PERIOD OF ' . strtoupper(date('M d', strtotime($dtFrom))) . ' - ' . strtoupper(date('M d, Y', strtotime($dtTo)));
    }
    $fileTitle = 'Monthly_Gross_Income_' . date('Y_m', strtotime($dtFrom)) . '.xls';
}

// Total columns = DATE(1) + regularTables + TOTAL(1)
$tableCols = count($regularTables);
$totalCols = 1 + $tableCols + 1; // DATE + tables + TOTAL

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fileTitle . '"');
header('Pragma: no-cache');
header('Expires: 0');

?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head><meta charset="UTF-8">
<style>
  body { font-family: Calibri, Arial, sans-serif; font-size: 11px; }
  table { border-collapse: collapse; }
  .title-line { font-size: 11px; font-weight: normal; }
  .hdr-billiards {
    background-color: #548235; color: white; font-weight: bold;
    text-align: center; border: 1px solid #000; vertical-align: middle;
  }
  .hdr-date {
    background-color: #548235; color: white; font-weight: bold;
    text-align: center; border: 1px solid #000; vertical-align: middle;
  }
  .hdr-total {
    background-color: #548235; color: white; font-weight: bold;
    text-align: center; border: 1px solid #000; vertical-align: middle;
  }
  .hdr-table {
    background-color: #70AD47; color: white; font-weight: bold;
    text-align: center; border: 1px solid #000;
  }
  .cell-date { text-align: left; border: 1px solid #ccc; padding: 2px 6px; }
  .cell-amt  { text-align: right; border: 1px solid #ccc; padding: 2px 4px; }
  .cell-zero { text-align: right; border: 1px solid #ccc; padding: 2px 4px; color: #333; }
  .cell-rowtotal { text-align: right; border: 1px solid #ccc; padding: 2px 4px; font-weight: bold; }
  .row-total-row { background-color: #FFC000; font-weight: bold; }
  .row-total-row td { border: 1px solid #000; padding: 2px 4px; }
  .week-sep td { height: 6px; border: none; background-color: transparent; }
  .sign-label { font-weight: bold; text-align: center; }
  .sign-name  { font-weight: bold; text-align: center; text-decoration: underline; }
  .sign-pos   { text-align: center; font-style: italic; }
</style>
</head>
<body>
<table border="0" cellpadding="2" cellspacing="0">

  <?php /* ── Title Row ── */ ?>
  <tr>
    <td colspan="<?= $totalCols ?>" class="title-line"><?= htmlspecialchars($titleLine) ?></td>
  </tr>
  <tr><td colspan="<?= $totalCols ?>"></td></tr>

  <?php /* ── Header Row 1: DATE | BILLIARDS (spanning) | TOTAL ── */ ?>
  <tr>
    <td rowspan="2" class="hdr-date" style="width:90px;">DATE</td>
    <td colspan="<?= $tableCols ?>" class="hdr-billiards">BILLIARDS</td>
    <td rowspan="2" class="hdr-total" style="width:110px;">TOTAL</td>
  </tr>

  <?php /* ── Header Row 2: individual table names ── */ ?>
  <tr>
    <?php foreach ($regularTables as $t): ?>
      <td class="hdr-table" style="width:88px;"><?= htmlspecialchars(strtoupper($t['table_number'])) ?></td>
    <?php endforeach; ?>
  </tr>

  <?php
  // Track grand totals
  $grandCols  = array_fill(0, $tableCols, 0.0);
  $grandTotal = 0.0;

  // Group dates by week (Mon–Sun)
  $weeks = [];
  $weekIdx = 0;
  foreach ($dates as $d) {
    $dow = (int)date('N', strtotime($d)); // 1=Mon, 7=Sun
    $weeks[$weekIdx][] = $d;
    if ($dow === 7) $weekIdx++;          // end of week → new group
  }
  $isFirst = true;
  foreach ($weeks as $wIdx => $weekDates):
    if (!$isFirst): ?>
      <?php /* blank separator row between weeks */ ?>
      <tr class="week-sep"><td colspan="<?= $totalCols ?>"></td></tr>
    <?php endif; $isFirst = false;
    $isFirstRowOfWeek = true;
    foreach ($weekDates as $d):
      $rowTotal = 0;
      $colAmts  = [];
      foreach ($regularTables as $t) {
          $a = $incomeByDate[$d][$t['id']];
          $colAmts[] = $a;
          $rowTotal += $a;
      }
      $grandTotal += $rowTotal;
      foreach ($colAmts as $ci => $a) $grandCols[$ci] += $a;
      ?>
      <tr>
        <td class="cell-date"><?= date('d-M-y', strtotime($d)) ?></td>

        <?php foreach ($colAmts as $ci => $a): ?>
          <?php if ($isFirstRowOfWeek): ?>
            <td class="cell-amt" style="mso-number-format:'\[$₱\]\ \#\,\#\#0\.00'">
              <?php if ($a > 0): ?>&#8369;&nbsp;<?= number_format($a, 2) ?><?php else: ?>&#8369;&nbsp;<?= number_format(0, 2) ?><?php endif; ?>
            </td>
          <?php else: ?>
            <td class="cell-<?= $a > 0 ? 'amt' : 'zero' ?>" style="mso-number-format:'\[$₱\]\ \#\,\#\#0\.00'"><?= number_format($a, 2) ?></td>
          <?php endif; ?>
        <?php endforeach; ?>

        <?php /* Row Total */ ?>
        <td class="cell-rowtotal" style="mso-number-format:'\[$₱\]\ \#\,\#\#0\.00'"><?= number_format($rowTotal, 2) ?></td>
      </tr>
      <?php $isFirstRowOfWeek = false;
    endforeach;
  endforeach; ?>

  <?php /* ── Grand Total Row ── */ ?>
  <tr class="row-total-row">
    <td style="text-align:center; font-weight:bold;">TOTAL</td>
    <?php foreach ($grandCols as $gc): ?>
      <td style="text-align:right; mso-number-format:'\[$₱\]\ \#\,\#\#0\.00'"><?= number_format($gc, 2) ?></td>
    <?php endforeach; ?>
    <td style="text-align:right; mso-number-format:'\[$₱\]\ \#\,\#\#0\.00'"><?= number_format($grandTotal, 2) ?></td>
  </tr>

</table>

<?php /* ── Signatures ── */ ?>
<br><br><br>
<table border="0" cellpadding="3" cellspacing="0" style="font-family:Calibri,sans-serif; font-size:11px; min-width:700px;">
  <tr>
    <td width="60"></td>
    <td colspan="3" class="sign-label">PREPARED BY:</td>
    <td width="80"></td>
    <td colspan="3" class="sign-label">CHECKED &amp; NOTED BY:</td>
  </tr>
  <tr><td colspan="8" style="height:36px;"></td></tr>
  <tr>
    <td width="60"></td>
    <td colspan="3" class="sign-name">TRECIA E. DE JESUS</td>
    <td width="80"></td>
    <td colspan="3" class="sign-name">ENRIQUE DM. MARTINEZ</td>
  </tr>
  <tr>
    <td width="60"></td>
    <td colspan="3" class="sign-pos">Manager</td>
    <td width="80"></td>
    <td colspan="3" class="sign-pos">Owner</td>
  </tr>
</table>

</body>
</html>
