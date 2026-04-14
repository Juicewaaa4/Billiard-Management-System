<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

try {
  $pdo = db();
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `kubo_rentals` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `customer_name` varchar(255) NOT NULL,
      `payment_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
      `rental_date` date NOT NULL,
      `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `created_by` int(11) DEFAULT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ");
  echo "Successfully created kubo_rentals table!\n";
} catch (Exception $e) {
  echo "Error: " . $e->getMessage() . "\n";
}
