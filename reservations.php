<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/util.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

start_app_session();
require_role(['admin', 'cashier']);

// ── Auto-create settings table & load shift times ──
try {
  db()->exec("
    CREATE TABLE IF NOT EXISTS app_settings (
      setting_key VARCHAR(50) PRIMARY KEY,
      setting_value VARCHAR(255) NOT NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
  ");
  // Seed defaults if not present
  db()->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('morning_shift_start', '08:00'), ('evening_shift_start', '16:30'), ('night_shift_end', '02:30')");
} catch (Throwable $ignore) {}

// Load saved shift settings
$savedMorning = '08:00';
$savedEvening = '16:30';
$savedNightEnd = '02:30';
try {
  $ssStmt = db()->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('morning_shift_start','evening_shift_start','night_shift_end')");
  foreach ($ssStmt->fetchAll() as $ss) {
    if ($ss['setting_key'] === 'morning_shift_start') $savedMorning = $ss['setting_value'];
    if ($ss['setting_key'] === 'evening_shift_start') $savedEvening = $ss['setting_value'];
    if ($ss['setting_key'] === 'night_shift_end') $savedNightEnd = $ss['setting_value'];
  }
} catch (Throwable $ignore) {}

// ── AJAX: Save shift settings ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_shift_settings') {
  header('Content-Type: application/json');
  try {
    require_role(['admin']);
    $mStart = trim((string)($_POST['morning_start'] ?? ''));
    $eStart = trim((string)($_POST['evening_start'] ?? ''));
    $nEnd   = trim((string)($_POST['night_end'] ?? ''));
    if (preg_match('/^\d{2}:\d{2}$/', $mStart)) {
      db()->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('morning_shift_start', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$mStart]);
    }
    if (preg_match('/^\d{2}:\d{2}$/', $eStart)) {
      db()->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('evening_shift_start', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$eStart]);
    }
    if (preg_match('/^\d{2}:\d{2}$/', $nEnd)) {
      db()->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('night_shift_end', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$nEnd]);
    }
    echo json_encode(['ok' => true]);
  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

// ── POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'add_reservation') {
      $tableId      = (int)($_POST['table_id'] ?? 0);
      $custName     = trim((string)($_POST['customer_name'] ?? ''));
      $custContact  = trim((string)($_POST['customer_contact'] ?? ''));
      $resDate      = (string)($_POST['reservation_date'] ?? '');
      $resTime      = (string)($_POST['start_time'] ?? '');
      $durHours     = (float)($_POST['duration_hours'] ?? 1);
      $downPayment  = (float)($_POST['down_payment'] ?? 0);
      $notes        = trim((string)($_POST['notes'] ?? ''));

      if ($tableId <= 0) throw new RuntimeException('Please select a table.');
      if ($custName === '') throw new RuntimeException('Customer name is required.');
      if ($resDate === '') throw new RuntimeException('Reservation date is required.');
      if ($resTime === '') throw new RuntimeException('Start time is required.');
      if ($durHours <= 0) throw new RuntimeException('Duration must be at least 30 minutes.');

      // Check for overlapping reservations on same table
      $endTime = date('H:i:s', strtotime($resTime) + (int)($durHours * 3600));
      $overlap = db()->prepare("
        SELECT id FROM reservations
        WHERE table_id = ? AND reservation_date = ? AND status IN ('pending')
        AND (
          (start_time < ? AND ADDTIME(start_time, SEC_TO_TIME(duration_hours * 3600)) > ?)
        )
      ");
      $overlap->execute([$tableId, $resDate, $endTime, $resTime]);
      if ($overlap->fetch()) {
        throw new RuntimeException('This table already has a reservation that overlaps with this time slot.');
      }

      $stmt = db()->prepare("
        INSERT INTO reservations (table_id, customer_name, customer_contact, reservation_date, start_time, duration_hours, down_payment, notes, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
      ");
      $stmt->execute([$tableId, $custName, $custContact ?: null, $resDate, $resTime, $durHours, $downPayment, $notes ?: null, (int)current_user()['id']]);
      flash_set('ok', 'Reservation added! Down payment: ₱' . number_format($downPayment, 2));
      redirect('reservations.php');
    }

    if ($action === 'cancel_reservation') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid reservation.');
      $r = db()->prepare("SELECT status FROM reservations WHERE id = ?");
      $r->execute([$id]);
      $row = $r->fetch();
      if (!$row) throw new RuntimeException('Reservation not found.');
      if ($row['status'] !== 'pending') throw new RuntimeException('Only pending reservations can be cancelled.');

      db()->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = ?")->execute([$id]);
      flash_set('ok', 'Reservation cancelled.');
      redirect('reservations.php');
    }

    if ($action === 'no_show') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid reservation.');
      db()->prepare("UPDATE reservations SET status = 'no_show' WHERE id = ? AND status = 'pending'")->execute([$id]);
      flash_set('ok', 'Marked as no-show.');
      redirect('reservations.php');
    }

  } catch (Throwable $e) {
    flash_set('danger', $e->getMessage());
    redirect('reservations.php');
  }
}

$flash = flash_get();

// ── Data ──
$filterDate = (string)($_GET['date'] ?? date('Y-m-d'));
$filterStatus = (string)($_GET['status'] ?? 'all');

$tables = db()->query("SELECT id, table_number, type FROM tables WHERE is_deleted = 0 AND type != 'kubo' ORDER BY CASE type WHEN 'regular' THEN 1 WHEN 'vip' THEN 2 WHEN 'ktv' THEN 3 END, table_number ASC")->fetchAll(PDO::FETCH_ASSOC);

$sql = "
  SELECT r.*, t.table_number, t.type, t.rate_per_hour
  FROM reservations r
  JOIN tables t ON t.id = r.table_id
  WHERE r.reservation_date = ?
";
$params = [$filterDate];
if ($filterStatus !== 'all') {
  $sql .= " AND r.status = ?";
  $params[] = $filterStatus;
}
$sql .= " ORDER BY r.start_time ASC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Today's upcoming count
$upcomingCount = (int)db()->prepare("SELECT COUNT(*) AS c FROM reservations WHERE reservation_date = CURDATE() AND status = 'pending'")->execute([]) ? 0 : 0;
$ucStmt = db()->query("SELECT COUNT(*) AS c FROM reservations WHERE reservation_date = CURDATE() AND status = 'pending'");
$upcomingCount = (int)$ucStmt->fetch()['c'];

render_header('Reservations', 'reservations');
?>

<?php if ($flash): ?>
  <div class="alert alert--<?php echo h($flash['type']); ?>"><?php echo h($flash['message']); ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:14px;">
  <div class="row" style="align-items:center; flex-wrap:wrap; gap:10px;">
    <div>
      <div class="card__title">Reservations</div>
      <div style="margin-top:4px; color:var(--muted); font-size:13px;">
        <?php echo $upcomingCount; ?> upcoming today
      </div>
    </div>
    <div class="spacer"></div>
    <div style="display:flex; gap:10px;">
      <button class="btn" type="button" onclick="document.getElementById('addResModal').style.display='flex'">+ Add Reservation</button>
    </div>
  </div>

  <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
  <!-- Date & Status Filter -->
  <form id="resFilterForm" method="get" class="row" style="margin-top:14px; gap:10px; align-items:flex-end; flex-wrap:wrap;">
    <div class="field" style="min-width:150px;">
      <div class="label">Date</div>
      <input type="date" name="date" value="<?php echo h($filterDate); ?>" onchange="this.form.submit()">
    </div>
    <div class="field" style="min-width:130px;">
      <div class="label">Status</div>
      <select name="status" onchange="this.form.submit()">
        <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All</option>
        <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
        <option value="started" <?php echo $filterStatus === 'started' ? 'selected' : ''; ?>>Started</option>
        <option value="completed" <?php echo $filterStatus === 'completed' ? 'selected' : ''; ?>>Completed</option>
        <option value="cancelled" <?php echo $filterStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
        <option value="no_show" <?php echo $filterStatus === 'no_show' ? 'selected' : ''; ?>>No Show</option>
      </select>
    </div>
    <div class="field">
      <a class="btn btn--ghost" href="reservations.php">Today</a>
    </div>
  </form>
  <?php endif; ?>

  <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
  <!-- Shift Settings & Export Buttons -->
  <div style="margin-top:16px; padding-top:14px; border-top:1px solid var(--border);">
    <div style="font-size:13px; font-weight:700; color:var(--text); margin-bottom:10px;">⏰ Shift Settings & Export</div>
    <div class="row" style="gap:10px; align-items:flex-end; flex-wrap:wrap;">
      <div class="field" style="min-width:140px;">
        <div class="label">Morning Shift Start</div>
        <input type="time" id="morningStart" value="<?php echo h($savedMorning); ?>" style="font-size:13px;">
      </div>
      <div class="field" style="min-width:140px;">
        <div class="label">Night Shift Start</div>
        <input type="time" id="eveningStart" value="<?php echo h($savedEvening); ?>" style="font-size:13px;">
      </div>
      <div class="field" style="min-width:140px;">
        <div class="label">Night Shift End</div>
        <input type="time" id="nightEnd" value="<?php echo h($savedNightEnd); ?>" style="font-size:13px;">
      </div>
      <div class="field" style="align-self:end;">
        <button class="btn" type="button" onclick="exportReservation('morning')" style="background:#38bdf8; color:white; border:none; font-size:12px;">
          ☀️ Export Morning
        </button>
      </div>
      <div class="field" style="align-self:end;">
        <button class="btn" type="button" onclick="exportReservation('evening')" style="background:#6366f1; color:white; border:none; font-size:12px;">
          🌙 Export Night
        </button>
      </div>
      <div class="field" style="align-self:end;">
        <button class="btn" type="button" onclick="exportReservation('both')" style="background:#22c55e; color:white; border:none; font-size:12px;">
          📊 Export Both
        </button>
      </div>
    </div>
    <div style="margin-top:6px; font-size:11px; color:var(--muted);">
      ☀️ Morning: <strong id="morningLabel"><?php echo date('g:i A', strtotime($savedMorning)); ?></strong> – <strong id="morningEndLabel"><?php echo date('g:i A', strtotime($savedEvening)); ?></strong>
      &nbsp;|&nbsp;
      🌙 Night: <strong id="eveningLabel"><?php echo date('g:i A', strtotime($savedEvening)); ?></strong> – <strong id="nightEndLabel"><?php echo date('g:i A', strtotime($savedNightEnd)); ?></strong> (next day)
      &nbsp;— Times auto-save on change.
    </div>
    <div id="shiftSaveStatus" style="margin-top:4px; font-size:11px; color:#22c55e; display:none;">✓ Saved</div>
  </div>
  <?php endif; ?>
</div>

<!-- Reservations List -->
<?php if (empty($reservations)): ?>
  <div class="card" style="text-align:center; padding:40px 20px; color:var(--muted);">
    <div style="font-size:15px;">No reservations found for <?php echo date('M d, Y', strtotime($filterDate)); ?></div>
  </div>
<?php else: ?>
  <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap:14px;">
    <?php foreach ($reservations as $r):
      $statusColors = [
        'pending'   => ['bg' => 'rgba(56,189,248,0.12)', 'color' => '#38bdf8'],
        'started'   => ['bg' => 'rgba(34,197,94,0.12)',  'color' => '#22c55e'],
        'completed' => ['bg' => 'rgba(107,114,128,0.12)','color' => '#9ca3af'],
        'cancelled' => ['bg' => 'rgba(239,68,68,0.12)',  'color' => '#ef4444'],
        'no_show'   => ['bg' => 'rgba(245,158,11,0.12)', 'color' => '#f59e0b'],
      ];
      $sc = $statusColors[$r['status']] ?? $statusColors['pending'];
      $startFmt = date('h:i A', strtotime($r['start_time']));
      $endTs = strtotime($r['start_time']) + (int)((float)$r['duration_hours'] * 3600);
      $endFmt = date('h:i A', $endTs);
      $totalCost = round((float)$r['rate_per_hour'] * (float)$r['duration_hours'], 2);
      $balance = max(0, $totalCost - (float)$r['down_payment']);
      $isPending = $r['status'] === 'pending';
      $typeBadge = '';
      if ($r['type'] === 'vip') $typeBadge = '<span class="badge badge--vip" style="font-size:10px; margin-left:4px;">VIP</span>';
      elseif ($r['type'] === 'ktv') $typeBadge = '<span class="badge" style="font-size:10px; margin-left:4px; background:rgba(168,85,247,0.2); color:#c084fc;">KTV</span>';
    ?>
      <div class="card" style="border-left:4px solid <?php echo $sc['color']; ?>; position:relative;">
        <!-- Header -->
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
          <div style="font-size:16px; font-weight:700;"><?php echo h($r['table_number']); ?> <?php echo $typeBadge; ?></div>
          <div class="spacer"></div>
          <span class="badge" style="background:<?php echo $sc['bg']; ?>; color:<?php echo $sc['color']; ?>; font-size:11px;">
            <?php echo ucfirst($r['status']); ?>
          </span>
        </div>

        <!-- Info Grid -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px; font-size:13px; margin-bottom:10px;">
          <div><span style="color:var(--muted);">Customer:</span><br><strong><?php echo h($r['customer_name']); ?></strong></div>
          <div><span style="color:var(--muted);">Contact:</span><br><strong><?php echo h($r['customer_contact'] ?? '—'); ?></strong></div>
          <div><span style="color:var(--muted);">Time:</span><br><strong><?php echo $startFmt . ' – ' . $endFmt; ?></strong></div>
          <div><span style="color:var(--muted);">Duration:</span><br><strong><?php echo $r['duration_hours']; ?>hr</strong></div>
        </div>

        <!-- Payment Info -->
        <div style="background:var(--surface2); border-radius:8px; padding:10px; margin-bottom:10px;">
          <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:4px;">
            <span style="color:var(--muted);">Estimated Total:</span>
            <strong>₱<?php echo number_format($totalCost, 2); ?></strong>
          </div>
          <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:4px;">
            <span style="color:#22c55e;">Down Payment:</span>
            <strong style="color:#22c55e;">₱<?php echo number_format((float)$r['down_payment'], 2); ?></strong>
          </div>
          <div style="display:flex; justify-content:space-between; font-size:14px; padding-top:6px; border-top:1px solid var(--border);">
            <span style="color:var(--muted); font-weight:600;">Balance Due:</span>
            <strong style="color:var(--primary); font-size:16px;">₱<?php echo number_format($balance, 2); ?></strong>
          </div>
        </div>

        <?php if (!empty($r['notes'])): ?>
          <div style="font-size:12px; color:var(--muted); margin-bottom:10px; font-style:italic;">Notes: <?php echo h($r['notes']); ?></div>
        <?php endif; ?>

        <!-- Actions -->
        <?php if ($isPending): ?>
          <div style="display:flex; gap:8px;">
            <a class="btn btn--primary" style="flex:1; text-align:center; text-decoration:none; font-size:13px;"
               href="tables.php?start_reservation=<?php echo (int)$r['id']; ?>">Start</a>
            <button class="btn btn--ghost btn--block" style="flex:1; font-size:13px;" type="button" onclick="openCancelModal(<?php echo (int)$r['id']; ?>, '<?php echo addslashes(h($r['customer_name'])); ?>')">Cancel</button>
            <button class="btn btn--ghost" style="flex-shrink:0; font-size:13px; padding:8px;" type="button" title="Mark as No-show" onclick="openNoShowModal(<?php echo (int)$r['id']; ?>, '<?php echo addslashes(h($r['customer_name'])); ?>')">No Show</button>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- ═══ Add Reservation Modal ═══ -->
<div id="addResModal" class="game-modal" style="display:none;">
  <div class="game-modal__box" style="max-width:500px;">
    <div class="game-modal__header">
      <h3>New Reservation</h3>
      <span class="game-modal__close" onclick="document.getElementById('addResModal').style.display='none'">&times;</span>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="add_reservation">
      <div class="game-modal__body">
        <div class="game-modal__row">
          <div class="game-modal__field">
            <label>Customer Name *</label>
            <input type="text" name="customer_name" required>
          </div>
          <div class="game-modal__field">
            <label>Contact #</label>
            <input type="text" name="customer_contact">
          </div>
        </div>

        <div class="game-modal__row" style="margin-top:12px;">
          <div class="game-modal__field">
            <label>Table *</label>
            <select name="table_id" required>
              <option value="">Select table...</option>
              <?php foreach ($tables as $t): ?>
                <option value="<?php echo (int)$t['id']; ?>">
                  <?php echo h($t['table_number']); ?>
                  <?php echo $t['type'] === 'vip' ? '(VIP)' : ($t['type'] === 'ktv' ? '(KTV)' : ''); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="game-modal__field">
            <label>Date *</label>
            <input type="date" name="reservation_date" required value="<?php echo date('Y-m-d'); ?>" min="<?php echo date('Y-m-d'); ?>">
          </div>
        </div>

        <div class="game-modal__row" style="margin-top:12px;">
          <div class="game-modal__field">
            <label>Start Time *</label>
            <input type="time" name="start_time" required>
          </div>
          <div class="game-modal__field">
            <label>Duration (hrs)</label>
            <select name="duration_hours" required>
              <option value="0.5">30 min</option>
              <option value="1" selected>1 hr</option>
              <option value="2">2 hrs</option>
              <option value="3">3 hrs</option>
              <option value="4">4 hrs</option>
              <option value="5">5 hrs</option>
            </select>
          </div>
        </div>

        <div class="game-modal__row" style="margin-top:12px;">
          <div class="game-modal__field">
            <label>Down Payment (₱) - Optional</label>
            <input type="number" name="down_payment" step="0.01" min="0" value="0.00">
          </div>
          <div class="game-modal__field">
            <label>Notes (optional)</label>
            <input type="text" name="notes">
          </div>
        </div>
      </div>
      <div style="padding:0 20px 20px; display:flex; gap:10px; justify-content:flex-end;">
        <button type="button" class="btn btn--ghost" onclick="document.getElementById('addResModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn btn--primary">Save Reservation</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ Cancel Reservation Modal ═══ -->
<div id="cancelModal" class="game-modal" style="display:none;">
  <div class="game-modal__box" style="max-width:400px;">
    <div class="game-modal__header">
      <h3>🛑 Cancel Reservation</h3>
      <span class="game-modal__close" onclick="closeCancelModal()">&times;</span>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="cancel_reservation">
      <input type="hidden" name="id" id="cancel_res_id">
      <div class="game-modal__body" style="text-align:center; padding:28px 24px;">
        <div style="width:56px; height:56px; margin:0 auto 16px; border-radius:50%; background:rgba(239,68,68,0.12); display:flex; align-items:center; justify-content:center; font-size:28px;">📅</div>
        <p style="color:var(--text); font-size:15px; margin:0 0 8px;">Are you sure you want to cancel the reservation for <strong id="cancelCustomerName"></strong>?</p>
        <p style="color:var(--muted); font-size:13px; margin:0;">This action cannot be undone.</p>
        <div style="display:flex; justify-content:center; gap:10px; margin-top:24px;">
          <button type="button" class="btn btn--ghost" onclick="closeCancelModal()">Go Back</button>
          <button type="submit" class="btn btn--danger">Cancel Reservation</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ═══ No Show Modal ═══ -->
<div id="noShowModal" class="game-modal" style="display:none;">
  <div class="game-modal__box" style="max-width:400px;">
    <div class="game-modal__header">
      <h3>🚫 Mark as No Show</h3>
      <span class="game-modal__close" onclick="closeNoShowModal()">&times;</span>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="no_show">
      <input type="hidden" name="id" id="noshow_res_id">
      <div class="game-modal__body" style="text-align:center; padding:28px 24px;">
        <div style="width:56px; height:56px; margin:0 auto 16px; border-radius:50%; background:rgba(245,158,11,0.12); display:flex; align-items:center; justify-content:center; font-size:28px;">🫥</div>
        <p style="color:var(--text); font-size:15px; margin:0 0 8px;">Mark reservation for <strong id="noshowCustomerName"></strong> as a no-show?</p>
        <p style="color:var(--muted); font-size:13px; margin:0;">The table will be freed up for other customers.</p>
        <div style="display:flex; justify-content:center; gap:10px; margin-top:24px;">
          <button type="button" class="btn btn--ghost" onclick="closeNoShowModal()">Go Back</button>
          <button type="submit" class="btn btn--primary" style="background:#f59e0b; border-color:#f59e0b; color:#fff;">Confirm No Show</button>
        </div>
      </div>
    </form>
  </div>
</div>

<style>
  .game-modal { position:fixed; inset:0; z-index:1000; background:rgba(0,0,0,0.35); display:flex; align-items:center; justify-content:center; }
  .game-modal__box { background:#fff; border:1px solid var(--border); border-radius:14px; width:95%; box-shadow:0 8px 32px rgba(0,0,0,.1); animation:modalIn 0.2s ease-out; }
  .game-modal__header { display:flex; justify-content:space-between; align-items:center; padding:18px 20px; border-bottom:1px solid var(--border); }
  .game-modal__header h3 { margin:0; font-size:16px; color:var(--text); }
  .game-modal__close { color:var(--muted); font-size:24px; cursor:pointer; line-height:1; }
  .game-modal__close:hover { color:var(--text); }
  .game-modal__body { padding:20px; }
  .game-modal__row { display:flex; gap:12px; }
  .game-modal__field { flex:1; }
  .game-modal__field label { display:block; font-size:12px; color:var(--muted); margin-bottom:4px; text-transform:uppercase; }
  @keyframes modalIn { from { opacity:0; transform:scale(0.92) translateY(10px); } to { opacity:1; transform:scale(1) translateY(0); } }
</style>

<script>
// Cancel Reservation Modal
function openCancelModal(id, customerName) {
  document.getElementById('cancel_res_id').value = id;
  document.getElementById('cancelCustomerName').textContent = customerName;
  document.getElementById('cancelModal').style.display = 'flex';
}
function closeCancelModal() {
  document.getElementById('cancelModal').style.display = 'none';
}

// No Show Modal
function openNoShowModal(id, customerName) {
  document.getElementById('noshow_res_id').value = id;
  document.getElementById('noshowCustomerName').textContent = customerName;
  document.getElementById('noShowModal').style.display = 'flex';
}
function closeNoShowModal() {
  document.getElementById('noShowModal').style.display = 'none';
}

// Format time to 12-hour AM/PM
function formatTime12(timeStr) {
  const [h, m] = timeStr.split(':').map(Number);
  const ampm = h >= 12 ? 'PM' : 'AM';
  const hr = h % 12 || 12;
  return hr + ':' + String(m).padStart(2, '0') + ' ' + ampm;
}

// Update shift labels when time inputs change
function updateShiftLabels() {
  const mEl = document.getElementById('morningStart');
  const eEl = document.getElementById('eveningStart');
  const nEl = document.getElementById('nightEnd');
  const mLabel = document.getElementById('morningLabel');
  const mEndLabel = document.getElementById('morningEndLabel');
  const eLabel = document.getElementById('eveningLabel');
  const nLabel = document.getElementById('nightEndLabel');
  if (mEl && mLabel) mLabel.textContent = formatTime12(mEl.value);
  if (eEl && mEndLabel) mEndLabel.textContent = formatTime12(eEl.value);
  if (eEl && eLabel) eLabel.textContent = formatTime12(eEl.value);
  if (nEl && nLabel) nLabel.textContent = formatTime12(nEl.value);
}

// Auto-save shift settings to database
function saveShiftSettings() {
  const mEl = document.getElementById('morningStart');
  const eEl = document.getElementById('eveningStart');
  const nEl = document.getElementById('nightEnd');
  if (!mEl || !eEl) return;

  const statusEl = document.getElementById('shiftSaveStatus');
  
  let body = 'action=save_shift_settings&morning_start=' + encodeURIComponent(mEl.value) + '&evening_start=' + encodeURIComponent(eEl.value);
  if (nEl) body += '&night_end=' + encodeURIComponent(nEl.value);

  fetch('reservations.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: body
  })
  .then(r => r.json())
  .then(data => {
    if (statusEl) {
      statusEl.textContent = data.ok ? '✓ Saved' : '✗ Error';
      statusEl.style.color = data.ok ? '#22c55e' : '#ef4444';
      statusEl.style.display = 'block';
      setTimeout(() => { statusEl.style.display = 'none'; }, 2000);
    }
  })
  .catch(() => {
    if (statusEl) {
      statusEl.textContent = '✗ Save failed';
      statusEl.style.color = '#ef4444';
      statusEl.style.display = 'block';
      setTimeout(() => { statusEl.style.display = 'none'; }, 2000);
    }
  });
}

// Attach listeners — update labels AND auto-save
['morningStart', 'eveningStart', 'nightEnd'].forEach(id => {
  const el = document.getElementById(id);
  if (el) el.addEventListener('change', () => { updateShiftLabels(); saveShiftSettings(); });
});

// Export reservation with shift filter
function exportReservation(shift) {
  const filterDate = <?php echo json_encode($filterDate); ?>;
  const filterStatus = <?php echo json_encode($filterStatus); ?>;
  const morningStart = document.getElementById('morningStart') ? document.getElementById('morningStart').value : '08:00';
  const eveningStart = document.getElementById('eveningStart') ? document.getElementById('eveningStart').value : '16:30';
  const nightEnd     = document.getElementById('nightEnd') ? document.getElementById('nightEnd').value : '02:30';
  
  const url = 'exports/export_reservations.php'
    + '?from=' + encodeURIComponent(filterDate)
    + '&to=' + encodeURIComponent(filterDate)
    + '&status=' + encodeURIComponent(filterStatus)
    + '&shift=' + encodeURIComponent(shift)
    + '&morning_start=' + encodeURIComponent(morningStart)
    + '&evening_start=' + encodeURIComponent(eveningStart)
    + '&night_end=' + encodeURIComponent(nightEnd);
  
  window.open(url, '_blank');
}
</script>

<?php render_footer(); ?>

