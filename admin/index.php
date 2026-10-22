<?php
declare(strict_types=1);
$adminPage = 'dash'; $pageTitle = 'Dashboard';
require __DIR__ . '/_header.php';
$db = Database::getConnection();

$revenue   = (float)$db->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE payment_status = 'Paid' AND order_status <> 'Cancelled'")->fetchColumn();
$pendingCod = (float)$db->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE payment_method='COD' AND order_status IN ('Pending','Shipped','Out for Delivery')")->fetchColumn();
$codCollected = (float)$db->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE payment_method='COD' AND payment_status='Paid' AND order_status='Delivered'")->fetchColumn();

/* ---- Module 1: payment-method breakdown, ONE conditional-aggregation query (cancelled orders excluded) ---- */
$pm = $db->query("SELECT
    COALESCE(SUM(CASE WHEN payment_method='Razorpay' AND payment_status='Paid' AND order_status<>'Cancelled' THEN total_amount END),0) AS online_amt,
    COUNT(CASE WHEN payment_method='Razorpay' AND payment_status='Paid' AND order_status<>'Cancelled' THEN 1 END) AS online_cnt,
    COALESCE(SUM(CASE WHEN payment_method='COD' AND payment_status='Paid' AND order_status<>'Cancelled' THEN total_amount END),0) AS cod_amt,
    COUNT(CASE WHEN payment_method='COD' AND payment_status='Paid' AND order_status<>'Cancelled' THEN 1 END) AS cod_cnt,
    COALESCE(SUM(CASE WHEN payment_method='COD' AND payment_status<>'Paid' AND order_status<>'Cancelled' THEN total_amount END),0) AS pend_amt,
    COUNT(CASE WHEN payment_method='COD' AND payment_status<>'Paid' AND order_status<>'Cancelled' THEN 1 END) AS pend_cnt,
    COALESCE(SUM(CASE WHEN payment_method NOT IN ('Razorpay','COD') AND payment_status='Paid' AND order_status<>'Cancelled' THEN total_amount END),0) AS other_amt,
    COUNT(CASE WHEN payment_method NOT IN ('Razorpay','COD') AND payment_status='Paid' AND order_status<>'Cancelled' THEN 1 END) AS other_cnt,
    COUNT(*) AS total_orders
  FROM orders")->fetch();
$realAmt = (float)$pm['online_amt'] + (float)$pm['cod_amt'] + (float)$pm['other_amt'];
$realCnt = (int)$pm['online_cnt'] + (int)$pm['cod_cnt'] + (int)$pm['other_cnt'];
$aov = fn(float $a, int $n): float => $n > 0 ? $a / $n : 0.0;
$payRows = [   // label, amount, count, badge text, badge class
    ['Online Paid (Razorpay / UPI / Cards)', (float)$pm['online_amt'], (int)$pm['online_cnt'], 'Settled', 'Paid'],
    ['COD Collected', (float)$pm['cod_amt'], (int)$pm['cod_cnt'], 'Cash received', 'Paid'],
    ['COD Pending to Collect', (float)$pm['pend_amt'], (int)$pm['pend_cnt'], 'Awaiting cash', 'Pending'],
    ['Wallet / Other Payments', (float)$pm['other_amt'], (int)$pm['other_cnt'], (int)$pm['other_cnt'] ? 'Settled' : 'None yet', (int)$pm['other_cnt'] ? 'Paid' : 'Cancelled'],
];
$payJson = json_encode(['labels' => ['Online Paid', 'COD Collected', 'COD Pending', 'Wallet / Other'],
    'amounts' => array_map(fn($r) => round($r[1], 2), $payRows), 'counts' => array_map(fn($r) => $r[2], $payRows)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
$orders    = (int)$db->query("SELECT COUNT(*) FROM orders WHERE NOT (payment_method='Razorpay' AND payment_status IN ('Pending','Failed'))")->fetchColumn();
$pending   = (int)$db->query("SELECT COUNT(*) FROM orders WHERE order_status='Pending' AND NOT (payment_method='Razorpay' AND payment_status IN ('Pending','Failed'))")->fetchColumn();
$customers = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='customer' AND is_verified=1")->fetchColumn();

$low = $db->prepare('SELECT product_id, name, stock_quantity FROM products WHERE stock_quantity <= ? ORDER BY stock_quantity ASC LIMIT 15');
$low->execute([LOW_STOCK_THRESHOLD]);
$lowRows = $low->fetchAll();

$recent = $db->query("SELECT o.order_id, o.total_amount, o.order_status, o.payment_status, o.created_at, u.full_name FROM orders o JOIN users u ON u.user_id=o.user_id ORDER BY o.order_id DESC LIMIT 8")->fetchAll();
$newUsers = $db->query("SELECT full_name, email, is_verified, created_at FROM users WHERE role='customer' ORDER BY user_id DESC LIMIT 8")->fetchAll();
?>
<h1>Dashboard</h1>
<div class="stats">
  <div class="stat ok">Total revenue<b><?= money($revenue) ?></b><small>Paid online + COD cash collected</small></div>
  <div class="stat">Total orders<b><?= $orders ?></b><small><?= $pending ?> pending</small></div>
  <div class="stat blue">COD to collect<b><?= money($pendingCod) ?></b><small>Not yet delivered</small></div>
  <div class="stat ok">COD collected<b><?= money($codCollected) ?></b><small>Verified by delivery OTP</small></div>
  <div class="stat blue">Customers<b><?= $customers ?></b><small>Verified accounts</small></div>
  <div class="stat warn">Low-stock items<b><?= count($lowRows) ?></b><small>≤ <?= LOW_STOCK_THRESHOLD ?> units</small></div>
</div>

<h2 style="margin:6px 0 10px">Payment breakdown</h2>
<div class="stats">
  <div class="stat ok">Total realized revenue<b><?= money($realAmt) ?></b><small><?= $realCnt ?> paid orders · AOV <?= money($aov($realAmt, $realCnt)) ?></small></div>
  <div class="stat blue">Online paid<b><?= money($pm['online_amt']) ?></b><small><?= (int)$pm['online_cnt'] ?> orders</small></div>
  <div class="stat ok">COD collected<b><?= money($pm['cod_amt']) ?></b><small><?= (int)$pm['cod_cnt'] ?> orders</small></div>
  <div class="stat warn">COD pending to collect<b><?= money($pm['pend_amt']) ?></b><small><?= (int)$pm['pend_cnt'] ?> orders · <?= (int)$pm['total_orders'] ?> total orders</small></div>
</div>
<div class="chart-grid">
  <div class="panel"><h3 style="margin-top:0">Revenue by Payment Method</h3>
    <div id="payEmpty" class="muted" hidden>No paid orders yet.</div>
    <div class="chart-box"><canvas id="payChart"></canvas></div>
    <script type="application/json" id="payData"><?= $payJson ?></script></div>
  <div class="panel" style="overflow-x:auto"><h3 style="margin-top:0">Payment method summary</h3>
    <table class="tbl"><thead><tr><th>Payment method</th><th class="r">Orders</th><th class="r">Total collected</th><th class="r">AOV</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($payRows as $r): ?>
      <tr><td><?= e($r[0]) ?></td><td class="r"><?= $r[2] ?></td><td class="r"><?= money($r[1]) ?></td><td class="r"><?= money($aov($r[1], $r[2])) ?></td><td><span class="pill <?= e($r[4]) ?>"><?= e($r[3]) ?></span></td></tr>
    <?php endforeach; ?>
      <tr style="font-weight:700;background:#fafafa"><td>Total realized revenue</td><td class="r"><?= $realCnt ?></td><td class="r"><?= money($realAmt) ?></td><td class="r"><?= money($aov($realAmt, $realCnt)) ?></td><td><span class="pill Paid">Realized</span></td></tr>
    </tbody></table>
    <p class="muted" style="font-size:12px">Cancelled orders are excluded. AOV = amount ÷ orders for each row.</p></div>
</div>
<div id="chartErr" class="alert alert-error" hidden></div>
<div class="panel" style="margin-bottom:16px">
  <div class="chart-head"><h3 style="margin:0">Sales trend - orders &amp; revenue</h3>
    <div class="seg" id="rangeSeg"><button type="button" data-range="daily" class="on">Daily (30 days)</button><button type="button" data-range="monthly">Monthly (12 months)</button></div></div>
  <div class="chart-box"><canvas id="salesChart"></canvas></div>
</div>
<div class="chart-grid">
  <div class="panel"><h3 style="margin-top:0">Inventory depletion - units sold per product</h3>
    <div id="depEmpty" class="muted" hidden>No stock movements in this period yet.</div>
    <div class="chart-box"><canvas id="depChart"></canvas></div></div>
  <div class="panel"><h3 style="margin-top:0">Stock levels (lowest first)</h3>
    <p class="legend-row"><span><i style="background:#b12704"></i>Out of stock: <b id="stockOut">0</b></span><span><i style="background:#ff9900"></i>Low (≤ <span id="stockTh"><?= LOW_STOCK_THRESHOLD ?></span>): <b id="stockLow">0</b></span><span><i style="background:#067d62"></i>Healthy</span></p>
    <div class="chart-box"><canvas id="stockChart"></canvas></div></div>
</div>
<div class="panel" style="margin-bottom:16px"><h3 style="margin-top:0">Recent stock movements</h3>
  <div style="overflow-x:auto"><table class="tbl"><thead><tr><th>Product</th><th>Change</th><th>Reason</th><th>When</th></tr></thead><tbody id="moveBody"></tbody></table></div></div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px">
  <div class="panel"><h3 style="margin-top:0">⚠ Low-stock warnings</h3>
    <?php if (!$lowRows): ?><p>All products are well stocked.</p><?php else: ?>
    <table class="tbl"><?php foreach ($lowRows as $r): ?>
      <tr><td><a href="<?= url('admin/product_form.php?id=' . (int)$r['product_id']) ?>"><?= e($r['name']) ?></a></td>
      <td class="r"><span class="pill <?= (int)$r['stock_quantity'] === 0 ? 'Failed' : 'Pending' ?>"><?= (int)$r['stock_quantity'] === 0 ? 'Out of stock' : (int)$r['stock_quantity'] . ' left' ?></span></td></tr>
    <?php endforeach; ?></table><?php endif; ?>
  </div>
  <div class="panel"><h3 style="margin-top:0">Recent orders</h3>
    <table class="tbl"><?php foreach ($recent as $r): ?>
      <tr><td><a href="<?= url('admin/orders.php#o' . (int)$r['order_id']) ?>">#<?= (int)$r['order_id'] ?></a></td><td><?= e($r['full_name']) ?></td><td><?= money($r['total_amount']) ?></td>
      <td><span class="pill <?= e($r['order_status']) ?>"><?= e($r['order_status']) ?></span></td></tr>
    <?php endforeach; if (!$recent) echo '<tr><td>No orders yet.</td></tr>'; ?></table>
  </div>
  <div class="panel"><h3 style="margin-top:0">Customer activity</h3>
    <table class="tbl"><?php foreach ($newUsers as $r): ?>
      <tr><td><?= e($r['full_name']) ?><br><small><?= e($r['email']) ?></small></td>
      <td><?= (int)$r['is_verified'] ? '<span class="pill Paid">Verified</span>' : '<span class="pill Pending">Unverified</span>' ?></td>
      <td><small><?= e(date('d M, h:i A', strtotime((string)$r['created_at']))) ?></small></td></tr>
    <?php endforeach; if (!$newUsers) echo '<tr><td>No customers yet.</td></tr>'; ?></table>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/admin_charts.js"></script>
<script src="<?= BASE_URL ?>/assets/js/admin_payments.js"></script>
<?php require __DIR__ . '/_footer.php'; ?>
