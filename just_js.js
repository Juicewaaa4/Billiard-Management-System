
const busyCustomers = <?php echo json_encode($busyCustomerIds); ?>;

  <?php if ($prefillReservation): ?>
  // Auto-open Start Game for Reservation
  document.addEventListener('DOMContentLoaded', () => {
    const r = <?php echo json_encode($prefillReservation); ?>;
    const rate = parseFloat(r.rate_per_hour);
    const maxH = parseFloat(r.duration_hours);
    const isKtv = (r.table_type === 'ktv');
    
    // Set hidden reservation ID
    document.getElementById('sf_reservation_id').value = r.id;

    // Use openStartModal
    openStartModal(r.table_id, r.table_number, rate, 99, isKtv); // 99 means no max hours restriction
    
    // Pre-fill walk-in name
    const nInput = document.getElementById('newCustName');
    if (nInput) {
        nInput.value = r.customer_name;
        nInput.disabled = true; // customer name is fixed for reservation
    }
    
    // Disable customer select
    const sel = document.getElementById('startCustomer');
    if (sel) {
        sel.disabled = true;
    }

    // Show reservation badge
    const resWarning = document.getElementById('resWarning');
    if (resWarning) {
        resWarning.style.display = 'block';
        const dp = parseFloat(r.down_payment) || 0;
        resWarning.querySelector('span').textContent = '📅 Reservation active. Down Payment: ₱' + dp.toFixed(2) + ' (will be deducted)';
        resWarning.querySelector('span').style.color = '#fff';
        resWarning.style.background = 'rgba(34, 197, 94, 0.2)';
        resWarning.style.border = '1px solid rgba(34, 197, 94, 0.4)';
    }

    // Pre-select hours
    startHours = maxH;
    document.getElementById('startHours').textContent = startHours + 'h';
    
    // Hide hour buttons that don't match exactly
    document.querySelectorAll('#hourButtons .hour-btn').forEach(b => {
      if (parseFloat(b.dataset.hours) === startHours) {
        b.classList.add('selected');
        b.disabled = false;
        b.style.pointerEvents = 'none'; // Lock selection
      } else {
        b.style.display = 'none';
      }
    });

    // Compute total & require payment minus down payment
    const total = startRate * startHours;
    document.getElementById('startTotal').textContent = '₱' + total.toFixed(2);
    
    const dpVal = parseFloat(r.down_payment) || 0;
    const req = Math.max(0, total - dpVal);
    const startPayment = document.getElementById('startPayment');
    startPayment.placeholder = 'Amount to pay: ₱' + req.toFixed(2);
    
    // Override calculate change logic to factor down payment
    startPayment.removeEventListener('input', updateStartChange);
    const resUpdateChange = function() {
      const pay = parseFloat(document.getElementById('startPayment').value) || 0;
      const t = startRate * startHours;
      const requiredPay = Math.max(0, t - dpVal);
      const change = pay - requiredPay;
      const el = document.getElementById('startChange');
      el.textContent = '₱' + change.toFixed(2);
      el.style.color = change >= 0 ? 'var(--success)' : 'var(--danger)';
    };
    startPayment.addEventListener('input', resUpdateChange);
    resUpdateChange();
  });
  }


// ── Countdown Timers ──


document.querySelectorAll('[data-countdown]').forEach(el => {
  const endTime = new Date(el.dataset.countdown);
  function tick() {
    const now = new Date();
    let diff = Math.floor((endTime - now) / 1000);
      if (diff <= 0) {
        el.textContent = "TIME'S UP";
        el.className = 'badge badge--danger';
        return;
      }
    const h = Math.floor(diff / 3600);
    const m = Math.floor((diff % 3600) / 60);
    const s = diff % 60;
    el.textContent = String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    setTimeout(tick, 1000);
  }
  tick();
});

// ── Start Game Modal ──
let startTableId = 0, startRate = 0, startHours = 0;

function openStartModal(tableId, tableName, rate, maxHours, isKtv = false) {
  startTableId = tableId; startRate = rate; startHours = 0;
  document.getElementById('startTableName').textContent = tableName;
  document.getElementById('startRate').textContent = '₱' + rate.toFixed(2) + '/hr';
  document.getElementById('startHours').textContent = '0';
  document.getElementById('startTotal').textContent = '₱0.00';
  document.getElementById('startPayment').value = '';
  document.getElementById('startChange').textContent = '₱0.00';
  document.getElementById('startCustomer').value = '';
  document.getElementById('newCustName').value = '';

  // Dynamic time blocking: disable hour buttons exceeding maxHours
  const resWarning = document.getElementById('resWarning');
  if (resWarning) {
      if (maxHours > 0 && maxHours < 99) {
        resWarning.style.display = 'block';
        resWarning.querySelector('span').textContent = 'Max ' + maxHours + 'hr – table has an upcoming reservation';
      } else {
        resWarning.style.display = 'none';
      }
  }

  document.querySelectorAll('#hourButtons .hour-btn').forEach(b => {
    b.classList.remove('selected');
    const h = parseFloat(b.dataset.hours);
    if (maxHours > 0 && maxHours < 99 && h > maxHours) {
      b.disabled = true;
      b.style.opacity = '0.3';
      b.style.pointerEvents = 'none';
    } else {
      b.disabled = false;
      b.style.opacity = '1';
      b.style.pointerEvents = '';
    }
  });


  const btn30 = document.querySelector('#hourButtons .hour-btn[data-hours="0.5"]');
  if (isKtv) {
    document.getElementById('karaokeOptionWrapper').style.display = 'none';
    document.getElementById('sf_karaoke').value = '1';
    if (btn30) btn30.style.display = 'none'; // Lock out 30m for KTV start
  } else {
    document.getElementById('karaokeOptionWrapper').style.display = 'block';
    document.getElementById('sf_karaoke').value = '0';
    document.querySelector('input[name="temp_karaoke"][value="0"]').checked = true;
    if (btn30) btn30.style.display = 'inline-block';
  }

  document.querySelectorAll('#hourButtons .hour-btn').forEach(b => b.classList.remove('selected'));
  document.getElementById('startModal').style.display = 'flex';
}
function closeStartModal() { document.getElementById('startModal').style.display = 'none'; }

document.querySelectorAll('#hourButtons .hour-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#hourButtons .hour-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    startHours = parseFloat(btn.dataset.hours);
    document.getElementById('startHours').textContent = startHours + 'h';
    const total = startRate * startHours;
    document.getElementById('startTotal').textContent = '₱' + total.toFixed(2);
    updateStartChange();
  });
});

document.getElementById('startPayment').addEventListener('input', updateStartChange);
function updateStartChange() {
  const pay = parseFloat(document.getElementById('startPayment').value) || 0;
  const total = startRate * startHours;
  const change = pay - total;
  const el = document.getElementById('startChange');
  el.textContent = '₱' + change.toFixed(2);
  el.style.color = change >= 0 ? 'var(--success)' : 'var(--danger)';
}

function submitStart() {
  const custId = parseInt(document.getElementById('startCustomer').value);
  if (custId && busyCustomers.includes(custId)) {
    showWarnModal('👀 Customer Busy', 'This customer is already playing at another table.');
    return;
  }

  if (startHours <= 0) { showWarnModal('⏰ Select Hours', 'Please select how many hours before starting the game.'); return; }
  const total = startRate * startHours;
  const pay = parseFloat(document.getElementById('startPayment').value) || 0;
  if (pay < total - 0.01) { showWarnModal('💰 Insufficient Payment', 'Payment is not enough. Required: ₱' + total.toFixed(2)); return; }

  document.getElementById('sf_table_id').value = startTableId;
  document.getElementById('sf_customer_id').value = document.getElementById('startCustomer').value;

  document.getElementById('sf_hours').value = startHours;
  document.getElementById('sf_payment').value = pay;
  document.getElementById('startForm').submit();
}

// ── Extend Game Modal ──
let extendSessionId = 0, extendRate = 0, extendHours = 0, extendInterval = null;

function openExtendModal(sessionId, tableName, rate, scheduledEnd) {
  extendSessionId = sessionId; extendRate = rate; extendHours = 0;
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

// ── End Game Modal ──
let endSessionId = 0;
function openEndModal(sessionId, tableName) {
  endSessionId = sessionId;
  document.getElementById('endTableName').textContent = tableName;
  document.getElementById('endModal').style.display = 'flex';
}
function closeEndModal() { document.getElementById('endModal').style.display = 'none'; }
function submitEnd() {
  document.getElementById('endf_session_id').value = endSessionId;
  document.getElementById('endForm').submit();
}

// Close modals on backdrop click
['startModal','extendModal','endModal'].forEach(id => {
  document.getElementById(id).addEventListener('click', e => {
    if (e.target.id === id) {
      if (id === 'startModal') closeStartModal();
      else if (id === 'extendModal') closeExtendModal();
      else closeEndModal();
    }
  });
});

// ── Register Customer via AJAX ──
function registerCustomer(selectId) {
  const nameInput = document.getElementById('newCustName');
  const name = nameInput.value.trim();
  if (!name) { alert('Please enter a customer name.'); nameInput.focus(); return; }

  const btn = event.target;
  btn.disabled = true;
  btn.textContent = '...';

  fetch('api/api_customers.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'action=add_customer&name=' + encodeURIComponent(name)
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false;
    btn.textContent = '+ Add';
    if (data.error) {
      if (data.error === 'duplicate') {
        showDuplicateModal(data.existing_name);
      } else {
        alert(data.error);
      }
      return;
    }
    const c = data.customer;
    const select = document.getElementById(selectId);
    const opt = document.createElement('option');
    opt.value = c.id;
    opt.textContent = c.name;
    select.appendChild(opt);
    select.value = c.id;
    nameInput.value = '';
    nameInput.placeholder = '✓ ' + c.name + ' added!';
    setTimeout(() => { nameInput.placeholder = 'Name'; }, 2000);
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = '+ Add';
    alert('Failed to register. Check connection.');
  });
}

// ── Warning Modal ──
function showWarnModal(title, msg) {
  document.getElementById('warnTitle').textContent = title;
  document.getElementById('warnMsg').textContent = msg;
  document.getElementById('warnModal').style.display = 'flex';
}
function closeWarnModal() { document.getElementById('warnModal').style.display = 'none'; }

// ── Duplicate Customer Modal ──
function showDuplicateModal(existingName) {
  document.getElementById('dupName').textContent = existingName;
  document.getElementById('dupModal').style.display = 'flex';
}
function closeDupModal() { document.getElementById('dupModal').style.display = 'none'; }

// Force reload on back navigation
window.onpageshow = function(event) { if (event.persisted) window.location.reload(); };
