<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

start_app_session();
require_role(['admin', 'cashier']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
  exit;
}

$sessionId = (int)($_POST['session_id'] ?? 0);
$voidReason = trim((string)($_POST['void_reason'] ?? ''));

if ($sessionId <= 0) {
  echo json_encode(['ok' => false, 'error' => 'Invalid session ID.']);
  exit;
}

if ($voidReason === '') {
  echo json_encode(['ok' => false, 'error' => 'Reason for voiding is required.']);
  exit;
}

try {
  $stmt = db()->prepare("
    SELECT gs.*, t.id AS t_id
    FROM game_sessions gs
    JOIN tables t ON t.id = gs.table_id
    WHERE gs.id = ? AND gs.end_time IS NULL
    LIMIT 1
  ");
  $stmt->execute([$sessionId]);
  $s = $stmt->fetch();

  if (!$s) {
    echo json_encode(['ok' => false, 'error' => 'Session not found or already ended.']);
    exit;
  }

  db()->beginTransaction();
  
  // Set end_time to NOW, mark voided, store reason (keep total_amount for audit)
  db()->prepare("
    UPDATE game_sessions 
    SET end_time = NOW(), 
        is_voided = 1, 
        void_reason = ?
    WHERE id = ?
  ")->execute([$voidReason, $sessionId]);
  
  // Free up the table
  db()->prepare("UPDATE tables SET status='available' WHERE id=?")->execute([(int)$s['table_id']]);
  
  // Free up reservation if any
  if (!empty($s['reservation_id'])) {
      // Technically if it's voided, maybe we should revert the reservation to pending or mark it void too?
      // Since they just clicked wrong table, let's mark the reservation as completed so it doesn't hang,
      // or we can just leave it. Usually, they'd want to use the reservation on the correct table.
      // So let's reset the reservation status to 'pending' so it can be used again!
      db()->prepare("UPDATE reservations SET status = 'pending' WHERE id = ?")->execute([$s['reservation_id']]);
  }

  db()->commit();

  echo json_encode(['ok' => true]);
} catch (Throwable $e) {
  if (db()->inTransaction()) {
    db()->rollBack();
  }
  echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
