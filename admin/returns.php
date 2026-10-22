<?php
declare(strict_types=1);
$adminPage = 'returns'; $pageTitle = 'Return Requests';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/returns.php';
require_admin();
$db = Database::getConnection();

$tabs = ['' => 'Needs action', 'OTP_Verified' => 'Requested', 'Approved' => 'Approved', 'Picked_Up' => 'Picked up', 'Rejected' => 'Rejected', 'Pending' => 'Unverified'];
$f = (string)($_GET['status'] ?? '');
$f = array_key_exists($f, $tabs) ? $f : '';
$sql = 'SELECT r.*, u.full_name, u.email, u.phone, p.name AS product FROM returns r
        JOIN users u ON u.user_id = r.user_id JOIN products p ON p.product_id = r.product_id';
$args = [];
if ($f === '') $sql .= " WHERE r.return_status IN ('OTP_Verified','Approved')"; else { $sql .= ' WHERE r.return_status = ?'; $args[] = $f; }
$st = $db->prepare($sql . ' ORDER BY r.id DESC LIMIT 200');
$st->execute($args);
$rows = $st->fetchAll();
require __DIR__ . '/_header.php';
?>
<h1>Return requests</h1>
<p><?php foreach ($tabs as $k => $l): ?><a class="btn btn-sm <?= $f === $k ? 'btn-orange' : 'btn-light' ?>" href="<?= url('admin/returns.php' . ($k !== '' ? '?status=' . $k : '')) ?>"><?= e($l) ?></a> <?php endforeach; ?></p>
<div id="retAlert" class="alert" hidden></div>
<div class="panel" style="overflow-x:auto"><table class="tbl">
  <thead><tr><th>Order</th><th>Customer</th><th>Product</th><th>Reason</th><th>Requested</th><th>Status</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr data-id="<?= (int)$r['id'] ?>">
      <td><a href="<?= url('admin/orders.php#o' . (int)$r['order_id']) ?>">#<?= (int)$r['order_id'] ?></a></td>
      <td><?= e($r['full_name']) ?><br><small><?= e($r['email']) ?><br>📞 <?= e($r['phone']) ?></small></td>
      <td><?= e($r['product']) ?></td>
      <td style="max-width:260px"><?= nl2br(e($r['return_reason'])) ?></td>
      <td><small><?= e(date('d M Y, h:i A', strtotime((string)$r['request_date']))) ?></small></td>
      <td><span class="pill <?= e($r['return_status']) ?>" data-badge><?= e(return_status_label($r['return_status'])) ?></span></td>
      <td>
        <?php if ($r['return_status'] === 'OTP_Verified'): ?>
          <button class="btn btn-sm btn-orange js-ret" data-s="Approved">Approve</button> <button class="btn btn-sm btn-danger js-ret" data-s="Rejected">Reject</button>
        <?php elseif ($r['return_status'] === 'Approved'): ?>
          <?php $pp = $r['pickup_otp'] && strtotime((string)$r['pickup_otp_expiry']) > time(); [$en, $ed] = array_pad(explode('@', (string)$r['email'], 2), 2, ''); ?>
          <button class="btn btn-sm btn-orange js-pickup" data-pending="<?= $pp ? max(1, (int)strtotime((string)$r['pickup_otp_expiry']) - time()) : 0 ?>" data-resend="<?= max(0, 45 - (time() - (int)strtotime((string)$r['pickup_otp_sent_at']))) ?>" data-email="<?= e(substr($en, 0, 1) . '***@' . $ed) ?>">Mark Picked Up</button> <button class="btn btn-sm btn-danger js-ret" data-s="Rejected">Reject</button>
        <?php else: ?><small>—</small><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$rows) echo '<tr><td colspan="7">No return requests here.</td></tr>'; ?>
  </tbody></table></div>
<script>
document.querySelectorAll('.js-ret').forEach((b) => b.addEventListener('click', async () => {
  const tr = b.closest('tr'), s = b.dataset.s;
  if (s === 'Rejected' && !confirm('Reject this return request?')) return;
  tr.querySelectorAll('button').forEach((x) => (x.disabled = true));
  const j = await postJSON(BASE_URL + '/admin/update_return_status.php', { id: tr.dataset.id, status: s });
  const a = document.getElementById('retAlert'); a.hidden = false; a.className = 'alert alert-' + (j.ok ? 'success' : 'error'); a.textContent = j.message || 'Error';
  if (j.ok) setTimeout(() => location.reload(), 900); else tr.querySelectorAll('button').forEach((x) => (x.disabled = false));
}));
</script>

<dialog id="pickDialog" class="otp-dialog" style="width:min(440px,94vw)">
  <h3 style="margin-top:0">Confirm pickup <span id="pkNum"></span></h3>
  <div id="pkMsg" class="alert alert-error" hidden></div>
  <p>The customer received a 6-digit pickup OTP at <strong id="pkEmail"></strong>. Enter it after collecting the item.</p>
  <div class="field"><input class="otp-input" id="pkOtp" inputmode="numeric" maxlength="6" autocomplete="off" placeholder="••••••"></div>
  <p class="timer">Expires in <strong id="pkTimer">--:--</strong></p>
  <div style="display:flex;gap:8px;flex-wrap:wrap"><button type="button" class="btn btn-orange" id="pkVerify">Verify &amp; mark picked up</button><button type="button" class="btn btn-light" id="pkResend" disabled>Resend OTP</button><button type="button" class="btn btn-light" id="pkClose">Close</button></div>
</dialog>
<script>
(function () {
  const dlg = document.getElementById('pickDialog'), $ = (i) => document.getElementById(i), st = { id: 0, row: null, t: null };
  const msg = (t) => { $('pkMsg').textContent = t || ''; $('pkMsg').hidden = !t; };
  const top = (t, ok) => { const a = $('retAlert'); a.hidden = false; a.className = 'alert alert-' + (ok ? 'success' : 'error'); a.textContent = t; };
  function timers(e, r) {
    clearInterval(st.t);
    const tick = () => { $('pkTimer').textContent = e > 0 ? String(Math.floor(e / 60)).padStart(2, '0') + ':' + String(e % 60).padStart(2, '0') : 'expired';
      $('pkResend').textContent = r > 0 ? 'Resend OTP (' + r + 's)' : 'Resend OTP'; $('pkResend').disabled = r > 0; if (e <= 0 && r <= 0) clearInterval(st.t); e--; r--; };
    tick(); st.t = setInterval(tick, 1000);
  }
  function openDlg(email, e, r) { $('pkNum').textContent = '#' + st.id; $('pkEmail').textContent = email; $('pkOtp').value = ''; msg(''); timers(e, r); if (!dlg.open) dlg.showModal(); $('pkOtp').focus(); }
  async function send(btn) {
    btn.disabled = true;
    const j = await postJSON(BASE_URL + '/admin/update_return_status.php', { id: st.id, action: 'send_pickup_otp' });
    btn.disabled = false;
    if (!j.ok) { if (dlg.open) msg(j.message); else top(j.message, false); return; }
    openDlg(j.email, j.expires_in, j.resend_in);
  }
  document.querySelectorAll('.js-pickup').forEach((b) => b.addEventListener('click', () => {
    st.row = b.closest('tr'); st.id = st.row.dataset.id; const pend = parseInt(b.dataset.pending, 10);
    if (pend > 0) openDlg(b.dataset.email, pend, parseInt(b.dataset.resend, 10) || 0); else send(b);     // OTP already sent? just ask for it
  }));
  $('pkResend').addEventListener('click', (e) => send(e.currentTarget));
  $('pkClose').addEventListener('click', () => { clearInterval(st.t); dlg.close(); });
  $('pkOtp').addEventListener('input', (e) => { e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6); });
  $('pkVerify').addEventListener('click', async (e) => {
    msg(''); const otp = $('pkOtp').value; if (otp.length !== 6) return msg('Enter the 6-digit OTP.');
    e.currentTarget.disabled = true;
    const j = await postJSON(BASE_URL + '/admin/update_return_status.php', { id: st.id, action: 'verify_pickup_otp', otp });
    e.currentTarget.disabled = false;
    if (!j.ok) return msg(j.message);
    clearInterval(st.t); dlg.close(); top(j.message, true);
    const bd = st.row.querySelector('[data-badge]'); bd.className = 'pill Picked_Up'; bd.textContent = j.label;
    const ac = st.row.cells[6]; ac.textContent = ''; const sm = document.createElement('small'); sm.textContent = '—'; ac.appendChild(sm);
  });
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
