<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

try {
  // Only check sessions that have reached exactly 0 or below, and are not yet manually ended.
  $stmt = db()->prepare("
    SELECT 
      gs.id AS session_id, 
      t.table_number, 
      t.type, 
      COALESCE(c.name, NULLIF(gs.walk_in_name, ''), 'Walk-in') AS player_name, 
      gs.scheduled_end_time, 
      gs.rate_per_hour
    FROM game_sessions gs
    JOIN tables t ON gs.table_id = t.id
    LEFT JOIN customers c ON c.id = gs.customer_id
    WHERE gs.end_time IS NULL AND gs.scheduled_end_time <= NOW()
  ");
  $stmt->execute();
  $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // 10-minute warning check
  $stmtWarn = db()->prepare("
    SELECT 
      gs.id AS session_id, 
      t.table_number, 
      t.type, 
      COALESCE(c.name, NULLIF(gs.walk_in_name, ''), 'Walk-in') AS player_name 
    FROM game_sessions gs
    JOIN tables t ON gs.table_id = t.id
    LEFT JOIN customers c ON c.id = gs.customer_id
    WHERE gs.end_time IS NULL 
      AND gs.scheduled_end_time > NOW() 
      AND gs.scheduled_end_time <= DATE_ADD(NOW(), INTERVAL 10 MINUTE)
  ");
  $stmtWarn->execute();
  $warnings = $stmtWarn->fetchAll(PDO::FETCH_ASSOC);
  
  echo json_encode(['status' => 'ok', 'expired' => $expired, 'warnings' => $warnings]);
} catch (Exception $e) {
  echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
