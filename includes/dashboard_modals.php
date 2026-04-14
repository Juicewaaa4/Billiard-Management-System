<!-- Extend Game Modal -->
<div id="extendModal" class="game-modal" style="display:none;">
  <div class="game-modal__box">
    <div class="game-modal__header">
      <h3>⏱️ Extend Time — <span id="extendTableName"></span></h3>
      <span class="game-modal__close" onclick="closeExtendModal()">&times;</span>
    </div>
    <div class="game-modal__body">
      <div id="extendRemaining" style="text-align:center; margin-bottom:14px; font-size:14px; color:var(--muted);">
        Time remaining: <strong id="extendTimeLeft">--:--:--</strong>
      </div>

      <label style="display:block; margin:0 0 6px; color:var(--muted); font-size:12px; text-transform:uppercase;">Add Hours</label>
      <div class="hour-buttons" id="extendHourButtons">
        <button type="button" class="hour-btn" data-hours="0.5">30 min</button>
        <button type="button" class="hour-btn" data-hours="1">1 hr</button>
        <button type="button" class="hour-btn" data-hours="2">2 hrs</button>
        <button type="button" class="hour-btn" data-hours="3">3 hrs</button>
      </div>

      <div class="game-modal__summary">
        <div class="game-modal__row">
          <div class="game-modal__field"><label>Rate</label><div id="extendRate" class="val">₱0.00/hr</div></div>
          <div class="game-modal__field"><label>Extension Cost</label><div id="extendCost" class="val total">₱0.00</div></div>
        </div>
      </div>

      <div class="game-modal__row" style="margin-top:14px;">
        <div class="game-modal__field">
          <label>Payment (₱)</label>
          <input type="number" id="extendPayment" step="0.01" min="0" placeholder="Cash received">
        </div>
        <div class="game-modal__field">
          <label>Change</label>
          <div id="extendChange" class="val" style="font-weight:700; color:var(--success);">₱0.00</div>
        </div>
      </div>

      <div class="game-modal__footer">
        <button type="button" class="btn btn--ghost" onclick="closeExtendModal()">Cancel</button>
        <button type="button" class="btn btn--primary" id="confirmExtendBtn" onclick="submitExtend()">Confirm Extension</button>
      </div>
    </div>
  </div>
</div>

<form id="extendForm" method="post" style="display:none;" action="tables.php">
  <input type="hidden" name="return_url" value="dashboard.php">
  <input type="hidden" name="action" value="extend_game">
  <input type="hidden" name="session_id" id="ef_session_id">
  <input type="hidden" name="hours" id="ef_hours">
  <input type="hidden" name="payment" id="ef_payment">
</form>

<form id="endForm" method="post" style="display:none;" action="tables.php">
  <input type="hidden" name="return_url" value="dashboard.php">
  <input type="hidden" name="action" value="end_game">
  <input type="hidden" name="session_id" id="endf_session_id">
</form>

<!-- End Game Modal -->
<div id="endModal" class="game-modal" style="display:none;">
  <div class="game-modal__box" style="max-width:400px;">
    <div class="game-modal__header">
      <h3>🛑 End Game — <span id="endTableName"></span></h3>
      <span class="game-modal__close" onclick="closeEndModal()">&times;</span>
    </div>
    <div class="game-modal__body" style="text-align:center; padding:28px 24px;">
      <div style="width:56px; height:56px; margin:0 auto 16px; border-radius:50%; background:rgba(239,68,68,0.12); display:flex; align-items:center; justify-content:center; font-size:28px;">🎱</div>
      <p style="color:var(--text); font-size:15px; margin:0 0 8px;">Are you sure you want to <strong>end this game</strong> and free the table?</p>
      <p style="color:var(--muted); font-size:13px; margin:0;">Payment has already been collected upfront.</p>
      <div class="game-modal__footer" style="justify-content:center; margin-top:24px;">
        <button type="button" class="btn btn--ghost" onclick="closeEndModal()">Cancel</button>
        <button type="button" class="btn btn--danger" onclick="submitEnd()">End Game</button>
      </div>
    </div>
  </div>
</div>

<!-- Warning Modal -->
<div id="warnModal" class="game-modal" style="display:none; z-index: 100000;" onclick="if(event.target.id==='warnModal')closeWarnModal()">
  <div class="game-modal__box" style="max-width:380px;">
    <div class="game-modal__header">
      <h3 id="warnTitle">Warning</h3>
      <span class="game-modal__close" onclick="closeWarnModal()">&times;</span>
    </div>
    <div class="game-modal__body" style="text-align:center; padding:28px 24px;">
      <div style="width:56px; height:56px; margin:0 auto 16px; border-radius:50%; background:rgba(245,158,11,0.12); display:flex; align-items:center; justify-content:center; font-size:28px;">⚠️</div>
      <p id="warnMsg" style="color:var(--text); font-size:15px; margin:0;"></p>
      <div style="margin-top:20px;">
        <button type="button" class="btn btn--primary" onclick="closeWarnModal()">OK</button>
      </div>
    </div>
  </div>
</div>

<style>
.game-modal { position:fixed; inset:0; z-index:1000; background:rgba(0,0,0,0.35); display:flex; align-items:center; justify-content:center; }
.game-modal__box { background:#fff; border:1px solid var(--border); border-radius:14px; width:95%; max-width:520px; box-shadow:0 8px 32px rgba(0,0,0,.1); animation:modalIn 0.2s ease-out; }
.game-modal__header { display:flex; justify-content:space-between; align-items:center; padding:18px 20px; border-bottom:1px solid var(--border); }
.game-modal__header h3 { margin:0; font-size:16px; color:var(--text); }
.game-modal__close { color:var(--muted); font-size:24px; cursor:pointer; line-height:1; }
.game-modal__close:hover { color:var(--text); }
.game-modal__body { padding:20px; }
.game-modal__row { display:flex; gap:12px; }
.game-modal__field { flex:1; }
.game-modal__field label { display:block; font-size:12px; color:var(--muted); margin-bottom:4px; text-transform:uppercase; }
.game-modal__field .val { font-weight:600; font-size:16px; color:var(--text); padding:6px 0; }
.game-modal__field .val.total { font-size:20px; color:var(--primary); }
.game-modal__summary { background:var(--bg-secondary, var(--surface2)); padding:14px; border-radius:8px; margin-top:14px; }
.game-modal__footer { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; padding-top:14px; border-top:1px solid var(--border); }

.hour-buttons { display:flex; gap:8px; flex-wrap:wrap; }
.hour-btn { padding:8px 16px; border:1px solid var(--border); background:transparent; color:var(--text); border-radius:6px; cursor:pointer; font-size:14px; transition:all 0.15s; }
.hour-btn:hover { border-color:var(--primary); color:var(--primary); }
.hour-btn.selected { background:var(--primary); color:#fff; border-color:var(--primary); }

@keyframes modalIn { from { opacity:0; transform:scale(0.92) translateY(10px); } to { opacity:1; transform:scale(1) translateY(0); } }
</style>

<script>
// Dashboard Modals specifically targeting correct form action endpoints
let extendSessionId = 0, extendRate = 0, extendHours = 0, extendInterval = null, currentTableType = 'regular';

function setFormActions(type) {
  let endpoint = type === 'vip' ? 'vip_tables.php' : 'tables.php';
  document.getElementById('extendForm').action = endpoint;
  document.getElementById('endForm').action = endpoint;
}

function openExtendModal(sessionId, tableName, rate, scheduledEnd, type) {
  extendSessionId = sessionId; extendRate = rate; extendHours = 0; currentTableType = type;
  setFormActions(type);
  document.getElementById('extendTableName').textContent = tableName;
  document.getElementById('extendRate').textContent = '₱' + rate.toFixed(2) + '/hr';
  document.getElementById('extendCost').textContent = '₱0.00';
  document.getElementById('extendPayment').value = '';
  document.getElementById('extendChange').textContent = '₱0.00';
  document.querySelectorAll('#extendHourButtons .hour-btn').forEach(b => b.classList.remove('selected'));

  if (scheduledEnd) {
    const end = new Date(scheduledEnd);
    function tickExtend() {
      const diff = Math.max(0, Math.floor((end - new Date()) / 1000));
      const h = Math.floor(diff / 3600), m = Math.floor((diff % 3600) / 60), s = diff % 60;
      document.getElementById('extendTimeLeft').textContent =
        diff <= 0 ? "TIME'S UP" : String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    }
    tickExtend();
    extendInterval = setInterval(tickExtend, 1000);
  } else {
    document.getElementById('extendTimeLeft').textContent = '--:--:--';
  }
  document.getElementById('extendModal').style.display = 'flex';
}
function closeExtendModal() {
  document.getElementById('extendModal').style.display = 'none';
  if (extendInterval) { clearInterval(extendInterval); extendInterval = null; }
}

document.querySelectorAll('#extendHourButtons .hour-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#extendHourButtons .hour-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    extendHours = parseFloat(btn.dataset.hours);
    const cost = extendRate * extendHours;
    document.getElementById('extendCost').textContent = '₱' + cost.toFixed(2);
    updateExtendChange();
  });
});

document.getElementById('extendPayment').addEventListener('input', updateExtendChange);
function updateExtendChange() {
  const pay = parseFloat(document.getElementById('extendPayment').value) || 0;
  const cost = extendRate * extendHours;
  const change = pay - cost;
  const el = document.getElementById('extendChange');
  el.textContent = '₱' + change.toFixed(2);
  el.style.color = change >= 0 ? 'var(--success)' : 'var(--danger)';
}

function submitExtend() {
  if (extendHours <= 0) { showWarnModal('⏰ Select Hours', 'Please select how many hours to extend.'); return; }
  const cost = extendRate * extendHours;
  const pay = parseFloat(document.getElementById('extendPayment').value) || 0;
  if (pay < cost - 0.01) { showWarnModal('💰 Insufficient Payment', 'Payment is not enough. Required: ₱' + cost.toFixed(2)); return; }

  document.getElementById('ef_session_id').value = extendSessionId;
  document.getElementById('ef_hours').value = extendHours;
  document.getElementById('ef_payment').value = pay;
  document.getElementById('extendForm').submit();
}

let endSessionId = 0;
function openEndModal(sessionId, tableName, type) {
  endSessionId = sessionId;
  setFormActions(type);
  document.getElementById('endTableName').textContent = tableName;
  document.getElementById('endModal').style.display = 'flex';
}
function closeEndModal() { document.getElementById('endModal').style.display = 'none'; }
function submitEnd() {
  document.getElementById('endf_session_id').value = endSessionId;
  document.getElementById('endForm').submit();
}

// Close modals on backdrop click
['extendModal','endModal'].forEach(id => {
  document.getElementById(id).addEventListener('click', e => {
    if (e.target.id === id) {
      if (id === 'extendModal') closeExtendModal();
      else closeEndModal();
    }
  });
});

  function showWarnModal(title, msg) {
    document.getElementById('warnTitle').textContent = title;
    document.getElementById('warnMsg').textContent = msg;
    document.getElementById('warnModal').style.display = 'flex';
  }
  function closeWarnModal() { document.getElementById('warnModal').style.display = 'none'; }
</script>

