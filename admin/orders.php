<?php
declare(strict_types=1);
$adminPage = 'orders'; $pageTitle = 'Orders';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/OrderService.php';
require_admin();
$db  = Database::getConnection();
$svc = new OrderService($db);

$statuses = ['Pending', 'Shipped', 'Out for Delivery', 'Delivered', 'Cancelled'];
$manual   = ['Pending', 'Shipped', 'Out for Delivery'];     // Delivered -> delivery OTP, Cancelled -> cancellation OTP

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $oid    = (int)($_POST['order_id'] ?? 0);
    $action = (string)($_POST['action'] ?? 'status');
    try {
        if ($action === 'verify_delivery') {
            $o = $svc->find($oid);
            if ($o && $o['payment_method'] === 'COD' && ($_POST['cash_collected'] ?? '') !== '1') {
                $r = ['ok' => false, 'msg' => 'Please confirm that you collected ' . money($o['total_amount']) . ' in cash.'];
            } else {
                $r = $svc->verifyDelivery($oid, (string)($_POST['otp'] ?? ''));
            }
        } elseif ($action === 'resend_otp') {
            $r = $svc->resendDeliveryOtp($oid);
        } else {
            $r = $svc->updateStatus($oid, (string)($_POST['status'] ?? ''), ['courier' => $_POST['courier'] ?? '', 'tracking' => $_POST['tracking'] ?? '']);
        }
        flash($r['ok'] ? 'success' : 'error', $r['msg']);
    } catch (Throwable $e) { error_log($e->getMessage()); flash('error', 'Something went wrong. Please try again.'); }
    redirect('admin/orders.php' . (isset($_GET['status']) ? '?status=' . urlencode((string)$_GET['status']) : ''));
}

$filter = (string)($_GET['status'] ?? '');
$sql = 'SELECT o.*, u.full_name, u.email FROM orders o JOIN users u ON u.user_id = o.user_id';
$args = [];
if (in_array($filter, $statuses, true)) { $sql .= ' WHERE o.order_status = ?'; $args[] = $filter; }
$sql .= ' ORDER BY o.order_id DESC LIMIT 200';
$st = $db->prepare($sql); $st->execute($args);
$orders = $st->fetchAll();

require __DIR__ . '/_header.php';
?>
<h1>Orders</h1>
<p>
  <a class="btn btn-sm <?= $filter === '' ? 'btn-orange' : 'btn-light' ?>" href="<?= url('admin/orders.php') ?>">All</a>
  <?php foreach ($statuses as $v): ?><a class="btn btn-sm <?= $filter === $v ? 'btn-orange' : 'btn-light' ?>" href="<?= url('admin/orders.php?status=' . urlencode($v)) ?>"><?= e($v) ?></a> <?php endforeach; ?>
</p>
<div class="panel" style="overflow-x:auto">
<table class="tbl">
  <thead><tr><th>Order</th><th>Customer &amp; delivery</th><th>Items</th><th class="r">Total</th><th>Payment</th><th>Status</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach ($orders as $o): $items = $svc->items((int)$o['order_id']); $isCod = $o['payment_method'] === 'COD'; ?>
    <tr id="o<?= (int)$o['order_id'] ?>">
      <td><strong>#<?= (int)$o['order_id'] ?></strong><br><small><?= e(date('d M Y, h:i A', strtotime((string)$o['created_at']))) ?></small></td>
      <td><?= e($o['shipping_name']) ?><br>📞 <a href="tel:<?= e($o['shipping_phone']) ?>"><?= e($o['shipping_phone']) ?></a><br><small><?= e($o['email']) ?></small>
        <details class="addr"><summary>Address</summary><?= nl2br(e($o['shipping_address'])) ?></details></td>
      <td><?php foreach ($items as $it): ?><div><?= e($it['name']) ?> × <?= (int)$it['quantity'] ?></div><?php endforeach; ?></td>
      <td class="r"><?= money($o['total_amount']) ?></td>
      <td><?= $isCod ? 'COD' : 'Razorpay' ?><br><span class="pill <?= e($o['payment_status']) ?>"><?= e($o['payment_status']) ?></span>
        <?php if ($isCod && $o['payment_status'] === 'Paid' && $o['cod_collected_at']): ?><br><small>Cash collected<br><?= e(date('d M, h:i A', strtotime((string)$o['cod_collected_at']))) ?></small><?php endif; ?></td>
      <td><span class="pill <?= e($o['order_status']) ?>"><?= e($o['order_status']) ?></span>
        <?php if ($o['order_status'] === 'Out for Delivery' && $o['delivery_otp_expires']): ?><br><small>OTP sent · valid till<br><?= e(date('d M, h:i A', strtotime((string)$o['delivery_otp_expires']))) ?></small><?php endif; ?>
        <?php if ($o['order_status'] === 'Delivered' && $o['delivered_at']): ?><br><small><?= e(date('d M, h:i A', strtotime((string)$o['delivered_at']))) ?></small><?php endif; ?></td>
      <td>
        <?php if (in_array($o['order_status'], ['Cancelled', 'Delivered'], true)): ?><small>Final</small>
        <?php else: ?>
          <form class="inline-form" method="post" style="flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int)$o['order_id'] ?>"><input type="hidden" name="action" value="status">
            <select name="status"><?php foreach ($manual as $v): ?><option <?= $v === $o['order_status'] ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
            <input name="courier" placeholder="Courier (Shipped)" maxlength="80" style="width:120px;padding:5px;border:1px solid #bbb;border-radius:4px"><input name="tracking" placeholder="Tracking no." maxlength="80" style="width:110px;padding:5px;border:1px solid #bbb;border-radius:4px">
            <button class="btn btn-sm">Update</button></form>
          <?php
            $cnPending = $o['cancel_otp'] && strtotime((string)$o['cancel_otp_expiry']) > time();
            [$cn, $cd] = array_pad(explode('@', (string)$o['email'], 2), 2, '');
          ?>
          <div style="margin-top:8px"><button type="button" class="btn btn-sm btn-danger js-cancel" data-order="<?= (int)$o['order_id'] ?>"
              data-email="<?= e(substr($cn, 0, 1) . '***@' . $cd) ?>"
              data-pending="<?= $cnPending ? max(1, (int)strtotime((string)$o['cancel_otp_expiry']) - time()) : 0 ?>"
              data-resend="<?= max(0, OrderService::CANCEL_RESEND_S - (time() - (int)strtotime((string)$o['cancel_otp_sent_at']))) ?>">Cancel Order</button></div>
          <?php if ($o['order_status'] === 'Out for Delivery'): ?>
            <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
              <button type="button" class="btn btn-orange btn-sm js-verify"
                      data-order="<?= (int)$o['order_id'] ?>" data-cod="<?= $isCod ? '1' : '0' ?>" data-amount="<?= e(money($o['total_amount'])) ?>" data-name="<?= e($o['shipping_name']) ?>">
                <?= $isCod ? 'Verify Delivery &amp; Collect Cash' : 'Verify Delivery' ?></button>
              <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int)$o['order_id'] ?>"><input type="hidden" name="action" value="resend_otp">
                <button class="btn btn-light btn-sm" title="Email a new OTP to the customer">Resend OTP</button></form>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$orders) echo '<tr><td colspan="7">No orders found.</td></tr>'; ?>
  </tbody>
</table></div>

<dialog id="otpDialog" class="otp-dialog">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="verify_delivery">
    <input type="hidden" name="order_id" id="dlgOrder">
    <h3 style="margin-top:0">Verify Delivery <span id="dlgOrderLabel"></span></h3>
    <p id="dlgName" style="margin:0 0 10px;color:#565959"></p>
    <div id="dlgCod" class="cash-box" hidden>Collect cash from customer<br><strong id="dlgAmount"></strong></div>
    <div id="dlgPrepaid" class="cash-box paid" hidden>Prepaid order - no cash to collect</div>
    <div class="field"><label for="dlgOtp">Customer's 4-digit delivery OTP</label>
      <input id="dlgOtp" class="otp-input" name="otp" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="off" placeholder="••••" required></div>
    <label id="dlgCashRow" style="display:block;margin-bottom:12px" hidden><input type="checkbox" name="cash_collected" value="1" id="dlgCash"> I have collected the full cash amount</label>
    <div style="display:flex;gap:8px"><button class="btn btn-orange" type="submit" id="dlgSubmit">Confirm Delivery</button><button class="btn btn-light" type="button" id="dlgClose">Cancel</button></div>
  </form>
</dialog>
<script>
(function () {
  const dlg = document.getElementById('otpDialog');
  document.querySelectorAll('.js-verify').forEach((b) => b.addEventListener('click', () => {
    const cod = b.dataset.cod === '1';
    document.getElementById('dlgOrder').value = b.dataset.order;
    document.getElementById('dlgOrderLabel').textContent = '#' + b.dataset.order;
    document.getElementById('dlgName').textContent = 'Customer: ' + b.dataset.name;
    document.getElementById('dlgAmount').textContent = b.dataset.amount;
    document.getElementById('dlgCod').hidden = !cod;
    document.getElementById('dlgPrepaid').hidden = cod;
    document.getElementById('dlgCashRow').hidden = !cod;
    document.getElementById('dlgCash').required = cod;
    document.getElementById('dlgSubmit').textContent = cod ? 'Confirm & Collect Cash' : 'Confirm Delivery';
    document.getElementById('dlgOtp').value = '';
    dlg.showModal(); document.getElementById('dlgOtp').focus();
  }));
  document.getElementById('dlgClose').addEventListener('click', () => dlg.close());
  dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
  document.getElementById('dlgOtp').addEventListener('input', (e) => { e.target.value = e.target.value.replace(/\D/g, '').slice(0, 4); });
})();
</script>

<dialog id="cancelDialog" class="otp-dialog" style="width:min(460px,94vw)">
  <h3 style="margin-top:0">Cancel order <span id="cnOrder"></span></h3>
  <div id="cnMsg" class="alert alert-error" hidden></div>
  <div data-step="1">
    <div class="field"><label for="cnReason">Reason for cancellation</label><textarea id="cnReason" rows="4" maxlength="500" placeholder="Shown to the customer in the OTP email"></textarea></div>
    <div style="display:flex;gap:8px"><button type="button" class="btn btn-orange" id="cnSend">Send OTP to Customer</button><button type="button" class="btn btn-light js-cn-close">Close</button></div>
  </div>
  <div data-step="2" hidden>
    <p>OTP emailed to <strong id="cnEmail"></strong>. Ask the customer for the 6-digit code.</p>
    <div class="field"><input class="otp-input" id="cnOtp" inputmode="numeric" maxlength="6" autocomplete="off" placeholder="••••••"></div>
    <p class="timer">Expires in <strong id="cnTimer">--:--</strong></p>
    <div style="display:flex;gap:8px;flex-wrap:wrap"><button type="button" class="btn btn-danger" id="cnVerify">Verify &amp; Cancel</button><button type="button" class="btn btn-light" id="cnResend" disabled>Resend OTP</button><button type="button" class="btn btn-light js-cn-close">Close</button></div>
  </div>
  <div data-step="3" hidden><div class="alert alert-success" id="cnDone"></div><button type="button" class="btn btn-orange js-cn-close">Close</button></div>
</dialog>
<script>
(function () {
  const dlg = document.getElementById('cancelDialog'), $ = (s) => dlg.querySelector(s), st = { id: 0, row: null, t: null };
  const step = (n) => dlg.querySelectorAll('[data-step]').forEach((x) => (x.hidden = x.dataset.step !== String(n)));
  const msg = (t) => { const m = $('#cnMsg'); m.textContent = t || ''; m.hidden = !t; };
  function timers(e, r) {
    clearInterval(st.t); const te = $('#cnTimer'), rb = $('#cnResend');
    const tick = () => { te.textContent = e > 0 ? String(Math.floor(e / 60)).padStart(2, '0') + ':' + String(e % 60).padStart(2, '0') : 'expired';
      rb.textContent = r > 0 ? 'Resend OTP (' + r + 's)' : 'Resend OTP'; rb.disabled = r > 0; if (e <= 0 && r <= 0) clearInterval(st.t); e--; r--; };
    tick(); st.t = setInterval(tick, 1000);
  }
  async function send(btn) {
    msg(''); btn.disabled = true;
    const j = await postJSON(BASE_URL + '/admin/send_cancel_otp.php', { order_id: st.id, reason: $('#cnReason').value.trim() });
    btn.disabled = false;
    if (!j.ok) return msg(j.message);
    $('#cnEmail').textContent = j.email; $('#cnOtp').value = ''; step(2); timers(j.expires_in, j.resend_in); $('#cnOtp').focus();
  }
  document.querySelectorAll('.js-cancel').forEach((b) => b.addEventListener('click', () => {
    st.id = b.dataset.order; st.row = b.closest('tr'); $('#cnOrder').textContent = '#' + st.id; $('#cnReason').value = ''; msg('');
    const pend = parseInt(b.dataset.pending, 10);
    if (pend > 0) { $('#cnEmail').textContent = b.dataset.email; $('#cnOtp').value = ''; step(2); timers(pend, parseInt(b.dataset.resend, 10) || 0); } else step(1);
    dlg.showModal();
  }));
  dlg.querySelectorAll('.js-cn-close').forEach((b) => b.addEventListener('click', () => { clearInterval(st.t); dlg.close(); }));
  $('#cnSend').addEventListener('click', (e) => send(e.currentTarget));
  $('#cnResend').addEventListener('click', (e) => send(e.currentTarget));
  $('#cnOtp').addEventListener('input', (e) => { e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6); });
  $('#cnVerify').addEventListener('click', async (e) => {
    msg(''); const otp = $('#cnOtp').value; if (otp.length !== 6) return msg('Enter the 6-digit OTP.');
    e.currentTarget.disabled = true;
    const j = await postJSON(BASE_URL + '/admin/verify_cancel_otp.php', { order_id: st.id, otp });
    e.currentTarget.disabled = false;
    if (!j.ok) return msg(j.message);
    clearInterval(st.t); $('#cnDone').textContent = j.message; step(3);
    if (st.row) {   // update the row in place: status pill + actions
      const sc = st.row.cells[5]; sc.textContent = ''; const p = document.createElement('span'); p.className = 'pill Cancelled'; p.textContent = 'Cancelled'; sc.appendChild(p);
      const ac = st.row.cells[6]; ac.textContent = ''; const f = document.createElement('small'); f.textContent = 'Final'; ac.appendChild(f);
    }
  });
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
