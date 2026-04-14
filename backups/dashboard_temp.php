<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/util.php';

start_app_session();
require_role(['admin', 'cashier']);


$flash = null;

const DEFAULT_RATE_PER_HOUR = 150.00;

function get_customers(): array
{
  return db()->query("SELECT id, name, contact, loyalty_games FROM customers ORDER BY name ASC")->fetchAll();
}

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'add_table') {
      require_role(['admin']);
      $tableNumber = trim((string)($_POST['table_number'] ?? ''));
      if ($tableNumber === '') throw new RuntimeException('Table number is required.');

      $stmt = db()->prepare("INSERT INTO tables (table_number, type, status, rate_per_hour) VALUES (?, 'regular', 'available', ?)");
      $stmt->execute([$tableNumber, DEFAULT_RATE_PER_HOUR]);
      flash_set('ok', 'Table added.');
      redirect('tables.php');
    }

    if ($action === 'edit_table') {
      require_role(['admin']);
      $id = (int)($_POST['id'] ?? 0);
      $tableNumber = trim((string)($_POST['table_number'] ?? ''));
      $rate = (float)($_POST['rate_per_hour'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid table.');
      if ($tableNumber === '') throw new RuntimeException('Table number is required.');
      if ($rate < 0) throw new RuntimeException('Invalid rate per hour.');

      $stmt = db()->prepare("UPDATE tables SET table_number = ?, rate_per_hour = ? WHERE id = ?");
      $stmt->execute([$tableNumber, $rate, $id]);
      flash_set('ok', 'Table updated.');
      redirect('tables.php');
    }

    if ($action === 'delete_table') {
      require_role(['admin']);
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid table.');

      $t = db()->prepare("SELECT status FROM tables WHERE id=?");
      $t->execute([$id]);
      $row = $t->fetch();
      if (!$row) throw new RuntimeException('Table not found.');
      if ($row['status'] === 'in_use') throw new RuntimeException('Cannot delete: table is in use.');

      db()->prepare("UPDATE tables SET is_deleted = 1 WHERE id=?")->execute([$id]);
      flash_set('ok', 'Table removed.');
      redirect('tables.php');
    }

    if ($action === 'start_game') {
      $tableId = (int)($_POST['table_id'] ?? 0);
      $customerIdRaw = (string)($_POST['customer_id'] ?? '');
      $walkInName = trim((string)($_POST['walk_in_name'] ?? ''));
      $rate = (float)($_POST['rate_per_hour'] ?? 0);

      if ($tableId <= 0) throw new RuntimeException('Invalid table.');
      if ($rate < 0) throw new RuntimeException('Invalid hourly rate.');

      $customerId = null;
      if ($customerIdRaw !== '') {
        $customerId = (int)$customerIdRaw;
        if ($customerId <= 0) $customerId = null;
      }

      if ($customerId === null && $walkInName === '') {
        $walkInName = 'Walk-in';
      }

      // Ensure table available
      $stmt = db()->prepare("SELECT status, rate_per_hour, type FROM tables WHERE id=?");
      $stmt->execute([$tableId]);
      $table = $stmt->fetch();
      if (!$table) throw new RuntimeException('Table not found.');
      if ($table['status'] !== 'available') throw new RuntimeException('Table is not available.');

      // Always use the table's stored rate
      $rate = (float)$table['rate_per_hour'];

      // Auto free hour when customer reaches 10 games:
      // - reset loyalty_games to 0
      // - apply 1 free billing hour via billing_bonus_seconds
      $applyFreeHour = false;
      if ($customerId) {
        $c = db()->prepare("SELECT loyalty_games FROM customers WHERE id=? LIMIT 1");
        $c->execute([$customerId]);
        $cr = $c->fetch();
        if ($cr && (int)$cr['loyalty_games'] >= 10) {
          $applyFreeHour = true;
        }
      }

      db()->beginTransaction();
      db()->prepare("UPDATE tables SET status='in_use' WHERE id=?")->execute([$tableId]);

      if ($applyFreeHour) {
        db()->prepare("UPDATE customers SET loyalty_games = 0 WHERE id=?")->execute([$customerId]);
      }

      $ins = db()->prepare("
        INSERT INTO game_sessions
          (table_id, customer_id, walk_in_name, rate_per_hour, start_time, billing_bonus_seconds, games_redeemed, created_by)
        VALUES
          (?, ?, ?, ?, NOW(),
            CASE WHEN ? THEN 3600 ELSE 0 END,
            CASE WHEN ? THEN 10 ELSE 0 END,
            ?
          )
      ");
      $ins->execute([
        $tableId,
        $customerId,
        $customerId ? null : $walkInName,
        $rate,
        (int)$applyFreeHour,
        (int)$applyFreeHour,
        (int)current_user()['id'],
      ]);
      db()->commit();

      flash_set('ok', 'Game started.');
      redirect('tables.php');
    }

    if ($action === 'redeem_free_hour') {
      $sessionId = (int)($_POST['session_id'] ?? 0);
      $customerId = (int)($_POST['customer_id'] ?? 0);
      if ($sessionId <= 0 || $customerId <= 0) throw new RuntimeException('Invalid request.');

      $s = db()->prepare("SELECT * FROM game_sessions WHERE id = ? AND end_time IS NULL LIMIT 1");
      $s->execute([$sessionId]);
      if (!$s->fetch()) throw new RuntimeException('Session is not active.');

      $c = db()->prepare("SELECT loyalty_games FROM customers WHERE id=?");
      $c->execute([$customerId]);
      $cr = $c->fetch();
      if (!$cr || (int)$cr['loyalty_games'] < 10) throw new RuntimeException('Not enough games to redeem.');

      db()->beginTransaction();
      db()->prepare("UPDATE customers SET loyalty_games = loyalty_games - 10 WHERE id=?")->execute([$customerId]);
      db()->prepare("
        UPDATE game_sessions
        SET
          billing_bonus_seconds = COALESCE(billing_bonus_seconds,0) + 3600,
          games_redeemed = games_redeemed + 10
        WHERE id=?
      ")->execute([$sessionId]);
      db()->commit();

      flash_set('ok', '⭐ 1 Free Hour applied to the table!');
      redirect('tables.php');
    }

    if ($action === 'end_game') {
      $sessionId = (int)($_POST['session_id'] ?? 0);
      $payment = (float)($_POST['payment'] ?? 0);
      if ($sessionId <= 0) throw new RuntimeException('Invalid session.');

      $stmt = db()->prepare("
        SELECT gs.*, t.id AS t_id, t.status AS table_status, t.type AS table_type
             , (TIMESTAMPDIFF(SECOND, gs.start_time, NOW()) + COALESCE(gs.billing_bonus_seconds,0)) AS computed_duration_seconds
        FROM game_sessions gs
        JOIN tables t ON t.id = gs.table_id
        WHERE gs.id = ? AND gs.end_time IS NULL
        LIMIT 1
      ");
      $stmt->execute([$sessionId]);
      $s = $stmt->fetch();
      if (!$s) throw new RuntimeException('Session not found or already ended.');

      // Use MySQL NOW() so duration_seconds matches stored start_time/end_time.
      $durationSeconds = (int)($s['computed_duration_seconds'] ?? 0);
      if ($durationSeconds < 60 && $durationSeconds > 0) {
        $durationSeconds = 60; // minimum charge 1 min if played
      }

      $total = $durationSeconds === 0
        ? 0.0
        : round(((float)$s['rate_per_hour']) * ($durationSeconds / 3600), 2);

      $customerId = $s['customer_id'] ? (int)$s['customer_id'] : null;

      if ($payment < $total) throw new RuntimeException('Payment is not enough.');
      $change = round($payment - $total, 2);

      // Earn 1 game per session
      $earned = 1;

      db()->beginTransaction();

      db()->prepare("
        UPDATE game_sessions
        SET end_time = NOW(),
            duration_seconds = ?,
            total_amount = ?,
            games_earned = ?
        WHERE id = ?
      ")->execute([$durationSeconds, $total, $earned, $sessionId]);

      db()->prepare("UPDATE tables SET status='available' WHERE id=?")->execute([(int)$s['table_id']]);

      db()->prepare("
        INSERT INTO transactions (session_id, payment, change_amount, created_by)
        VALUES (?, ?, ?, ?)
      ")->execute([$sessionId, $payment, $change, (int)current_user()['id']]);

      if ($customerId) {
        db()->prepare("
          UPDATE customers
          SET loyalty_games = loyalty_games + ?
          WHERE id = ?
        ")->execute([$earned, $customerId]);
      }

      db()->commit();

      flash_set('ok', 'Game ended and payment recorded.');
      redirect('receipt.php?session_id=' . $sessionId);
    }
  } catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    flash_set('danger', $e->getMessage());
    redirect('tables.php');
  }
}

$flash = flash_get();

// Data for UI
$tables = db()->query("SELECT * FROM tables WHERE type='regular' AND is_deleted=0 ORDER BY id ASC")->fetchAll();
$customers = get_customers();
$customerNameById = [];
foreach ($customers as $c) {
  $customerNameById[(int)$c['id']] = (string)$c['name'];
}

// Active sessions per table
$activeSessions = db()->query("
  SELECT gs.*, t.table_number
  FROM game_sessions gs
  JOIN tables t ON t.id = gs.table_id
  WHERE gs.end_time IS NULL
")->fetchAll();
$activeByTable = [];
foreach ($activeSessions as $s) {
  $activeByTable[(int)$s['table_id']] = $s;
}

render_header('Tables', 'tables');
?>

<?php if ($flash): ?>
  <div class="alert alert--<?php echo h($flash['type']); ?>" style="margin-bottom:14px;">
    <?php echo h($flash['message']); ?>
  </div>
<?php endif; ?>

<div class="grid" style="grid-template-columns: 1fr; gap:14px;">
  <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
    <div class="card">
      <div class="row">
        <div>
          <div class="card__title">Add Table</div>
          <div style="margin-top:6px;color:var(--muted);">Only admin can add or remove regular tables.</div>
        </div>
        <div class="spacer"></div>
      </div>

      <form method="post" class="form" style="margin-top:12px;">
        <input type="hidden" name="action" value="add_table">
        <div class="row">
          <div class="field" style="flex:2; min-width:220px;">
            <div class="label">Table name</div>
            <input name="table_number" placeholder="Table 4" required>
          </div>
          <div class="field" style="align-self:end;">
            <button class="btn" type="submit">Add</button>
          </div>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="row">
      <div>
        <div class="card__title">Tables</div>
        <div style="margin-top:6px;color:var(--muted);">Start or end games. Timer shows elapsed time.</div>
      </div>
      <div class="spacer"></div>
      <a class="btn btn--ghost" href="customers.php">Manage customers</a>
    </div>

    <!-- Search & Filter Bar -->
    <div style="margin-top:14px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <div style="position:relative; flex:1; min-width:220px;">
        <input type="text" id="tableSearch" placeholder="Search table name..." style="width:100%; padding-left:36px;">
        <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--muted); pointer-events:none; font-size:15px;">🔍</span>
      </div>
      <div style="display:flex; gap:6px;">
        <button type="button" class="btn filter-btn is-active" data-filter="all">All</button>
        <button type="button" class="btn btn--ghost filter-btn" data-filter="available">Available</button>
        <button type="button" class="btn btn--ghost filter-btn" data-filter="in_use">In Use</button>
      </div>
    </div>

    <div id="noResults" style="display:none; text-align:center; padding:28px 14px; color:var(--muted);">
      No tables found matching your search.
    </div>

    <div style="overflow:auto; margin-top:12px;">
      <table class="table" id="tablesTable">
        <thead>
          <tr>
            <th>Table</th>
            <th>Status</th>
            <th>Current Game</th>
            <th style="width:420px;">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($tables as $t): ?>
          <?php
            $tid = (int)$t['id'];
            $status = (string)$t['status'];
            $active = $activeByTable[$tid] ?? null;
          ?>
          <tr data-table-name="<?php echo h(strtolower($t['table_number'])); ?>" data-status="<?php echo h($status); ?>">
            <td>
              <strong><?php echo h($t['table_number']); ?></strong>
            </td>
            <td>
              <?php if ($status === 'available'): ?>
                <span class="badge badge--ok">Available</span>
              <?php else: ?>
                <span class="badge badge--warn">In Use</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($active): ?>
                <div style="font-weight:800;">Started: <?php echo h($active['start_time']); ?></div>
                <div style="color:var(--muted); margin-top:4px;">
                  Elapsed:
                  <span class="badge" data-timer-start="<?php echo h($active['start_time']); ?>">00:00:00</span>
                </div>
                <div style="color:var(--muted); margin-top:4px;">
                  Customer:
                  <?php
                    $customerId = !empty($active['customer_id']) ? (int)$active['customer_id'] : null;
                    $customerName = $customerId !== null
                      ? ($customerNameById[$customerId] ?? ('Customer #' . $customerId))
                      : 'Walk-in';
                    echo h(!empty($active['walk_in_name']) ? (string)$active['walk_in_name'] : $customerName);
                  ?>
                </div>
              <?php else: ?>
                <span style="color:var(--muted);">—</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="row" style="gap:10px;">
                <?php if (!$active): ?>
                  <form method="post" class="row" style="gap:10px; flex-wrap:wrap;">
                    <input type="hidden" name="action" value="start_game">
                    <input type="hidden" name="table_id" value="<?php echo $tid; ?>">
                    <select name="customer_id" style="min-width:190px;">
                      <option value="">Walk-in</option>
                      <?php foreach ($customers as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>">
                          <?php echo h($c['name']); ?> (<?php echo (int)$c['loyalty_games']; ?> games)
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <input name="walk_in_name" placeholder="Walk-in name (optional)" style="min-width:170px;">
                    <button class="btn" type="submit">Start</button>
                  </form>
                <?php else: ?>
                  <?php
                    $customerId = $active['customer_id'] ? (int)$active['customer_id'] : null;
                    $games = 0;
                    if ($customerId) {
                      foreach ($customers as $c) {
                        if ((int)$c['id'] === $customerId) { $games = (int)$c['loyalty_games']; break; }
                      }
                    }
                  ?>
                  <?php if ($games >= 10): ?>
                    <form method="post" class="row" onsubmit="return confirm('Deduct 10 games and add 1 free hour?');" style="margin-bottom:10px; flex-basis:100%;">
                      <input type="hidden" name="action" value="redeem_free_hour">
                      <input type="hidden" name="session_id" value="<?php echo (int)$active['id']; ?>">
                      <input type="hidden" name="customer_id" value="<?php echo $customerId; ?>">
                      <button class="btn btn--danger" type="submit" style="width:100%; border-radius:6px; box-shadow:0 0 10px rgba(239, 68, 68, 0.4);">
                        ⭐ Redeem 1 Free Hour (Cost: 10 Games)
                      </button>
                    </form>
                  <?php endif; ?>
                  <form method="post" class="row" style="gap:10px; flex-wrap:wrap; width:100%;">
                    <input type="hidden" name="action" value="end_game">
                    <input type="hidden" name="session_id" value="<?php echo (int)$active['id']; ?>">
                    <input name="payment" type="number" step="0.01" min="0" placeholder="Payment" style="width:140px;" required>
                    <button class="btn btn--primary" type="button" onclick="openPaymentModal(<?php echo (int)$active['id']; ?>, <?php echo (float)$active['rate_per_hour']; ?>, '<?php echo h($active['start_time']); ?>')">Proceed to Payment</button>
                    <a class="btn btn--ghost" href="receipt.php?session_id=<?php echo (int)$active['id']; ?>&preview=1">Preview</a>
                  </form>
                <?php endif; ?>

                <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
                <details style="margin-left:auto;">
                  <summary class="btn btn--ghost">Edit</summary>
                  <div class="card" style="margin-top:10px; min-width:280px;">
                    <form method="post" class="form" style="margin-bottom:12px;">
                      <input type="hidden" name="action" value="edit_table">
                      <input type="hidden" name="id" value="<?php echo $tid; ?>">
                      <div class="field">
                        <div class="label">Table name</div>
                        <input name="table_number" value="<?php echo h($t['table_number']); ?>" required>
                      </div>
                      <div class="field">
                        <div class="label">Rate per hour (₱)</div>
                        <input name="rate_per_hour" type="number" step="0.01" min="0" value="<?php echo h((string)$t['rate_per_hour']); ?>" required>
                      </div>
                      <div class="row">
                        <button class="btn" type="submit">Save</button>
                      </div>
                    </form>
                    <form method="post" onsubmit="return confirm('Delete this table?');">
                      <input type="hidden" name="action" value="delete_table">
                      <input type="hidden" name="id" value="<?php echo $tid; ?>">
                      <button class="btn btn--danger btn--block" type="submit">Delete Table</button>
                    </form>
                  </div>
                </details>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php render_footer(); ?>

<!-- Payment Modal -->
<div id="paymentModal" class="modal" style="display: none;">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Complete Payment</h3>
      <span class="close" onclick="closePaymentModal()">&times;</span>
    </div>
    <div class="modal-body">
      <div class="payment-summary">
        <div class="row">
          <div class="field">
            <div class="label">Start Time</div>
            <div id="startTime" class="value">-</div>
          </div>
          <div class="field">
            <div class="label">Current Duration</div>
            <div id="currentDuration" class="value">00:00:00</div>
          </div>
        </div>
        <div class="row">
          <div class="field">
            <div class="label">Rate per Hour</div>
            <div id="ratePerHour" class="value">₱0.00</div>
          </div>
          <div class="field">
            <div class="label">Total Amount</div>
            <div id="totalAmount" class="value amount">₱0.00</div>
          </div>
        </div>
      </div>
      
      <form id="paymentForm" method="post" style="margin-top: 20px;">
        <input type="hidden" name="action" value="end_game">
        <input type="hidden" id="sessionId" name="session_id">
        <div class="field">
          <div class="label">Payment Amount</div>
          <input type="number" name="payment" id="paymentAmount" step="0.01" min="0" placeholder="Enter payment" required>
        </div>
        <div class="field">
          <div class="label">Change</div>
          <div id="changeAmount" class="value">₱0.00</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn--ghost" onclick="closePaymentModal()">Cancel</button>
          <button type="submit" class="btn btn--primary">Confirm Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
.modal {
  position: fixed;
  z-index: 1000;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0,0,0,0.5);
}

.modal-content {
  background-color: var(--bg);
  margin: 10% auto;
  padding: 0;
  border-radius: 8px;
  width: 90%;
  max-width: 500px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px;
  border-bottom: 1px solid var(--border);
}

.modal-header h3 {
  margin: 0;
  color: var(--text);
}

.close {
  color: var(--muted);
  font-size: 28px;
  font-weight: bold;
  cursor: pointer;
}

.close:hover {
  color: var(--text);
}

.modal-body {
  padding: 20px;
}

.payment-summary {
  background: var(--bg-secondary);
  padding: 15px;
  border-radius: 6px;
  margin-bottom: 15px;
}

.payment-summary .row {
  display: flex;
  gap: 20px;
  margin-bottom: 10px;
}

.payment-summary .row:last-child {
  margin-bottom: 0;
}

.payment-summary .field {
  flex: 1;
}

.payment-summary .label {
  font-size: 12px;
  color: var(--muted);
  margin-bottom: 4px;
}

.payment-summary .value {
  font-weight: 600;
  color: var(--text);
}

.payment-summary .value.amount {
  font-size: 18px;
  color: var(--primary);
}

.modal-footer {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  margin-top: 20px;
}

#changeAmount {
  font-weight: 600;
  color: var(--success);
}
</style>

<script>
let currentSessionId = null;
let currentRatePerHour = 0;
let currentStartTime = null;
let durationInterval = null;

function openPaymentModal(sessionId, ratePerHour, startTime) {
  currentSessionId = sessionId;
  currentRatePerHour = ratePerHour;
  currentStartTime = startTime;
  
  document.getElementById('sessionId').value = sessionId;
  document.getElementById('ratePerHour').textContent = '₱' + ratePerHour.toFixed(2);
  document.getElementById('startTime').textContent = startTime;
  document.getElementById('paymentModal').style.display = 'block';
  
  updateDurationAndAmount();
  durationInterval = setInterval(updateDurationAndAmount, 1000);
  
  document.getElementById('paymentAmount').addEventListener('input', calculateChange);
}

function closePaymentModal() {
  document.getElementById('paymentModal').style.display = 'none';
  if (durationInterval) {
    clearInterval(durationInterval);
    durationInterval = null;
  }
  document.getElementById('paymentForm').reset();
  document.getElementById('paymentAmount').removeEventListener('input', calculateChange);
}

function updateDurationAndAmount() {
  if (!currentStartTime) return;
  
  const start = new Date(currentStartTime);
  const now = new Date();
  const diffMs = now - start;
  const diffSeconds = Math.floor(diffMs / 1000);
  
  const hours = Math.floor(diffSeconds / 3600);
  const minutes = Math.floor((diffSeconds % 3600) / 60);
  const seconds = diffSeconds % 60;
  
  const durationStr = String(hours).padStart(2, '0') + ':' + 
                      String(minutes).padStart(2, '0') + ':' + 
                      String(seconds).padStart(2, '0');
  
  document.getElementById('currentDuration').textContent = durationStr;
  
  // Calculate amount (minimum 1 minute charge if played)
  const chargeSeconds = diffSeconds > 0 ? Math.max(diffSeconds, 60) : 0;
  const totalAmount = (currentRatePerHour * chargeSeconds) / 3600;
  
  document.getElementById('totalAmount').textContent = '₱' + totalAmount.toFixed(2);
  
  // Update change calculation
  calculateChange();
}

function calculateChange() {
  const payment = parseFloat(document.getElementById('paymentAmount').value) || 0;
  const totalText = document.getElementById('totalAmount').textContent;
  const total = parseFloat(totalText.replace('₱', ''));
  
  const change = payment - total;
  document.getElementById('changeAmount').textContent = '₱' + change.toFixed(2);
  
  if (change >= 0) {
    document.getElementById('changeAmount').style.color = 'var(--success)';
  } else {
    document.getElementById('changeAmount').style.color = 'var(--danger)';
  }
}

// Close modal when clicking outside
window.onclick = function(event) {
  const modal = document.getElementById('paymentModal');
  if (event.target == modal) {
    closePaymentModal();
  }
}

// Form validation
document.getElementById('paymentForm').addEventListener('submit', function(e) {
  const payment = parseFloat(document.getElementById('paymentAmount').value) || 0;
  const totalText = document.getElementById('totalAmount').textContent;
  const total = parseFloat(totalText.replace('₱', ''));
  
  if (payment < total) {
    e.preventDefault();
    alert('Payment amount is not enough!');
    return false;
  }
  
  if (!confirm('Are you sure you want to complete this payment?')) {
    e.preventDefault();
    return false;
  }
});
</script>

