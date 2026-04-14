<?php
require '../config/database.php';
try {
  $pdo = db();
  $pdo->exec("TRUNCATE TABLE kubo_rentals;");
  $pdo->exec("ALTER TABLE kubo_rentals ADD COLUMN kubo_number INT NOT NULL AFTER id;");
  $pdo->exec("ALTER TABLE kubo_rentals ADD COLUMN status ENUM('active','completed') NOT NULL DEFAULT 'active' AFTER rental_date;");
  $pdo->exec("ALTER TABLE kubo_rentals ADD COLUMN end_time DATETIME NULL AFTER status;");
  echo "Table altered successfully.\n";
} catch (Exception $e) {
  echo "Error: " . $e->getMessage() . "\n";
}
