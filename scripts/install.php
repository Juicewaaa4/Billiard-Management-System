<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
// BILLIARD MANAGEMENT SYSTEM — MASTER INSTALLER
// Run once on a fresh XAMPP to create all tables, columns, and seed data.
// URL: http://localhost/Billiard%20System/scripts/install.php
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/database.php';

function run(string $sql): void
{
  db()->exec($sql);
}

try {
  run("CREATE DATABASE IF NOT EXISTS billiard_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
  run("USE billiard_system;");

  // ── Users ──
  run("
    CREATE TABLE IF NOT EXISTS users (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      username VARCHAR(50) NOT NULL UNIQUE,
      password_hash VARCHAR(255) NOT NULL,
      role ENUM('admin','cashier') NOT NULL DEFAULT 'cashier',
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
  ");

  // ── Tables (billiard/vip/ktv/kubo) ──
  run("
    CREATE TABLE IF NOT EXISTS tables (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      table_number VARCHAR(30) NOT NULL UNIQUE,
      type ENUM('regular','vip','ktv','kubo') NOT NULL DEFAULT 'regular',
      status ENUM('available','in_use') NOT NULL DEFAULT 'available',
      rate_per_hour DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      is_disabled TINYINT(1) NOT NULL DEFAULT 0,
      is_deleted TINYINT(1) NOT NULL DEFAULT 0,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
  ");

  // ── Customers ──
  run("
    CREATE TABLE IF NOT EXISTS customers (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL,
      contact VARCHAR(80) NULL,
      loyalty_games INT NOT NULL DEFAULT 0,
      loyalty_vip_games INT NOT NULL DEFAULT 0,
      loyalty_reset_date DATE NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
  ");

  // ── Game Sessions ──
  run("
    CREATE TABLE IF NOT EXISTS game_sessions (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      table_id INT UNSIGNED NOT NULL,
      customer_id INT UNSIGNED NULL,
      walk_in_name VARCHAR(120) NULL,
      rate_per_hour DECIMAL(10,2) NOT NULL,
      start_time DATETIME NOT NULL,
      scheduled_end_time DATETIME NULL,
      hours_purchased DECIMAL(4,1) NOT NULL DEFAULT 0,
      karaoke_included TINYINT(1) DEFAULT 0,
      billing_start_time DATETIME NULL,
      billing_bonus_seconds INT UNSIGNED NOT NULL DEFAULT 0,
      end_time DATETIME NULL,
      duration_seconds INT UNSIGNED NULL,
      total_amount DECIMAL(10,2) NULL,
      games_earned INT NOT NULL DEFAULT 0,
      games_redeemed INT NOT NULL DEFAULT 0,
      discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      is_promo TINYINT(1) NOT NULL DEFAULT 0,
      reservation_id INT UNSIGNED NULL,
      down_payment DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      is_voided TINYINT(1) NOT NULL DEFAULT 0,
      void_reason VARCHAR(255) NULL,
      created_by INT UNSIGNED NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_sessions_table FOREIGN KEY (table_id) REFERENCES tables(id),
      CONSTRAINT fk_sessions_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
      CONSTRAINT fk_sessions_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
      INDEX idx_sessions_end_time(end_time),
      INDEX idx_sessions_start_time(start_time)
    ) ENGINE=InnoDB;
  ");

  // ── Transactions ──
  run("
    CREATE TABLE IF NOT EXISTS transactions (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      session_id INT UNSIGNED NOT NULL,
      payment DECIMAL(10,2) NOT NULL,
      change_amount DECIMAL(10,2) NOT NULL,
      paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      created_by INT UNSIGNED NULL,
      CONSTRAINT fk_tx_session FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE,
      CONSTRAINT fk_tx_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
      INDEX idx_tx_paid_at(paid_at)
    ) ENGINE=InnoDB;
  ");

  // ── Reservations ──
  run("
    CREATE TABLE IF NOT EXISTS reservations (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      table_id INT UNSIGNED NOT NULL,
      customer_name VARCHAR(120) NOT NULL,
      customer_contact VARCHAR(80) NULL,
      reservation_date DATE NOT NULL,
      start_time TIME NOT NULL,
      duration_hours DECIMAL(4,1) NOT NULL DEFAULT 1,
      down_payment DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      notes TEXT NULL,
      status ENUM('pending','confirmed','completed','cancelled','no_show') NOT NULL DEFAULT 'pending',
      created_by INT UNSIGNED NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_res_table FOREIGN KEY (table_id) REFERENCES tables(id),
      CONSTRAINT fk_res_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
      INDEX idx_res_date(reservation_date)
    ) ENGINE=InnoDB;
  ");

  // ── Kubo Rentals ──
  run("
    CREATE TABLE IF NOT EXISTS kubo_rentals (
      id INT AUTO_INCREMENT PRIMARY KEY,
      table_id INT NOT NULL,
      customer_name VARCHAR(255) NOT NULL,
      payment_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      rental_date DATE NOT NULL,
      status ENUM('active','completed') NOT NULL DEFAULT 'active',
      end_time DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      created_by INT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ");

  // ── App Settings ──
  run("
    CREATE TABLE IF NOT EXISTS app_settings (
      setting_key VARCHAR(50) PRIMARY KEY,
      setting_value VARCHAR(255) NOT NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
  ");

  // Seed default settings
  run("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('morning_shift_start', '08:00'),
    ('evening_shift_start', '16:30'),
    ('night_shift_end', '02:30'),
    ('tx_from_time', '08:00'),
    ('tx_to_time', '23:59'),
    ('dt_op_start', '08:00'),
    ('dt_op_end', '02:00')
  ");

  // ── Seed Users ──
  $stmt = db()->prepare("INSERT IGNORE INTO users (username, password_hash, role) VALUES (?,?,?)");
  $stmt->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'admin']);
  $stmt->execute(['cashier', password_hash('cashier123', PASSWORD_DEFAULT), 'cashier']);

  // ── Seed Sample Tables ──
  db()->exec("
    INSERT IGNORE INTO tables (table_number, type, status, rate_per_hour) VALUES
    ('Table 1','available',150.00),
    ('Table 2','available',150.00),
    ('Table 3','available',180.00);
  ");

  // ═══════════════════════════════════════════════════════════
  // MIGRATION PATCHES (safe to re-run — uses try/catch for each)
  // These handle upgrading older installs that are missing columns.
  // ═══════════════════════════════════════════════════════════

  $migrations = [
    "ALTER TABLE tables ADD COLUMN is_disabled TINYINT(1) NOT NULL DEFAULT 0 AFTER rate_per_hour",
    "ALTER TABLE tables ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER is_disabled",
    "ALTER TABLE tables MODIFY COLUMN type ENUM('regular','vip','ktv','kubo') NOT NULL DEFAULT 'regular'",
    "ALTER TABLE customers ADD COLUMN loyalty_vip_games INT NOT NULL DEFAULT 0 AFTER loyalty_games",
    "ALTER TABLE game_sessions ADD COLUMN scheduled_end_time DATETIME NULL AFTER start_time",
    "ALTER TABLE game_sessions ADD COLUMN hours_purchased DECIMAL(4,1) NOT NULL DEFAULT 0 AFTER scheduled_end_time",
    "ALTER TABLE game_sessions ADD COLUMN karaoke_included TINYINT(1) DEFAULT 0 AFTER hours_purchased",
    "ALTER TABLE game_sessions ADD COLUMN is_promo TINYINT(1) NOT NULL DEFAULT 0 AFTER discount_amount",
    "ALTER TABLE game_sessions ADD COLUMN reservation_id INT UNSIGNED NULL AFTER is_promo",
    "ALTER TABLE game_sessions ADD COLUMN down_payment DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER reservation_id",
    "ALTER TABLE game_sessions ADD COLUMN is_voided TINYINT(1) NOT NULL DEFAULT 0 AFTER down_payment",
    "ALTER TABLE game_sessions ADD COLUMN void_reason VARCHAR(255) NULL AFTER is_voided",
  ];

  foreach ($migrations as $m) {
    try { db()->exec($m); } catch (Throwable $ignore) {}
  }

  echo "<h2 style='color:#22c55e;'>✅ Install complete!</h2>";
  echo "<p>Login credentials:</p>";
  echo "<ul><li><strong>admin</strong> / admin123</li><li><strong>cashier</strong> / cashier123</li></ul>";
  echo "<p>Next: open <a href='../index.php'>index.php</a>. For security, delete <code>install.php</code> after setup.</p>";
} catch (Throwable $e) {
  http_response_code(500);
  echo "<h2 style='color:#ef4444;'>❌ Install failed</h2>";
  echo "<pre>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</pre>";
}
