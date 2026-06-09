<!-- ═══ TABLE PICKER MODAL ═══ -->
<div id="pickerModal" class="game-modal" style="display:none;" onclick="if(event.target.id==='pickerModal')closePickerModal()">
  <div class="game-modal__box" style="max-width:500px;">
    <div class="game-modal__header" id="pickerHeader">
      <h3 id="pickerTitle">Select Table</h3>
      <span class="game-modal__close" onclick="closePickerModal()">&times;</span>
    </div>
    <div class="game-modal__body" style="max-height:400px; overflow-y:auto;">
      <div id="pickerList" style="display:flex; flex-direction:column; gap:8px;"></div>
      <div id="pickerEmpty" style="display:none; text-align:center; padding:30px; color:var(--muted);">
        <div style="font-size:30px; margin-bottom:8px; opacity:0.5;">🚫</div>
        <div>No available tables right now.</div>
      </div>
    </div>
  </div>
</div>

<!-- ═══ START GAME MODAL (Regular/VIP/KTV) ═══ -->
<div id="dshStartModal" class="game-modal" style="display:none;" onclick="if(event.target.id==='dshStartModal')closeDshStartModal()">
  <div class="game-modal__box">
    <div class="game-modal__header" id="dshStartHeader">
      <h3 id="dshStartTitle">🎱 Start Game</h3>
      <span class="game-modal__close" onclick="closeDshStartModal()">&times;</span>
    </div>
    <div class="game-modal__body">
      <form id="dshStartForm" method="post" action="tables.php">
        <input type="hidden" name="return_url" value="dashboard.php">
        <input type="hidden" name="action" value="start_game">
        <input type="hidden" name="table_id" id="dshTableId">
        <input type="hidden" name="is_promo" id="dshIsPromo" value="0">

        <div class="game-modal__row">
          <div class="game-modal__field" style="flex:2;">
            <label>Customer</label>
            <select id="dshCustomer" name="customer_id" style="width:100%;">
              <option value="">Walk-in</option>
              <?php foreach ($cashierCustomers as $c): ?>
                <option value="<?php echo (int)$c['id']; ?>"><?php echo h($c['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="game-modal__field" style="flex:1;">
            <label>Walk-in Name</label>
            <input type="text" name="walk_in_name" id="dshWalkIn" placeholder="Name">
          </div>
        </div>

        <!-- Promo Toggle (Regular only) -->
        <div id="dshPromoWrap" style="margin:14px 0; display:none;">
          <label style="border:1px solid var(--border); padding:10px; border-radius:8px; display:flex; align-items:center; gap:8px; cursor:pointer; background:var(--surface2);">
            <input type="checkbox" id="dshPromoToggle" onchange="dshTogglePromo()">
            <span style="font-size:13px; font-weight:600; color:#38bdf8;">🏷️ Apply 50% Promo (8 AM - 12 NN)</span>
          </label>
        </div>

        <!-- Karaoke Toggle (VIP/KTV) -->
        <div id="dshKaraokeWrap" style="margin:14px 0; display:none;">
          <label style="border:1px solid var(--border); padding:10px; border-radius:8px; display:flex; align-items:center; gap:8px; cursor:pointer; background:var(--surface2);">
            <input type="checkbox" name="karaoke_included" value="1" id="dshKaraokeToggle">
            <span style="font-size:13px; font-weight:600; color:#c084fc;">🎤 Include Karaoke</span>
          </label>
        </div>

        <label style="display:block; margin:14px 0 6px; color:var(--muted); font-size:12px; text-transform:uppercase;">Select Hours</label>
        <div class="hour-buttons" id="dshHourButtons">
          <button type="button" class="hour-btn" data-hours="0.5">30 min</button>
          <button type="button" class="hour-btn" data-hours="1">1 hr</button>
          <button type="button" class="hour-btn" data-hours="2">2 hrs</button>
          <button type="button" class="hour-btn" data-hours="3">3 hrs</button>
          <button type="button" class="hour-btn" data-hours="4">4 hrs</button>
          <button type="button" class="hour-btn" data-hours="5">5 hrs</button>
        </div>

        <div class="game-modal__summary">
          <div class="game-modal__row">
            <div class="game-modal__field"><label>Rate</label><div id="dshRate" class="val">₱0.00/hr</div></div>
            <div class="game-modal__field"><label>Total</label><div id="dshTotal" class="val total">₱0.00</div></div>
          </div>
        </div>

        <div class="game-modal__row" style="margin-top:14px;">
          <div class="game-modal__field">
            <label>Payment (₱)</label>
            <input type="number" name="payment" id="dshPayment" step="0.01" min="0" placeholder="Cash received">
          </div>
          <div class="game-modal__field">
            <label>Change</label>
            <div id="dshChange" class="val" style="font-weight:700; color:var(--success);">₱0.00</div>
          </div>
        </div>
        <input type="hidden" name="hours" id="dshHoursInput">

        <div class="game-modal__footer">
          <button type="button" class="btn btn--ghost" onclick="closeDshStartModal()">Cancel</button>
          <button type="submit" class="btn btn--primary" id="dshConfirmBtn">Start Game</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══ KUBO RENTAL MODAL ═══ -->
<div id="dshKuboModal" class="game-modal" style="display:none;" onclick="if(event.target.id==='dshKuboModal')document.getElementById('dshKuboModal').style.display='none'">
  <div class="game-modal__box" style="max-width:420px;">
    <div class="game-modal__header" style="background:var(--surface2);">
      <h3 style="color:var(--text);">🏡 Rent <span id="dshKuboName"></span></h3>
      <span class="game-modal__close" onclick="document.getElementById('dshKuboModal').style.display='none'">&times;</span>
    </div>
    <div class="game-modal__body">
      <form method="post" action="kubo.php">
        <input type="hidden" name="return_url" value="dashboard.php">
        <input type="hidden" name="action" value="start_kubo">
        <input type="hidden" name="table_id" id="dshKuboId">
        <div class="game-modal__field" style="margin-bottom:16px;">
          <label>Customer Name *</label>
          <input type="text" name="customer_name" required placeholder="Enter customer name" style="width:100%;">
        </div>
        <div class="game-modal__field" style="margin-bottom:20px;">
          <label>Payment Amount (₱) *</label>
          <input type="number" name="payment_amount" step="0.01" min="0" required style="width:100%; font-weight:700; font-size:16px; color:#22c55e;">
        </div>
        <div class="game-modal__footer">
          <button type="button" class="btn btn--ghost" onclick="document.getElementById('dshKuboModal').style.display='none'">Cancel</button>
          <button type="submit" class="btn btn--primary" style="background:#22c55e; border-color:#22c55e;">Confirm Rental</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══ END KUBO MODAL ═══ -->
<div id="endKuboModal" class="game-modal" style="display:none;" onclick="if(event.target.id==='endKuboModal')document.getElementById('endKuboModal').style.display='none'">
  <div class="game-modal__box" style="max-width:400px;">
    <div class="game-modal__header" style="background:#f87171;">
      <h3 style="color:white;">🛑 End Kubo Rental — <span id="endKuboName"></span></h3>
      <span class="game-modal__close" style="color:white;" onclick="document.getElementById('endKuboModal').style.display='none'">&times;</span>
    </div>
    <div class="game-modal__body" style="text-align:center;">
      <p style="margin:0 0 16px;">Are you sure you want to end this Kubo rental?</p>
      <form method="post" action="kubo.php">
        <input type="hidden" name="return_url" value="dashboard.php">
        <input type="hidden" name="action" value="end_kubo">
        <input type="hidden" name="rental_id" id="endKuboId">
        <div class="game-modal__footer" style="justify-content:center;">
          <button type="button" class="btn btn--ghost" onclick="document.getElementById('endKuboModal').style.display='none'">Cancel</button>
          <button type="submit" class="btn btn--danger">End Rental</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══ VOID KUBO MODAL ═══ -->
<div id="voidKuboModal" class="game-modal" style="display:none;" onclick="if(event.target.id==='voidKuboModal')document.getElementById('voidKuboModal').style.display='none'">
  <div class="game-modal__box" style="max-width:400px;">
    <div class="game-modal__header" style="background:#ef4444;">
      <h3 style="color:white;">🗑️ Void Kubo Rental — <span id="voidKuboName"></span></h3>
      <span class="game-modal__close" style="color:white;" onclick="document.getElementById('voidKuboModal').style.display='none'">&times;</span>
    </div>
    <div class="game-modal__body" style="text-align:center;">
      <p style="margin:0 0 16px; color:var(--muted); font-size:13px;">This will cancel the rent. This action will not be counted in the sales report.</p>
      <form method="post" action="kubo.php" style="text-align:left;">
        <input type="hidden" name="return_url" value="dashboard.php">
        <input type="hidden" name="action" value="void_kubo">
        <input type="hidden" name="rental_id" id="voidKuboId">
        
        <label style="display:block; font-size:12px; color:var(--muted); margin-bottom:6px; text-transform:uppercase;">Reason for Voiding *</label>
        <input type="text" name="void_reason" required placeholder="e.g. Test, Customer Cancelled" style="width:100%; border:1px solid var(--border); background:var(--surface2); color:var(--text); border-radius:8px; padding:10px; font-size:14px; margin-bottom:20px;" id="voidKuboReasonInput">
        
        <div class="game-modal__footer" style="justify-content:center; margin-top:0;">
          <button type="button" class="btn btn--ghost" onclick="document.getElementById('voidKuboModal').style.display='none'">Cancel</button>
          <button type="submit" class="btn btn--danger">Void Rental</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══ START KARAOKE (ON KUBO) MODAL ═══ -->
<div id="startKaraokeModal" class="game-modal" style="display:none;" onclick="if(event.target.id==='startKaraokeModal')document.getElementById('startKaraokeModal').style.display='none'">
  <div class="game-modal__box" style="max-width:420px;">
    <div class="game-modal__header" style="background:#faf5ff; border-bottom:1px solid var(--border);">
      <h3 style="color:#a855f7; margin:0;">🎤 Add Karaoke — <span id="skTableName"></span></h3>
      <span class="game-modal__close" onclick="document.getElementById('startKaraokeModal').style.display='none'">&times;</span>
    </div>
    <div class="game-modal__body">
      <form id="startKaraokeForm" method="post" action="kubo.php">
        <input type="hidden" name="return_url" value="dashboard.php">
        <input type="hidden" name="action" value="start_karaoke">
        <input type="hidden" name="table_id" id="skTableId">
        <input type="hidden" name="customer_name" id="skCustName">

        <label style="display:block; margin:0 0 6px; color:var(--muted); font-size:12px; text-transform:uppercase;">Add Hours</label>
        <div class="hour-buttons" id="skHourButtons" style="margin-bottom:14px;">
          <button type="button" class="hour-btn" data-hours="0.5">30 min</button>
          <button type="button" class="hour-btn" data-hours="1">1 hr</button>
          <button type="button" class="hour-btn" data-hours="2">2 hrs</button>
          <button type="button" class="hour-btn" data-hours="3">3 hrs</button>
          <button type="button" class="hour-btn" data-hours="4">4 hrs</button>
        </div>
        <input type="hidden" name="hours" id="skHoursInput" required>

        <div class="game-modal__summary">
          <div class="game-modal__row">
            <div class="game-modal__field"><label>Karaoke Rate</label><div id="skRate" class="val">₱100.00/hr</div></div>
            <div class="game-modal__field"><label>Total Cost</label><div id="skCost" class="val total">₱0.00</div></div>
          </div>
        </div>

        <div class="game-modal__row" style="margin-top:14px;">
          <div class="game-modal__field">
            <label>Payment (₱) *</label>
            <input type="number" name="payment" id="skPayment" step="0.01" min="0" required placeholder="Cash received">
          </div>
          <div class="game-modal__field">
            <label>Change</label>
            <div id="skChange" class="val" style="font-weight:700; color:var(--success);">₱0.00</div>
          </div>
        </div>

        <div class="game-modal__footer">
          <button type="button" class="btn btn--ghost" onclick="document.getElementById('startKaraokeModal').style.display='none'">Cancel</button>
          <button type="submit" class="btn" style="background:#a855f7; color:white; border:none; padding:8px 18px; font-weight:600;">Start Karaoke</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══ RESERVATIONS MODAL ═══ -->
<div id="dshResModal" class="game-modal" style="display:none;" onclick="if(event.target.id==='dshResModal')document.getElementById('dshResModal').style.display='none'">
  <div class="game-modal__box" style="max-width:650px;">
    <div class="game-modal__header" style="background:#6366f1;">
      <h3 style="color:white; flex:1;">📅 Pending Reservations</h3>
      <button type="button" class="btn btn--ok" onclick="openDshAddResModal()" style="margin-right:16px; background:rgba(255,255,255,0.2); color:white; border:none; padding:4px 10px; font-size:12px;">+ Add</button>
      <span class="game-modal__close" style="color:white;" onclick="document.getElementById('dshResModal').style.display='none'">&times;</span>
    </div>
    <div class="game-modal__body" style="max-height:420px; overflow-y:auto; padding:12px;">
      <?php if (empty($cashierReservations)): ?>
        <div style="text-align:center; padding:30px; color:var(--muted);">
          <div style="font-size:30px; margin-bottom:8px;">📭</div>
          <div>No pending reservations.</div>
        </div>
      <?php else: ?>
        <table class="table" style="font-size:12px; margin:0;">
          <thead>
            <tr>
              <th>Date</th><th>Time</th><th>Table</th><th>Customer</th><th>Hours</th><th>DP</th><th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cashierReservations as $rv):
              $resDate = date('M j', strtotime($rv['reservation_date']));
              $resTime = date('h:i A', strtotime($rv['start_time']));
              $isToday = ($rv['reservation_date'] === date('Y-m-d'));
              $canStart = $isToday;
              $endpoint = in_array($rv['table_type'], ['vip','ktv']) ? 'vip_tables.php' : 'tables.php';
              $rate = (float)$rv['rate_per_hour'];
              $hours = (float)$rv['duration_hours'];
              $dp = (float)$rv['down_payment'];
              $total = $rate * $hours;
              $reqPay = max(0, $total - $dp);
            ?>
              <tr>
                <td><?php echo $resDate; ?><?php if($isToday): ?> <span style="color:#22c55e; font-weight:700;">Today</span><?php endif; ?></td>
                <td style="font-weight:600;"><?php echo $resTime; ?></td>
                <td><strong><?php echo h($rv['table_number']); ?></strong>
                  <?php if($rv['table_type']==='vip'): ?><span class="badge badge--vip" style="font-size:9px;">VIP</span><?php endif; ?>
                  <?php if($rv['table_type']==='ktv'): ?><span class="badge" style="font-size:9px; background:rgba(168,85,247,0.2); color:#c084fc;">KTV</span><?php endif; ?>
                </td>
                <td><?php echo h($rv['customer_name']); ?></td>
                <td><?php echo $hours; ?>h</td>
                <td><?php echo $dp > 0 ? '₱'.number_format($dp,2) : '—'; ?></td>
                <td style="display:flex; gap:4px; border:none; padding-top:6px; justify-content:flex-end;">
                  <?php if ($canStart): ?>
                    <button type="button" onclick="openDshStartResModal(<?php echo (int)$rv['id']; ?>, <?php echo (int)$rv['table_id']; ?>, '<?php echo $endpoint; ?>', '<?php echo h($rv['table_number']); ?>', '<?php echo h(addslashes($rv['customer_name'])); ?>', <?php echo $rate; ?>, <?php echo $hours; ?>, <?php echo $dp; ?>)" class="btn" style="padding:4px 8px; font-size:11px; background:#22c55e; color:white; border:none;" title="Start Reservation">▶ Start</button>
                  <?php else: ?>
                    <span style="font-size:11px; color:var(--muted); margin-right:6px;">Upcoming</span>
                  <?php endif; ?>
                  <button type="button" onclick="openDshCancelModal(<?php echo (int)$rv['id']; ?>, '<?php echo h(addslashes($rv['customer_name'])); ?>')" class="btn btn--danger" style="padding:4px 8px; font-size:11px; background:#ef4444; color:white; border:none;" title="Cancel Reservation">✖ Cancel</button>
                  <button type="button" onclick="openDshNoShowModal(<?php echo (int)$rv['id']; ?>, '<?php echo h(addslashes($rv['customer_name'])); ?>')" class="btn btn--ok" style="padding:4px 8px; font-size:11px; background:#f59e0b; color:white; border:none;" title="Mark as No Show">🫥 No Show</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ═══ START RESERVATION MODAL ═══ -->
<div id="startResModal" class="game-modal" style="display:none;" onclick="if(event.target.id==='startResModal')document.getElementById('startResModal').style.display='none'">
  <div class="game-modal__box" style="max-width:420px;">
    <div class="game-modal__header" style="background:#22c55e;">
      <h3 style="color:white;">▶ Start Reservation — <span id="srTableName"></span></h3>
      <span class="game-modal__close" style="color:white;" onclick="document.getElementById('startResModal').style.display='none'">&times;</span>
    </div>
    <div class="game-modal__body">
      <form id="startResForm" method="post" action="tables.php">
        <input type="hidden" name="return_url" value="dashboard.php">
        <input type="hidden" name="action" value="start_game">
        <input type="hidden" name="reservation_id" id="srResId">
        <input type="hidden" name="table_id" id="srTableId">
        <input type="hidden" name="walk_in_name" id="srCustName">
        <input type="hidden" name="hours" id="srHours">
        <input type="hidden" name="karaoke_included" value="0">

        <div style="background:var(--surface2); padding:14px; border-radius:8px; margin-bottom:16px;">
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; font-size:13px; margin-bottom:12px;">
            <div><span style="color:var(--muted);">Customer:</span><br><strong id="srCustNameLabel"></strong></div>
            <div><span style="color:var(--muted);">Duration:</span><br><strong id="srHoursLabel"></strong></div>
          </div>
          <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:4px;">
            <span style="color:var(--muted);">Total Cost:</span>
            <strong id="srTotalCost">₱0.00</strong>
          </div>
          <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:4px;">
            <span style="color:#22c55e;">Down Payment:</span>
            <strong style="color:#22c55e;" id="srDp">₱0.00</strong>
          </div>
          <div style="display:flex; justify-content:space-between; font-size:14px; padding-top:6px; border-top:1px solid var(--border);">
            <span style="color:var(--muted); font-weight:600;">Balance Due:</span>
            <strong style="color:var(--primary); font-size:16px;" id="srBalance">₱0.00</strong>
          </div>
        </div>

        <div class="game-modal__row">
          <div class="game-modal__field">
            <label>Payment (₱) *</label>
            <input type="number" name="payment" id="srPayment" step="0.01" min="0" required placeholder="Cash received">
          </div>
          <div class="game-modal__field">
            <label>Change</label>
            <div id="srChange" class="val" style="font-weight:700; color:var(--success);">₱0.00</div>
          </div>
        </div>

        <div class="game-modal__footer" style="margin-top:20px;">
          <button type="button" class="btn btn--ghost" onclick="document.getElementById('startResModal').style.display='none'">Cancel</button>
          <button type="submit" class="btn" style="background:#22c55e; color:white; border:none; padding:8px 18px; font-weight:600;">Start Game</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══ ADD RESERVATION MODAL ═══ -->
<div id="dshAddResModal" class="game-modal" style="display:none;" onclick="if(event.target.id==='dshAddResModal')document.getElementById('dshAddResModal').style.display='none'">
  <div class="game-modal__box" style="max-width:500px;">
    <div class="game-modal__header" style="background:#38bdf8;">
      <h3 style="color:white;">➕ New Reservation</h3>
      <span class="game-modal__close" style="color:white;" onclick="document.getElementById('dshAddResModal').style.display='none'">&times;</span>
    </div>
    <form method="post" action="reservations.php">
      <input type="hidden" name="action" value="add_reservation">
      <input type="hidden" name="return_url" value="dashboard.php">
      <div class="game-modal__body">
        <div class="game-modal__row">
          <div class="game-modal__field">
            <label>Customer Name *</label>
            <input type="text" name="customer_name" required>
          </div>
          <div class="game-modal__field">
            <label>Contact #</label>
            <input type="text" name="customer_contact">
          </div>
        </div>

        <div class="game-modal__row" style="margin-top:12px;">
          <div class="game-modal__field">
            <label>Table *</label>
            <select name="table_id" required>
              <option value="">Select table...</option>
              <?php foreach ($cashierAllTables as $t): ?>
                <option value="<?php echo (int)$t['id']; ?>">
                  <?php echo h($t['table_number']); ?>
                  <?php echo $t['type'] === 'vip' ? '(VIP)' : ($t['type'] === 'ktv' ? '(KTV)' : ''); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="game-modal__field">
            <label>Date *</label>
            <input type="date" name="reservation_date" required value="<?php echo date('Y-m-d'); ?>" min="<?php echo date('Y-m-d'); ?>">
          </div>
        </div>

        <!-- Live Booked Slots Panel -->
        <div id="dshBookedSlotsPanel" style="display:none; margin-top:12px; padding:10px 12px; border-radius:8px; background:var(--surface2); border:1px solid var(--border);">
          <div style="font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; margin-bottom:6px; letter-spacing:.5px;">📋 Existing Reservations on this Date</div>
          <div id="dshBookedSlotsList"></div>
        </div>

        <div class="game-modal__row" style="margin-top:12px;">
          <div class="game-modal__field">
            <label>Start Time *</label>
            <input type="time" id="dshResStartTime" name="start_time" required>
          </div>
          <div class="game-modal__field">
            <label>Duration (hrs)</label>
            <select id="dshResDuration" name="duration_hours" required>
              <option value="0.5">30 min</option>
              <option value="1" selected>1 hr</option>
              <option value="1.5">1.5 hrs</option>
              <option value="2">2 hrs</option>
              <option value="2.5">2.5 hrs</option>
              <option value="3">3 hrs</option>
              <option value="3.5">3.5 hrs</option>
              <option value="4">4 hrs</option>
              <option value="4.5">4.5 hrs</option>
              <option value="5">5 hrs</option>
              <option value="6">6 hrs</option>
              <option value="7">7 hrs</option>
              <option value="8">8 hrs</option>
              <option value="9">9 hrs</option>
              <option value="10">10 hrs</option>
              <option value="11">11 hrs</option>
              <option value="12">12 hrs</option>
            </select>
          </div>
        </div>

        <!-- Conflict Warning -->
        <div id="dshConflictWarning" style="display:none; margin-top:8px; padding:8px 12px; border-radius:7px; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); font-size:12px; color:#ef4444; font-weight:600;"></div>

        <div class="game-modal__row" style="margin-top:12px;">
          <div class="game-modal__field">
            <label>Down Payment (₱) - Optional</label>
            <input type="number" name="down_payment" step="0.01" min="0" value="0.00">
          </div>
          <div class="game-modal__field">
            <label>Notes (optional)</label>
            <input type="text" name="notes">
          </div>
        </div>
      </div>
      <div style="padding:0 20px 20px; display:flex; gap:10px; justify-content:flex-end;">
        <button type="button" class="btn btn--ghost" onclick="document.getElementById('dshAddResModal').style.display='none'">Cancel</button>
        <button type="submit" id="dshSaveResBtn" class="btn btn--primary" style="background:#38bdf8; border:none; color:white;">Save Reservation</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ DASHBOARD CANCEL RESERVATION MODAL ═══ -->
<div id="dshCancelModal" class="game-modal" style="display:none; z-index:10000;">
  <div class="game-modal__box" style="max-width:400px;">
    <div class="game-modal__header">
      <h3>🛑 Cancel Reservation</h3>
      <span class="game-modal__close" onclick="document.getElementById('dshCancelModal').style.display='none'">&times;</span>
    </div>
    <form method="post" action="reservations.php">
      <input type="hidden" name="return_url" value="dashboard.php">
      <input type="hidden" name="action" value="cancel_reservation">
      <input type="hidden" name="id" id="dsh_cancel_res_id">
      <div class="game-modal__body" style="text-align:center; padding:28px 24px;">
        <div style="width:56px; height:56px; margin:0 auto 16px; border-radius:50%; background:rgba(239,68,68,0.12); display:flex; align-items:center; justify-content:center; font-size:28px;">📅</div>
        <p style="color:var(--text); font-size:15px; margin:0 0 8px;">Are you sure you want to cancel the reservation for <strong id="dshCancelCustomerName"></strong>?</p>
        <p style="color:var(--muted); font-size:13px; margin:0;">This action cannot be undone.</p>
        <div style="display:flex; justify-content:center; gap:10px; margin-top:24px;">
          <button type="button" class="btn btn--ghost" onclick="document.getElementById('dshCancelModal').style.display='none'">Go Back</button>
          <button type="submit" class="btn btn--danger">Cancel Reservation</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ═══ DASHBOARD NO SHOW MODAL ═══ -->
<div id="dshNoShowModal" class="game-modal" style="display:none; z-index:10000;">
  <div class="game-modal__box" style="max-width:400px;">
    <div class="game-modal__header">
      <h3>🚫 Mark as No Show</h3>
      <span class="game-modal__close" onclick="document.getElementById('dshNoShowModal').style.display='none'">&times;</span>
    </div>
    <form method="post" action="reservations.php">
      <input type="hidden" name="return_url" value="dashboard.php">
      <input type="hidden" name="action" value="no_show">
      <input type="hidden" name="id" id="dsh_noshow_res_id">
      <div class="game-modal__body" style="text-align:center; padding:28px 24px;">
        <div style="width:56px; height:56px; margin:0 auto 16px; border-radius:50%; background:rgba(245,158,11,0.12); display:flex; align-items:center; justify-content:center; font-size:28px;">🫥</div>
        <p style="color:var(--text); font-size:15px; margin:0 0 8px;">Mark reservation for <strong id="dshNoshowCustomerName"></strong> as a no-show?</p>
        <p style="color:var(--muted); font-size:13px; margin:0;">The table will be freed up for other customers.</p>
        <div style="display:flex; justify-content:center; gap:10px; margin-top:24px;">
          <button type="button" class="btn btn--ghost" onclick="document.getElementById('dshNoShowModal').style.display='none'">Go Back</button>
          <button type="submit" class="btn btn--primary" style="background:#f59e0b; border-color:#f59e0b; color:#fff;">Confirm No Show</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
// ── Available tables data (injected from PHP) ──
const availData = {
  regular: <?php echo json_encode($cashierAvailTables); ?>,
  vip: <?php echo json_encode($cashierAvailVip); ?>,
  ktv: <?php echo json_encode($cashierAvailKtv); ?>,
  kubo: <?php echo json_encode($cashierAvailKubos); ?>
};

const typeConfig = {
  regular: { title: '🎱 Select Regular Table', color: '#38bdf8', action: 'tables.php', label: 'Regular' },
  vip:     { title: '⭐ Select VIP Table', color: '#eab308', action: 'vip_tables.php', label: 'VIP' },
  ktv:     { title: '🎤 Select KTV Room', color: '#a855f7', action: 'vip_tables.php', label: 'KTV' },
  kubo:    { title: '🏡 Select Kubo', color: '#22c55e', action: 'kubo.php', label: 'Kubo' }
};

// ── Picker Modal ──
function openPickerModal(type) {
  const cfg = typeConfig[type];
  const items = availData[type] || [];
  document.getElementById('pickerTitle').textContent = cfg.title;
  document.getElementById('pickerHeader').style.borderBottomColor = cfg.color;

  const listEl = document.getElementById('pickerList');
  const emptyEl = document.getElementById('pickerEmpty');
  listEl.innerHTML = '';

  if (items.length === 0) {
    emptyEl.style.display = 'block';
    listEl.style.display = 'none';
  } else {
    emptyEl.style.display = 'none';
    listEl.style.display = 'flex';
    items.forEach(t => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn';
      btn.style.cssText = 'width:100%; text-align:left; padding:12px 16px; border:1px solid var(--border); background:var(--surface2); color:var(--text); border-radius:8px; font-size:14px; cursor:pointer; display:flex; justify-content:space-between; align-items:center;';
      btn.innerHTML = '<strong>' + t.table_number + '</strong><span style="color:var(--muted); font-size:12px;">₱' + parseFloat(t.rate_per_hour).toFixed(2) + '/hr</span>';
      btn.onmouseover = () => { btn.style.borderColor = cfg.color; btn.style.background = 'rgba(255,255,255,0.05)'; };
      btn.onmouseout = () => { btn.style.borderColor = 'var(--border)'; btn.style.background = 'var(--surface2)'; };
      btn.onclick = () => {
        closePickerModal();
        if (type === 'kubo') {
          openDshKuboModal(t.id, t.table_number);
        } else {
          openDshStartModal(t.id, t.table_number, parseFloat(t.rate_per_hour), type);
        }
      };
      listEl.appendChild(btn);
    });
  }
  document.getElementById('pickerModal').style.display = 'flex';
}
function closePickerModal() { document.getElementById('pickerModal').style.display = 'none'; }

// ── Start Game Modal ──
let dshRate = 0, dshHours = 0, dshType = 'regular', dshPromo = false;

function openDshStartModal(tableId, tableName, rate, type) {
  dshRate = rate; dshHours = 0; dshType = type; dshPromo = false;
  document.getElementById('dshTableId').value = tableId;
  document.getElementById('dshIsPromo').value = '0';
  document.getElementById('dshPromoToggle') && (document.getElementById('dshPromoToggle').checked = false);
  document.getElementById('dshKaraokeToggle') && (document.getElementById('dshKaraokeToggle').checked = false);

  // Set form action
  const action = type === 'regular' ? 'tables.php' : 'vip_tables.php';
  document.getElementById('dshStartForm').action = action;

  // Title
  const icons = { regular: '🎱', vip: '⭐', ktv: '🎤' };
  document.getElementById('dshStartTitle').textContent = (icons[type] || '🎱') + ' Start Game — ' + tableName;

  // Show/hide promo and karaoke toggles
  document.getElementById('dshPromoWrap').style.display = type === 'regular' ? 'block' : 'none';
  document.getElementById('dshKaraokeWrap').style.display = (type === 'vip' || type === 'ktv') ? 'block' : 'none';

  // Reset
  document.getElementById('dshRate').textContent = '₱' + rate.toFixed(2) + '/hr';
  document.getElementById('dshTotal').textContent = '₱0.00';
  document.getElementById('dshPayment').value = '';
  document.getElementById('dshChange').textContent = '₱0.00';
  document.getElementById('dshHoursInput').value = '';
  document.querySelectorAll('#dshHourButtons .hour-btn').forEach(b => b.classList.remove('selected'));

  document.getElementById('dshStartModal').style.display = 'flex';
}
function closeDshStartModal() { document.getElementById('dshStartModal').style.display = 'none'; }

// Hour selection
document.querySelectorAll('#dshHourButtons .hour-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#dshHourButtons .hour-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    dshHours = parseFloat(btn.dataset.hours);
    document.getElementById('dshHoursInput').value = dshHours;
    updateDshTotal();
  });
});

function dshTogglePromo() {
  dshPromo = document.getElementById('dshPromoToggle').checked;
  document.getElementById('dshIsPromo').value = dshPromo ? '1' : '0';
  updateDshTotal();
}

function updateDshTotal() {
  const effectiveRate = dshPromo ? dshRate * 0.5 : dshRate;
  const total = effectiveRate * dshHours;
  document.getElementById('dshRate').textContent = '₱' + effectiveRate.toFixed(2) + '/hr' + (dshPromo ? ' (50% off)' : '');
  document.getElementById('dshTotal').textContent = '₱' + total.toFixed(2);
  updateDshChange();
}

document.getElementById('dshPayment').addEventListener('input', updateDshChange);
function updateDshChange() {
  const effectiveRate = dshPromo ? dshRate * 0.5 : dshRate;
  const total = effectiveRate * dshHours;
  const pay = parseFloat(document.getElementById('dshPayment').value) || 0;
  const change = pay - total;
  const el = document.getElementById('dshChange');
  el.textContent = '₱' + change.toFixed(2);
  el.style.color = change >= 0 ? 'var(--success)' : 'var(--danger)';
}

// Validate before submit
document.getElementById('dshStartForm').addEventListener('submit', function(e) {
  if (dshHours <= 0) { e.preventDefault(); showWarnModal('⏰ Select Hours', 'Please select how many hours to play.'); return; }
  const effectiveRate = dshPromo ? dshRate * 0.5 : dshRate;
  const total = effectiveRate * dshHours;
  const pay = parseFloat(document.getElementById('dshPayment').value) || 0;
  if (pay < total - 0.01) { e.preventDefault(); showWarnModal('💰 Payment Not Enough', 'Payment is not enough. Required: ₱' + total.toFixed(2)); }
});

// ── Kubo Modals ──
function openDshKuboModal(tableId, tableName) {
  document.getElementById('dshKuboId').value = tableId;
  document.getElementById('dshKuboName').textContent = tableName;
  document.getElementById('dshKuboModal').style.display = 'flex';
}

function openDshEndKuboModal(rentalId, tableName) {
  document.getElementById('endKuboId').value = rentalId;
  document.getElementById('endKuboName').textContent = tableName;
  document.getElementById('endKuboModal').style.display = 'flex';
}

function openDshVoidKuboModal(rentalId, tableName) {
  document.getElementById('voidKuboId').value = rentalId;
  document.getElementById('voidKuboName').textContent = tableName;
  document.getElementById('voidKuboReasonInput').value = '';
  document.getElementById('voidKuboModal').style.display = 'flex';
  setTimeout(() => document.getElementById('voidKuboReasonInput').focus(), 100);
}

function openDshStartKaraokeModal(tableId, tableName, rate, custName) {
  document.getElementById('skTableId').value = tableId;
  document.getElementById('skCustName').value = custName;
  document.getElementById('skTableName').textContent = tableName;
  document.getElementById('skRate').textContent = '₱' + rate.toFixed(2) + '/hr';
  document.getElementById('skCost').textContent = '₱0.00';
  document.getElementById('skPayment').value = '';
  document.getElementById('skChange').textContent = '₱0.00';
  document.querySelectorAll('#skHourButtons .hour-btn').forEach(b => b.classList.remove('selected'));
  document.getElementById('startKaraokeModal').style.display = 'flex';
}

let skRate = 100, skHours = 0;
document.querySelectorAll('#skHourButtons .hour-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#skHourButtons .hour-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    skHours = parseFloat(btn.dataset.hours);
    document.getElementById('skHoursInput').value = skHours;
    updateSkTotal();
  });
});

function updateSkTotal() {
  const cost = skRate * skHours;
  document.getElementById('skCost').textContent = '₱' + cost.toFixed(2);
  updateSkChange();
}

document.getElementById('skPayment').addEventListener('input', updateSkChange);
function updateSkChange() {
  const cost = skRate * skHours;
  const pay = parseFloat(document.getElementById('skPayment').value) || 0;
  const change = pay - cost;
  const el = document.getElementById('skChange');
  el.textContent = '₱' + change.toFixed(2);
  el.style.color = change >= 0 ? 'var(--success)' : 'var(--danger)';
}

document.getElementById('startKaraokeForm').addEventListener('submit', function(e) {
  if (skHours <= 0) { e.preventDefault(); showWarnModal('⏰ Select Hours', 'Please select how many hours for Karaoke.'); return; }
  const cost = skRate * skHours;
  const pay = parseFloat(document.getElementById('skPayment').value) || 0;
  if (pay < cost - 0.01) { e.preventDefault(); showWarnModal('💰 Payment Not Enough', 'Payment is not enough. Required: ₱' + cost.toFixed(2)); }
});

// ── Reservations Modal ──
function openReservationsModal() {
  document.getElementById('dshResModal').style.display = 'flex';
}

let srRate = 0, srHours = 0, srDp = 0;
function openDshStartResModal(resId, tableId, endpoint, tableName, custName, rate, hours, dp) {
  document.getElementById('dshResModal').style.display = 'none'; // Close reservations list
  
  document.getElementById('startResForm').action = endpoint;
  document.getElementById('srResId').value = resId;
  document.getElementById('srTableId').value = tableId;
  document.getElementById('srCustName').value = custName;
  document.getElementById('srHours').value = hours;
  
  document.getElementById('srTableName').textContent = tableName;
  document.getElementById('srCustNameLabel').textContent = custName;
  document.getElementById('srHoursLabel').textContent = hours + ' hrs';
  
  srRate = rate;
  srHours = hours;
  srDp = dp;
  
  const total = rate * hours;
  const balance = Math.max(0, total - dp);
  
  document.getElementById('srTotalCost').textContent = '₱' + total.toFixed(2);
  document.getElementById('srDp').textContent = '₱' + dp.toFixed(2);
  document.getElementById('srBalance').textContent = '₱' + balance.toFixed(2);
  
  document.getElementById('srPayment').value = '';
  document.getElementById('srChange').textContent = '₱0.00';
  document.getElementById('startResModal').style.display = 'flex';
}

document.getElementById('srPayment').addEventListener('input', function() {
  const total = srRate * srHours;
  const reqPay = Math.max(0, total - srDp);
  const pay = parseFloat(this.value) || 0;
  const change = pay - reqPay;
  const el = document.getElementById('srChange');
  el.textContent = '₱' + change.toFixed(2);
  el.style.color = change >= 0 ? 'var(--success)' : 'var(--danger)';
});

document.getElementById('startResForm').addEventListener('submit', function(e) {
  const total = srRate * srHours;
  const reqPay = Math.max(0, total - srDp);
  const pay = parseFloat(document.getElementById('srPayment').value) || 0;
  if (pay < reqPay - 0.01) { e.preventDefault(); showWarnModal('💰 Payment Not Enough', 'Payment is not enough. Required: ₱' + reqPay.toFixed(2)); }
});

function openDshCancelModal(id, customerName) {
  document.getElementById('dshResModal').style.display = 'none'; // Close list modal
  document.getElementById('dsh_cancel_res_id').value = id;
  document.getElementById('dshCancelCustomerName').textContent = customerName;
  document.getElementById('dshCancelModal').style.display = 'flex';
}

function openDshNoShowModal(id, customerName) {
  document.getElementById('dshResModal').style.display = 'none'; // Close list modal
  document.getElementById('dsh_noshow_res_id').value = id;
  document.getElementById('dshNoshowCustomerName').textContent = customerName;
  document.getElementById('dshNoShowModal').style.display = 'flex';
}

function openDshAddResModal() {
  document.getElementById('dshResModal').style.display = 'none'; // Close list modal
  dshCurrentSlots = [];
  document.getElementById('dshBookedSlotsPanel').style.display = 'none';
  document.getElementById('dshConflictWarning').style.display = 'none';
  document.getElementById('dshSaveResBtn').disabled = false;
  document.getElementById('dshSaveResBtn').style.opacity = '1';
  document.getElementById('dshAddResModal').style.display = 'flex';
}

// ── Live Availability for Add Reservation ──
let dshCurrentSlots = [];

function dshTimeToMins(t) {
  const parts = t.split(':').map(Number);
  return parts[0] * 60 + parts[1];
}

function dshMinsToTime12(m) {
  const h = Math.floor(m / 60) % 24;
  const mn = m % 60;
  const ampm = h >= 12 ? 'PM' : 'AM';
  return (h % 12 || 12) + ':' + String(mn).padStart(2, '0') + ' ' + ampm;
}

function dshFetchBookedSlots() {
  const tableEl = document.querySelector('#dshAddResModal select[name="table_id"]');
  const dateEl  = document.querySelector('#dshAddResModal input[name="reservation_date"]');
  const panel   = document.getElementById('dshBookedSlotsPanel');
  const list    = document.getElementById('dshBookedSlotsList');
  if (!tableEl || !dateEl) return;
  const tid  = tableEl.value;
  const date = dateEl.value;
  if (!tid || !date) { panel.style.display = 'none'; dshCurrentSlots = []; dshCheckConflict(); return; }

  fetch('reservations.php?ajax=table_slots&table_id=' + encodeURIComponent(tid) + '&date=' + encodeURIComponent(date))
    .then(r => r.json())
    .then(data => {
      dshCurrentSlots = data.slots || [];
      let html = '';
      if (dshCurrentSlots.length === 0) {
        html += '<div style="color:#22c55e; font-size:12px; margin-bottom:8px;">✓ Walang naka-reserve sa araw na ito.</div>';
      } else {
        dshCurrentSlots.forEach(s => {
          const startMins = dshTimeToMins(s.start_time);
          const endMins   = startMins + Math.round(parseFloat(s.duration_hours) * 60);
          html += `<div style="display:flex; justify-content:space-between; align-items:center; font-size:12px; padding:5px 8px; margin-bottom:4px; background:rgba(239,68,68,0.08); border-radius:6px; border-left:3px solid #ef4444;">
            <div>
              <strong>${dshMinsToTime12(startMins)} – ${dshMinsToTime12(endMins)}</strong>
              &nbsp;·&nbsp; ${String(s.customer_name).replace(/</g,'&lt;')}
            </div>
            <span style="font-size:10px; padding:2px 6px; border-radius:4px; background:rgba(239,68,68,0.15); color:#ef4444; text-transform:uppercase;">${String(s.status)}</span>
          </div>`;
        });
      }
      
      const SHIFT_START = 8 * 60;
      const SHIFT_END = 24 * 60 + 150;
      function toShiftMins(tStr) { let m = dshTimeToMins(tStr); return m < 480 ? m + 1440 : m; }
      
      let gaps = [];
      let prev = SHIFT_START;
      const now = new Date();
      const todayStr = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`;
      if (date === todayStr) {
         let currMins = now.getHours() * 60 + now.getMinutes();
         let currShiftMins = currMins < 480 ? currMins + 1440 : currMins;
         prev = Math.max(prev, Math.ceil(currShiftMins / 30) * 30);
      }
      
      const sorted = [...dshCurrentSlots].sort((a,b) => toShiftMins(a.start_time) - toShiftMins(b.start_time));
      sorted.forEach(s => {
        const sm = toShiftMins(s.start_time);
        const em = sm + Math.round(parseFloat(s.duration_hours) * 60);
        if (sm > prev) gaps.push([prev, sm]);
        prev = Math.max(prev, em);
      });
      if (prev < SHIFT_END) gaps.push([prev, SHIFT_END]);
      
      if (gaps.length > 0) {
        html += '<div style="font-size:11px; font-weight:700; color:var(--muted); margin:8px 0 4px;">🟢 Available Time Slots:</div>';
        gaps.forEach(([s, e]) => {
          const label = dshMinsToTime12(s) + ' – ' + (e === SHIFT_END ? '2:30 AM' : dshMinsToTime12(e));
          html += `<div style="font-size:12px; padding:4px 8px; margin-bottom:3px; background:rgba(34,197,94,0.08); border-radius:5px; border-left:3px solid #22c55e; color:#16a34a;">${label}</div>`;
        });
      }
      list.innerHTML = html;
      panel.style.display = 'block';
      dshCheckConflict();
    })
    .catch(() => { panel.style.display = 'none'; dshCurrentSlots = []; });
}

function dshCheckConflict() {
  const startEl = document.getElementById('dshResStartTime');
  const durEl   = document.getElementById('dshResDuration');
  const warn    = document.getElementById('dshConflictWarning');
  const btn     = document.getElementById('dshSaveResBtn');
  if (!startEl || !durEl || !warn) return;

  const startVal = startEl.value;
  const durVal   = parseFloat(durEl.value || 0);
  if (!startVal || !durVal || dshCurrentSlots.length === 0) {
    warn.style.display = 'none';
    if (btn) btn.disabled = false;
    return;
  }

  const newStart = dshTimeToMins(startVal);
  const newEnd   = newStart + Math.round(durVal * 60);

  const conflict = dshCurrentSlots.find(s => {
    const sm = dshTimeToMins(s.start_time);
    const em = sm + Math.round(parseFloat(s.duration_hours) * 60);
    return newStart < em && newEnd > sm;
  });

  if (conflict) {
    const csm = dshTimeToMins(conflict.start_time);
    const cem = csm + Math.round(parseFloat(conflict.duration_hours) * 60);
    warn.textContent = '⛔ Conflict! ' + String(conflict.customer_name) + ' reserved from ' + dshMinsToTime12(csm) + ' to ' + dshMinsToTime12(cem) + '.';
    warn.style.display = 'block';
    if (btn) { btn.disabled = true; btn.style.opacity = '0.5'; }
  } else {
    warn.style.display = 'none';
    if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
  }
}

document.addEventListener('DOMContentLoaded', function() {
  const tableEl = document.querySelector('#dshAddResModal select[name="table_id"]');
  const dateEl  = document.querySelector('#dshAddResModal input[name="reservation_date"]');
  const startEl = document.getElementById('dshResStartTime');
  const durEl   = document.getElementById('dshResDuration');
  if (tableEl) tableEl.addEventListener('change', dshFetchBookedSlots);
  if (dateEl)  dateEl.addEventListener('change',  dshFetchBookedSlots);
  if (startEl) startEl.addEventListener('change', dshCheckConflict);
  if (startEl) startEl.addEventListener('input',  dshCheckConflict);
  if (durEl)   durEl.addEventListener('change',   dshCheckConflict);
});
</script>
