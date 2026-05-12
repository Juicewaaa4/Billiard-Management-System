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
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    body, html {
      margin: 0;
      padding: 0;
      height: 100%;
      font-family: 'Inter', sans-serif;
    }

    .split-layout {
      display: flex;
      min-height: 100vh;
      width: 100%;
    }

    /* Left Side: Billiard Graphics */
    .login-left {
      flex: 1.2;
      background: radial-gradient(circle at center, #2E7D32, #1B5E20);
      position: relative;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: inset -10px 0 20px rgba(0,0,0,0.2);
    }

    /* Pool table texture overlay */
    .login-left::before {
      content: "";
      position: absolute;
      top: 0; left: 0; right: 0; bottom: 0;
      background-image: url('data:image/svg+xml,%3Csvg width="20" height="20" xmlns="http://www.w3.org/2000/svg"%3E%3Cpath d="M0 0h20v20H0z" fill="%232e7d32" fill-opacity="0.4"/%3E%3Cpath d="M0 0h10v10H0zm10 10h10v10H10z" fill="%231b5e20" fill-opacity="0.2"/%3E%3C/svg%3E');
      opacity: 0.3;
      pointer-events: none;
    }

    .balls-container {
      position: relative;
      width: 100%;
      height: 100%;
    }

    .ball {
      position: absolute;
      border-radius: 50%;
      box-shadow: inset -15px -15px 25px rgba(0,0,0,0.6), 10px 15px 25px rgba(0,0,0,0.4);
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      transition: transform 0.3s ease;
    }

    .ball-8 {
      width: 240px;
      height: 240px;
      background: radial-gradient(circle at 35% 35%, #555, #0a0a0a);
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      z-index: 3;
    }

    .ball-3 {
      width: 150px;
      height: 150px;
      background: radial-gradient(circle at 35% 35%, #ff6b6b, #c92a2a);
      top: 15%;
      left: 20%;
      z-index: 2;
      transform: translate(0, 0);
      animation: float 6s ease-in-out infinite;
    }

    .ball-2 {
      width: 130px;
      height: 130px;
      background: radial-gradient(circle at 35% 35%, #4dabf7, #1864ab);
      bottom: 15%;
      right: 20%;
      z-index: 2;
      animation: float 7s ease-in-out infinite reverse;
    }

    @keyframes float {
      0% { transform: translateY(0px); }
      50% { transform: translateY(-15px); }
      100% { transform: translateY(0px); }
    }

    .eyes {
      display: flex;
      gap: 15px;
      margin-bottom: 8px;
      position: relative;
    }

    .ball-8 .eyes {
      gap: 22px;
      margin-bottom: 12px;
    }

    .eye {
      width: 40px;
      height: 40px;
      background: #fff;
      border-radius: 50%;
      position: relative;
      box-shadow: inset 0 3px 6px rgba(0,0,0,0.4);
      overflow: hidden;
      z-index: 2;
    }

    .ball-8 .eye {
      width: 60px;
      height: 60px;
    }

    .pupil {
      width: 16px;
      height: 16px;
      background: #111;
      border-radius: 50%;
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      transition: transform 0.1s ease-out;
    }

    .ball-8 .pupil {
      width: 24px;
      height: 24px;
    }

    /* Eye highlight */
    .pupil::after {
      content: '';
      position: absolute;
      top: 3px;
      left: 3px;
      width: 4px;
      height: 4px;
      background: #fff;
      border-radius: 50%;
    }

    .ball-8 .pupil::after {
      width: 6px; height: 6px; top: 4px; left: 4px;
    }

    .eyelid {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 0;
      transition: height 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      z-index: 2;
    }

    .ball-8 .eyelid { background: #1a1a1a; }
    .ball-3 .eyelid { background: #c92a2a; }
    .ball-2 .eyelid { background: #1864ab; }

    .hide-eyes .eyelid {
      height: 100%;
    }

    /* Kawaii Blush */
    .blush {
      position: absolute;
      width: 14px;
      height: 8px;
      background: rgba(255, 105, 180, 0.7);
      border-radius: 50%;
      top: 32px;
      filter: blur(2px);
      z-index: 3;
      transition: opacity 0.3s;
    }
    .ball-8 .blush { width: 18px; height: 10px; top: 48px; }
    
    .blush.left { left: -10px; }
    .blush.right { right: -10px; }
    .ball-8 .blush.left { left: -12px; }
    .ball-8 .blush.right { right: -12px; }

    .hide-eyes .blush { opacity: 0.2; }

    /* Cute Sparkles */
    .sparkle {
      position: absolute;
      background: #fff;
      border-radius: 50%;
      box-shadow: 0 0 10px 2px rgba(255,255,255,0.6);
      animation: twinkle 3s infinite ease-in-out;
      z-index: 1;
    }
    .s1 { top: 20%; left: 40%; width: 6px; height: 6px; animation-delay: 0s; }
    .s2 { top: 60%; left: 20%; width: 4px; height: 4px; animation-delay: 1s; }
    .s3 { top: 30%; right: 25%; width: 8px; height: 8px; animation-delay: 0.5s; }
    .s4 { bottom: 25%; right: 40%; width: 5px; height: 5px; animation-delay: 1.5s; }

    @keyframes twinkle {
      0%, 100% { opacity: 0.3; transform: scale(0.6); }
      50% { opacity: 1; transform: scale(1.2); }
    }

    /* Cute Chalk Mascot */
    .chalk {
      position: absolute;
      bottom: 12%;
      left: 15%;
      width: 70px;
      height: 70px;
      background: linear-gradient(135deg, #4facfe, #00f2fe);
      border-radius: 12px;
      transform: rotate(-15deg);
      box-shadow: -8px 12px 20px rgba(0,0,0,0.4), inset -5px -5px 15px rgba(0,0,0,0.2);
      animation: float 4.5s ease-in-out infinite 0.5s;
      z-index: 4;
      display: flex;
      flex-direction: column;
      align-items: center;
    }
    .chalk-top {
      width: 100%;
      height: 15px;
      background: rgba(255,255,255,0.3);
      border-radius: 12px 12px 0 0;
      border-bottom: 2px solid rgba(0,0,0,0.1);
      margin-bottom: 5px;
    }
    .chalk .eyelid { background: #4facfe; }

    /* Cute Cue Stick */
    .cue-stick {
      position: absolute;
      width: 500px;
      height: 24px;
      background: linear-gradient(to right, #fde68a 65%, #b45309 65%, #b45309 90%, #1e293b 90%);
      border-radius: 12px;
      top: 15%;
      right: -80px;
      transform: rotate(35deg);
      box-shadow: 0 15px 25px rgba(0,0,0,0.4), inset 0 5px 5px rgba(255,255,255,0.3);
      z-index: 1;
      animation: float 7s ease-in-out infinite 1s;
    }
    .cue-tip {
      position: absolute;
      left: -8px;
      top: 2px;
      width: 8px;
      height: 20px;
      background: #38bdf8;
      border-radius: 4px 0 0 4px;
    }

    .white-circle {
      width: 45px;
      height: 45px;
      background: #f8f9fa;
      border-radius: 50%;
      display: flex;
      justify-content: center;
      align-items: center;
      font-weight: 900;
      font-size: 24px;
      color: #212529;
      box-shadow: inset 0 2px 4px rgba(0,0,0,0.2);
    }

    .ball-8 .white-circle {
      width: 65px;
      height: 65px;
      font-size: 36px;
    }

    /* Right Side: Login Form */
    .login-right {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #f8faf9;
      position: relative;
      padding: 22px;
    }

    .login-box {
      width: min(420px, 100%);
      background: #ffffff;
      border: 1px solid rgba(102, 187, 106, .2);
      border-radius: 24px;
      box-shadow: 
        0 20px 50px rgba(0, 0, 0, .06),
        0 0 0 1px rgba(255, 255, 255, .8) inset,
        0 0 30px rgba(102, 187, 106, .06);
      padding: 48px 40px;
      position: relative;
      animation: fadeInUp .5s cubic-bezier(0.16, 1, 0.3, 1) both;
    }

    .login-box::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, transparent 0%, #81C784 25%, #66BB6A 50%, #81C784 75%, transparent 100%);
    }

    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .login-logo {
      text-align: center;
      margin-bottom: 12px;
    }

    .login-logo img {
      width: 90px;
      height: 90px;
      object-fit: cover;
      border-radius: 50%;
      border: 3px solid #C8E6C9;
      padding: 3px;
      background: #E8F5E9;
      box-shadow: 0 8px 24px rgba(102, 187, 106, .2);
    }

    .login-title {
      text-align: center;
      font-size: 26px;
      font-weight: 800;
      letter-spacing: -.5px;
      color: #1e293b;
      margin-top: 8px;
    }

    .login-subtitle {
      text-align: center;
      color: #64748b;
      font-size: 12px;
      font-weight: 600;
      letter-spacing: 2px;
      text-transform: uppercase;
      margin-top: 6px;
      margin-bottom: 32px;
    }

    .field {
      margin-bottom: 20px;
    }

    .label {
      font-size: 13px;
      font-weight: 600;
      color: #475569;
      margin-bottom: 8px;
    }

    .login-box input {
      width: 100%;
      padding: 12px 16px;
      background: #f1f5f9;
      border: 1px solid transparent;
      border-radius: 12px;
      font-family: 'Inter', sans-serif;
      font-size: 15px;
      color: #1e293b;
      transition: all .2s ease;
      outline: none;
    }

    .login-box input:focus {
      background: #ffffff;
      border-color: #66BB6A;
      box-shadow: 0 0 0 4px rgba(102, 187, 106, .15);
    }

    .password-wrapper {
      position: relative;
    }

    .password-wrapper input {
      padding-right: 44px;
    }

    .eye-toggle {
      position: absolute;
      right: 12px;
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

    .login-btn {
      width: 100%;
      padding: 14px;
      margin-top: 12px;
      font-size: 15px;
      font-weight: 700;
      letter-spacing: .5px;
      border-radius: 12px;
      background: linear-gradient(135deg, #66BB6A 0%, #4CAF50 100%);
      border: none;
      color: #fff;
      cursor: pointer;
      font-family: 'Inter', sans-serif;
      transition: all .2s ease;
      box-shadow: 0 8px 20px rgba(102, 187, 106, .3);
    }

    .login-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 24px rgba(102, 187, 106, .4);
    }

    .login-btn:active {
      transform: translateY(0);
      box-shadow: 0 4px 12px rgba(102, 187, 106, .2);
    }

    .developer-credit {
      text-align: center;
      margin-top: 32px;
      font-size: 12px;
      font-weight: 500;
      color: #94a3b8;
      letter-spacing: 0.5px;
    }

    .developer-credit strong {
      font-weight: 700;
      color: #64748b;
    }

    @media (max-width: 860px) {
      .login-left {
        display: none;
      }
      .login-right {
        background: radial-gradient(ellipse at center, rgba(102, 187, 106, .1) 0%, transparent 70%), #f8faf9;
      }
    }
  </style>
</head>

<body>
  <div class="split-layout">
    <!-- Left Side: Animated Billiard Balls -->
    <div class="login-left">
      <div class="balls-container">
        <!-- Sparkles -->
        <div class="sparkle s1"></div>
        <div class="sparkle s2"></div>
        <div class="sparkle s3"></div>
        <div class="sparkle s4"></div>

        <!-- Cute Cue Stick -->
        <div class="cue-stick">
          <div class="cue-tip"></div>
        </div>

        <!-- Cute Chalk Mascot -->
        <div class="chalk">
          <div class="chalk-top"></div>
          <div class="eyes" style="gap: 8px; margin-bottom: 0;">
            <div class="blush left" style="top: 14px; left: -6px; width: 10px; height: 6px;"></div>
            <div class="eye" style="width: 18px; height: 18px; box-shadow: none;"><div class="pupil" style="width: 8px; height: 8px;"></div><div class="eyelid"></div></div>
            <div class="eye" style="width: 18px; height: 18px; box-shadow: none;"><div class="pupil" style="width: 8px; height: 8px;"></div><div class="eyelid"></div></div>
            <div class="blush right" style="top: 14px; right: -6px; width: 10px; height: 6px;"></div>
          </div>
        </div>

        <!-- 3 Ball (Red) -->
        <div class="ball ball-3">
          <div class="eyes">
            <div class="blush left"></div>
            <div class="eye"><div class="pupil"></div><div class="eyelid"></div></div>
            <div class="eye"><div class="pupil"></div><div class="eyelid"></div></div>
            <div class="blush right"></div>
          </div>
          <div class="white-circle">3</div>
        </div>

        <!-- 8 Ball (Black) -->
        <div class="ball ball-8">
          <div class="eyes">
            <div class="blush left"></div>
            <div class="eye"><div class="pupil"></div><div class="eyelid"></div></div>
            <div class="eye"><div class="pupil"></div><div class="eyelid"></div></div>
            <div class="blush right"></div>
          </div>
          <div class="white-circle">8</div>
        </div>

        <!-- 2 Ball (Blue) -->
        <div class="ball ball-2">
          <div class="eyes">
            <div class="blush left"></div>
            <div class="eye"><div class="pupil"></div><div class="eyelid"></div></div>
            <div class="eye"><div class="pupil"></div><div class="eyelid"></div></div>
            <div class="blush right"></div>
          </div>
          <div class="white-circle">2</div>
        </div>
      </div>
    </div>

    <!-- Right Side: Login Form -->
    <div class="login-right">
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

        <div class="developer-credit">
          Developed by: <strong>Lloyd Joshua De Lara</strong>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Password toggle
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
      
      // Update mascot eyes if focused
      if (document.activeElement === passwordInput) {
        if (passwordInput.type === 'password') {
          document.body.classList.add('hide-eyes');
        } else {
          document.body.classList.remove('hide-eyes');
        }
      }
    });

    // Mascot Eye Tracking
    const pupils = document.querySelectorAll('.pupil');
    
    document.addEventListener('mousemove', (e) => {
      if (document.body.classList.contains('hide-eyes')) return;
      
      pupils.forEach(pupil => {
        const eye = pupil.parentElement;
        const rect = eye.getBoundingClientRect();
        
        // Calculate center of the eye
        const eyeCenterX = rect.left + rect.width / 2;
        const eyeCenterY = rect.top + rect.height / 2;
        
        // Calculate angle between eye and cursor
        const angle = Math.atan2(e.clientY - eyeCenterY, e.clientX - eyeCenterX);
        
        // Limit movement radius based on eye size
        const distance = Math.min(
          eye.offsetWidth / 3.5, 
          Math.hypot(e.clientX - eyeCenterX, e.clientY - eyeCenterY) / 12
        );
        
        const x = Math.cos(angle) * distance;
        const y = Math.sin(angle) * distance;
        
        pupil.style.transform = `translate(calc(-50% + ${x}px), calc(-50% + ${y}px))`;
      });
    });

    // Hide eyes when typing password
    passwordInput.addEventListener('focus', () => {
      if (passwordInput.type === 'password') {
        document.body.classList.add('hide-eyes');
        // Reset pupils to center when eyes close
        pupils.forEach(pupil => {
          pupil.style.transform = 'translate(-50%, -50%)';
        });
      }
    });

    passwordInput.addEventListener('blur', () => {
      document.body.classList.remove('hide-eyes');
    });
  </script>
</body>
</html>