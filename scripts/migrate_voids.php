<?php
require_once __DIR__ . '/../config/database.php';

try {
  $pdo = db();
  
  // Add is_voided
  $pdo->exec("ALTER TABLE game_sessions ADD COLUMN is_voided TINYINT(1) NOT NULL DEFAULT 0;");
  echo "Added is_voided column.\n";
  
  // Add void_reason
  $pdo->exec("ALTER TABLE game_sessions ADD COLUMN void_reason VARCHAR(255) NULL;");
  echo "Added void_reason column.\n";
  
  echo "Migration successful!\n";
} catch (Exception $e) {
  // Ignore duplicates
  if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
    echo "Columns already exist.\n";
  } else {
    echo "Error: " . $e->getMessage() . "\n";
  }
}
