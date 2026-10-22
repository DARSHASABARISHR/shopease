<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$u = current_user();
if (!$u || $u['role'] !== 'admin') json_response(['error' => 'Forbidden'], 403);
$db = Database::getConnection();
$range = (($_GET['range'] ?? '') === 'monthly') ? 'monthly' : 'daily';

// ---- build the time axis ----
$keys = []; $labels = [];
if ($range === 'daily') {
    $fmt = '%Y-%m-%d';
    for ($i = 29; $i >= 0; $i--) { $t = strtotime("-$i day"); $keys[] = date('Y-m-d', $t); $labels[] = date('d M', $t); }
    $start = $keys[0] . ' 00:00:00';
} else {
    $fmt = '%Y-%m';
    for ($i = 11; $i >= 0; $i--) { $t = strtotime(date('Y-m-01') . " -$i month"); $keys[] = date('Y-m', $t); $labels[] = date('M Y', $t); }
    $start = $keys[0] . '-01 00:00:00';
}

try {
    /* ---- 1. Orders + revenue ---- */
    $st = $db->prepare("SELECT DATE_FORMAT(created_at, '$fmt') AS k, COUNT(*) AS orders,
                               COALESCE(SUM(CASE WHEN payment_status = 'Paid' THEN total_amount END), 0) AS revenue
                        FROM orders
                        WHERE created_at >= ? AND order_status <> 'Cancelled'
                          AND NOT (payment_method = 'Razorpay' AND payment_status IN ('Pending','Failed'))
                        GROUP BY k");
    $st->execute([$start]);
    $byKey = [];
    foreach ($st->fetchAll() as $r) $byKey[$r['k']] = $r;
    $orders = []; $revenue = [];
    foreach ($keys as $k) { $orders[] = (int)($byKey[$k]['orders'] ?? 0); $revenue[] = round((float)($byKey[$k]['revenue'] ?? 0), 2); }

    /* ---- 2. Stock depletion per product (net of cancellations) ---- */
    $st = $db->prepare("SELECT sl.product_id, p.name, DATE_FORMAT(sl.created_at, '$fmt') AS k, -SUM(sl.change_qty) AS units
                        FROM stock_log sl JOIN products p ON p.product_id = sl.product_id
                        WHERE sl.created_at >= ? AND sl.reason IN ('order','cancel')
                        GROUP BY sl.product_id, p.name, k");
    $st->execute([$start]);
    $perProduct = []; $totals = [];
    foreach ($st->fetchAll() as $r) {
        $perProduct[$r['product_id']]['name'] = $r['name'];
        $perProduct[$r['product_id']]['data'][$r['k']] = max(0, (int)$r['units']);
        $totals[$r['product_id']] = ($totals[$r['product_id']] ?? 0) + max(0, (int)$r['units']);
    }
    arsort($totals);
    $series = [];
    foreach (array_slice(array_keys($totals), 0, 5, true) as $pid) {
        if ($totals[$pid] <= 0) continue;
        $row = [];
        foreach ($keys as $k) $row[] = $perProduct[$pid]['data'][$k] ?? 0;
        $series[] = ['name' => $perProduct[$pid]['name'], 'data' => $row, 'total' => $totals[$pid]];
    }

    /* ---- 3. Current stock levels: lowest first, colour coded ---- */
    $th = LOW_STOCK_THRESHOLD;
    $st = $db->query('SELECT name, stock_quantity FROM products ORDER BY stock_quantity ASC, name ASC LIMIT 12');
    $sl = []; $sv = []; $sc = [];
    foreach ($st->fetchAll() as $r) {
        $q = (int)$r['stock_quantity'];
        $sl[] = mb_strimwidth($r['name'], 0, 28, '…'); $sv[] = $q;
        $sc[] = $q === 0 ? '#b12704' : ($q <= $th ? '#ff9900' : '#067d62');
    }
    $out = (int)$db->query('SELECT COUNT(*) FROM products WHERE stock_quantity = 0')->fetchColumn();
    $low = (int)$db->query("SELECT COUNT(*) FROM products WHERE stock_quantity > 0 AND stock_quantity <= $th")->fetchColumn();

    /* ---- 4. Latest stock movements ---- */
    $moves = [];
    foreach ($db->query("SELECT sl.change_qty, sl.reason, sl.order_id, sl.created_at, p.name
                         FROM stock_log sl JOIN products p ON p.product_id = sl.product_id ORDER BY sl.log_id DESC LIMIT 12")->fetchAll() as $m) {
        $moves[] = ['product' => $m['name'], 'qty' => (int)$m['change_qty'], 'reason' => $m['reason'], 'order' => $m['order_id'] ? (int)$m['order_id'] : null,
                    'time' => date('d M, h:i A', strtotime((string)$m['created_at']))];
    }
} catch (Throwable $e) {
    error_log('chart_data: ' . $e->getMessage());
    json_response(['error' => 'Could not load chart data. Have you run sql/analytics_whatsapp_update.sql in phpMyAdmin?'], 500);
}

json_response([
    'range' => $range,
    'sales' => ['labels' => $labels, 'orders' => $orders, 'revenue' => $revenue],
    'depletion' => ['labels' => $labels, 'series' => $series],
    'stock' => ['labels' => $sl, 'values' => $sv, 'colors' => $sc, 'threshold' => $th, 'out' => $out, 'low' => $low],
    'moves' => $moves,
]);
