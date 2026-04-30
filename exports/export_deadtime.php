<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/util.php';

start_app_session();
require_role(['admin']);

$dtFrom  = (string)($_GET['dt_from']  ?? date('Y-m-d'));
$dtTo    = (string)($_GET['dt_to']    ?? date('Y-m-d'));
$dtStart = (string)($_GET['dt_start'] ?? '08:00');
$dtEnd   = (string)($_GET['dt_end']   ?? '02:00');

// ── Format duration ──
function fmtDur(int $secs): string {
    $totalMins = max(0, (int)round($secs / 60));
    $h = intdiv($totalMins, 60);
    $m = $totalMins % 60;
    if ($h > 0 && $m > 0) return "{$h}hr {$m}min";
    if ($h > 0) return "{$h}hr";
    if ($m > 0) return "{$m}min";
    return "0min";
}

// ── Get all active tables ──
$allTables = db()->query("
    SELECT id, table_number, type FROM tables WHERE is_deleted = 0 AND type != 'kubo'
    ORDER BY CASE type WHEN 'regular' THEN 1 WHEN 'vip' THEN 2 WHEN 'ktv' THEN 3 END, table_number ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Calculate operating window timestamps for a given date ──
function opWindow(string $date, string $startT, string $endT): array {
    $s = strtotime("$date $startT");
    $e = strtotime("$date $endT");
    if ($endT === '00:00' || $endT === '24:00') {
        $e = strtotime($date) + 86400;
    } elseif ($e <= $s) {
        $e += 86400;
    }
    return [$s, $e];
}

// ── Compute dead time slots for one table on one day ──
function deadPeriodsForTable(int $tableId, string $date, string $startT, string $endT): array {
    [$opStart, $opEnd] = opWindow($date, $startT, $endT);
    $wS = date('Y-m-d H:i:s', $opStart);
    $wE = date('Y-m-d H:i:s', $opEnd);

    $st = db()->prepare("
        SELECT start_time, COALESCE(end_time, scheduled_end_time) AS eff_end
        FROM game_sessions
        WHERE table_id = ? AND is_voided = 0
        AND start_time < ?
        AND COALESCE(end_time, scheduled_end_time, '2099-12-31 23:59:59') > ?
        ORDER BY start_time ASC
    ");
    $st->execute([$tableId, $wE, $wS]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $dead = [];
    $cursor = $opStart;
    foreach ($rows as $r) {
        $ss = max(strtotime($r['start_time']), $opStart);
        $se = $r['eff_end'] ? min(strtotime($r['eff_end']), $opEnd) : $opEnd;
        if ($ss > $cursor && ($ss - $cursor) >= 60) {
            $dead[] = ['from' => $cursor, 'to' => $ss, 'dur' => $ss - $cursor];
        }
        $cursor = max($cursor, $se);
    }
    if ($cursor < $opEnd && ($opEnd - $cursor) >= 60) {
        $dead[] = ['from' => $cursor, 'to' => $opEnd, 'dur' => $opEnd - $cursor];
    }
    return $dead;
}

// ── Process each day in range ──
$daysList = [];
$cur = $dtFrom;
while (strtotime($cur) <= strtotime($dtTo)) {
    $daysList[] = $cur;
    $cur = date('Y-m-d', strtotime("$cur +1 day"));
}

$allData = [];
foreach ($daysList as $day) {
    $allData[$day] = [];
    foreach ($allTables as $t) {
        $allData[$day][(int)$t['id']] = deadPeriodsForTable((int)$t['id'], $day, $dtStart, $dtEnd);
    }
}

// ── Income breakdown (for the entire selected range) ──
$incStmt = db()->prepare("
    SELECT t.type, COALESCE(SUM(gs.total_amount),0) AS total
    FROM game_sessions gs JOIN tables t ON t.id = gs.table_id
    WHERE DATE(gs.start_time) >= ? AND DATE(gs.start_time) <= ? AND gs.end_time IS NOT NULL AND gs.is_voided = 0
    GROUP BY t.type
");
$incStmt->execute([$dtFrom, $dtTo]);
$inc = [];
foreach ($incStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $inc[$r['type']] = (float)$r['total'];
$walkIn     = $inc['regular'] ?? 0;
$vipInc     = $inc['vip']     ?? 0;
$ktvInc     = $inc['ktv']     ?? 0;
$grandTotal = $walkIn + $vipInc + $ktvInc;

// ── Excel output ──
$fn = "DeadTime_{$dtFrom}_to_{$dtTo}.xls";
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$fn\"");
header("Pragma: no-cache");
header("Expires: 0");

// Cell styles
$hdrStyle   = "background-color:#f8d7da; font-weight:bold; text-align:center; padding:5px 8px; font-size:11px; font-family:Arial,sans-serif; border:1px solid #ccc;";
$cellStyle  = "text-align:center; padding:4px 6px; font-size:11px; font-family:Arial,sans-serif; border:1px solid #ddd;";
$brkHdrStyle = "background-color:#d4edda; font-weight:bold; text-align:center; padding:5px 8px; font-size:11px; font-family:Arial,sans-serif; border:1px solid #ccc;";
$brkLabel   = "text-align:right; font-weight:bold; padding:4px 8px; font-size:11px; font-family:Arial,sans-serif; border:1px solid #ddd;";
$brkVal     = "text-align:right; padding:4px 8px; font-size:11px; font-family:Arial,sans-serif; border:1px solid #ddd;";
$totStyle   = "background-color:#fff3cd; font-weight:bold; text-align:right; padding:4px 8px; font-size:11px; font-family:Arial,sans-serif; border:1px solid #ccc;";
$emptyBdr   = "border:none;";

echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>';

foreach ($daysList as $dayIdx => $day) {
    $data = $allData[$day];
    $isLastDay = ($dayIdx === count($daysList) - 1);

    // Multi-day: show date subheader
    if (count($daysList) > 1) {
        echo "<br><table><tr><td style='font-weight:bold; font-size:12px; font-family:Arial,sans-serif; padding:6px 0;'>📅 " . date('F d, Y (l)', strtotime($day)) . "</td></tr></table>";
    }

    // Group tables: 6 per horizontal row
    $groups = array_chunk($allTables, 6);

    foreach ($groups as $gi => $group) {
        $isLastGroup = ($gi === count($groups) - 1);
        $colCount    = count($group) * 2;
        $showBreakdown = $isLastGroup && $isLastDay;
        $totalCols   = $showBreakdown ? $colCount + 4 : $colCount;

        echo "<table cellpadding='0' cellspacing='0' style='border-collapse:collapse; margin-bottom:12px;'>";

        // ═══ DEAD TIME title (first group, first day only) ═══
        if ($gi === 0 && $dayIdx === 0) {
            echo "<tr><td colspan='{$totalCols}' style='text-align:center; font-weight:bold; font-size:13px; background:#f8d7da; padding:6px; border:1px solid #ccc; font-family:Arial,sans-serif;'>DEAD TIME</td></tr>";
        }

        // ═══ Table name headers ═══
        echo "<tr>";
        foreach ($group as $t) {
            $name = strtoupper($t['table_number']);
            echo "<td style='{$hdrStyle} min-width:140px;'>{$name}</td>";
            echo "<td style='{$hdrStyle} min-width:85px;'>HOUR/MINS</td>";
        }
        if ($showBreakdown) {
            echo "<td style='{$emptyBdr}' width='20'></td>";
            echo "<td colspan='3' style='{$brkHdrStyle}'>BILLIARDS BREAKDOWN</td>";
        }
        echo "</tr>";

        // ═══ Find max dead periods in this group ═══
        $maxP = 0;
        foreach ($group as $t) {
            $maxP = max($maxP, count($data[(int)$t['id']]));
        }
        if ($showBreakdown) $maxP = max($maxP, 4);

        // Breakdown labels
        $brkRows = [
            ['WALK IN', '₱' . number_format($walkIn, 2)],
            ['VIP',     '₱' . number_format($vipInc, 2)],
            ['KTV',     '₱' . number_format($ktvInc, 2)],
        ];

        // ═══ Data rows ═══
        for ($i = 0; $i < $maxP; $i++) {
            echo "<tr>";

            // Dead time slots per table
            foreach ($group as $t) {
                $periods = $data[(int)$t['id']];
                if (isset($periods[$i])) {
                    $p  = $periods[$i];
                    $fr = date('h:i A', $p['from']);
                    $to = date('h:i A', $p['to']);
                    $du = fmtDur((int)$p['dur']);
                    echo "<td style='{$cellStyle}'>{$fr} – {$to}</td>";
                    echo "<td style='{$cellStyle}'>{$du}</td>";
                } else {
                    echo "<td style='border:1px solid #eee;'></td><td style='border:1px solid #eee;'></td>";
                }
            }

            // Breakdown column (right side of last group)
            if ($showBreakdown) {
                echo "<td style='{$emptyBdr}'></td>";
                if ($i < 3) {
                    echo "<td colspan='2' style='{$brkLabel}'>{$brkRows[$i][0]}</td>";
                    echo "<td style='{$brkVal}'>{$brkRows[$i][1]}</td>";
                } elseif ($i === 3) {
                    echo "<td colspan='2' style='{$totStyle}'>GRAND TOTAL</td>";
                    echo "<td style='{$totStyle}'>₱" . number_format($grandTotal, 2) . "</td>";
                } else {
                    echo "<td style='{$emptyBdr}'></td><td style='{$emptyBdr}'></td><td style='{$emptyBdr}'></td>";
                }
            }
            echo "</tr>";
        }

        echo "</table>";
    }
}

echo '</body></html>';
exit;
