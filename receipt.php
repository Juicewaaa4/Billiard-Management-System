<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/util.php';

start_app_session();
require_role(['admin', 'cashier']);


$sessionId = (int)($_GET['session_id'] ?? 0);
$preview = !empty($_GET['preview']);
if ($sessionId <= 0) {
  http_response_code(400);
  echo 'Invalid session.';
  exit;
}

$stmt = db()->prepare("
  SELECT
    gs.*,
    t.table_number,
    t.type,
    c.name AS customer_name,
    c.contact AS customer_contact,
    tx.payment,
    tx.change_amount,
    tx.paid_at
  FROM game_sessions gs
  JOIN tables t ON t.id = gs.table_id
  LEFT JOIN customers c ON c.id = gs.customer_id
  LEFT JOIN transactions tx ON tx.session_id = gs.id
  WHERE gs.id = ?
  LIMIT 1
");
$stmt->execute([$sessionId]);
$r = $stmt->fetch();
if (!$r) {
  http_response_code(404);
  echo 'Not found.';
  exit;
}

$dur = (int)($r['duration_seconds'] ?? 0);
$estimatedTotal = null;
$estimatedGamesEarned = null;
if ($r['end_time'] === null && $preview) {
  // Compute live preview using MySQL NOW() to match how End Game saves.
  $diffStmt = db()->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, NOW()) AS diff_seconds");
  $diffStmt->execute([(string)$r['start_time']]);
  $diffSeconds = (int)($diffStmt->fetch()['diff_seconds'] ?? 0);
  $diffSeconds += (int)($r['billing_bonus_seconds'] ?? 0);

  if ($diffSeconds > 0 && $diffSeconds < 60) {
    $dur = 60;
  } else {
    $dur = max(0, $diffSeconds);
  }

  $estimatedTotal = $dur === 0
    ? 0.0
    : round(((float)$r['rate_per_hour']) * ($dur / 3600), 2);

  // tables.php always assigns games_earned = 1 on End Game.
  $estimatedGamesEarned = 1;
  
  // Update duration format for live preview
  $h = intdiv($dur, 3600);
  $m = intdiv($dur % 3600, 60);
  $s = $dur % 60;
  $durFmt = sprintf('%02d:%02d:%02d', $h, $m, $s);
}

// Only calculate duration format if not already calculated in preview
if (!($r['end_time'] === null && $preview)) {
  $h = intdiv($dur, 3600);
  $m = intdiv($dur % 3600, 60);
  $s = $dur % 60;
  $durFmt = sprintf('%02d:%02d:%02d', $h, $m, $s);
}

$cust = $r['customer_name'] ?: ($r['walk_in_name'] ?: 'Walk-in');
$isPaid = $r['payment'] !== null;
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php page_title('Receipt'); ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    /* Hide sidebar for receipt page */
    .sidebar { display: none !important; }
    .main { margin-left: 0 !important; }
    .topbar { display: none !important; }
    .content { padding: 20px !important; }
  </style>
</head>
<body>
  <div class="app">
    <main class="main">
      <section class="content">

<div class="card">
  <div class="row">
    <div>
      <div class="card__title">Receipt</div>
      <div style="margin-top:6px;color:var(--muted);">Session #<?php echo (int)$r['id']; ?><?php echo $preview ? ' (Preview)' : ''; ?></div>
    </div>
    <div class="spacer"></div>
    <?php
    // Determine which page to go back to based on table type
    $tableType = $r['type'] ?? 'regular';
    $backUrl = ($tableType === 'vip') ? 'vip_tables.php' : 'tables.php';
    ?>
    <a class="btn btn--ghost" href="<?php echo $backUrl; ?>">Back to Tables</a>
  </div>
</div>

<div class="card" style="margin-top:14px;">
  <div class="grid grid--cards">
    <div class="col-6">
      <div class="label">Table</div>
      <div style="font-weight:900; font-size:18px; margin-top:6px;"><?php echo h($r['table_number']); ?></div>
    </div>
    <div class="col-6">
      <div class="label">Customer</div>
      <div style="font-weight:900; font-size:18px; margin-top:6px;"><?php echo h($cust); ?></div>
      <?php if (!empty($r['customer_contact'])): ?>
        <div style="color:var(--muted); margin-top:6px;"><?php echo h($r['customer_contact']); ?></div>
      <?php endif; ?>
    </div>

    <div class="col-4">
      <div class="label">Start</div>
      <div style="margin-top:6px;"><?php echo h($r['start_time']); ?></div>
    </div>
    <div class="col-4">
      <div class="label">End</div>
      <div style="margin-top:6px;"><?php echo h((string)($r['end_time'] ?? ($preview ? '—' : '—'))); ?></div>
    </div>
    <div class="col-4">
      <div class="label">Duration</div>
      <div style="margin-top:6px;"><span class="badge"><?php echo h($durFmt); ?></span></div>
    </div>

    <div class="col-4">
      <div class="label">Rate / hour</div>
      <div style="margin-top:6px;"><?php echo money((float)$r['rate_per_hour']); ?></div>
    </div>
    <div class="col-4">
      <div class="label">Total</div>
      <div style="margin-top:6px; font-weight:900;">
        <?php
          $totalToShow = ($preview && $r['end_time'] === null)
            ? (float)($estimatedTotal ?? 0)
            : (float)($r['total_amount'] ?? 0);
          echo money($totalToShow);
        ?>
      </div>
    </div>

    <div class="col-4">
      <div class="label">Payment</div>
      <div style="margin-top:6px;"><?php echo $isPaid ? money((float)$r['payment']) : '—'; ?></div>
    </div>
    <div class="col-4">
      <div class="label">Change</div>
      <div style="margin-top:6px;"><?php echo $isPaid ? money((float)$r['change_amount']) : '—'; ?></div>
    </div>
    <div class="col-4">
      <div class="label">Games</div>
      <div style="margin-top:6px; color:var(--muted);">
        Earned: <strong><?php echo (int)(($preview && $r['end_time'] === null) ? ($estimatedGamesEarned ?? 0) : ($r['games_earned'] ?? 0)); ?></strong>
        &nbsp;|&nbsp; Redeemed: <strong><?php echo (int)($r['games_redeemed'] ?? 0); ?></strong>
      </div>
    </div>
  </div>

  <div class="alert" style="margin-top:14px;">
    <div class="label">Notes</div>
    <div style="color:var(--muted); margin-top:6px;">
      Receipt printing uses your browser print dialog.
    </div>
  </div>
</div>

      </section>
    </main>
  </div>

  <?php if ($preview && $r['end_time'] === null): ?>
  <script>
    // Update duration in real-time for preview
    function updatePreviewDuration() {
      const startTime = new Date('<?php echo h($r['start_time']); ?>');
      const now = new Date();
      const diffMs = now - startTime;
      const diffSeconds = Math.floor(diffMs / 1000);
      
      // Add billing bonus seconds if any
      const bonusSeconds = <?php echo (int)($r['billing_bonus_seconds'] ?? 0); ?>;
      const totalSeconds = diffSeconds + bonusSeconds;
      
      // Apply minimum 1 minute charge if played
      const chargeSeconds = totalSeconds > 0 ? Math.max(totalSeconds, 60) : 0;
      
      const hours = Math.floor(chargeSeconds / 3600);
      const minutes = Math.floor((chargeSeconds % 3600) / 60);
      const seconds = chargeSeconds % 60;
      
      const durationStr = String(hours).padStart(2, '0') + ':' + 
                          String(minutes).padStart(2, '0') + ':' + 
                          String(seconds).padStart(2, '0');
      
      // Update duration display
      const durationElement = document.querySelector('.badge');
      if (durationElement) {
        durationElement.textContent = durationStr;
      }
      
      // Update total amount
      const ratePerHour = <?php echo (float)$r['rate_per_hour']; ?>;
      const totalAmount = (ratePerHour * chargeSeconds) / 3600;
      const totalElements = document.querySelectorAll('[style*="font-weight:900"]');
      
      totalElements.forEach(el => {
        if (el.textContent.includes('₱')) {
          el.textContent = '₱' + totalAmount.toFixed(2);
        }
      });
    }
    
    // Update every second
    setInterval(updatePreviewDuration, 1000);
    updatePreviewDuration(); // Initial update
  </script>
  <?php endif; ?>
</body>
</html>

