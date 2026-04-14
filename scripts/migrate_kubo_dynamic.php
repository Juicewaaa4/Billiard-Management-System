<?php
require '../config/database.php';
try {
  $pdo = db();
  
  // 1. Rename kubo_number to table_id
  $pdo->exec("ALTER TABLE kubo_rentals CHANGE kubo_number table_id INT NOT NULL;");

  // 2. Insert Kubo 1-8 into tables if they don't exist
  for ($i = 1; $i <= 8; $i++) {
    $stmt = $pdo->prepare("INSERT INTO tables (table_number, type, rate_per_hour, status) VALUES (?, 'kubo', 0, 'available')");
    $stmt->execute(["Kubo $i"]);
    
    // Get new table id
    $newId = $pdo->lastInsertId();

    // 3. Update existing kubo_rentals
    // Since table_id was 1-8 but now corresponds to some arbitrary auto_increment id from tables
    $pdo->prepare("UPDATE kubo_rentals SET table_id = ? WHERE table_id = ?")->execute([$newId, $i]);
  }

  echo "Migration completed successfully.\n";
} catch (Exception $e) {
  echo "Error: " . $e->getMessage() . "\n";
}
