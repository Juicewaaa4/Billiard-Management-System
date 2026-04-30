<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/util.php';

start_app_session();
require_role(['admin']);


$flash = flash_get();

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
    c.name AS customer_name,
    gs.walk_in_name,
    SUM(tx.payment) AS payment,
    SUM(tx.change_amount) AS change_amount,
    MAX(tx.paid_at) AS paid_at
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
$rows = $stmt->fetchAll();

$customers = db()->query("SELECT id, name FROM customers ORDER BY name ASC")->fetchAll();

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
        ?>
        <tr>
          <td><span style="color:var(--muted); font-size:13px;">#<?php echo (int)$r['id']; ?></span></td>
          <td><span class="badge" style="background:var(--bg); border:1px solid var(--border); color:var(--text); font-weight:700;"><?php echo h($r['table_number']); ?></span></td>
          <td><strong style="color:var(--text);"><?php echo h($cust); ?></strong></td>
          <td style="color:var(--muted); font-size:13px;"><?php echo h(date('M j, g:i A', strtotime($r['start_time']))); ?></td>
          <td style="color:var(--muted); font-size:13px;"><?php echo h(date('g:i A', strtotime($r['end_time']))); ?></td>
          <td><span class="badge badge--warn" style="font-family:monospace;"><?php echo h($durFmt); ?></span></td>
          <td style="text-align:right; font-weight:700; color:var(--text);"><?php echo money((float)$r['total_amount']); ?></td>
          <td style="text-align:right; font-weight:700; color:#22c55e;"><?php echo money((float)($r['payment'] ?? 0)); ?></td>
          <td style="text-align:right; color:var(--muted);"><?php echo money((float)($r['change_amount'] ?? 0)); ?></td>
          <td style="text-align:center;">
            <a class="btn btn--ghost" href="receipt.php?session_id=<?php echo (int)$r['id']; ?>" style="padding:4px 10px; font-size:12px;">View Receipt</a>
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

