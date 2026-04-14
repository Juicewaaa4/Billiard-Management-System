<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

// Update these to match your XAMPP MySQL settings.
$DB_HOST = '127.0.0.1';
$DB_NAME = 'billiard_system';
$DB_USER = 'root';
$DB_PASS = '';
$DB_CHARSET = 'utf8mb4';

function db(): PDO
{
  static $pdo = null;
  if ($pdo instanceof PDO) {
    return $pdo;
  }

  global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS, $DB_CHARSET;

  $options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ];

  $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
  try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
  } catch (PDOException $e) {
    // 1049 = Unknown database
    if ((string)$e->getCode() !== '1049') {
      throw $e;
    }

    $serverDsn = "mysql:host={$DB_HOST};charset={$DB_CHARSET}";
    $serverPdo = new PDO($serverDsn, $DB_USER, $DB_PASS, $options);

    // Safely quote identifier with backticks
    $dbIdent = '`' . str_replace('`', '``', $DB_NAME) . '`';
    $serverPdo->exec("CREATE DATABASE IF NOT EXISTS {$dbIdent} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
  }

  return $pdo;
}

