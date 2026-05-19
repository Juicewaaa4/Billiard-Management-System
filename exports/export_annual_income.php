<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/util.php';

start_app_session();
require_role(['admin']);

$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');

// Fetch all non-kubo tables (regular, vip, ktv) ordered nicely
$regularTables = db()->query("SELECT id, table_number, type FROM tables WHERE is_deleted = 0 AND type IN ('regular','vip','ktv') ORDER BY CASE type WHEN 'regular' THEN 1 WHEN 'vip' THEN 2 WHEN 'ktv' THEN 3 END, id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all game sessions in the year grouped by month and table
$gsStmt = db()->prepare("
    SELECT table_id, MONTH(end_time) AS month, SUM(total_amount) AS total_amount
    FROM game_sessions gs
    WHERE gs.is_voided = 0 AND gs.end_time IS NOT NULL AND YEAR(end_time) = ?
    GROUP BY table_id, MONTH(end_time)
");
$gsStmt->execute([$year]);
$sessions = $gsStmt->fetchAll(PDO::FETCH_ASSOC);

// Build income map [month][table_id] = amount
$incomeByMonth = [];
for ($m = 1; $m <= 12; $m++) {
    $incomeByMonth[$m] = [];
    foreach ($regularTables as $t) {
        $incomeByMonth[$m][$t['id']] = 0.0;
    }
}

foreach ($sessions as $s) {
    $m = (int)$s['month'];
    $tid = (int)$s['table_id'];
    if (isset($incomeByMonth[$m][$tid])) {
        $incomeByMonth[$m][$tid] += (float)$s['total_amount'];
    }
}

$tableCols = count($regularTables);
$totalCols = 1 + $tableCols + 1; // MONTH + tables + TOTAL

$titleLine = 'ANNUAL GROSS INCOME REPORT FOR ' . strtoupper(date('Y', mktime(0,0,0,1,1,$year)));
$fileTitle = 'Annual_Gross_Income_' . $year . '.xls';

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
  .cell-date { text-align: left; border: 1px solid #ccc; padding: 2px 6px; font-weight: bold; }
  .cell-amt  { text-align: right; border: 1px solid #ccc; padding: 2px 4px; }
  .cell-zero { text-align: right; border: 1px solid #ccc; padding: 2px 4px; color: #aaa; }
  .cell-rowtotal { text-align: right; border: 1px solid #ccc; padding: 2px 4px; font-weight: bold; }
  .row-total-row { background-color: #FFC000; font-weight: bold; }
  .row-total-row td { border: 1px solid #000; padding: 2px 4px; }
  .quarter-sep td { height: 4px; border: none; background-color: transparent; }
  .quarter-label td { background-color: #d9e1f2; font-weight: bold; font-style: italic; border: 1px solid #bbb; padding: 2px 6px; }
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

  <?php /* ── Header Row 1: MONTH | BILLIARDS (spanning) | TOTAL ── */ ?>
  <tr>
    <td rowspan="2" class="hdr-date" style="width:100px;">MONTH</td>
    <td colspan="<?= count($regularTables) ?>" class="hdr-billiards">BILLIARDS</td>
    <td rowspan="2" class="hdr-total" style="width:110px;">TOTAL</td>
  </tr>

  <?php /* ── Header Row 2: individual table names ── */ ?>
  <tr>
    <?php foreach ($regularTables as $t): ?>
      <td class="hdr-table" style="width:88px;"><?= htmlspecialchars(strtoupper($t['table_number'])) ?></td>
    <?php endforeach; ?>
  </tr>

  <?php
  $grandCols  = array_fill(0, count($regularTables), 0.0);
  $grandTotal = 0.0;

  $quarterTotals = [];
  $months = [
    1=>'January', 2=>'February', 3=>'March', 4=>'April',
    5=>'May', 6=>'June', 7=>'July', 8=>'August',
    9=>'September', 10=>'October', 11=>'November', 12=>'December'
  ];
  $quarters = [1=>[1,2,3], 2=>[4,5,6], 3=>[7,8,9], 4=>[10,11,12]];

  foreach ($quarters as $qNum => $qMonths):
    // Quarter separator + label
    $qLabels = ['1st Quarter (Jan – Mar)', '2nd Quarter (Apr – Jun)', '3rd Quarter (Jul – Sep)', '4th Quarter (Oct – Dec)'];
  ?>
    <?php if ($qNum > 1): ?>
    <tr class="quarter-sep"><td colspan="<?= $totalCols ?>"></td></tr>
    <?php endif; ?>
    <tr class="quarter-label">
      <td colspan="<?= $totalCols ?>"><?= $qLabels[$qNum - 1] ?></td>
    </tr>

    <?php
    $qColTotals = array_fill(0, count($regularTables), 0.0);
    $qTotal = 0.0;
    foreach ($qMonths as $m):
      $colAmts = [];
      $rowTotal = 0.0;
      foreach ($regularTables as $ci => $t) {
          $a = $incomeByMonth[$m][$t['id']] ?? 0.0;
          $colAmts[] = $a;
          $rowTotal += $a;
          $qColTotals[$ci] += $a;
          $grandCols[$ci] += $a;
      }
      $qTotal += $rowTotal;
      $grandTotal += $rowTotal;
    ?>
    <tr>
      <td class="cell-date"><?= $months[$m] ?></td>
      <?php foreach ($colAmts as $a): ?>
        <td class="cell-<?= $a > 0 ? 'amt' : 'zero' ?>" style="mso-number-format:'\[$₱\]\ \#\,\#\#0\.00'"><?= number_format($a, 2) ?></td>
      <?php endforeach; ?>
      <td class="cell-rowtotal" style="mso-number-format:'\[$₱\]\ \#\,\#\#0\.00'"><?= number_format($rowTotal, 2) ?></td>
    </tr>
    <?php endforeach; ?>

    <!-- Quarter subtotal row -->
    <tr style="background-color:#e2efda; font-weight:bold;">
      <td style="text-align:center; border:1px solid #999; font-style:italic;">Q<?= $qNum ?> TOTAL</td>
      <?php foreach ($qColTotals as $gc): ?>
        <td style="text-align:right; border:1px solid #999; mso-number-format:'\[$₱\]\ \#\,\#\#0\.00'"><?= number_format($gc, 2) ?></td>
      <?php endforeach; ?>
      <td style="text-align:right; border:1px solid #999; mso-number-format:'\[$₱\]\ \#\,\#\#0\.00'"><?= number_format($qTotal, 2) ?></td>
    </tr>

  <?php endforeach; ?>

  <?php /* ── Grand Total Row ── */ ?>
  <tr class="quarter-sep"><td colspan="<?= $totalCols ?>"></td></tr>
  <tr class="row-total-row">
    <td style="text-align:center; font-weight:bold;">GRAND TOTAL</td>
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
