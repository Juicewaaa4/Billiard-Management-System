<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';

start_app_session();

if (is_logged_in()) {
  redirect('dashboard.php');
}

$error = '';
if (!empty($_GET['timeout'])) {
  $error = 'You were logged out due to inactivity.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim((string) ($_POST['username'] ?? ''));
  $password = (string) ($_POST['password'] ?? '');

  if ($username === '' || $password === '') {
    $error = 'Please enter username and password.';
  } else {
    $stmt = db()->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
      $error = 'Invalid credentials.';
    } else {
      $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'role' => (string) $user['role'],
      ];
      $_SESSION['last_activity'] = time();
      redirect('dashboard.php');
    }
  }
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login • Zoey's Billiard House</title>
  <link rel="icon" type="image/png" href="Logo/Billiards Logo.png">
  <link rel="stylesheet" href="assets/css/style.css?v=2.0">
  <style>
    .login-page {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 22px;
      background:
        radial-gradient(ellipse 800px 500px at 20% 15%, rgba(102, 187, 106, .15) 0%, transparent 60%),
        radial-gradient(ellipse 600px 400px at 85% 85%, rgba(129, 199, 132, .12) 0%, transparent 60%),
        linear-gradient(135deg, #E8F5E9 0%, #C8E6C9 50%, #E8F5E9 100%);
    }

    /* Decorative circles */
    .login-page::before,
    .login-page::after {
      content: "";
      position: fixed;
      border-radius: 50%;
      pointer-events: none;
    }

    .login-page::before {
      width: 350px;
      height: 350px;
      top: -100px;
      right: -80px;
      background: radial-gradient(circle, rgba(102, 187, 106, .1) 0%, transparent 70%);
    }

    .login-page::after {
      width: 280px;
      height: 280px;
      bottom: -60px;
      left: -60px;
      background: radial-gradient(circle, rgba(129, 199, 132, .1) 0%, transparent 70%);
    }

    .login-box {
      width: min(400px, 100%);
      background: #ffffff;
      border: 1px solid rgba(102, 187, 106, .2);
      border-radius: 18px;
      box-shadow:
        0 20px 50px rgba(0, 0, 0, .06),
        0 0 0 1px rgba(255, 255, 255, .8) inset,
        0 0 30px rgba(102, 187, 106, .06);
      padding: 40px 32px 36px;
      position: relative;
      overflow: hidden;
      animation: fadeInUp .45s ease both;
    }

    .login-box::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, transparent 0%, #81C784 25%, #66BB6A 50%, #81C784 75%, transparent 100%);
    }

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(20px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    /* Logo */
    .login-logo {
      text-align: center;
      margin-bottom: 6px;
    }

    .login-logo img {
      width: 110px;
      height: 110px;
      max-width: 110px;
      max-height: 110px;
      min-width: 110px;
      min-height: 110px;
      object-fit: cover;
      border-radius: 50%;
      border: 3px solid #C8E6C9;
      padding: 3px;
      background: #E8F5E9;
      box-shadow: 0 4px 20px rgba(102, 187, 106, .15);
      display: inline-block;
    }

    .login-title {
      text-align: center;
      font-size: 22px;
      font-weight: 900;
      letter-spacing: -.3px;
      color: #1e293b;
      margin-top: 16px;
    }

    .login-subtitle {
      text-align: center;
      color: #64748b;
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      margin-top: 5px;
      margin-bottom: 28px;
    }

    /* Password field wrapper */
    .password-wrapper {
      position: relative;
    }

    .password-wrapper input {
      padding-right: 44px;
    }

    .eye-toggle {
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      cursor: pointer;
      color: #94a3b8;
      padding: 4px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: color .15s ease;
      outline: none;
    }

    .eye-toggle:hover {
      color: #66BB6A;
    }

    .eye-toggle svg {
      width: 20px;
      height: 20px;
      fill: none;
      stroke: currentColor;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
    }

    /* Login button */
    .login-btn {
      width: 100%;
      padding: 13px 18px;
      margin-top: 6px;
      font-size: 14px;
      font-weight: 800;
      letter-spacing: .4px;
      text-transform: uppercase;
      border-radius: 10px;
      background: linear-gradient(135deg, #66BB6A 0%, #4CAF50 100%);
      border: none;
      color: #fff;
      cursor: pointer;
      font-family: inherit;
      transition: all .2s ease;
      box-shadow: 0 4px 14px rgba(102, 187, 106, .25);
    }

    .login-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(102, 187, 106, .35);
      filter: brightness(1.04);
    }

    .login-btn:active {
      transform: translateY(0);
      filter: brightness(.95);
    }

    .login-footer {
      text-align: center;
      margin-top: 24px;
      color: #94a3b8;
      font-size: 11px;
      font-weight: 500;
    }

    .login-footer span {
      color: #4CAF50;
      font-weight: 700;
    }

    /* Make input style consistent for login */
    .login-box input {
      background: #f5faf6;
      border: 1px solid rgba(0, 0, 0, .1);
    }

    .login-box input:focus {
      background: #fff;
      border-color: #66BB6A;
      box-shadow: 0 0 0 3px rgba(102, 187, 106, .2);
    }

    .developer-credit {
      position: absolute;
      bottom: 24px;
      left: 0;
      right: 0;
      text-align: center;
      font-size: 11px;
      font-weight: 500;
      color: rgba(30, 41, 59, 0.55);
      letter-spacing: 0.5px;
      text-transform: uppercase;
      animation: fadeInUp 0.6s ease both 0.2s;
      pointer-events: none;
    }

    .developer-credit strong {
      font-weight: 700;
      color: rgba(30, 41, 59, 0.8);
    }
  </style>
</head>

<body>
  <div class="login-page">
    <div class="login-box">
      <div class="login-logo">
        <img src="Logo/Billiards Logo.png" alt="Zoey's Billiard House Logo">
      </div>
      <div class="login-title">Zoey's Billiard House</div>
      <div class="login-subtitle">Management System</div>

      <?php if ($error !== ''): ?>
        <div class="alert alert--danger" style="margin-bottom:16px;">
          <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </div>
      <?php endif; ?>

      <form method="post" class="form">
        <div class="field">
          <div class="label">Username</div>
          <input name="username" autocomplete="username" required placeholder="Enter your username">
        </div>
        <div class="field">
          <div class="label">Password</div>
          <div class="password-wrapper">
            <input name="password" type="password" id="loginPassword" autocomplete="current-password" required
              placeholder="Enter your password">
            <button type="button" class="eye-toggle" id="eyeToggle" tabindex="-1" aria-label="Show password">
              <svg id="eyeOpen" viewBox="0 0 24 24">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                <circle cx="12" cy="12" r="3" />
              </svg>
              <svg id="eyeClosed" viewBox="0 0 24 24" style="display:none;">
                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94" />
                <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19" />
                <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24" />
                <line x1="1" y1="1" x2="23" y2="23" />
              </svg>
            </button>
          </div>
        </div>
        <button class="login-btn" type="submit">Sign In</button>
      </form>

      <div class="login-footer">
        Secured by <span>Zoey's Billiard House</span> System
      </div>
    </div>

    <div class="developer-credit">
      Developed by: <strong>Lloyd Joshua De Lara</strong>
    </div>
  </div>

  <script>
    const toggle = document.getElementById('eyeToggle');
    const passwordInput = document.getElementById('loginPassword');
    const eyeOpen = document.getElementById('eyeOpen');
    const eyeClosed = document.getElementById('eyeClosed');

    toggle.addEventListener('click', () => {
      const isPassword = passwordInput.type === 'password';
      passwordInput.type = isPassword ? 'text' : 'password';
      eyeOpen.style.display = isPassword ? 'none' : 'block';
      eyeClosed.style.display = isPassword ? 'block' : 'none';
      toggle.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
    });
  </script>
</body>

</html>