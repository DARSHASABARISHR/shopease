<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
$user = require_login();

// Orders matrum return status-ai join panni edukkirom
$st = Database::getConnection()->prepare('
    SELECT o.*, r.return_status 
    FROM orders o 
    LEFT JOIN returns r ON r.order_id = o.order_id 
    WHERE o.user_id = ? 
    ORDER BY o.order_id DESC 
    LIMIT 100
');
$st->execute([$user['user_id']]);
$orders = $st->fetchAll();
$pageTitle = 'My Orders';
require __DIR__ . '/includes/header.php';
?>
<div class="panel">
  <h2 style="margin-top:0"><?= e(t('my_orders')) ?></h2>
  <div id="ordAlert" class="alert" hidden></div>
  <?php if (!$orders): ?><p>You haven't placed any orders yet. <a href="<?= url('index.php') ?>">Start shopping</a></p>
  <?php else: ?>
  <div style="overflow-x:auto"><table class="tbl">
    <thead><tr><th>Order</th><th>Date</th><th>Total</th><th>Payment</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): 
      // 7 Days (1 Week) Return Window Check:
      // delivered_at column iruntha athai edukkum, illana created_at-ai base pannikkum
      $deliveryDate = !empty($o['delivered_at']) ? strtotime((string)$o['delivered_at']) : strtotime((string)$o['created_at']);
      $isWithin7Days = (time() - $deliveryDate) <= (7 * 24 * 60 * 60);
    ?>
      <tr data-order="<?= (int)$o['order_id'] ?>">
        <td>#<?= (int)$o['order_id'] ?></td>
        <td><?= e(date('d M Y', strtotime((string)$o['created_at']))) ?></td>
        <td><?= money($o['total_amount']) ?></td>
        <td><span class="pill <?= e($o['payment_status']) ?>" data-pay><?= e($o['payment_status']) ?></span>
          <?php if (!empty($o['refund_id'])): ?><br><small>Refund ID: <?= e($o['refund_id']) ?></small><?php endif; ?></td>
        <td><span class="pill <?= e($o['order_status']) ?>" data-status><?= e($o['order_status']) ?></span></td>
        <td data-actions>
          <a href="<?= url('order_success.php?id=' . (int)$o['order_id']) ?>">View / Print</a>

          <!-- Cancel Button (Pending-il irunthaal mattum) -->
          <?php if ($o['order_status'] === 'Pending'): ?> 
            · <button type="button" class="linkbtn js-cancel-order" style="color:#b12704; cursor:pointer;" data-order="<?= (int)$o['order_id'] ?>"><?= e(t('cancel_order')) ?></button>
          <?php endif; ?>

          <!-- Return Button / Status (Delivered aagi 1 Week-kulla irunthaal mattum) -->
          <?php if ($o['order_status'] === 'Delivered'): ?>
            <?php if (!empty($o['return_status'])): ?>
              · <span class="badge" style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:4px;font-size:12px;">Return: <?= e($o['return_status']) ?></span>
            <?php elseif ($isWithin7Days): ?>
              · <button type="button" class="linkbtn js-return-order" style="color:#0284c7;font-weight:bold;cursor:pointer;" data-order="<?= (int)$o['order_id'] ?>">Return Item</button>
            <?php else: ?>
              · <span style="font-size:11px;color:#888;">(Return window closed)</span>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</div>

<!-- CANCEL ORDER DIALOG -->
<dialog id="coDialog" class="otp-dialog" style="width:min(440px,94vw)">
  <h3 style="margin-top:0"><?= e(t('cancel_order')) ?> <span id="coNum"></span></h3>
  <div id="coMsg" class="alert alert-error" hidden></div>
  <div class="field"><label for="coReason"><?= e(t('cancel_reason')) ?></label><textarea id="coReason" rows="3" maxlength="300"></textarea></div>
  <p style="font-size:12px;color:#565959">Online payments are refunded to your original payment method in 5-7 business days.</p>
  <div style="display:flex;gap:8px"><button type="button" class="btn btn-danger" id="coGo"><?= e(t('confirm_cancel')) ?></button><button type="button" class="btn btn-light" id="coClose"><?= e(t('close')) ?></button></div>
</dialog>

<!-- RETURN ORDER & OTP DIALOG -->
<dialog id="roDialog" class="otp-dialog" style="width:min(440px,94vw)">
  <h3 style="margin-top:0">Return Order <span id="roNum"></span></h3>
  <div id="roMsg" class="alert alert-error" hidden></div>

  <!-- Step 1: Return Reason -->
  <div id="roStepReason">
    <div class="field"><label for="roReason">Reason for Return</label><textarea id="roReason" rows="3" maxlength="300" placeholder="Please mention the issue with the product..."></textarea></div>
    <div style="display:flex;gap:8px;margin-top:12px;">
      <button type="button" class="btn btn-primary" id="roSendOtp">Send OTP to Email</button>
      <button type="button" class="btn btn-light" id="roClose1"><?= e(t('close')) ?></button>
    </div>
  </div>

  <!-- Step 2: OTP Verification -->
  <div id="roStepOtp" hidden>
    <div class="alert alert-info" style="font-size:13px;margin-bottom:10px;">We've sent a 6-digit verification OTP to your registered email.</div>
    <div class="field"><label for="roOtp">Enter 6-Digit OTP</label><input type="text" id="roOtp" maxlength="6" style="text-align:center;letter-spacing:5px;font-size:18px;" placeholder="------"></div>
    <div style="display:flex;gap:8px;margin-top:12px;">
      <button type="button" class="btn btn-success" id="roVerifyOtp">Verify OTP & Submit Return</button>
      <button type="button" class="btn btn-light" id="roClose2"><?= e(t('close')) ?></button>
    </div>
  </div>
</dialog>

<script>
(function () {
  // 1. Cancel Order Logic
  const dlg = document.getElementById('coDialog'); let id = 0, row = null;
  const msg = (t) => { const m = document.getElementById('coMsg'); m.textContent = t || ''; m.hidden = !t; };
  document.querySelectorAll('.js-cancel-order').forEach((b) => b.addEventListener('click', () => {
    id = b.dataset.order; row = b.closest('tr'); document.getElementById('coNum').textContent = '#' + id; document.getElementById('coReason').value = ''; msg(''); dlg.showModal();
  }));
  document.getElementById('coClose').addEventListener('click', () => dlg.close());
  document.getElementById('coGo').addEventListener('click', async (e) => {
    const reason = document.getElementById('coReason').value.trim();
    if (reason.length < 5) return msg('Please tell us why you are cancelling.');
    e.currentTarget.disabled = true; e.currentTarget.textContent = '…';
    const j = await postJSON(BASE_URL + '/customer/cancel_order.php', { order_id: id, reason });
    e.currentTarget.disabled = false; e.currentTarget.textContent = <?= json_encode(t('confirm_cancel')) ?>;
    if (!j.ok) return msg(j.message);
    dlg.close();
    const a = document.getElementById('ordAlert'); a.hidden = false; a.className = 'alert alert-success'; a.textContent = j.message;
    const s = row.querySelector('[data-status]'); s.className = 'pill Cancelled'; s.textContent = 'Cancelled';
    const p = row.querySelector('[data-pay]'); p.className = 'pill ' + j.payment_status; p.textContent = j.payment_status;
    if (j.refund_id) { const sm = document.createElement('small'); sm.textContent = 'Refund ID: ' + j.refund_id; p.parentNode.append(document.createElement('br'), sm); }
    const btn = row.querySelector('.js-cancel-order'); if (btn) { btn.previousSibling && btn.previousSibling.nodeType === 3 && btn.previousSibling.remove(); btn.remove(); }
  });

  // 2. Return Order Logic
  const roDlg = document.getElementById('roDialog');
  let retId = 0, retRow = null;
  const roMsg = (t) => { const m = document.getElementById('roMsg'); m.textContent = t || ''; m.hidden = !t; };

  document.querySelectorAll('.js-return-order').forEach((b) => b.addEventListener('click', () => {
    retId = b.dataset.order; retRow = b.closest('tr');
    document.getElementById('roNum').textContent = '#' + retId;
    document.getElementById('roReason').value = '';
    document.getElementById('roOtp').value = '';
    document.getElementById('roStepReason').hidden = false;
    document.getElementById('roStepOtp').hidden = true;
    roMsg('');
    roDlg.showModal();
  }));

  document.getElementById('roClose1').addEventListener('click', () => roDlg.close());
  document.getElementById('roClose2').addEventListener('click', () => roDlg.close());

  // Send OTP
  document.getElementById('roSendOtp').addEventListener('click', async (e) => {
    const reason = document.getElementById('roReason').value.trim();
    if (reason.length < 5) return roMsg('Please provide a valid return reason (at least 5 characters).');
    e.currentTarget.disabled = true; e.currentTarget.textContent = 'Sending OTP…';

    const j = await postJSON(BASE_URL + '/customer/send_return_otp.php', { order_id: retId, reason });
    e.currentTarget.disabled = false; e.currentTarget.textContent = 'Send OTP to Email';

    if (!j.ok) return roMsg(j.message);
    document.getElementById('roStepReason').hidden = true;
    document.getElementById('roStepOtp').hidden = false;
    roMsg('');
  });

  // Verify OTP
  document.getElementById('roVerifyOtp').addEventListener('click', async (e) => {
    const otp = document.getElementById('roOtp').value.trim();
    if (otp.length < 6) return roMsg('Please enter the 6-digit OTP.');
    e.currentTarget.disabled = true; e.currentTarget.textContent = 'Verifying…';

    const j = await postJSON(BASE_URL + '/customer/verify_return_otp.php', { order_id: retId, otp });
    e.currentTarget.disabled = false; e.currentTarget.textContent = 'Verify OTP & Submit Return';

    if (!j.ok) return roMsg(j.message);
    roDlg.close();
    location.reload();
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>