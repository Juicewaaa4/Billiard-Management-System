<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/util.php';

start_app_session();
require_role(['admin', 'cashier']);
$role = current_user()['role'] ?? '';

// ── POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'start_kubo') {
      $tableId  = (int)($_POST['table_id'] ?? 0);
      $custName = trim((string)($_POST['customer_name'] ?? ''));
      $payment  = (float)($_POST['payment_amount'] ?? 0);
      $date     = date('Y-m-d'); // Always today for active rentals

      if ($tableId <= 0) throw new RuntimeException('Invalid Kubo.');
      if ($custName === '') throw new RuntimeException('Customer name is required.');
      if ($payment < 0) throw new RuntimeException('Payment amount cannot be negative.');

      // Check if already active
      $check = db()->prepare("SELECT id FROM kubo_rentals WHERE rental_date = ? AND table_id = ? AND status = 'active'");
      $check->execute([$date, $tableId]);
      if ($check->fetch()) throw new RuntimeException("This Kubo is already in use.");

      $stmt = db()->prepare("
        INSERT INTO kubo_rentals (table_id, customer_name, payment_amount, rental_date, status, created_by)
        VALUES (?, ?, ?, ?, 'active', ?)
      ");
      $stmt->execute([$tableId, $custName, $payment, $date, (int)current_user()['id']]);
      flash_set('ok', "Kubo rented to $custName.");
      redirect('kubo.php');
    }

    if ($action === 'end_kubo') {
      $rentalId = (int)($_POST['rental_id'] ?? 0);
      if ($rentalId <= 0) throw new RuntimeException('Invalid rental record.');

      db()->prepare("UPDATE kubo_rentals SET status = 'completed', end_time = NOW() WHERE id = ? AND status = 'active'")->execute([$rentalId]);
      flash_set('ok', 'Kubo rental ended.');
      redirect('kubo.php');
    }

    // Admin Actions
    if ($role === 'admin') {
      if ($action === 'add_kubo') {
        $name = trim((string)($_POST['table_number'] ?? ''));
        if ($name === '') throw new RuntimeException('Kubo name cannot be empty.');
        db()->prepare("INSERT INTO tables (table_number, type, status) VALUES (?, 'kubo', 'available')")->execute([$name]);
        flash_set('ok', 'New Kubo added.');
        redirect('kubo.php');
      }

      if ($action === 'disable_table') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("UPDATE tables SET is_disabled = 1 WHERE id = ?")->execute([$id]);
        flash_set('ok', 'Kubo disabled.');
        redirect('kubo.php');
      }

      if ($action === 'enable_table') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("UPDATE tables SET is_disabled = 0 WHERE id = ?")->execute([$id]);
        flash_set('ok', 'Kubo enabled.');
        redirect('kubo.php');
      }

      if ($action === 'delete_table') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("UPDATE tables SET is_deleted = 1 WHERE id = ?")->execute([$id]);
        flash_set('ok', 'Kubo deleted.');
        redirect('kubo.php');
      }
    }

  } catch (Throwable $e) {
    flash_set('danger', $e->getMessage());
    redirect('kubo.php');
  }
}

$flash = flash_get();
$selectedDate = parse_date((string)($_GET['date'] ?? date('Y-m-d')));
$isToday = ($selectedDate === date('Y-m-d'));

// GET all income for selected date (Admin only)
$totalIncome = 0;
if ($role === 'admin') {
  $stmt = db()->prepare("SELECT SUM(payment_amount) as total FROM kubo_rentals WHERE rental_date = ? AND (status = 'completed' || status = 'active')");
  $stmt->execute([$selectedDate]);
  $totalIncome = (float)($stmt->fetch()['total'] ?? 0);
}

// GET active rentals for the cards (Only matters if viewing Today)
$activeRentals = [];
if ($isToday) {
  $activeStmt = db()->prepare("SELECT * FROM kubo_rentals WHERE rental_date = ? AND status = 'active'");
  $activeStmt->execute([$selectedDate]);
  foreach ($activeStmt->fetchAll() as $r) {
    $activeRentals[(int)$r['table_id']] = $r;
  }
}

// GET valid kubos
$kubos = db()->query("SELECT * FROM tables WHERE type = 'kubo' AND is_deleted = 0 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// --- Admin Transactions Data ---
$txRows = [];
if ($role === 'admin') {
  $sql = "
    SELECT
      kr.id, kr.customer_name, kr.payment_amount, kr.rental_date, kr.status, kr.created_at, kr.end_time, t.table_number
    FROM kubo_rentals kr
    JOIN tables t ON t.id = kr.table_id
    WHERE DATE(kr.rental_date) = ?
    ORDER BY kr.created_at DESC LIMIT 500
  ";
  $stmt = db()->prepare($sql);
  $stmt->execute([$selectedDate]);
  $txRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

render_header('Kubo Rentals', 'kubo');
?>

<?php if ($flash): ?>
  <div class="alert alert--<?php echo h($flash['type']); ?>"><?php echo h($flash['message']); ?></div>
<?php endif; ?>

<!-- Top Actions -->
<div class="card" style="margin-bottom:14px;">
  <div class="row" style="align-items:center; flex-wrap:wrap; gap:10px;">
    <div>
      <div class="card__title">Kubo Operations</div>
      <div style="margin-top:4px; color:var(--muted); font-size:13px;">Manage kubo reservations and view records.</div>
    </div>
    <div class="spacer"></div>
    <?php if ($role === 'admin'): ?>
      <form method="get" style="display:flex; align-items:center; gap:8px;">
        <span style="font-size:13px; color:var(--muted);">Filter Date:</span>
        <input type="date" name="date" value="<?php echo h($selectedDate); ?>" onchange="this.form.submit()" style="padding:4px 8px; border-radius:6px; border:1px solid var(--border); background:var(--bg); color:var(--text);">
      </form>
      <div class="field">
        <a class="btn btn--ghost" href="export_kubo.php?from=<?php echo urlencode($selectedDate); ?>&to=<?php echo urlencode($selectedDate); ?>" target="_blank" style="color:#22c55e; border-color:#22c55e;">Export Excel</a>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($role === 'admin'): ?>
  <div class="row" style="margin-bottom:14px; gap:14px;">
    <!-- Add Kubo -->
    <div class="card" style="flex:1;">
      <div class="card__title" style="margin-bottom:12px;">Add New Kubo</div>
      <form method="post" class="row" style="gap:10px;">
        <input type="hidden" name="action" value="add_kubo">
        <input name="table_number" placeholder="e.g. Kubo 9" required style="flex:1;">
        <button class="btn btn--primary" type="submit">Add</button>
      </form>
    </div>

    <!-- Total Income Card -->
    <div class="card" style="flex:1; border-left:4px solid #38bdf8;">
      <div class="card__title" style="font-size:14px; color:var(--muted); text-transform:uppercase;">Admin: Income (<?php echo $isToday ? 'Today' : date('M j', strtotime($selectedDate)); ?>)</div>
      <div style="font-size:26px; font-weight:700; color:#38bdf8; margin-top:8px;">₱<?php echo number_format($totalIncome, 2); ?></div>
    </div>
  </div>
<?php endif; ?>

<?php if (!$isToday): ?>
  <div class="alert alert--warn" style="margin-bottom:20px;">
    <strong>Viewing Past Date:</strong> The interactive Kubo Grid is hidden because you are viewing records for <?php echo date('F j, Y', strtotime($selectedDate)); ?>. To rent kubos, please switch back to Today.
  </div>
<?php else: ?>
  <!-- Kubo Grid -->
  <div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:14px;">
    <?php foreach ($kubos as $k): 
      $tid = (int)$k['id'];
      $isActive = isset($activeRentals[$tid]);
      $rental = $isActive ? $activeRentals[$tid] : null;
      $isDisabled = !empty($k['is_disabled']);
    ?>
      <div class="card table-card" style="border-left: 4px solid <?php echo $isDisabled ? '#6b7280' : ($isActive ? '#f59e0b' : '#22c55e'); ?>; position:relative; <?php echo $isDisabled ? 'opacity:0.6;' : ''; ?>">
        
        <?php if ($isDisabled): ?>
          <div style="position:absolute; top:0; right:0; width:80px; height:80px; background:radial-gradient(circle at top right, rgba(107,114,128,0.15), transparent 70%); pointer-events:none;"></div>
        <?php endif; ?>

        <!-- Header -->
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
          <div style="font-size:20px; font-weight:700; color:var(--text);"><?php echo h($k['table_number']); ?></div>
          <?php if ($isDisabled): ?>
            <span class="badge" style="background:rgba(107,114,128,0.2); color:#9ca3af;">🚫 Disabled</span>
          <?php elseif ($isActive): ?>
            <span class="badge badge--warn">In Use</span>
          <?php else: ?>
            <span class="badge badge--ok">Available</span>
          <?php endif; ?>
        </div>

        <?php if ($isActive): ?>
          <div style="background:var(--surface2); border-radius:8px; padding:12px; margin-bottom:12px;">
            <div style="color:var(--muted); font-size:11px; text-transform:uppercase; margin-bottom:4px;">Customer Name</div>
            <div style="font-size:15px; font-weight:700; color:var(--text); margin-bottom:12px;"><?php echo h($rental['customer_name']); ?></div>
            
            <div style="display:flex; justify-content:space-between; border-top:1px solid var(--border); padding-top:10px;">
              <div>
                <span style="color:var(--muted); font-size:11px; text-transform:uppercase;">Time Started</span><br>
                <strong style="font-size:13px;"><?php echo date('h:i A', strtotime($rental['created_at'])); ?></strong>
              </div>
            </div>
          </div>

          <button class="btn btn--block" type="button" style="background:var(--bg); border:1px solid var(--danger); color:var(--danger);" onclick="openEndKubo(<?php echo (int)$rental['id']; ?>, '<?php echo h($k['table_number']); ?>')">End Rental</button>

        <?php elseif ($isDisabled): ?>
          <div style="text-align:center; padding:20px 0 8px;">
            <div style="font-size:32px; margin-bottom:8px; opacity:0.4;">🚫</div>
            <div style="color:#9ca3af; font-size:14px; font-weight:600;">Temporarily Unavailable</div>
          </div>
        <?php else: ?>
          <div style="text-align:center; padding:16px 0; color:var(--muted); font-size:13px;">
             Ready for next customer.
          </div>
          <button class="btn btn--primary btn--block" type="button" onclick="openStartKubo(<?php echo $tid; ?>, '<?php echo h($k['table_number']); ?>')">Rent Kubo</button>
        <?php endif; ?>

        <!-- Admin Settings Details -->
        <?php if ($role === 'admin'): ?>
          <details style="margin-top:10px; border-top:1px solid var(--border); padding-top:10px;">
            <summary class="btn btn--ghost" style="font-size:12px; width:100%; text-align:center;">⚙️ Admin Settings</summary>
            <div style="display:flex; gap:8px; margin-top:10px;">
              <form method="post" onsubmit="return confirm('Change disabled status?');" style="flex:1;">
                <input type="hidden" name="action" value="<?php echo $isDisabled ? 'enable_table' : 'disable_table'; ?>">
                <input type="hidden" name="id" value="<?php echo $tid; ?>">
                <button class="btn btn--ghost btn--block" type="submit" style="font-size:12px; <?php echo $isDisabled ? 'color:var(--success);' : 'color:var(--warn);'; ?>">
                  <?php echo $isDisabled ? 'Enable' : 'Disable'; ?>
                </button>
              </form>
              
              <?php if (!$isActive): ?>
                <form method="post" onsubmit="return confirm('Are you sure you want to permanently delete this Kubo?');" style="flex:1;">
                  <input type="hidden" name="action" value="delete_table">
                  <input type="hidden" name="id" value="<?php echo $tid; ?>">
                  <button class="btn btn--ghost btn--block" type="submit" style="font-size:12px; color:var(--danger);">Delete</button>
                </form>
              <?php endif; ?>
            </div>
          </details>
        <?php endif; ?>

      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($role === 'admin'): ?>
<!-- ═══ Admin Transaction History ═══ -->
<div class="card" style="padding:0; margin-top: 24px; margin-bottom: 40px;">
  <div style="padding:16px 20px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
    <div>
      <div class="card__title">Records for <?php echo date('M j, Y', strtotime($selectedDate)); ?></div>
      <div style="color:var(--muted); font-size:12px; margin-top:2px;">Showing all rentals registered on this date.</div>
    </div>
    <div style="font-size:14px; color:var(--muted);">Total Rentals: <strong style="color:var(--text);"><?php echo count($txRows); ?></strong></div>
  </div>
  <div style="overflow:auto;">
    <table class="table">
      <thead>
        <tr>
          <th>Kubo</th>
          <th>Customer</th>
          <th>Time Started</th>
          <th>Time Ended</th>
          <th>Payment</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($txRows)): ?>
          <tr>
            <td colspan="6" style="text-align:center; padding:30px; color:var(--muted);">No kubo transactions found for this day.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($txRows as $r): 
            $isTxActive = $r['status'] === 'active';
            $tEnded = $r['end_time'] ? date('h:i A', strtotime($r['end_time'])) : '--:-- --';
          ?>
            <tr>
              <td><strong><?php echo h($r['table_number']); ?></strong></td>
              <td><strong><?php echo h($r['customer_name']); ?></strong></td>
              <td><?php echo date('h:i A', strtotime($r['created_at'])); ?></td>
              <td style="color:var(--muted);"><?php echo $tEnded; ?></td>
              <td style="font-weight:700; color:#22c55e;">₱<?php echo number_format((float)$r['payment_amount'], 2); ?></td>
              <td>
                <?php if ($isTxActive): ?>
                  <span class="badge badge--warn">Active</span>
                <?php else: ?>
                  <span class="badge badge--ok">Completed</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ═══ Start Kubo Modal ═══ -->
<div id="startKuboModal" class="global-modal" style="display:none;" onclick="if(event.target.id==='startKuboModal')document.getElementById('startKuboModal').style.display='none'">
  <div class="global-modal__box" style="animation: modalIn 0.2s ease-out; max-width:400px;">
    <div class="global-modal__header" style="background:#f5faf6; border-bottom:1px solid var(--border);">
      <h3 style="color:var(--text); margin:0;">Rent <span id="kuboNumDisplay"></span></h3>
      <span class="global-modal__close" style="color:var(--muted);" onclick="document.getElementById('startKuboModal').style.display='none'">&times;</span>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="start_kubo">
      <input type="hidden" name="table_id" id="startKuboId" value="">
      
      <div class="global-modal__body" style="text-align:left; padding:20px;">
        <div class="field" style="margin-bottom:16px;">
          <label style="display:block; font-size:12px; color:var(--muted); margin-bottom:4px; text-transform:uppercase;">Customer Name *</label>
          <input type="text" name="customer_name" required style="width:100%; border:1px solid var(--border); background:rgba(0,0,0,0.2); color:var(--text); border-radius:8px; padding:10px;">
        </div>
        
        <div class="field" style="margin-bottom:20px;">
          <label style="display:block; font-size:12px; color:var(--muted); margin-bottom:4px; text-transform:uppercase;">Payment Amount (₱) *</label>
          <input type="number" name="payment_amount" step="0.01" min="0" required style="width:100%; border:1px solid var(--border); background:rgba(0,0,0,0.2); color:var(--text); border-radius:8px; padding:10px; font-weight:700; font-size:16px; color:#22c55e;">
        </div>

        <div style="display:flex; gap:10px; justify-content:flex-end;">
          <button type="button" class="btn btn--ghost" onclick="document.getElementById('startKuboModal').style.display='none'">Cancel</button>
          <button type="submit" class="btn btn--primary">Confirm Rental</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ═══ End Kubo Modal ═══ -->
<div id="endKuboModal" class="global-modal" style="display:none;" onclick="if(event.target.id==='endKuboModal')document.getElementById('endKuboModal').style.display='none'">
  <div class="global-modal__box" style="animation: modalIn 0.2s ease-out; max-width:400px;">
    <div class="global-modal__header" style="background:#f5faf6; border-bottom:1px solid var(--border);">
      <h3 style="color:var(--text); margin:0;">End <span id="endKuboDisplay"></span></h3>
      <span class="global-modal__close" style="color:var(--muted);" onclick="document.getElementById('endKuboModal').style.display='none'">&times;</span>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="end_kubo">
      <input type="hidden" name="rental_id" id="endRentalId" value="">
      
      <div class="global-modal__body" style="text-align:center; padding:30px 20px;">
        <div style="font-size:40px; margin-bottom:16px;">🛑</div>
        <p style="margin-bottom:24px; color:var(--muted);">Are you sure you want to end this Kubo rental? It will be marked as available for the next customer.</p>
        
        <div style="display:flex; gap:10px; justify-content:center;">
          <button type="button" class="btn btn--ghost" onclick="document.getElementById('endKuboModal').style.display='none'">Cancel</button>
          <button type="submit" class="btn btn--danger">Confirm End Rental</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
function openStartKubo(tableId, tableName) {
  document.getElementById('startKuboId').value = tableId;
  document.getElementById('kuboNumDisplay').textContent = tableName;
  document.getElementById('startKuboModal').style.display='flex';
}
function openEndKubo(rentalId, tableName) {
  document.getElementById('endRentalId').value = rentalId;
  document.getElementById('endKuboDisplay').textContent = tableName;
  document.getElementById('endKuboModal').style.display='flex';
}
</script>

<style>
@keyframes modalIn { from { opacity:0; transform:scale(0.95); } to { opacity:1; transform:scale(1); } }
.table-card { transition: transform 0.15s ease-out; }
.table-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(0,0,0,0.2); }
</style>

<?php render_footer(); ?>

