<?php
declare(strict_types=1);

// Session + auth helpers used by every protected page.

function start_app_session(): void
{
  if (session_status() === PHP_SESSION_NONE) {
    // Basic hardening (works well on modern PHP versions).
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
      ini_set('session.cookie_secure', '1');
    }
    session_start();
  }
}

function redirect(string $to): never
{
  header("Location: {$to}");
  exit;
}

function is_logged_in(): bool
{
  return !empty($_SESSION['user']['id']);
}

function require_login(): void
{
  if (!is_logged_in()) {
    redirect('index.php');
  }
}

function require_role(array $roles): void
{
  require_login();
  $role = $_SESSION['user']['role'] ?? '';
  if (!in_array($role, $roles, true)) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
  }
}

function current_user(): array
{
  return $_SESSION['user'] ?? [];
}

function logout(): void
{
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
  }
  session_destroy();
}

// Auto logout after inactivity (seconds). Adjust as needed.
function enforce_inactivity_timeout(int $seconds = 900): void
{
  $now = time();
  $last = (int)($_SESSION['last_activity'] ?? 0);

  if ($last > 0 && ($now - $last) > $seconds) {
    logout();
    redirect('index.php?timeout=1');
  }

  $_SESSION['last_activity'] = $now;
}

