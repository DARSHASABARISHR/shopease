<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/OrderService.php';
$user = require_login();
$svc = new OrderService(Database::getConnection());
$order = $svc->find((int)($_GET['id'] ?? 0), (int)$user['user_id']);
if (!$order) { http_response_code(404); flash('error', 'Order not found.'); redirect('my_orders.php'); }
$items = $svc->items((int)$order['order_id']);
require_once __DIR__ . '/includes/returns.php';
$returns = [];
try {
    $rq = Database::getConnection()->prepare('SELECT id, product_id, return_status FROM returns WHERE order_id = ?');
    $rq->execute([$order['order_id']]);
    foreach ($rq->fetchAll() as $rr) $returns[(int)$rr['product_id']] = $rr;
} catch (PDOException $e) { /* returns table not installed yet */ }
$pageTitle = 'Order #' . $order['order_id'];
require __DIR__ . '/includes/header.php';
$paid = $order['payment_status'] === 'Paid';
?>
<div class="panel" style="max-width:860px;margin:0 auto" id="receipt">
  <div class="success-hero">
    <div class="tick">✓</div>
    <?php if ($order['order_status'] === 'Cancelled' || $order['payment_status'] === 'Failed'): ?>
      <h1 style="margin:0">Order not completed</h1><p>This order was cancelled or the payment did not go through.</p>
    <?php else: ?>
      <h1 style="margin:0">Order placed, thank you!</h1>
      <p>A confirmation email with your invoice has been sent to <strong><?= e($user['email']) ?></strong>.</p>
    <?php endif; ?>
  </div>
  <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <strong>Order ID:</strong> #<?= (int)$order['order_id'] ?><br>
      <strong>Date:</strong> <?= e(date('d M Y, h:i A', strtotime((string)$order['created_at']))) ?><br>
      <strong>Payment:</strong> <?= $order['payment_method'] === 'COD' ? 'Cash on Delivery' : 'Online (Razorpay)' ?>
      <span class="pill <?= e($order['payment_status']) ?>"><?= $paid ? 'PAID' : e(strtoupper($order['payment_status'])) ?></span><br>
      <strong>Order status:</strong> <span class="pill <?= e($order['order_status']) ?>"><?= e($order['order_status']) ?></span>
    </div>
    <div>
      <strong>Shipping to</strong><br><?= e($order['shipping_name']) ?><br><?= nl2br(e($order['shipping_address'])) ?><br>Mobile: <?= e($order['shipping_phone']) ?>
    </div>
  </div>
  <?php if ($order['order_status'] === 'Out for Delivery'): ?>
    <div class="alert alert-info" style="margin-top:16px">🚚 <strong>Your order is out for delivery.</strong> Share the 4-digit Delivery OTP we emailed you with the delivery agent only when you receive your package.<?php if ($order['payment_method'] === 'COD'): ?> Please keep <strong><?= money($order['total_amount']) ?></strong> ready in cash.<?php endif; ?></div>
  <?php endif; ?>
  <table class="tbl" style="margin-top:18px">
    <thead><tr><th>Product</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Total</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr><td><?= e($it['name']) ?></td><td class="r"><?= (int)$it['quantity'] ?></td><td class="r"><?= money($it['price']) ?></td><td class="r"><?= money((float)$it['price'] * (int)$it['quantity']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="3" class="r">Items total</td><td class="r"><?= money($order['items_total']) ?></td></tr>
      <tr><td colspan="3" class="r">Delivery</td><td class="r"><?= (float)$order['delivery_charge'] > 0 ? money($order['delivery_charge']) : 'FREE' ?></td></tr>
      <tr><td colspan="3" class="r">GST (<?= rtrim(rtrim(number_format(GST_RATE, 2), '0'), '.') ?>%)</td><td class="r"><?= money($order['gst_amount']) ?></td></tr>
      <tr><td colspan="3" class="r"><strong><?= $paid ? 'Amount paid' : 'Amount to pay on delivery' ?></strong></td><td class="r"><strong><?= money($order['total_amount']) ?></strong></td></tr>
    </tfoot>
  </table>
  <?php if ($order['order_status'] === 'Delivered'): ?>
  <div class="no-print" style="margin-top:20px"><h3>Returns</h3>
    <table class="tbl"><tbody>
    <?php foreach ($items as $it): $pid = (int)$it['product_id']; $rt = $returns[$pid] ?? null; $el = return_eligibility($order + $it); ?>
      <tr><td><?= e($it['name']) ?></td>
        <td data-ret-cell="<?= $pid ?>">
          <?php if ($rt && $rt['return_status'] !== 'Pending'): ?><span class="pill <?= e($rt['return_status']) ?>"><?= e(return_status_label($rt['return_status'])) ?></span>
          <?php elseif ($el['ok']): ?>
            <button type="button" class="btn btn-sm js-return" data-order="<?= (int)$order['order_id'] ?>" data-product="<?= $pid ?>" data-name="<?= e($it['name']) ?>"><?= $rt ? 'Resume return (verify OTP)' : 'Return Product' ?></button>
            <small style="color:#565959"><?= e($el['reason']) ?></small>
          <?php else: ?><span class="pill Cancelled"><?= e($el['reason']) ?></span><?php endif; ?>
        </td></tr>
    <?php endforeach; ?></tbody></table>
  </div>
  <dialog id="returnDialog" class="otp-dialog" style="width:min(440px,94vw)">
    <h3 style="margin-top:0">Return: <span id="retProduct"></span></h3>
    <div id="retMsg" class="alert alert-error" hidden></div>
    <div data-step="1">
      <div class="field"><label for="retReason">Reason for return</label><textarea id="retReason" rows="4" maxlength="500" placeholder="Describe the problem (min 10 characters)"></textarea></div>
      <div style="display:flex;gap:8px"><button type="button" class="btn btn-orange" id="retSend">Send OTP to my email</button><button type="button" class="btn btn-light js-ret-close">Cancel</button></div>
    </div>
    <div data-step="2" hidden>
      <p>Enter the 6-digit OTP sent to <strong id="retEmail"></strong>.</p>
      <div class="field"><input class="otp-input" id="retOtp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="••••••"></div>
      <p class="timer">Expires in <strong id="retTimer">--:--</strong></p>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><button type="button" class="btn btn-orange" id="retVerify">Verify OTP</button><button type="button" class="btn btn-light" id="retResend" disabled>Resend OTP</button></div>
    </div>
    <div data-step="3" hidden><div class="alert alert-success" id="retDone"></div><button type="button" class="btn btn-orange js-ret-close">Close</button></div>
  </dialog>
  <script src="<?= BASE_URL ?>/assets/js/returns.js"></script>
  <?php endif; ?>
  <div class="no-print" style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap">
    <button class="btn btn-orange" onclick="window.print()">🖨 Print receipt</button>
    <a class="btn" href="<?= url('index.php') ?>">Continue shopping</a>
    <a class="btn btn-light" href="<?= url('my_orders.php') ?>">My orders</a>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
