<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/util.php';

// Prevent browser caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

start_app_session();
require_role(['admin', 'cashier']);


$flash = null;

const VIP_RATE_PER_HOUR = 250.00;

function get_customers_vip(): array
{
  return db()->query("SELECT id, name, contact, loyalty_games, loyalty_vip_games FROM customers ORDER BY name ASC")->fetchAll();
}

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'add_vip_table') {
      require_role(['admin']);
      $tableNumber = trim((string)($_POST['table_number'] ?? ''));
      if ($tableNumber === '') throw new RuntimeException('Table name is required.');

      $stmt = db()->prepare("INSERT INTO tables (table_number, type, status, rate_per_hour) VALUES (?, 'vip', 'available', ?)");
      $stmt->execute([$tableNumber, VIP_RATE_PER_HOUR]);
      $returnUrl = (string)($_POST['return_url'] ?? 'vip_tables.php');
      flash_set('ok', 'VIP Table added.');
      redirect($returnUrl);
    }

    if ($action === 'add_ktv_table') {
      require_role(['admin']);
      $tableNumber = trim((string)($_POST['table_number'] ?? ''));
      if ($tableNumber === '') throw new RuntimeException('KTV Room name is required.');

      $stmt = db()->prepare("INSERT INTO tables (table_number, type, status, rate_per_hour) VALUES (?, 'ktv', 'available', 200)");
      $stmt->execute([$tableNumber]);
      $returnUrl = (string)($_POST['return_url'] ?? 'vip_tables.php');
      flash_set('ok', 'KTV Room added.');
      redirect($returnUrl);
    }

    if ($action === 'edit_ktv_table') {
      require_role(['admin']);
      $id = (int)($_POST['id'] ?? 0);
      $tableNumber = trim((string)($_POST['table_number'] ?? ''));
      $rate = (float)($_POST['rate_per_hour'] ?? 0);
      if ($id <= 0 || $tableNumber === '' || $rate < 0) throw new RuntimeException('Invalid data.');
      $stmt = db()->prepare("UPDATE tables SET table_number = ?, rate_per_hour = ? WHERE id = ?");
      $stmt->execute([$tableNumber, $rate, $id]);
      $returnUrl = (string)($_POST['return_url'] ?? 'vip_tables.php');
      flash_set('ok', 'KTV Room updated.');
      redirect($returnUrl);
    }

    if ($action === 'delete_ktv_table') {
      require_role(['admin']);
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid table.');
      $t = db()->prepare("SELECT status FROM tables WHERE id=?");
      $t->execute([$id]);
      $row = $t->fetch();
      if (!$row) throw new RuntimeException('Table not found.');
      if ($row['status'] === 'in_use') throw new RuntimeException('Cannot delete: table is in use.');
      db()->prepare("UPDATE tables SET is_deleted = 1 WHERE id=?")->execute([$id]);
      $returnUrl = (string)($_POST['return_url'] ?? 'vip_tables.php');
      flash_set('ok', 'KTV Room removed.');
      redirect($returnUrl);
    }

    if ($action === 'edit_vip_table') {
      require_role(['admin']);
      $id = (int)($_POST['id'] ?? 0);
      $tableNumber = trim((string)($_POST['table_number'] ?? ''));
      $rate = (float)($_POST['rate_per_hour'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid table.');
      if ($tableNumber === '') throw new RuntimeException('Table name is required.');
      if ($rate < 0) throw new RuntimeException('Invalid rate per hour.');

      $stmt = db()->prepare("UPDATE tables SET table_number = ?, rate_per_hour = ? WHERE id = ?");
      $stmt->execute([$tableNumber, $rate, $id]);
      $returnUrl = (string)($_POST['return_url'] ?? 'vip_tables.php');
      flash_set('ok', 'VIP Table updated.');
      redirect($returnUrl);
    }

    if ($action === 'delete_vip_table') {
      require_role(['admin']);
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid table.');

      $t = db()->prepare("SELECT status FROM tables WHERE id=?");
      $t->execute([$id]);
      $row = $t->fetch();
      if (!$row) throw new RuntimeException('Table not found.');
      if ($row['status'] === 'in_use') throw new RuntimeException('Cannot delete: table is in use.');

      db()->prepare("UPDATE tables SET is_deleted = 1 WHERE id=?")->execute([$id]);
      $returnUrl = (string)($_POST['return_url'] ?? 'vip_tables.php');
      flash_set('ok', 'VIP Table removed.');
      redirect($returnUrl);
    }

    if ($action === 'start_game') {
      $tableId = (int)($_POST['table_id'] ?? 0);
      $customerIdRaw = (string)($_POST['customer_id'] ?? '');
      $walkInName = trim((string)($_POST['walk_in_name'] ?? ''));
      $hours = (float)($_POST['hours'] ?? 0);
      $payment = (float)($_POST['payment'] ?? 0);
      $resId = (int)($_POST['reservation_id'] ?? 0);
      $karaoke = (int)($_POST['karaoke_included'] ?? 0);
      $downPayment = 0.0;
      if ($resId > 0) {
        $rv = db()->prepare("SELECT * FROM reservations WHERE id = ? AND status = 'pending'");
        $rv->execute([$resId]);
        $rvRow = $rv->fetch();
        if ($rvRow) {
          $downPayment = (float)$rvRow['down_payment'];
        }
      }

      if ($tableId <= 0) throw new RuntimeException('Invalid table.');
      if ($hours <= 0) throw new RuntimeException('Please select number of hours.');

      $customerId = null;
      if ($customerIdRaw !== '') {
        $customerId = (int)$customerIdRaw;
        if ($customerId <= 0) $customerId = null;
      }
      if ($customerId === null && $walkInName === '') {
        $walkInName = 'Walk-in';
      }

      $stmt = db()->prepare("SELECT status, rate_per_hour FROM tables WHERE id=? AND type IN ('vip', 'ktv')");
      $stmt->execute([$tableId]);
      $table = $stmt->fetch();
      if (!$table) throw new RuntimeException('VIP/KTV Room not found.');
      if ($table['status'] !== 'available') throw new RuntimeException('Table is not available.');

      $rate = (float)$table['rate_per_hour'];
      $total = round($rate * $hours, 2);

      if (round($payment, 2) < round($total, 2) - 0.01)
        throw new RuntimeException('Payment not enough. Required: ₱' . number_format($total, 2));
      $change = round($payment - $total, 2);

      db()->beginTransaction();
      db()->prepare("UPDATE tables SET status='in_use' WHERE id=?")->execute([$tableId]);

      $ins = db()->prepare("
        INSERT INTO game_sessions
          (table_id, customer_id, walk_in_name, rate_per_hour, start_time, scheduled_end_time, hours_purchased, total_amount, duration_seconds, games_earned, games_redeemed, created_by, karaoke_included, reservation_id, down_payment)
        VALUES
          (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND), ?, ?, ?, 0, 0, ?, ?)
      ");
      $ins->execute([
        $tableId,
        $customerId,
        $customerId ? null : $walkInName,
        $rate,
        (int)($hours * 3600),
        $hours,
        $total,
        (int)($hours * 3600),
        (int)current_user()['id'],
        $karaoke,
        $resId > 0 ? $resId : null,
        $downPayment
      ]);
      $sessionId = (int)db()->lastInsertId();

      if ($resId > 0) {
        db()->prepare("UPDATE reservations SET status = 'started', session_id = ? WHERE id = ?")->execute([$sessionId, $resId]);
      }

      db()->prepare("
        INSERT INTO transactions (session_id, payment, change_amount, created_by)
        VALUES (?, ?, ?, ?)
      ")->execute([$sessionId, $payment, $change, (int)current_user()['id']]);

      db()->commit();

      $returnUrl = (string)($_POST['return_url'] ?? 'vip_tables.php');
      $msg = 'Game session started (' . $hours . 'h).';
      flash_set('ok', $msg);
      redirect($returnUrl);
    }

    if ($action === 'extend_game') {
      $sessionId = (int)($_POST['session_id'] ?? 0);
      $hours = (float)($_POST['hours'] ?? 0);
      $payment = (float)($_POST['payment'] ?? 0);
      $resId = (int)($_POST['reservation_id'] ?? 0);
      $downPayment = 0.0;
      if ($resId > 0) {
        $rv = db()->prepare("SELECT * FROM reservations WHERE id = ? AND status = 'pending'");
        $rv->execute([$resId]);
        $rvRow = $rv->fetch();
        if ($rvRow) {
          $downPayment = (float)$rvRow['down_payment'];
        }
      }
      $karaoke = (int)($_POST['karaoke_included'] ?? 0);

      if ($sessionId <= 0) throw new RuntimeException('Invalid session.');
      if ($hours <= 0) throw new RuntimeException('Please select hours to extend.');

      $s = db()->prepare("SELECT gs.*, t.rate_per_hour AS current_rate FROM game_sessions gs JOIN tables t ON t.id=gs.table_id WHERE gs.id=? AND gs.end_time IS NULL LIMIT 1");
      $s->execute([$sessionId]);
      $session = $s->fetch();
      if (!$session) throw new RuntimeException('Session not found or already ended.');

      $rate = (float)$session['rate_per_hour'];
      $cost = round($rate * $hours, 2);

      if (round($payment, 2) < round($cost, 2) - 0.01)
        throw new RuntimeException('Payment not enough. Required: ₱' . number_format($cost, 2));
      $change = round($payment - $cost, 2);

      db()->beginTransaction();

      db()->prepare("
        UPDATE game_sessions
        SET scheduled_end_time = DATE_ADD(scheduled_end_time, INTERVAL ? SECOND),
            hours_purchased = hours_purchased + ?,
            total_amount = total_amount + ?,
            duration_seconds = duration_seconds + ?
        WHERE id = ?
      ")->execute([(int)($hours * 3600), $hours, $cost, (int)($hours * 3600), $sessionId]);

      db()->prepare("
        INSERT INTO transactions (session_id, payment, change_amount, created_by)
        VALUES (?, ?, ?, ?)
      ")->execute([$sessionId, $payment, $change, (int)current_user()['id']]);

      db()->commit();

      $returnUrl = (string)($_POST['return_url'] ?? 'vip_tables.php');
      flash_set('ok', 'Extended by ' . $hours . 'h. Additional: ₱' . number_format($cost, 2));
      redirect($returnUrl);
    }

    if ($action === 'end_game') {
      $sessionId = (int)($_POST['session_id'] ?? 0);
      if ($sessionId <= 0) throw new RuntimeException('Invalid session.');

      $stmt = db()->prepare("
        SELECT gs.*, t.id AS t_id
        FROM game_sessions gs
        JOIN tables t ON t.id = gs.table_id
        WHERE gs.id = ? AND gs.end_time IS NULL
        LIMIT 1
      ");
      $stmt->execute([$sessionId]);
      $s = $stmt->fetch();
      if (!$s) throw new RuntimeException('Session not found or already ended.');

      db()->beginTransaction();
      db()->prepare("UPDATE game_sessions SET end_time = NOW() WHERE id = ?")->execute([$sessionId]);
      db()->prepare("UPDATE tables SET status='available' WHERE id=?")->execute([(int)$s['table_id']]);
      if (!empty($s['reservation_id'])) {
        db()->prepare("UPDATE reservations SET status = 'completed' WHERE id = ?")->execute([$s['reservation_id']]);
      }
      db()->commit();

      $returnUrl = (string)($_POST['return_url'] ?? 'vip_tables.php');
      flash_set('ok', 'Game ended. Table is now available.');
      redirect($returnUrl);
    }
  } catch (Throwable $e) {
    $returnUrl = (string)($_POST['return_url'] ?? 'vip_tables.php');
    if (db()->inTransaction()) db()->rollBack();
    flash_set('danger', $e->getMessage());
    redirect($returnUrl);
  }
}

$flash = flash_get();

// Data — VIP and KTV tables
$tables = db()->query("SELECT * FROM tables WHERE type='vip' AND is_deleted=0 ORDER BY id ASC")->fetchAll();
$tables_ktv = db()->query("SELECT * FROM tables WHERE type='ktv' AND is_deleted=0 ORDER BY id ASC")->fetchAll();
$customers = get_customers_vip();
$customerNameById = [];
foreach ($customers as $c) {
  $customerNameById[(int)$c['id']] = (string)$c['name'];
}

// Active sessions per table
$activeSessions = db()->query("
  SELECT gs.*, t.table_number
  FROM game_sessions gs
  JOIN tables t ON t.id = gs.table_id
  WHERE gs.end_time IS NULL AND t.type IN ('vip', 'ktv')
")->fetchAll();
$activeByTable = [];
foreach ($activeSessions as $s) {
  $activeByTable[(int)$s['table_id']] = $s;
}

$busyCustomerIds = [];
$allActive = db()->query("SELECT customer_id FROM game_sessions WHERE end_time IS NULL AND customer_id IS NOT NULL")->fetchAll();
foreach ($allActive as $a) {
  $busyCustomerIds[] = (int)$a['customer_id'];
}
$busyCustomerIds = array_values(array_unique($busyCustomerIds));

// Upcoming reservations per table (today, pending only)
$resStmt = db()->query("
  SELECT r.*, t.table_number
  FROM reservations r
  JOIN tables t ON t.id = r.table_id
  WHERE r.reservation_date = CURDATE() AND r.status = 'pending' AND t.type IN ('vip', 'ktv')
  ORDER BY r.start_time ASC
");
$allReservations = $resStmt->fetchAll(PDO::FETCH_ASSOC);

$nextResByTable = [];
foreach ($allReservations as $rv) {
  $tid = (int)$rv['table_id'];
  if (!isset($nextResByTable[$tid])) {
    $nextResByTable[$tid] = $rv;
  }
}

$maxHoursByTable = [];
foreach ($nextResByTable as $tid => $rv) {
  $resStartTs = strtotime(date('Y-m-d') . ' ' . $rv['start_time']);
  $nowTs = time();
  if ($resStartTs > $nowTs) {
    $diffHours = ($resStartTs - $nowTs) / 3600;
    $maxHoursByTable[$tid] = round($diffHours * 2) / 2;
    if ($maxHoursByTable[$tid] < 0.5) $maxHoursByTable[$tid] = 0;
  } else {
    $maxHoursByTable[$tid] = 0;
  }
}

// Handle start_reservation
$prefillReservation = null;
if (isset($_GET['start_reservation'])) {
  $resId = (int)$_GET['start_reservation'];
  $prStmt = db()->prepare("
    SELECT r.*, t.table_number, t.rate_per_hour, t.type as table_type
    FROM reservations r JOIN tables t ON t.id = r.table_id
    WHERE r.id = ? AND r.status = 'pending'
  ");
  $prStmt->execute([$resId]);
  $prefillReservation = $prStmt->fetch(PDO::FETCH_ASSOC);
}

render_header('VIP Table With Karaoke', 'vip_tables');
?>

<?php if ($flash): ?>
  <div class="alert alert--<?php echo h($flash['type']); ?>" style="margin-bottom:14px;">
    <?php echo h($flash['message']); ?>
  </div>
<?php endif; ?>

<div class="row" style="gap:14px; align-items:flex-start; flex-wrap:wrap; margin-bottom:14px;">
  <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
    <div class="card" style="flex:1; min-width:300px;">
      <div class="row">
        <div>
          <div class="card__title">Add VIP Table</div>
        </div>
        <div class="spacer"></div>
      </div>

      <form method="post" class="form" style="margin-top:12px;">
        <input type="hidden" name="action" value="add_vip_table">
        <div class="row">
          <div class="field" style="flex:2; min-width:220px;">
            <div class="label">VIP Table name</div>
            <input name="table_number" placeholder="VIP Table 1" required>
          </div>
          <div class="field" style="align-self:end;">
            <button class="btn" type="submit">Add</button>
          </div>
        </div>
      </form>
    </div>
      <div class="card" style="flex:1; min-width:300px;">
        <div class="row">
          <div><div class="card__title" style="color:#c084fc;">Add KTV Room</div></div>
          <div class="spacer"></div>
        </div>
        <form method="post" class="form" style="margin-top:12px;">
          <input type="hidden" name="action" value="add_ktv_table">
          <div class="row">
            <div class="field" style="flex:2; min-width:220px;">
              <div class="label">KTV Room name</div>
              <input name="table_number" placeholder="KTV Room 1" required>
            </div>
            <div class="field" style="align-self:end;">
              <button class="btn" style="background:#a855f7; color:white;" type="submit">Add</button>
            </div>
          </div>
        </form>
      </div>
  <?php endif; ?>
</div>

<div class="row" style="gap:14px; align-items:flex-start; flex-wrap:wrap;">
  <!-- Left Side: VIP Section -->
  <div style="flex:1; min-width:320px; display:flex; flex-direction:column; gap:14px;">
    <div class="card">
    <div class="row">
      <div>
        <div class="card__title">VIP Table With Karaoke</div>
      </div>
      <div class="spacer"></div>
      <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
      <a class="btn btn--ghost" href="customers.php">Manage customers</a>
      <?php endif; ?>
    </div>

    <!-- Search & Filter Bar -->
    <div style="margin-top:14px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <div style="position:relative; flex:1; min-width:220px;">
        <input type="text" id="tableSearch" placeholder="Search VIP table name..." style="width:100%; padding-left:36px;">
        <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--muted); pointer-events:none; font-size:15px;">🔍</span>
      </div>
      <div style="display:flex; gap:6px;">
        <button type="button" class="btn vip-filter-btn is-active btn--primary" data-filter="all">All</button>
        <button type="button" class="btn btn--ghost vip-filter-btn" data-filter="available">Available</button>
        <button type="button" class="btn btn--ghost vip-filter-btn" data-filter="in_use">In Use</button>
      </div>
    </div>

    <div id="noResults" style="display:none; text-align:center; padding:28px 14px; color:var(--muted);">
      No VIP tables found.
    </div>

  </div>

  <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(310px, 1fr)); gap:14px; margin-top:14px;" id="vipGrid">
    <?php foreach ($tables as $t): ?>
      <?php
        $tid = (int)$t['id'];
        $status = (string)$t['status'];
        $active = $activeByTable[$tid] ?? null;
        $isAvailable = $status === 'available';
        
        $hasRes = isset($nextResByTable[$tid]);
        $maxH = $maxHoursByTable[$tid] ?? 99;
        $resInfo = $hasRes ? $nextResByTable[$tid] : null;
        $resTimeFmt = $hasRes ? date('h:i A', strtotime($resInfo['start_time'])) : '';

        $maxExtH = 99;
        if ($active && $hasRes && !empty($active['scheduled_end_time'])) {
            $resStartTs = strtotime(date('Y-m-d') . ' ' . $resInfo['start_time']);
            $schedEndTs = strtotime($active['scheduled_end_time']);
            $diffH = ($resStartTs - $schedEndTs) / 3600;
            $maxExtH = max(0, round($diffH * 2) / 2);
        }
      ?>
      <div class="card table-card" data-table-name="<?php echo h(strtolower($t['table_number'])); ?>" data-status="<?php echo h($status); ?>"
        style="border-left: 4px solid <?php echo $isAvailable ? '#f59e0b' : '#f59e0b'; ?>; position:relative; overflow:visible;">

        <?php if (!$isAvailable): ?>
          <div style="position:absolute; top:0; right:0; width:80px; height:80px; background:radial-gradient(circle at top right, rgba(245,158,11,0.1), transparent 70%); pointer-events:none;"></div>
        <?php endif; ?>

        <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
          <div style="font-size:18px; font-weight:700; color:var(--text);">
            <?php echo h($t['table_number']); ?>
            <span class="badge badge--vip" style="margin-left:6px; font-size:11px;">VIP</span>
          </div>
          <div class="spacer"></div>
          <?php if ($isAvailable): ?>
            <span class="badge badge--ok">Available</span>
          <?php else: ?>
            <span class="badge badge--warn">In Use</span>
          <?php endif; ?>
        </div>

        <?php if ($active): ?>
          <?php if ($hasRes): ?>
            <div style="padding:6px 10px; margin-bottom:10px; background:linear-gradient(90deg, rgba(56,189,248,0.08), rgba(56,189,248,0.02)); border-radius:6px; border:1px solid rgba(56,189,248,0.2);">
              <span style="color:#38bdf8; font-size:12px; font-weight:700;">📅 Reserved at <?php echo $resTimeFmt; ?> (Up Next)</span>
              <span style="display:block; color:var(--muted); font-size:11px; margin-top:2px;">
                <?php echo h($resInfo['customer_name']); ?> · <?php echo $resInfo['duration_hours']; ?>hr
                <?php if ((float)$resInfo['down_payment'] > 0): ?>
                  · DP: ₱<?php echo number_format((float)$resInfo['down_payment'], 2); ?>
                <?php endif; ?>
              </span>
            </div>
          <?php endif; ?>
          <div style="background:var(--surface2); border-radius:8px; padding:12px; margin-bottom:12px;">
            <?php
              $customerId = !empty($active['customer_id']) ? (int)$active['customer_id'] : null;
              $customerName = $customerId !== null ? ($customerNameById[$customerId] ?? ('Customer #' . $customerId)) : 'Walk-in';
            ?>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
              <span style="font-size:12px; color:var(--muted); text-transform:uppercase;">Time Remaining</span>
              <?php if (!empty($active['scheduled_end_time'])): ?>
                <span class="badge badge--warn" 
                  data-countdown="<?php echo h($active['scheduled_end_time']); ?>"
                  data-session-id="<?php echo $active['id']; ?>"
                  data-table-name="<?php echo h($t['table_number']); ?>"
                  data-player-name="<?php echo h($customerName); ?>"
                  data-rate="<?php echo (float)$active['rate_per_hour']; ?>"
                  style="font-size:14px; font-weight:700;"
                >--:--:--</span>
              <?php else: ?>
                <span class="badge" data-timer-start="<?php echo h($active['start_time']); ?>">00:00:00</span>
              <?php endif; ?>
            </div>
            
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px; font-size:13px;">
              <div><span style="color:var(--muted);">Hours:</span> <strong><?php echo h(($active['hours_purchased'] ?? 0) . 'h'); ?></strong></div>
              <div><span style="color:var(--muted);">Paid:</span> <strong>₱<?php echo number_format((float)($active['total_amount'] ?? 0), 2); ?></strong></div>
              <div style="grid-column:span 2;">
                <strong><?php echo h($customerName); ?></strong>
                <?php if (!empty($active['karaoke_included'])): ?>
                  <span style="color:#38bdf8; font-size:11px; margin-left:6px;">🎤 Karaoke</span>
                <?php endif; ?>
              </div>
              <div style="grid-column:span 2; display:flex; justify-content:space-between; margin-top:4px; padding-top:6px; border-top:1px solid var(--border2);">
                <div><span style="color:var(--muted); font-size:11px; text-transform:uppercase;">Start Time</span><br><strong><?php echo date('h:i A', strtotime($active['start_time'])); ?></strong></div>
                <div style="text-align:right;"><span style="color:var(--muted); font-size:11px; text-transform:uppercase;">Scheduled End</span><br><strong><?php echo !empty($active['scheduled_end_time']) ? date('h:i A', strtotime($active['scheduled_end_time'])) : '--:-- --'; ?></strong></div>
              </div>
            </div>
          </div>

          <div style="display:flex; gap:8px;">
            <button class="btn btn--primary" type="button" style="flex:1;" onclick="openExtendModal(<?php echo (int)$active['id']; ?>, '<?php echo h($t['table_number']); ?>', <?php echo (float)$active['rate_per_hour']; ?>, '<?php echo h($active['scheduled_end_time'] ?? ''); ?>', <?php echo $maxExtH; ?>)">Extend</button>
            <button class="btn btn--ghost" type="button" style="flex:1;" onclick="openEndModal(<?php echo (int)$active['id']; ?>, '<?php echo h($t['table_number']); ?>')">End Game</button>
          </div>
        <?php else: ?>
          <?php if ($hasRes): ?>
            <div style="padding:6px 10px; margin-bottom:10px; background:linear-gradient(90deg, rgba(56,189,248,0.08), rgba(56,189,248,0.02)); border-radius:6px; border:1px solid rgba(56,189,248,0.2);">
              <span style="color:#38bdf8; font-size:12px; font-weight:700;">📅 Reserved at <?php echo $resTimeFmt; ?></span>
              <span style="display:block; color:var(--muted); font-size:11px; margin-top:2px;">
                <?php echo h($resInfo['customer_name']); ?> · <?php echo $resInfo['duration_hours']; ?>hr
                <?php if ((float)$resInfo['down_payment'] > 0): ?>
                  · DP: ₱<?php echo number_format((float)$resInfo['down_payment'], 2); ?>
                <?php endif; ?>
              </span>
            </div>
          <?php endif; ?>

          <div style="text-align:center; padding:<?php echo $hasRes ? '8px' : '16px'; ?> 0 8px;">
            <div style="color:var(--muted); font-size:13px; margin-bottom:8px;">₱<?php echo number_format((float)$t['rate_per_hour'], 2); ?>/hr</div>
            
            <?php if ($hasRes && $maxH <= 0): ?>
              <a class="btn btn--primary" style="width:100%; text-decoration:none; text-align:center;" href="vip_tables.php?start_reservation=<?php echo (int)$resInfo['id']; ?>">▶️ Start Reservation</a>
            <?php elseif ($hasRes && $maxH > 0): ?>
              <div style="color:#f59e0b; font-size:11px; margin-bottom:6px; font-weight:600;">
                ⚠️ Max <?php echo $maxH; ?>hr allowed (reserved at <?php echo $resTimeFmt; ?>)
              </div>
              <button class="btn" type="button" style="width:100%;" onclick="openStartModal(<?php echo $tid; ?>, '<?php echo h($t['table_number']); ?>', <?php echo (float)$t['rate_per_hour']; ?>, <?php echo $maxH; ?>)">Start Game</button>
            <?php else: ?>
              <button class="btn" type="button" style="width:100%;" onclick="openStartModal(<?php echo $tid; ?>, '<?php echo h($t['table_number']); ?>', <?php echo (float)$t['rate_per_hour']; ?>, 0)">Start Game</button>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
          <details style="margin-top:10px; border-top:1px solid var(--border); padding-top:10px;">
            <summary class="btn btn--ghost" style="font-size:12px; width:100%; text-align:center;">⚙️ Admin Settings</summary>
            <div style="margin-top:10px;">
              <form method="post" class="form" style="margin-bottom:10px;">
                <input type="hidden" name="action" value="edit_vip_table">
                <input type="hidden" name="id" value="<?php echo $tid; ?>">
                <div class="field">
                  <div class="label" style="font-size:12px;">Table name</div>
                  <input name="table_number" value="<?php echo h($t['table_number']); ?>" required style="font-size:13px;">
                </div>
                <div class="field">
                  <div class="label" style="font-size:12px;">Rate/hr (₱)</div>
                  <input name="rate_per_hour" type="number" step="0.01" min="0" value="<?php echo h((string)$t['rate_per_hour']); ?>" required style="font-size:13px;">
                </div>
                <button class="btn btn--block" type="submit" style="font-size:12px;">Save</button>
              </form>
              <form method="post" onsubmit="return confirm('Delete this VIP table?');">
                <input type="hidden" name="action" value="delete_vip_table">
                <input type="hidden" name="id" value="<?php echo $tid; ?>">
                <button class="btn btn--danger btn--block" type="submit" style="font-size:12px;">Delete Table</button>
              </form>
            </div>
          </details>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  </div>

  <!-- Right Side: KTV Section -->
  <div style="flex:1; min-width:320px; display:flex; flex-direction:column; gap:14px;">
    <div class="card">
    <div class="row">
      <div>
        <div class="card__title" style="color:#c084fc;">🎤 KTV Rooms</div>
      </div>
      <div class="spacer"></div>
      <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
      <!-- Invisible spacer to exactly match 'Manage customers' button height -->
      <div style="height:38px;"></div>
      <?php endif; ?>
    </div>

    <!-- Search & Filter Bar -->
    <div style="margin-top:14px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <div style="position:relative; flex:1; min-width:220px;">
        <input type="text" id="ktvSearch" placeholder="Search KTV room name..." style="width:100%; padding-left:36px;">
        <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--muted); pointer-events:none; font-size:15px;">🔍</span>
      </div>
      <div style="display:flex; gap:6px;">
        <button type="button" class="btn ktv-filter-btn is-active btn--primary" style="background:#a855f7; border-color:#a855f7;" data-filter="all">All</button>
        <button type="button" class="btn btn--ghost ktv-filter-btn" data-filter="available">Available</button>
        <button type="button" class="btn btn--ghost ktv-filter-btn" data-filter="in_use">In Use</button>
      </div>
    </div>

  </div>

  <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(310px, 1fr)); gap:14px; margin-top:14px;" id="ktvGrid">
    <?php foreach ($tables_ktv as $t): ?>
      <?php
        $tid = (int)$t['id'];
        $status = (string)$t['status'];
        $active = $activeByTable[$tid] ?? null;
        $isAvailable = $status === 'available';

        $hasRes = isset($nextResByTable[$tid]);
        $maxH = $maxHoursByTable[$tid] ?? 99;
        $resInfo = $hasRes ? $nextResByTable[$tid] : null;
        $resTimeFmt = $hasRes ? date('h:i A', strtotime($resInfo['start_time'])) : '';

        $maxExtH = 99;
        if ($active && $hasRes && !empty($active['scheduled_end_time'])) {
            $resStartTs = strtotime(date('Y-m-d') . ' ' . $resInfo['start_time']);
            $schedEndTs = strtotime($active['scheduled_end_time']);
            $diffH = ($resStartTs - $schedEndTs) / 3600;
            $maxExtH = max(0, round($diffH * 2) / 2);
        }
      ?>
      <div class="card table-card" data-table-name="<?php echo h(strtolower($t['table_number'])); ?>" data-status="<?php echo h($status); ?>"
        style="border-left: 4px solid <?php echo $isAvailable ? '#c084fc' : '#c084fc'; ?>; position:relative; overflow:visible;">

        <?php if (!$isAvailable): ?>
          <div style="position:absolute; top:0; right:0; width:80px; height:80px; background:radial-gradient(circle at top right, rgba(168,85,247,0.1), transparent 70%); pointer-events:none;"></div>
        <?php endif; ?>

        <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
          <div style="font-size:18px; font-weight:700; color:var(--text);">
            <?php echo h($t['table_number']); ?>
            <span class="badge" style="margin-left:6px; font-size:11px; background:rgba(168, 85, 247, 0.2); color:#c084fc;">KTV</span>
          </div>
          <div class="spacer"></div>
          <?php if ($isAvailable): ?>
            <span class="badge badge--ok">Available</span>
          <?php else: ?>
            <span class="badge badge--warn">In Use</span>
          <?php endif; ?>
        </div>

        <?php if ($active): ?>
          <?php if ($hasRes): ?>
            <div style="padding:6px 10px; margin-bottom:10px; background:linear-gradient(90deg, rgba(56,189,248,0.08), rgba(56,189,248,0.02)); border-radius:6px; border:1px solid rgba(56,189,248,0.2);">
              <span style="color:#38bdf8; font-size:12px; font-weight:700;">📅 Reserved at <?php echo $resTimeFmt; ?> (Up Next)</span>
              <span style="display:block; color:var(--muted); font-size:11px; margin-top:2px;">
                <?php echo h($resInfo['customer_name']); ?> · <?php echo $resInfo['duration_hours']; ?>hr
                <?php if ((float)$resInfo['down_payment'] > 0): ?>
                  · DP: ₱<?php echo number_format((float)$resInfo['down_payment'], 2); ?>
                <?php endif; ?>
              </span>
            </div>
          <?php endif; ?>
          <div style="background:var(--surface2); border-radius:8px; padding:12px; margin-bottom:12px;">
            <?php
              $customerId = !empty($active['customer_id']) ? (int)$active['customer_id'] : null;
              $customerName = $customerId !== null ? ($customerNameById[$customerId] ?? ('Customer #' . $customerId)) : 'Walk-in';
            ?>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
              <span style="font-size:12px; color:var(--muted); text-transform:uppercase;">Time Remaining</span>
              <?php if (!empty($active['scheduled_end_time'])): ?>
                <span class="badge badge--warn" 
                  data-countdown="<?php echo h($active['scheduled_end_time']); ?>"
                  data-session-id="<?php echo $active['id']; ?>"
                  data-table-name="<?php echo h($t['table_number']); ?>"
                  data-player-name="<?php echo h($customerName); ?>"
                  data-rate="<?php echo (float)$active['rate_per_hour']; ?>"
                  style="font-size:14px; font-weight:700; background:rgba(168, 85, 247, 0.15); color:#d8b4fe;"
                >--:--:--</span>
              <?php else: ?>
                <span class="badge" data-timer-start="<?php echo h($active['start_time']); ?>">00:00:00</span>
              <?php endif; ?>
            </div>
            
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px; font-size:13px;">
              <div><span style="color:var(--muted);">Hours:</span> <strong><?php echo h(($active['hours_purchased'] ?? 0) . 'h'); ?></strong></div>
              <div><span style="color:var(--muted);">Paid:</span> <strong>₱<?php echo number_format((float)($active['total_amount'] ?? 0), 2); ?></strong></div>
              <div style="grid-column:span 2;">
                <strong><?php echo h($customerName); ?></strong>
              </div>
              <div style="grid-column:span 2; display:flex; justify-content:space-between; margin-top:4px; padding-top:6px; border-top:1px solid var(--border2);">
                <div><span style="color:var(--muted); font-size:11px; text-transform:uppercase;">Start Time</span><br><strong><?php echo date('h:i A', strtotime($active['start_time'])); ?></strong></div>
                <div style="text-align:right;"><span style="color:var(--muted); font-size:11px; text-transform:uppercase;">Scheduled End</span><br><strong><?php echo !empty($active['scheduled_end_time']) ? date('h:i A', strtotime($active['scheduled_end_time'])) : '--:-- --'; ?></strong></div>
              </div>
            </div>
          </div>

          <div style="display:flex; gap:8px;">
            <button class="btn" style="background:#c084fc; color:#fff; border:none; flex:1;" type="button" onclick="openExtendModal(<?php echo (int)$active['id']; ?>, '<?php echo h($t['table_number']); ?>', <?php echo (float)$active['rate_per_hour']; ?>, '<?php echo h($active['scheduled_end_time'] ?? ''); ?>', <?php echo $maxExtH; ?>)">Extend</button>
            <button class="btn btn--ghost" type="button" style="flex:1;" onclick="openEndModal(<?php echo (int)$active['id']; ?>, '<?php echo h($t['table_number']); ?>')">End Session</button>
          </div>
        <?php else: ?>
          <?php if ($hasRes): ?>
            <div style="padding:6px 10px; margin-bottom:10px; background:linear-gradient(90deg, rgba(56,189,248,0.08), rgba(56,189,248,0.02)); border-radius:6px; border:1px solid rgba(56,189,248,0.2);">
              <span style="color:#38bdf8; font-size:12px; font-weight:700;">📅 Reserved at <?php echo $resTimeFmt; ?></span>
              <span style="display:block; color:var(--muted); font-size:11px; margin-top:2px;">
                <?php echo h($resInfo['customer_name']); ?> · <?php echo $resInfo['duration_hours']; ?>hr
                <?php if ((float)$resInfo['down_payment'] > 0): ?>
                  · DP: ₱<?php echo number_format((float)$resInfo['down_payment'], 2); ?>
                <?php endif; ?>
              </span>
            </div>
          <?php endif; ?>

          <div style="text-align:center; padding:<?php echo $hasRes ? '8px' : '16px'; ?> 0 8px;">
            <div style="color:var(--muted); font-size:13px; margin-bottom:8px;">₱<?php echo number_format((float)$t['rate_per_hour'], 2); ?>/hr</div>
            
            <?php if ($hasRes && $maxH <= 0): ?>
              <a class="btn" style="background:#a855f7; color:white; border:none; width:100%; text-decoration:none; text-align:center;" href="vip_tables.php?start_reservation=<?php echo (int)$resInfo['id']; ?>">▶️ Start Reservation</a>
            <?php elseif ($hasRes && $maxH > 0): ?>
              <div style="color:#f59e0b; font-size:11px; margin-bottom:6px; font-weight:600;">
                ⚠️ Max <?php echo $maxH; ?>hr allowed (reserved at <?php echo $resTimeFmt; ?>)
              </div>
              <button class="btn" type="button" style="background:#a855f7; color:white; border:none; width:100%;" onclick="openStartModal(<?php echo $tid; ?>, '<?php echo h($t['table_number']); ?>', <?php echo (float)$t['rate_per_hour']; ?>, <?php echo $maxH; ?>)">Start Session</button>
            <?php else: ?>
              <button class="btn" type="button" style="background:#a855f7; color:white; border:none; width:100%;" onclick="openStartModal(<?php echo $tid; ?>, '<?php echo h($t['table_number']); ?>', <?php echo (float)$t['rate_per_hour']; ?>, 0)">Start Session</button>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
          <details style="margin-top:10px; border-top:1px solid var(--border); padding-top:10px;">
            <summary class="btn btn--ghost" style="font-size:12px; width:100%; text-align:center;">⚙️ Admin Settings</summary>
            <div style="margin-top:10px;">
              <form method="post" class="form" style="margin-bottom:10px;">
                <input type="hidden" name="action" value="edit_ktv_table">
                <input type="hidden" name="id" value="<?php echo $tid; ?>">
                <div class="field">
                  <div class="label" style="font-size:12px;">Room name</div>
                  <input name="table_number" value="<?php echo h($t['table_number']); ?>" required style="font-size:13px;">
                </div>
                <div class="field">
                  <div class="label" style="font-size:12px;">Rate/hr (₱)</div>
                  <input name="rate_per_hour" type="number" step="0.01" min="0" value="<?php echo h((string)$t['rate_per_hour']); ?>" required style="font-size:13px;">
                </div>
                <button class="btn btn--block" style="background:#a855f7; color:white; border:none; font-size:12px;" type="submit">Save</button>
              </form>
              <form method="post" onsubmit="return confirm('Delete this KTV room?');">
                <input type="hidden" name="action" value="delete_ktv_table">
                <input type="hidden" name="id" value="<?php echo $tid; ?>">
                <button class="btn btn--danger btn--block" type="submit" style="font-size:12px;">Delete Room</button>
              </form>
            </div>
          </details>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
</div>

<?php render_footer(); ?>

<!-- Start Game Modal -->
<div id="startModal" class="game-modal" style="display:none;">
  <div class="game-modal__box">
    <div class="game-modal__header">
      <h3>👑 Start VIP Game — <span id="startTableName"></span></h3>
      <span class="game-modal__close" onclick="closeStartModal()">&times;</span>
    </div>
    <div class="game-modal__body">
      <div class="game-modal__row">
        <div class="game-modal__field" style="flex:2;">
          <label>Customer</label>
          <select id="startCustomer">
            <option value="">Walk-in</option>
            <?php foreach ($customers as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>"><?php echo h($c['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="game-modal__field" style="flex:1;">
          <label>New Customer</label>
          <div class="row" style="gap:6px;">
            <input type="text" id="newCustName" placeholder="Name" style="flex:1;">
            <button type="button" class="btn" onclick="registerCustomer('startCustomer')" style="padding:6px 12px; font-size:12px; white-space:nowrap;">+ Add</button>
          </div>
        </div>
      </div>

      <label style="display:block; margin:14px 0 6px; color:var(--muted); font-size:12px; text-transform:uppercase;">Select Hours</label>
      <div class="hour-buttons" id="hourButtons">
        <button type="button" class="hour-btn" data-hours="0.5">30 min</button>
        <button type="button" class="hour-btn" data-hours="1">1 hr</button>
        <button type="button" class="hour-btn" data-hours="2">2 hrs</button>
        <button type="button" class="hour-btn" data-hours="3">3 hrs</button>
        <button type="button" class="hour-btn" data-hours="4">4 hrs</button>
        <button type="button" class="hour-btn" data-hours="5">5 hrs</button>
      </div>

      <div id="karaokeOptionWrapper">
        <label style="display:block; margin:14px 0 6px; color:var(--muted); font-size:12px; text-transform:uppercase;">Options</label>
        <div style="display:flex; gap:10px; margin-bottom:14px;" id="karaokeOption">
          <label style="flex:1; border:1px solid var(--border); padding:10px; border-radius:8px; display:flex; align-items:center; justify-content:center; gap:8px; cursor:pointer; background:var(--surface2);">
            <input type="radio" name="temp_karaoke" value="0" checked onchange="document.getElementById('sf_karaoke').value='0'">
            <span style="font-size:13px; font-weight:600;">Without Karaoke</span>
          </label>
          <label style="flex:1; border:1px solid var(--border); padding:10px; border-radius:8px; display:flex; align-items:center; justify-content:center; gap:8px; cursor:pointer; background:var(--surface2);">
            <input type="radio" name="temp_karaoke" value="1" onchange="document.getElementById('sf_karaoke').value='1'">
            <span style="font-size:13px; font-weight:600; color:#38bdf8;">🎤 With Karaoke</span>
          </label>
        </div>
      </div>

      <div class="game-modal__summary">
        <div class="game-modal__row">
          <div class="game-modal__field"><label>Rate</label><div id="startRate" class="val">₱0.00/hr</div></div>
          <div class="game-modal__field"><label>Hours</label><div id="startHours" class="val">0</div></div>
          <div class="game-modal__field"><label>Total</label><div id="startTotal" class="val total">₱0.00</div></div>
        </div>
      </div>

      <div class="game-modal__row" style="margin-top:14px;">
        <div class="game-modal__field">
          <label>Payment (₱)</label>
          <input type="number" id="startPayment" step="0.01" min="0" placeholder="Cash received">
        </div>
        <div class="game-modal__field">
          <label>Change</label>
          <div id="startChange" class="val" style="font-weight:700; color:var(--success);">₱0.00</div>
        </div>
      </div>

      <div class="game-modal__footer">
        <button type="button" class="btn btn--ghost" onclick="closeStartModal()">Cancel</button>
        <button type="button" class="btn btn--primary" onclick="submitStart()">Confirm & Start</button>
      </div>
    </div>
  </div>
</div>

<!-- Extend Game Modal -->
<div id="extendModal" class="game-modal" style="display:none;">
  <div class="game-modal__box">
    <div class="game-modal__header">
      <h3>⏱️ Extend VIP Time — <span id="extendTableName"></span></h3>
      <span class="game-modal__close" onclick="closeExtendModal()">&times;</span>
    </div>
    <div class="game-modal__body">
      <div id="extendRemaining" style="text-align:center; margin-bottom:14px; font-size:14px; color:var(--muted);">
        Time remaining: <strong id="extendTimeLeft">--:--:--</strong>
      </div>

      <label style="display:block; margin:0 0 6px; color:var(--muted); font-size:12px; text-transform:uppercase;">Add Hours</label>
      <div class="hour-buttons" id="extendHourButtons">
        <button type="button" class="hour-btn" data-hours="0.5">30 min</button>
        <button type="button" class="hour-btn" data-hours="1">1 hr</button>
        <button type="button" class="hour-btn" data-hours="2">2 hrs</button>
        <button type="button" class="hour-btn" data-hours="3">3 hrs</button>
      </div>

      <div class="game-modal__summary">
        <div class="game-modal__row">
          <div class="game-modal__field"><label>Rate</label><div id="extendRate" class="val">₱0.00/hr</div></div>
          <div class="game-modal__field"><label>Extension Cost</label><div id="extendCost" class="val total">₱0.00</div></div>
        </div>
      </div>

      <div class="game-modal__row" style="margin-top:14px;">
        <div class="game-modal__field">
          <label>Payment (₱)</label>
          <input type="number" id="extendPayment" step="0.01" min="0" placeholder="Cash received">
        </div>
        <div class="game-modal__field">
          <label>Change</label>
          <div id="extendChange" class="val" style="font-weight:700; color:var(--success);">₱0.00</div>
        </div>
      </div>

      <div class="game-modal__footer">
        <button type="button" class="btn btn--ghost" onclick="closeExtendModal()">Cancel</button>
        <button type="button" class="btn btn--primary" onclick="submitExtend()">Confirm Extension</button>
      </div>
    </div>
  </div>
</div>

<!-- Hidden forms -->
<form id="startForm" method="post" style="display:none;">
  <input type="hidden" name="action" value="start_game">
  <input type="hidden" name="table_id" id="sf_table_id">
  <input type="hidden" name="customer_id" id="sf_customer_id">

  <input type="hidden" name="hours" id="sf_hours">
  <input type="hidden" name="payment" id="sf_payment">
  <input type="hidden" name="karaoke_included" id="sf_karaoke" value="0">
  <input type="hidden" name="reservation_id" id="sf_reservation_id" value="0">
</form>
<form id="extendForm" method="post" style="display:none;">
  <input type="hidden" name="action" value="extend_game">
  <input type="hidden" name="session_id" id="ef_session_id">
  <input type="hidden" name="hours" id="ef_hours">
  <input type="hidden" name="payment" id="ef_payment">
</form>
<form id="endForm" method="post" style="display:none;">
  <input type="hidden" name="action" value="end_game">
  <input type="hidden" name="session_id" id="endf_session_id">
</form>

<!-- End Game Modal -->
<div id="endModal" class="game-modal" style="display:none;">
  <div class="game-modal__box" style="max-width:400px;">
    <div class="game-modal__header">
      <h3>🛑 End VIP Game — <span id="endTableName"></span></h3>
      <span class="game-modal__close" onclick="closeEndModal()">&times;</span>
    </div>
    <div class="game-modal__body" style="text-align:center; padding:28px 24px;">
      <div style="width:56px; height:56px; margin:0 auto 16px; border-radius:50%; background:rgba(239,68,68,0.12); display:flex; align-items:center; justify-content:center; font-size:28px;">👑</div>
      <p style="color:var(--text); font-size:15px; margin:0 0 8px;">Are you sure you want to <strong>end this VIP game</strong> and free the table?</p>
      <p style="color:var(--muted); font-size:13px; margin:0;">Payment has already been collected upfront.</p>
      <div class="game-modal__footer" style="justify-content:center; margin-top:24px;">
        <button type="button" class="btn btn--ghost" onclick="closeEndModal()">Cancel</button>
        <button type="button" class="btn btn--danger" onclick="submitEnd()">End Game</button>
      </div>
    </div>
  </div>
</div>

<style>
.game-modal { position:fixed; inset:0; z-index:1000; background:rgba(0,0,0,0.35); display:flex; align-items:center; justify-content:center; }
.game-modal__box { background:#fff; border:1px solid var(--border); border-radius:14px; width:95%; max-width:520px; box-shadow:0 8px 32px rgba(0,0,0,.1); animation:modalIn 0.2s ease-out; }
.game-modal__header { display:flex; justify-content:space-between; align-items:center; padding:18px 20px; border-bottom:1px solid var(--border); }
.game-modal__header h3 { margin:0; font-size:16px; color:var(--text); }
.game-modal__close { color:var(--muted); font-size:24px; cursor:pointer; line-height:1; }
.game-modal__close:hover { color:var(--text); }
.game-modal__body { padding:20px; }
.game-modal__row { display:flex; gap:12px; }
.game-modal__field { flex:1; }
.game-modal__field label { display:block; font-size:12px; color:var(--muted); margin-bottom:4px; text-transform:uppercase; }
.game-modal__field .val { font-weight:600; font-size:16px; color:var(--text); padding:6px 0; }
.game-modal__field .val.total { font-size:20px; color:var(--primary); }
.game-modal__summary { background:var(--bg-secondary, var(--surface2)); padding:14px; border-radius:8px; margin-top:14px; }
.game-modal__footer { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; padding-top:14px; border-top:1px solid var(--border); }

.hour-buttons { display:flex; gap:8px; flex-wrap:wrap; }
.hour-btn { padding:8px 16px; border:1px solid var(--border); background:transparent; color:var(--text); border-radius:6px; cursor:pointer; font-size:14px; transition:all 0.15s; }
.hour-btn:hover { border-color:var(--primary); color:var(--primary); }
.hour-btn.selected { background:var(--primary); color:#fff; border-color:var(--primary); }

@keyframes modalIn { from { opacity:0; transform:scale(0.92) translateY(10px); } to { opacity:1; transform:scale(1) translateY(0); } }
</style>

<script>
const busyCustomers = <?php echo json_encode($busyCustomerIds); ?>;

  <?php if (!empty($prefillReservation)): ?>
  // Auto-open Start Game for Reservation
  document.addEventListener('DOMContentLoaded', () => {
    const r = <?php echo json_encode($prefillReservation); ?>;
    const rate = parseFloat(r.rate_per_hour);
    const maxH = parseFloat(r.duration_hours);
    const isKtv = (r.table_type === 'ktv');
    
    // Set hidden reservation ID
    document.getElementById('sf_reservation_id').value = r.id;

    // Use openStartModal
    openStartModal(r.table_id, r.table_number, rate, 99, isKtv); // 99 means no max hours restriction
    
    // Pre-fill walk-in name
    const nInput = document.getElementById('newCustName');
    if (nInput) {
        nInput.value = r.customer_name;
        nInput.disabled = true; // customer name is fixed for reservation
    }
    
    // Disable customer select
    const sel = document.getElementById('startCustomer');
    if (sel) {
        sel.disabled = true;
    }

    // Show reservation badge
    const resWarning = document.getElementById('resWarning');
    if (resWarning) {
        resWarning.style.display = 'block';
        const dp = parseFloat(r.down_payment) || 0;
        resWarning.querySelector('span').textContent = '📅 Reservation active. Down Payment: ₱' + dp.toFixed(2) + ' (will be deducted)';
        resWarning.querySelector('span').style.color = '#fff';
        resWarning.style.background = 'rgba(34, 197, 94, 0.2)';
        resWarning.style.border = '1px solid rgba(34, 197, 94, 0.4)';
    }

    // Pre-select hours
    startHours = maxH;
    document.getElementById('startHours').textContent = startHours + 'h';
    
    // Hide hour buttons that don't match exactly
    document.querySelectorAll('#hourButtons .hour-btn').forEach(b => {
      if (parseFloat(b.dataset.hours) === startHours) {
        b.classList.add('selected');
        b.disabled = false;
        b.style.pointerEvents = 'none'; // Lock selection
      } else {
        b.style.display = 'none';
      }
    });

    // Compute total & require payment minus down payment
    const total = startRate * startHours;
    document.getElementById('startTotal').textContent = '₱' + total.toFixed(2);
    
    const dpVal = parseFloat(r.down_payment) || 0;
    const req = Math.max(0, total - dpVal);
    const startPayment = document.getElementById('startPayment');
    startPayment.placeholder = 'Amount to pay: ₱' + req.toFixed(2);
    
    // Override calculate change logic to factor down payment
    startPayment.removeEventListener('input', updateStartChange);
    const resUpdateChange = function() {
      const pay = parseFloat(document.getElementById('startPayment').value) || 0;
      const t = startRate * startHours;
      const requiredPay = Math.max(0, t - dpVal);
      const change = pay - requiredPay;
      const el = document.getElementById('startChange');
      el.textContent = '₱' + change.toFixed(2);
      el.style.color = change >= 0 ? 'var(--success)' : 'var(--danger)';
    };
    startPayment.addEventListener('input', resUpdateChange);
    resUpdateChange();
  });
  <?php endif; ?>


// ── Countdown Timers ──


document.querySelectorAll('[data-countdown]').forEach(el => {
  const endTime = new Date(el.dataset.countdown);
  function tick() {
    const now = new Date();
    let diff = Math.floor((endTime - now) / 1000);
      if (diff <= 0) {
        el.textContent = "TIME'S UP";
        el.className = 'badge badge--danger';
        return;
      }
    const h = Math.floor(diff / 3600);
    const m = Math.floor((diff % 3600) / 60);
    const s = diff % 60;
    el.textContent = String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    setTimeout(tick, 1000);
  }
  tick();
});

// ── Start Game Modal ──
let startTableId = 0, startRate = 0, startHours = 0;

function openStartModal(tableId, tableName, rate, maxHours, isKtv = false) {
  startTableId = tableId; startRate = rate; startHours = 0;
  document.getElementById('startTableName').textContent = tableName;
  document.getElementById('startRate').textContent = '₱' + rate.toFixed(2) + '/hr';
  document.getElementById('startHours').textContent = '0';
  document.getElementById('startTotal').textContent = '₱0.00';
  document.getElementById('startPayment').value = '';
  document.getElementById('startChange').textContent = '₱0.00';
  document.getElementById('startCustomer').value = '';
  document.getElementById('newCustName').value = '';

  // Dynamic time blocking: disable hour buttons exceeding maxHours
  const resWarning = document.getElementById('resWarning');
  if (resWarning) {
      if (maxHours > 0 && maxHours < 99) {
        resWarning.style.display = 'block';
        resWarning.querySelector('span').textContent = 'Max ' + maxHours + 'hr – table has an upcoming reservation';
      } else {
        resWarning.style.display = 'none';
      }
  }

  document.querySelectorAll('#hourButtons .hour-btn').forEach(b => {
    b.classList.remove('selected');
    const h = parseFloat(b.dataset.hours);
    if (maxHours > 0 && maxHours < 99 && h > maxHours) {
      b.disabled = true;
      b.style.opacity = '0.3';
      b.style.pointerEvents = 'none';
    } else {
      b.disabled = false;
      b.style.opacity = '1';
      b.style.pointerEvents = '';
    }
  });


  const btn30 = document.querySelector('#hourButtons .hour-btn[data-hours="0.5"]');
  if (isKtv) {
    document.getElementById('karaokeOptionWrapper').style.display = 'none';
    document.getElementById('sf_karaoke').value = '1';
    if (btn30) btn30.style.display = 'none'; // Lock out 30m for KTV start
  } else {
    document.getElementById('karaokeOptionWrapper').style.display = 'block';
    document.getElementById('sf_karaoke').value = '0';
    document.querySelector('input[name="temp_karaoke"][value="0"]').checked = true;
    if (btn30) btn30.style.display = 'inline-block';
  }

  document.querySelectorAll('#hourButtons .hour-btn').forEach(b => b.classList.remove('selected'));
  document.getElementById('startModal').style.display = 'flex';
}
function closeStartModal() { document.getElementById('startModal').style.display = 'none'; }

document.querySelectorAll('#hourButtons .hour-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#hourButtons .hour-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    startHours = parseFloat(btn.dataset.hours);
    document.getElementById('startHours').textContent = startHours + 'h';
    const total = startRate * startHours;
    document.getElementById('startTotal').textContent = '₱' + total.toFixed(2);
    updateStartChange();
  });
});

document.getElementById('startPayment').addEventListener('input', updateStartChange);
function updateStartChange() {
  const pay = parseFloat(document.getElementById('startPayment').value) || 0;
  const total = startRate * startHours;
  const change = pay - total;
  const el = document.getElementById('startChange');
  el.textContent = '₱' + change.toFixed(2);
  el.style.color = change >= 0 ? 'var(--success)' : 'var(--danger)';
}

function submitStart() {
  const custId = parseInt(document.getElementById('startCustomer').value);
  if (custId && busyCustomers.includes(custId)) {
    showWarnModal('👀 Customer Busy', 'This customer is already playing at another table.');
    return;
  }

  if (startHours <= 0) { showWarnModal('⏰ Select Hours', 'Please select how many hours before starting the game.'); return; }
  const total = startRate * startHours;
  const pay = parseFloat(document.getElementById('startPayment').value) || 0;
  if (pay < total - 0.01) { showWarnModal('💰 Insufficient Payment', 'Payment is not enough. Required: ₱' + total.toFixed(2)); return; }

  document.getElementById('sf_table_id').value = startTableId;
  document.getElementById('sf_customer_id').value = document.getElementById('startCustomer').value;

  document.getElementById('sf_hours').value = startHours;
  document.getElementById('sf_payment').value = pay;
  document.getElementById('startForm').submit();
}

// ── Extend Game Modal ──
let extendSessionId = 0, extendRate = 0, extendHours = 0, extendInterval = null;

function openExtendModal(sessionId, tableName, rate, scheduledEnd, maxExtH = 99) {
  extendSessionId = sessionId; extendRate = rate; extendHours = 0;
  document.getElementById('extendTableName').textContent = tableName;
  document.getElementById('extendRate').textContent = '₱' + rate.toFixed(2) + '/hr';
  document.getElementById('extendCost').textContent = '₱0.00';
  document.getElementById('extendPayment').value = '';
  document.getElementById('extendChange').textContent = '₱0.00';

  document.querySelectorAll('#extendHourButtons .hour-btn').forEach(b => {
    b.classList.remove('selected');
    const h = parseFloat(b.dataset.hours);
    if (maxExtH > 0 && maxExtH < 99 && h > maxExtH) {
      b.disabled = true;
      b.style.opacity = '0.3';
      b.style.pointerEvents = 'none';
    } else {
      b.disabled = false;
      b.style.opacity = '1';
      b.style.pointerEvents = '';
    }
  });

  if (scheduledEnd) {
    const end = new Date(scheduledEnd);
    function tickExtend() {
      const diff = Math.max(0, Math.floor((end - new Date()) / 1000));
      const h = Math.floor(diff / 3600), m = Math.floor((diff % 3600) / 60), s = diff % 60;
      document.getElementById('extendTimeLeft').textContent =
        diff <= 0 ? "TIME'S UP" : String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    }
    tickExtend();
    extendInterval = setInterval(tickExtend, 1000);
  }
  document.getElementById('extendModal').style.display = 'flex';
}
function closeExtendModal() {
  document.getElementById('extendModal').style.display = 'none';
  if (extendInterval) { clearInterval(extendInterval); extendInterval = null; }
}

document.querySelectorAll('#extendHourButtons .hour-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#extendHourButtons .hour-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    extendHours = parseFloat(btn.dataset.hours);
    const cost = extendRate * extendHours;
    document.getElementById('extendCost').textContent = '₱' + cost.toFixed(2);
    updateExtendChange();
  });
});

document.getElementById('extendPayment').addEventListener('input', updateExtendChange);
function updateExtendChange() {
  const pay = parseFloat(document.getElementById('extendPayment').value) || 0;
  const cost = extendRate * extendHours;
  const change = pay - cost;
  const el = document.getElementById('extendChange');
  el.textContent = '₱' + change.toFixed(2);
  el.style.color = change >= 0 ? 'var(--success)' : 'var(--danger)';
}

function submitExtend() {
  if (extendHours <= 0) { showWarnModal('⏰ Select Hours', 'Please select how many hours to extend.'); return; }
  const cost = extendRate * extendHours;
  const pay = parseFloat(document.getElementById('extendPayment').value) || 0;
  if (pay < cost - 0.01) { showWarnModal('💰 Insufficient Payment', 'Payment is not enough. Required: ₱' + cost.toFixed(2)); return; }

  document.getElementById('ef_session_id').value = extendSessionId;
  document.getElementById('ef_hours').value = extendHours;
  document.getElementById('ef_payment').value = pay;
  document.getElementById('extendForm').submit();
}

// ── End Game Modal ──
let endSessionId = 0;
function openEndModal(sessionId, tableName) {
  endSessionId = sessionId;
  document.getElementById('endTableName').textContent = tableName;
  document.getElementById('endModal').style.display = 'flex';
}
function closeEndModal() { document.getElementById('endModal').style.display = 'none'; }
function submitEnd() {
  document.getElementById('endf_session_id').value = endSessionId;
  document.getElementById('endForm').submit();
}

// Close modals on backdrop click
['startModal','extendModal','endModal'].forEach(id => {
  document.getElementById(id).addEventListener('click', e => {
    if (e.target.id === id) {
      if (id === 'startModal') closeStartModal();
      else if (id === 'extendModal') closeExtendModal();
      else closeEndModal();
    }
  });
});

// ── Register Customer via AJAX ──
function registerCustomer(selectId) {
  const nameInput = document.getElementById('newCustName');
  const name = nameInput.value.trim();
  if (!name) { alert('Please enter a customer name.'); nameInput.focus(); return; }

  const btn = event.target;
  btn.disabled = true;
  btn.textContent = '...';

  fetch('api_customers.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'action=add_customer&name=' + encodeURIComponent(name)
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false;
    btn.textContent = '+ Add';
    if (data.error) {
      if (data.error === 'duplicate') {
        showDuplicateModal(data.existing_name);
      } else {
        alert(data.error);
      }
      return;
    }
    const c = data.customer;
    const select = document.getElementById(selectId);
    const opt = document.createElement('option');
    opt.value = c.id;
    opt.textContent = c.name;
    select.appendChild(opt);
    select.value = c.id;
    nameInput.value = '';
    nameInput.placeholder = '✓ ' + c.name + ' added!';
    setTimeout(() => { nameInput.placeholder = 'Name'; }, 2000);
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = '+ Add';
    alert('Failed to register. Check connection.');
  });
}

// ── Warning Modal ──
function showWarnModal(title, msg) {
  document.getElementById('warnTitle').textContent = title;
  document.getElementById('warnMsg').textContent = msg;
  document.getElementById('warnModal').style.display = 'flex';
}
function closeWarnModal() { document.getElementById('warnModal').style.display = 'none'; }

// ── Duplicate Customer Modal ──
function showDuplicateModal(existingName) {
  document.getElementById('dupName').textContent = existingName;
  document.getElementById('dupModal').style.display = 'flex';
}
function closeDupModal() { document.getElementById('dupModal').style.display = 'none'; }

// Force reload on back navigation
window.onpageshow = function(event) { if (event.persisted) window.location.reload(); };
</script>

<!-- Warning Modal -->
<div id="warnModal" class="game-modal" style="display:none; z-index: 100000;" onclick="if(event.target.id==='warnModal')closeWarnModal()">
  <div class="game-modal__box" style="max-width:380px;">
    <div class="game-modal__header">
      <h3 id="warnTitle">Warning</h3>
      <span class="game-modal__close" onclick="closeWarnModal()">&times;</span>
    </div>
    <div class="game-modal__body" style="text-align:center; padding:28px 24px;">
      <div style="width:56px; height:56px; margin:0 auto 16px; border-radius:50%; background:rgba(245,158,11,0.12); display:flex; align-items:center; justify-content:center; font-size:28px;">⚠️</div>
      <p id="warnMsg" style="color:var(--text); font-size:15px; margin:0;"></p>
      <div style="margin-top:20px;">
        <button type="button" class="btn btn--primary" onclick="closeWarnModal()">OK</button>
      </div>
    </div>
  </div>
</div>

<!-- Duplicate Name Modal -->
<div id="dupModal" class="game-modal" style="display:none; z-index: 100000;" onclick="if(event.target.id==='dupModal')closeDupModal()">
  <div class="game-modal__box" style="max-width:400px;">
    <div class="game-modal__header">
      <h3>⚠️ Duplicate Name</h3>
      <span class="game-modal__close" onclick="closeDupModal()">&times;</span>
    </div>
    <div class="game-modal__body" style="text-align:center; padding:28px 24px;">
      <div style="width:56px; height:56px; margin:0 auto 16px; border-radius:50%; background:rgba(245,158,11,0.12); display:flex; align-items:center; justify-content:center; font-size:28px;">👤</div>
      <p style="color:var(--text); font-size:15px; margin:0 0 6px;">A customer named</p>
      <p style="color:var(--primary); font-size:18px; font-weight:700; margin:0 0 6px;">"<span id="dupName"></span>"</p>
      <p style="color:var(--text); font-size:15px; margin:0 0 4px;">already exists.</p>
      <p style="color:var(--muted); font-size:13px; margin:0;">Please select them from the dropdown instead.</p>
      <div style="margin-top:20px;">
        <button type="button" class="btn btn--primary" onclick="closeDupModal()">Got it</button>
      </div>
    </div>
  </div>
</div>



