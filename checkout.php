<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
$summary = cart_summary();
if (!$summary['items'] || $summary['count'] < 1) { flash('info', 'Your cart is empty.'); redirect('cart.php'); }
foreach ($summary['items'] as $it) if (!empty($it['out'])) { flash('error', '"' . $it['name'] . '" is out of stock. Remove it to continue.'); redirect('cart.php'); }

$states = ['Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chhattisgarh','Goa','Gujarat','Haryana','Himachal Pradesh','Jharkhand','Karnataka','Kerala','Madhya Pradesh','Maharashtra','Manipur','Meghalaya','Mizoram','Nagaland','Odisha','Punjab','Rajasthan','Sikkim','Tamil Nadu','Telangana','Tripura','Uttar Pradesh','Uttarakhand','West Bengal','Delhi','Jammu and Kashmir','Ladakh','Puducherry','Chandigarh'];

// simple arithmetic captcha guarding COD orders
$a = random_int(2, 9); $b = random_int(2, 9);
$_SESSION['cod_captcha'] = $a + $b;

$pageTitle = 'Checkout';
require __DIR__ . '/includes/header.php';
?>
<h2 class="section-title"><?= e(t('checkout')) ?></h2>
<form id="checkoutForm" class="layout2" novalidate>
  <div>
    <section class="panel" style="margin-bottom:16px">
      <h3 style="margin-top:0">1. <?= e(t('shipping_address')) ?></h3>
      <div class="row2">
        <div class="field"><label><?= e(t('full_name')) ?></label><input name="name" value="<?= e($user['full_name']) ?>" required maxlength="120"></div>
        <div class="field"><label><?= e(t('mobile')) ?></label><input name="phone" value="<?= e($user['phone']) ?>" inputmode="numeric" maxlength="10" placeholder="10-digit mobile" required></div>
      </div>
      <div class="field"><label><?= e(t('street')) ?></label><textarea name="street" rows="2" required><?= e($user['address']) ?></textarea></div>
      <div class="field" style="max-width:260px"><label><?= e(t('pincode')) ?></label>
        <input name="pincode" value="<?= e((string)$user['pincode']) ?>" inputmode="numeric" maxlength="6" placeholder="6-digit pincode" data-pincode required>
        <div class="pin-status" data-pin-status aria-live="polite"></div></div>
      <div class="row2">
        <div class="field"><label><?= e(t('city_district')) ?></label><input name="city" value="<?= e((string)$user['city']) ?>" placeholder="Auto-filled from pincode" data-pin-city readonly required></div>
        <div class="field"><label><?= e(t('state')) ?></label><input name="state" value="" placeholder="Auto-filled from pincode" data-pin-state readonly required></div>
      </div>
    </section>

    <section class="panel">
      <h3 style="margin-top:0">2. <?= e(t('payment_method')) ?></h3>
      <label class="pay-opt"><input type="radio" name="method" value="razorpay" checked>
        <span><strong><?= e(t('online_payment')) ?></strong> <small>(Razorpay)</small><br><small>UPI, GPay, PhonePe, Credit/Debit Cards, Net Banking, Wallets</small></span></label>
      <label class="pay-opt"><input type="radio" name="method" value="cod">
        <span><strong><?= e(t('cod')) ?></strong><br><small>Pay in cash when your order arrives</small></span></label>
      <div id="codBox" class="captcha" hidden>
        <label for="captcha"><strong>Confirm you're human:</strong> what is <span id="capQ"><?= $a ?> + <?= $b ?></span> ?</label>
        <input id="captcha" name="captcha" inputmode="numeric" style="width:90px;margin-left:8px;padding:8px">
        <label style="display:block;margin-top:10px"><input type="checkbox" name="confirm_cod" value="1"> I confirm this Cash on Delivery order and will pay on delivery.</label>
      </div>
    </section>
  </div>

  <aside class="panel summary">
    <h3><?= e(t('order_summary')) ?></h3>
    <?php foreach ($summary['items'] as $it): ?>
      <div class="sum-row" style="font-size:13px"><span><?= e($it['name']) ?> × <?= (int)$it['qty'] ?></span><span><?= money($it['line_total']) ?></span></div>
    <?php endforeach; ?>
    <hr>
    <div class="sum-row"><span><?= e(t('items_total')) ?></span><span><?= money($summary['items_total']) ?></span></div>
    <div class="sum-row"><span>Delivery</span><span><?= $summary['delivery'] > 0 ? money($summary['delivery']) : '<span class="save">FREE</span>' ?></span></div>
    <div class="sum-row"><span>GST (<?= rtrim(rtrim(number_format(GST_RATE, 2), '0'), '.') ?>%)</span><span><?= money($summary['gst']) ?></span></div>
    <div class="sum-row total"><span><?= e(t('net_payable')) ?></span><span><?= money($summary['total']) ?></span></div>
    <div id="formErr" class="alert alert-error" hidden style="margin-top:12px"></div>
    <button id="placeBtn" class="btn btn-orange btn-block" type="submit" style="margin-top:12px">Pay <?= e(money($summary['total'])) ?></button>
  </aside>
</form>

<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(function () {
  const form = document.getElementById('checkoutForm'), btn = document.getElementById('placeBtn'), errBox = document.getElementById('formErr');
  const codBox = document.getElementById('codBox'), total = <?= json_encode(money($summary['total'])) ?>;
  const showErr = (m) => { errBox.textContent = m; errBox.hidden = !m; if (m) errBox.scrollIntoView({ behavior: 'smooth', block: 'center' }); };
  const method = () => form.querySelector('input[name=method]:checked').value;

  function syncMethod() {
    codBox.hidden = method() !== 'cod';
    btn.textContent = method() === 'cod' ? 'Place COD Order' : 'Pay ' + total;
  }
  form.querySelectorAll('input[name=method]').forEach((r) => r.addEventListener('change', syncMethod));
  syncMethod();

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault(); showErr('');
    const fd = new FormData(form);
    const d = Object.fromEntries(fd.entries());
    // client-side validation (server re-validates everything)
    if (!d.name || d.name.trim().length < 2) return showErr('Enter the full name.');
    if (!/^[6-9][0-9]{9}$/.test(d.phone || '')) return showErr('Enter a valid 10-digit Indian mobile number.');
    if (!d.street || d.street.trim().length < 5) return showErr('Enter the street address.');
    if (!d.city || !d.state) return showErr('Enter city and select state.');
    if (!/^[1-9][0-9]{5}$/.test(d.pincode || '')) return showErr('Enter a valid 6-digit pincode.');

    btn.disabled = true; const label = btn.textContent; btn.textContent = 'Please wait...';
    const j = await postJSON(BASE_URL + '/create_order.php', d);
    if (!j.ok) {
      showErr(j.message || 'Could not place the order.');
      if (j.captcha) document.getElementById('capQ').textContent = j.captcha;
      btn.disabled = false; btn.textContent = label; return;
    }
    if (j.redirect) { location.href = j.redirect; return; }        // COD

    // ---- Razorpay Standard Checkout ----
    const rzp = new Razorpay({
      key: j.key,
      amount: j.amount,                 // paise (amount * 100)
      currency: 'INR',
      name: 'ShopEase',
      description: 'Order #' + j.order_id,
      order_id: j.razorpay_order_id,
      prefill: { name: d.name, email: j.email, contact: d.phone },
      theme: { color: '#ff9900' },
      handler: async function (resp) {
        btn.textContent = 'Verifying payment...';
        const v = await postJSON(BASE_URL + '/verify_payment.php', {
          razorpay_payment_id: resp.razorpay_payment_id,
          razorpay_order_id: resp.razorpay_order_id,
          razorpay_signature: resp.razorpay_signature
        });
        if (v.ok) location.href = v.redirect;
        else { showErr(v.message || 'Payment verification failed. If money was deducted, contact support with order #' + j.order_id); btn.disabled = false; btn.textContent = label; }
      },
      modal: { ondismiss: async function () {
        await postJSON(BASE_URL + '/verify_payment.php', { razorpay_order_id: j.razorpay_order_id, failed: 1 });
        showErr('Payment was cancelled. You can try again.'); btn.disabled = false; btn.textContent = label;
        // the held order was cancelled server-side; reload to get a fresh captcha / token
        setTimeout(() => location.reload(), 1800);
      } }
    });
    rzp.on('payment.failed', function (r) { showErr('Payment failed: ' + (r.error && r.error.description ? r.error.description : 'please try again')); });
    rzp.open();
  });
})();
</script>
<script src="<?= BASE_URL ?>/assets/js/pincode.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
