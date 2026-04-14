-- Billiard Hall Management System
-- MySQL (XAMPP) schema + seed data

CREATE DATABASE IF NOT EXISTS billiard_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE billiard_system;

-- Users (admin/cashier)
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','cashier') NOT NULL DEFAULT 'cashier',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Billiard tables
CREATE TABLE IF NOT EXISTS tables (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  table_number VARCHAR(30) NOT NULL UNIQUE,
  type ENUM('regular','vip') NOT NULL DEFAULT 'regular',
  status ENUM('available','in_use') NOT NULL DEFAULT 'available',
  rate_per_hour DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Customers (optional; walk-ins not stored)
CREATE TABLE IF NOT EXISTS customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  contact VARCHAR(80) NULL,
  loyalty_games INT NOT NULL DEFAULT 0,
  loyalty_reset_date DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Game sessions (start/end)
CREATE TABLE IF NOT EXISTS game_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  table_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NULL,
  walk_in_name VARCHAR(120) NULL,
  rate_per_hour DECIMAL(10,2) NOT NULL,
  start_time DATETIME NOT NULL,
  billing_start_time DATETIME NULL,
  billing_bonus_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  end_time DATETIME NULL,
  duration_seconds INT UNSIGNED NULL,
  total_amount DECIMAL(10,2) NULL,
  games_earned INT NOT NULL DEFAULT 0,
  games_redeemed INT NOT NULL DEFAULT 0,
  discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sessions_table FOREIGN KEY (table_id) REFERENCES tables(id),
  CONSTRAINT fk_sessions_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_sessions_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_sessions_end_time(end_time),
  INDEX idx_sessions_start_time(start_time)
) ENGINE=InnoDB;

-- Payments / transactions
CREATE TABLE IF NOT EXISTS transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id INT UNSIGNED NOT NULL UNIQUE,
  payment DECIMAL(10,2) NOT NULL,
  change_amount DECIMAL(10,2) NOT NULL,
  paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT UNSIGNED NULL,
  CONSTRAINT fk_tx_session FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_tx_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_tx_paid_at(paid_at)
) ENGINE=InnoDB;

-- Seed a few tables
INSERT INTO tables (table_number, status, rate_per_hour)
VALUES
('Table 1', 'available', 150.00),
('Table 2', 'available', 150.00),
('Table 3', 'available', 180.00)
ON DUPLICATE KEY UPDATE table_number = table_number;

