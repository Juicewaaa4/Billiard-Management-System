<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

function h(string $value): string
{
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function system_name(): string
{
  return "Zoey’s Billiard House";
}

function system_tagline(): string
{
  return "Billiard Management System";
}

function page_title(string $title): void
{
  echo h($title) . ' • ' . h(system_name());
}

function render_header(string $title, string $activeNav = ''): void
{
  $user = current_user();
  $role = $user['role'] ?? '';
  $username = $user['username'] ?? '';

  ?>
  <!doctype html>
  <html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php page_title($title); ?></title>
    <link rel="icon" type="image/png" href="Logo/Billiards Logo.png">
    <link rel="stylesheet" href="assets/css/style.css?v=2.0">
  </head>
  <body>
    <div class="app">
      <aside class="sidebar">
        <div class="brand">
          <div class="brand__logo">
            <img src="Logo/Billiards Logo.png" alt="Logo" class="brand__logo-img" style="width:44px;height:44px;max-width:44px;max-height:44px;min-width:44px;min-height:44px;">
          </div>
          <div style="min-width:0;">
            <div class="brand__title"><?php echo h(system_name()); ?></div>
            <div class="brand__sub"><?php echo h(system_tagline()); ?></div>
          </div>
        </div>
        <nav class="nav">
          <a class="nav__link <?php echo $activeNav === 'dashboard' ? 'is-active' : ''; ?>" href="dashboard.php">Dashboard</a>
          <a class="nav__link <?php echo $activeNav === 'tables' ? 'is-active' : ''; ?>" href="tables.php">Tables</a>
          <a class="nav__link <?php echo $activeNav === 'vip_tables' ? 'is-active' : ''; ?>" href="vip_tables.php">VIP Table With Karaoke</a>
          <a class="nav__link <?php echo $activeNav === 'reservations' ? 'is-active' : ''; ?>" href="reservations.php">Reservations</a>
          <a class="nav__link <?php echo $activeNav === 'kubo' ? 'is-active' : ''; ?>" href="kubo.php">Kubo</a>
          <a class="nav__link <?php echo $activeNav === 'customers' ? 'is-active' : ''; ?>" href="customers.php">Customers</a>
          <?php if ($role === 'admin'): ?>
            <a class="nav__link <?php echo $activeNav === 'transactions' ? 'is-active' : ''; ?>" href="transactions.php">Transactions</a>
            <a class="nav__link <?php echo $activeNav === 'reports' ? 'is-active' : ''; ?>" href="reports.php">Reports</a>
            <a class="nav__link <?php echo $activeNav === 'users' ? 'is-active' : ''; ?>" href="users.php">Users</a>
          <?php endif; ?>
        </nav>
        <div class="sidebar__footer">
          <div class="userchip">
            <div class="userchip__name"><?php echo h($username); ?></div>
            <div class="userchip__role"><?php echo h(ucfirst($role)); ?></div>
          </div>
          <button class="btn btn--ghost btn--block" type="button" onclick="document.getElementById('logoutModal').style.display='flex'">Logout</button>
        </div>
      </aside>

      <main class="main">
        <header class="topbar">
          <div class="topbar__title"><?php echo h($title); ?></div>
          <div class="topbar__spacer"></div>
        </header>

        <section class="content">
  <?php
}

function render_footer(): void
{
  $user = current_user();
  $username = $user['username'] ?? 'User';
  $role = ucfirst($user['role'] ?? 'user');
  ?>
        </section>
      </main>
    </div>

    <!-- Logout Confirmation Modal -->
    <div id="logoutModal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.35); align-items:center; justify-content:center;">
      <div style="background:#fff; border:1px solid var(--border); border-radius:14px; width:90%; max-width:400px; padding:0; box-shadow:0 8px 32px rgba(0,0,0,.12); animation: logoutModalIn 0.2s ease-out;">
        <div style="padding:24px 24px 0; text-align:center;">
          <div style="width:56px; height:56px; margin:0 auto 16px; border-radius:50%; background:rgba(239,68,68,0.12); display:flex; align-items:center; justify-content:center; font-size:28px;">🚪</div>
          <div style="font-size:18px; font-weight:700; color:var(--text, #fff); margin-bottom:6px;">Confirm Logout</div>
          <div style="color:var(--muted, #94a3b8); font-size:14px; line-height:1.5;">
            Are you sure you want to log out?<br>
            <span style="font-size:13px; opacity:0.8;">Signed in as <strong><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></strong> (<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>)</span>
          </div>
        </div>
        <div style="padding:20px 24px 24px; display:flex; gap:10px; justify-content:center; margin-top:8px;">
          <button type="button" class="btn btn--ghost" onclick="document.getElementById('logoutModal').style.display='none'" style="flex:1; max-width:160px;">Cancel</button>
          <a href="logout.php" class="btn btn--danger" style="flex:1; max-width:160px; text-align:center; text-decoration:none;">Logout</a>
        </div>
      </div>
    </div>

    <style>
      @keyframes logoutModalIn {
        from { opacity: 0; transform: scale(0.92) translateY(10px); }
        to   { opacity: 1; transform: scale(1) translateY(0); }
      }
      @keyframes globalPulse { 
        0% { transform: scale(1); opacity: 1; } 
        50% { transform: scale(1.15); opacity: 0.8; } 
        100% { transform: scale(1); opacity: 1; } 
      }
      .global-modal { position:fixed; inset:0; z-index:99999; background:rgba(0,0,0,0.35); display:flex; align-items:center; justify-content:center; }
      .global-modal__box { background:#fff; border:1px solid var(--border); border-radius:14px; width:95%; max-width:420px; box-shadow:0 8px 32px rgba(0,0,0,.12); animation: logoutModalIn 0.2s ease-out; }
      .global-modal__header { display:flex; justify-content:space-between; align-items:center; padding:18px 20px; background:var(--danger); border-radius:12px 12px 0 0; }
      .global-modal__close { color:white; font-size:24px; cursor:pointer; line-height:1; opacity:0.8; }
      .global-modal__close:hover { opacity:1; }
      .global-modal__body { padding:32px 24px; text-align:center; }
    </style>

    <!-- Time's Up Alert Modal (Global) -->
    <div id="globalTimeoutModal" class="global-modal" style="display:none;" onclick="if(event.target.id==='globalTimeoutModal')closeGlobalTimeoutModal()">
      <div class="global-modal__box">
        <div class="global-modal__header">
          <h3 style="color:white; margin:0; display:flex; align-items:center; gap:8px;">⏰ Time's Up!</h3>
          <span class="global-modal__close" onclick="closeGlobalTimeoutModal()">&times;</span>
        </div>
        <div class="global-modal__body">
          <div style="font-size:42px; margin-bottom:12px; animation: globalPulse 1s infinite;">🚨</div>
          <h2 style="margin:0 0 8px; font-size:22px; color:var(--text);">Table: <span id="globalToTableName" style="color:var(--primary);"></span></h2>
          <p style="margin:0 0 24px; font-size:16px; color:var(--muted);">Player: <span id="globalToPlayerName" style="color:var(--text); font-weight:700;"></span></p>
          <div style="display:flex; gap:12px; justify-content:center; margin-top:24px;">
             <button type="button" class="btn btn--primary" id="globalExtendBtn" style="flex:1;">Extend</button>
             <button type="button" class="btn btn--danger" id="globalEndBtn" style="flex:1;">End Game</button>
          </div>
        </div>
      </div>
    </div>

    <script>
      // Auto-dismiss flash notifications after 3 seconds
      document.querySelectorAll('.alert').forEach(el => {
        setTimeout(() => {
          el.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
          el.style.opacity = '0';
          el.style.transform = 'translateY(-10px)';
          setTimeout(() => el.remove(), 400);
        }, 3000);
      });

      // ── Global Timeout Poller ──
      let globalTimeUpAudioCtx = null;
      function playGlobalAlarmSound() {
        try {
          if (!window.AudioContext && !window.webkitAudioContext) return;
          if (!globalTimeUpAudioCtx) globalTimeUpAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
          if (globalTimeUpAudioCtx.state === 'suspended') globalTimeUpAudioCtx.resume();
          
          const osc = globalTimeUpAudioCtx.createOscillator();
          const gainNode = globalTimeUpAudioCtx.createGain();
          osc.connect(gainNode);
          gainNode.connect(globalTimeUpAudioCtx.destination);
          osc.type = 'square';
          
          osc.frequency.setValueAtTime(880, globalTimeUpAudioCtx.currentTime);
          osc.frequency.setValueAtTime(0, globalTimeUpAudioCtx.currentTime + 0.1);
          osc.frequency.setValueAtTime(880, globalTimeUpAudioCtx.currentTime + 0.2);
          osc.frequency.setValueAtTime(0, globalTimeUpAudioCtx.currentTime + 0.3);
          osc.frequency.setValueAtTime(880, globalTimeUpAudioCtx.currentTime + 0.4);
          osc.frequency.setValueAtTime(0, globalTimeUpAudioCtx.currentTime + 0.6);
          
          gainNode.gain.setValueAtTime(0.04, globalTimeUpAudioCtx.currentTime);
          osc.start();
          osc.stop(globalTimeUpAudioCtx.currentTime + 0.65);
        } catch(e) {}
      }

      let globalWarningAudioCtx = null;
      function playGlobalWarningSound() {
        try {
          if (!window.AudioContext && !window.webkitAudioContext) return;
          if (!globalWarningAudioCtx) globalWarningAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
          if (globalWarningAudioCtx.state === 'suspended') globalWarningAudioCtx.resume();
          
          const osc = globalWarningAudioCtx.createOscillator();
          const gainNode = globalWarningAudioCtx.createGain();
          osc.connect(gainNode);
          gainNode.connect(globalWarningAudioCtx.destination);
          
          // Pleasant double ding (Sine wave)
          osc.type = 'sine';
          
          osc.frequency.setValueAtTime(659.25, globalWarningAudioCtx.currentTime); // E5
          gainNode.gain.setValueAtTime(0, globalWarningAudioCtx.currentTime);
          gainNode.gain.linearRampToValueAtTime(0.1, globalWarningAudioCtx.currentTime + 0.05);
          gainNode.gain.exponentialRampToValueAtTime(0.001, globalWarningAudioCtx.currentTime + 0.4);
          
          osc.frequency.setValueAtTime(523.25, globalWarningAudioCtx.currentTime + 0.4); // C5
          gainNode.gain.setValueAtTime(0, globalWarningAudioCtx.currentTime + 0.4);
          gainNode.gain.linearRampToValueAtTime(0.1, globalWarningAudioCtx.currentTime + 0.45);
          gainNode.gain.exponentialRampToValueAtTime(0.001, globalWarningAudioCtx.currentTime + 1.0);
          
          osc.start();
          osc.stop(globalWarningAudioCtx.currentTime + 1.2);
        } catch(e) {}
      }

      function showWarningToast(msg) {
        const c = document.getElementById('flash-container');
        if (!c) return;
        const el = document.createElement('div');
        el.className = 'flash flash--warn';
        el.innerHTML = '<div class="row" style="align-items:center; gap:10px;"><b style="font-size:18px;">⏳</b> <div>' + msg + '</div></div>';
        c.appendChild(el);
        setTimeout(() => {
          el.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
          el.style.opacity = '0';
          el.style.transform = 'translateY(-10px)';
          setTimeout(() => el.remove(), 400);
        }, 8000);
      }

      let globalAlertedSessions = new Set();
      let globalAlertedWarnings = new Set();

      function closeGlobalTimeoutModal() {
        document.getElementById('globalTimeoutModal').style.display = 'none';
      }

      function checkTimeoutsGlobally() {
        fetch('api_check_timeouts.php')
          .then(res => res.json())
          .then(data => {
            if (data.status === 'ok') {
               // Handle warnings 
               if (data.warnings && data.warnings.length > 0) {
                 data.warnings.forEach(w => {
                   if (!globalAlertedWarnings.has(w.session_id)) {
                      globalAlertedWarnings.add(w.session_id);
                      playGlobalWarningSound();
                      showWarningToast('<b>' + w.table_number + '</b> (' + w.player_name + ') has less than 10 mins remaining!');
                   }
                 });
               }

               // Handle expired ones (Alarm modal overrides toast)
               if (data.expired && data.expired.length > 0) {
                 const session = data.expired[0]; // just show the first expired one
                 
                 if (!globalAlertedSessions.has(session.session_id)) {
                   globalAlertedSessions.add(session.session_id);
                   playGlobalAlarmSound();
                   
                   document.getElementById('globalToTableName').textContent = session.table_number + (session.type === 'vip' ? ' (VIP)' : '');
                   document.getElementById('globalToPlayerName').textContent = session.player_name;
                   
                   document.getElementById('globalExtendBtn').onclick = function() {
                     closeGlobalTimeoutModal();
                     if (typeof openExtendModal === 'function') {
                       openExtendModal(session.session_id, session.table_number + (session.type === 'vip' ? ' (VIP)' : ''), parseFloat(session.rate_per_hour), session.scheduled_end_time, session.type);
                     } else {
                       window.location.href = session.type === 'vip' ? 'vip_tables.php' : 'tables.php';
                     }
                   };
                   
                   document.getElementById('globalEndBtn').onclick = function() {
                     closeGlobalTimeoutModal();
                     if (typeof openEndModal === 'function') {
                       openEndModal(session.session_id, session.table_number + (session.type === 'vip' ? ' (VIP)' : ''), session.type);
                     } else {
                       window.location.href = session.type === 'vip' ? 'vip_tables.php' : 'tables.php';
                     }
                   };
                   
                   document.getElementById('globalTimeoutModal').style.display = 'flex';
                 }
               }
            }
          })
          .catch(e => console.error("Global polling failed", e));
      }
      
      setInterval(checkTimeoutsGlobally, 3000);
    </script>
    <script src="assets/js/script.js"></script>
  </body>
  </html>
  <?php
}

