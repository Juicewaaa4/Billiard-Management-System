<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
// BILLIARD MANAGEMENT SYSTEM — DATABASE RESET (Fresh Start)
// Clears ALL transaction data while preserving system structure.
// URL: http://localhost/Billiard%20System/scripts/reset_data.php
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/database.php';

// Safety: Only allow if accessed directly (not accidentally)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['confirm'])) {
  echo '<!doctype html><html><head><meta charset="utf-8"><title>Reset Data</title>';
  echo '<style>body{font-family:Inter,Arial,sans-serif;max-width:600px;margin:60px auto;padding:20px;background:#0f172a;color:#e2e8f0;}';
  echo '.btn{display:inline-block;padding:14px 28px;border-radius:8px;font-weight:700;font-size:15px;text-decoration:none;cursor:pointer;border:none;margin:8px 4px;}';
  echo '.btn-danger{background:#ef4444;color:white;}.btn-cancel{background:#334155;color:#e2e8f0;}';
  echo '.warning{background:rgba(239,68,68,0.15);border:1px solid #ef4444;border-radius:10px;padding:20px;margin:20px 0;}';
  echo '</style></head><body>';
  echo '<h1>🗑️ Reset All Data</h1>';
  echo '<div class="warning">';
  echo '<h3 style="color:#ef4444;margin-top:0;">⚠️ WARNING: This will permanently delete:</h3>';
  echo '<ul>';
  echo '<li>All game sessions and transactions</li>';
  echo '<li>All kubo rentals</li>';
  echo '<li>All reservations</li>';
  echo '<li>All customer records</li>';
  echo '</ul>';
  echo '<p><strong>The following will be preserved:</strong></p>';
  echo '<ul>';
  echo '<li>✅ Tables (Regular, VIP, KTV, Kubo)</li>';
  echo '<li>✅ User accounts (admin, cashier)</li>';
  echo '<li>✅ App settings (shift times, etc.)</li>';
  echo '</ul>';
  echo '</div>';
  echo '<p>Are you sure you want to reset the database for a fresh start?</p>';
  echo '<form method="post">';
  echo '<button type="submit" name="reset" value="1" class="btn btn-danger">🗑️ Yes, Reset Everything</button>';
  echo '<a href="../dashboard.php" class="btn btn-cancel">Cancel</a>';
  echo '</form>';
  echo '</body></html>';
  exit;
}

try {
  $pdo = db();

  // Disable FK checks temporarily
  $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

  // Clear all transactional data
  $pdo->exec("TRUNCATE TABLE transactions");
  $pdo->exec("TRUNCATE TABLE game_sessions");
  $pdo->exec("TRUNCATE TABLE kubo_rentals");
  $pdo->exec("TRUNCATE TABLE reservations");
  $pdo->exec("TRUNCATE TABLE customers");

  // Reset all table statuses to available
  $pdo->exec("UPDATE tables SET status = 'available'");

  // Re-enable FK checks
  $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

  echo '<!doctype html><html><head><meta charset="utf-8"><title>Reset Complete</title>';
  echo '<style>body{font-family:Inter,Arial,sans-serif;max-width:600px;margin:60px auto;padding:20px;background:#0f172a;color:#e2e8f0;text-align:center;}';
  echo '.btn{display:inline-block;padding:14px 28px;border-radius:8px;font-weight:700;font-size:15px;text-decoration:none;cursor:pointer;border:none;margin:8px 4px;background:#22c55e;color:white;}';
  echo '</style></head><body>';
  echo '<h1 style="color:#22c55e;">✅ Database Reset Complete!</h1>';
  echo '<p>All transaction data has been cleared. The system is ready for a fresh start.</p>';
  echo '<p style="color:#94a3b8;margin-top:20px;">Tables, users, and settings were preserved.</p>';
  echo '<a href="../index.php" class="btn">🏠 Go to Login</a>';
  echo '<p style="margin-top:30px;color:#ef4444;font-size:13px;">⚠️ For security, delete this file after use: <code>scripts/reset_data.php</code></p>';
  echo '</body></html>';
} catch (Throwable $e) {
  http_response_code(500);
  echo '<h2 style="color:#ef4444;">❌ Reset failed</h2>';
  echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
}
