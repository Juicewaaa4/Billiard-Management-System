<?php
require 'config/database.php';
try {
    db()->exec("ALTER TABLE kubo_rentals ADD COLUMN is_voided TINYINT(1) NOT NULL DEFAULT 0 AFTER created_by");
    db()->exec("ALTER TABLE kubo_rentals ADD COLUMN void_reason VARCHAR(255) NULL AFTER is_voided");
    echo "Success!";
} catch (Exception $e) {
    echo $e->getMessage();
}
