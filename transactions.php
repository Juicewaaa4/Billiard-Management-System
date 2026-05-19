<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/util.php';

start_app_session();
require_role(['admin']);


$flash = flash_get();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_missing_game') {
    try {
        $tableId = (int)$_POST['table_id'];
        $walkInName = trim($_POST['walk_in_name']);
        $startTime = $_POST['start_time']; // YYYY-MM-DDTHH:MM
        $endTime = $_POST['end_time'];
        $totalAmt = (float)$_POST['total_amount'];
        
        $startFormatted = date('Y-m-d H:i:s', strtotime($startTime));
        $endFormatted = date('Y-m-d H:i:s', strtotime($endTime));
        $durSecs = max(0, strtotime($endFormatted) - strtotime($startFormatted));
        
        $tInfo = db()->prepare("SELECT type FROM tables WHERE id = ?");
        $tInfo->execute([$tableId]);
        $tType = $tInfo->fetchColumn();
        $isKubo = ($tType === 'kubo');
        
        db()->beginTransaction();
        if ($isKubo) {
            $stmt = db()->prepare("INSERT INTO kubo_rentals (table_id, customer_name, payment_amount, status, created_at, end_time) VALUES (?, ?, ?, 'completed', ?, ?)");
            $stmt->execute([$tableId, $walkInName, $totalAmt, $startFormatted, $endFormatted]);
        } else {
            $stmt = db()->prepare("INSERT INTO game_sessions (table_id, walk_in_name, rate_per_hour, hours_purchased, start_time, end_time, duration_seconds, total_amount, created_by) VALUES (?, ?, 0, 0, ?, ?, ?, ?, ?)");
            $stmt->execute([$tableId, $walkInName, $startFormatted, $endFormatted, $durSecs, $totalAmt, current_user()['id']]);
            $sessionId = (int)db()->lastInsertId();
            
            $stmt2 = db()->prepare("INSERT INTO transactions (session_id, amount, payment, change_amount, type, created_by) VALUES (?, ?, ?, 0, 'full', ?)");
            $stmt2->execute([$sessionId, $totalAmt, $totalAmt, current_user()['id']]);
        }
        db()->commit();
        flash_set('ok', 'Missing record added successfully!');
        redirect('transactions.php');
    } catch (Throwable $e) {
        if(db()->inTransaction()) db()->rollBack();
        flash_set('err', 'Error adding record: ' . $e->getMessage());
        redirect('transactions.php');
    }
}


$from = parse_date((string)($_GET['from'] ?? date('Y-m-d')));
$to = parse_date((string)($_GET['to'] ?? date('Y-m-d')));
$customerId = (int)($_GET['customer_id'] ?? 0);

$where = ["gs.end_time IS NOT NULL", "gs.is_voided = 0"];
$params = [];

if ($from) { $where[] = "DATE(gs.end_time) >= ?"; $params[] = $from; }
if ($to) { $where[] = "DATE(gs.end_time) <= ?"; $params[] = $to; }
if ($customerId > 0) { $where[] = "gs.customer_id = ?"; $params[] = $customerId; }

$sql = "
  SELECT
    gs.id,
    gs.start_time,
    gs.end_time,
    gs.duration_seconds,
    gs.total_amount,
    gs.discount_amount,
    gs.games_earned,
    gs.games_redeemed,
    t.table_number,
    t.type AS table_type,
    c.name AS customer_name,
    gs.walk_in_name,
    SUM(tx.payment) AS payment,
    SUM(tx.change_amount) AS change_amount,
    MAX(tx.paid_at) AS paid_at,
    COALESCE(gs.loyalty_hours, 0) AS loyalty_hours,
    gs.karaoke_included
  FROM game_sessions gs
  JOIN tables t ON t.id = gs.table_id
  LEFT JOIN customers c ON c.id = gs.customer_id
  LEFT JOIN transactions tx ON tx.session_id = gs.id
  WHERE " . implode(' AND ', $where) . "
  GROUP BY gs.id
  ORDER BY gs.end_time DESC
  LIMIT 300
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$gameRows = $stmt->fetchAll();

// Also fetch Kubo rentals
$kuboWhere = ["kr.status = 'completed'", "kr.is_voided = 0"];
$kuboParams = [];
if ($from) { $kuboWhere[] = "DATE(kr.end_time) >= ?"; $kuboParams[] = $from; }
if ($to) { $kuboWhere[] = "DATE(kr.end_time) <= ?"; $kuboParams[] = $to; }

$kuboSql = "
  SELECT
    kr.id,
    kr.created_at AS start_time,
    kr.end_time,
    TIMESTAMPDIFF(SECOND, kr.created_at, kr.end_time) AS duration_seconds,
    kr.payment_amount AS total_amount,
    0 AS discount_amount,
    0 AS games_earned,
    0 AS games_redeemed,
    t.table_number,
    'kubo' AS table_type,
    kr.customer_name,
    '' AS walk_in_name,
    kr.payment_amount AS payment,
    0 AS change_amount,
    kr.end_time AS paid_at,
    0 AS loyalty_hours,
    0 AS karaoke_included,
    kr.is_voided,
    kr.void_reason
  FROM kubo_rentals kr
  JOIN tables t ON t.id = kr.table_id
  WHERE " . implode(' AND ', $kuboWhere) . "
  ORDER BY kr.end_time DESC
  LIMIT 100
";
$kuboStmt = db()->prepare($kuboSql);
$kuboStmt->execute($kuboParams);
$kuboRows = $kuboStmt->fetchAll();

// Merge and sort by end_time DESC
$rows = array_merge($gameRows, $kuboRows);
usort($rows, function($a, $b) {
  return strtotime($b['end_time']) - strtotime($a['end_time']);
});

$customers = db()->query("SELECT id, name FROM customers ORDER BY name ASC")->fetchAll();
$allTables = db()->query("SELECT id, table_number, type FROM tables WHERE is_deleted=0 ORDER BY table_number ASC")->fetchAll();

render_header('Transactions', 'transactions');
?>

<?php if ($flash): ?>
  <div class="alert alert--<?php echo h($flash['type']); ?>" style="margin-bottom:14px;">
    <?php echo h($flash['message']); ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="row">
    <div>
      <div class="card__title">Transaction History</div>
      <div style="margin-top:6px;color:var(--muted);">Completed games with payment details. Filter by date/customer.</div>
    </div>
    <div class="spacer"></div>
    <div style="display: flex; gap: 10px;">
      <button class="btn" onclick="document.getElementById('addMissingModal').style.display='flex'" style="background-color: #6366f1; color: white; border: none;">
        + Add Missing Game
      </button>
      <a class="btn" href="exports/export_transactions.php?<?php echo http_build_query($_GET); ?>" target="_blank" style="background-color: #22c55e; color: white; border: none;">
        Export CSV
      </a>
      <?php if (!empty($_GET['customer_id'])): ?>
        <a class="btn btn--ghost" href="javascript:history.back()">Back</a>
      <?php endif; ?>
    </div>
  </div>

  <form id="txFilterForm" method="get" class="row" style="margin-top:12px; gap:10px;">
    <div class="field">
      <div class="label">From</div>
      <input type="date" name="from" value="<?php echo h((string)$from); ?>" style="width:170px;" onchange="this.form.submit()">
    </div>
    <div class="field">
      <div class="label">To</div>
      <input type="date" name="to" value="<?php echo h((string)$to); ?>" style="width:170px;" onchange="this.form.submit()">
    </div>
    <div class="field" style="min-width:240px;">
      <div class="label">Customer</div>
      <select name="customer_id" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach ($customers as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>" <?php echo $customerId === (int)$c['id'] ? 'selected' : ''; ?>>
            <?php echo h($c['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="align-self: end;">
      <button type="button" class="btn btn--ghost" onclick="setYesterday()">Yesterday</button>
    </div>
    <div class="field" style="align-self:end;">
      <a class="btn btn--ghost" href="transactions.php">Clear</a>
    </div>
  </form>
</div>

<div class="card" style="margin-top:14px;">
  <div style="overflow:auto;">
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Table</th>
          <th>Customer</th>
          <th>Start</th>
          <th>End</th>
          <th>Duration</th>
          <th style="text-align:right;">Total</th>
          <th style="text-align:right;">Payment</th>
          <th style="text-align:right;">Change</th>
          <th style="text-align:center;">Loyalty</th>
          <th style="text-align:center;">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          $dur = (int)($r['duration_seconds'] ?? 0);
          $h = intdiv($dur, 3600);
          $m = intdiv($dur % 3600, 60);
          $s = $dur % 60;
          $durFmt = sprintf('%02d:%02d:%02d', $h, $m, $s);
          $cust = $r['customer_name'] ?: ($r['walk_in_name'] ?: 'Walk-in');
          $isKubo = ($r['table_type'] ?? '') === 'kubo';
          $loyalty = (int)($r['loyalty_hours'] ?? 0);
          $rowBg = $loyalty > 0 ? 'background-color:rgba(253,186,116,0.15);' : '';
        ?>
        <tr style="<?php echo $rowBg; ?>">
          <td><span style="color:var(--muted); font-size:13px;">#<?php echo (int)$r['id']; ?></span></td>
          <td>
            <span class="badge" style="background:<?php echo $isKubo ? 'rgba(34,197,94,0.15)' : 'var(--bg)'; ?>; border:1px solid <?php echo $isKubo ? '#22c55e' : 'var(--border)'; ?>; color:<?php echo $isKubo ? '#22c55e' : 'var(--text)'; ?>; font-weight:700;">
              <?php echo h($r['table_number']); ?><?php if ($isKubo): ?> 🏠<?php endif; ?>
            </span>
          </td>
          <td><strong style="color:var(--text);"><?php echo h($cust); ?></strong></td>
          <td style="color:var(--muted); font-size:13px;"><?php echo h(date('M j, g:i A', strtotime($r['start_time']))); ?></td>
          <td style="color:var(--muted); font-size:13px;"><?php echo h(date('g:i A', strtotime($r['end_time']))); ?></td>
          <td><span class="badge badge--warn" style="font-family:monospace;"><?php echo h($durFmt); ?></span></td>
          <td style="text-align:right; font-weight:700; color:var(--text);"><?php echo money((float)$r['total_amount']); ?></td>
          <td style="text-align:right; font-weight:700; color:#22c55e;"><?php echo money((float)($r['payment'] ?? 0)); ?></td>
          <td style="text-align:right; color:var(--muted);"><?php echo money((float)($r['change_amount'] ?? 0)); ?></td>
          <td style="text-align:center;">
            <?php if ($loyalty > 0): ?>
              <span class="badge" style="background:#fdba74; color:#7c2d12; font-weight:700; font-size:11px;">+<?php echo $loyalty; ?>h Loyalty</span>
            <?php else: ?>
              <span style="color:var(--muted);">—</span>
            <?php endif; ?>
          </td>
          <td style="text-align:center;">
            <?php if (!$isKubo): ?>
              <a class="btn btn--ghost" href="receipt.php?session_id=<?php echo (int)$r['id']; ?>" style="padding:4px 10px; font-size:12px;">View Receipt</a>
            <?php else: ?>
              <span style="color:var(--muted); font-size:12px;">Kubo</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer(); ?>

<script>
function setYesterday() {
  const yesterday = new Date();
  yesterday.setDate(yesterday.getDate() - 1);
  const dateStr = yesterday.toISOString().split('T')[0];
  
  const url = new URL(window.location);
  url.searchParams.set('from', dateStr);
  url.searchParams.set('to', dateStr);
  
  window.location.href = url.toString();
}
</script>

<!-- Add Missing Game Modal -->
<div id="addMissingModal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal-box" style="max-width:450px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
      <h3 style="margin:0;">📝 Add Missing Record</h3>
      <span onclick="document.getElementById('addMissingModal').style.display='none'" style="cursor:pointer; font-size:24px; color:var(--muted);">&times;</span>
    </div>
    
    <form method="post" class="form">
      <input type="hidden" name="action" value="add_missing_game">
      
      <div class="field">
        <label class="label">Table / Kubo</label>
        <select name="table_id" required>
          <option value="">Select Table...</option>
          <?php foreach ($allTables as $tb): ?>
            <option value="<?php echo (int)$tb['id']; ?>"><?php echo h($tb['table_number'] . ' (' . strtoupper($tb['type']) . ')'); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label class="label">Customer Name</label>
        <input type="text" name="walk_in_name" placeholder="Walk-in or enter name" required>
      </div>

      <div class="row" style="gap:10px;">
        <div class="field" style="flex:1;">
          <label class="label">Time Started</label>
          <input type="datetime-local" name="start_time" required>
        </div>
        <div class="field" style="flex:1;">
          <label class="label">Time Ended</label>
          <input type="datetime-local" name="end_time" required>
        </div>
      </div>

      <div class="field">
        <label class="label">Total Amount Paid (₱)</label>
        <input type="number" step="0.01" name="total_amount" min="0" required>
      </div>

      <div style="margin-top:20px; display:flex; gap:10px; justify-content:flex-end;">
        <button type="button" class="btn btn--ghost" onclick="document.getElementById('addMissingModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn btn--primary">Save Record</button>
      </div>
    </form>
  </div>
</div>

