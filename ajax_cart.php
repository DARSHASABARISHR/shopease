<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
csrf_require(true);

$db     = Database::getConnection();
$action = (string)($_POST['action'] ?? '');
$pid    = (int)($_POST['product_id'] ?? 0);
$qty    = max(1, min(99, (int)($_POST['qty'] ?? 1)));

$stockOf = function (int $id) use ($db): ?int {
    $st = $db->prepare('SELECT stock_quantity FROM products WHERE product_id = ?');
    $st->execute([$id]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int)$v;
};

switch ($action) {
    case 'add':
        $stock = $stockOf($pid);
        if ($stock === null) json_response(['ok' => false, 'message' => 'Product not found.'], 404);
        if ($stock <= 0)     json_response(['ok' => false, 'message' => 'This product is out of stock.']);
        $new = ($_SESSION['cart'][$pid] ?? 0) + $qty;
        if ($new > $stock) { $new = $stock; $msg = 'Only ' . $stock . ' available - quantity adjusted.'; }
        $_SESSION['cart'][$pid] = $new;
        json_response(['ok' => true, 'count' => cart_count(), 'message' => $msg ?? 'Added to cart']);

    case 'update':
        if (!isset($_SESSION['cart'][$pid])) json_response(['ok' => false, 'message' => 'Item not in cart.', 'reload' => true]);
        $stock = $stockOf($pid) ?? 0;
        $msg = '';
        if ($qty > $stock) { $qty = max(1, $stock); $msg = 'Only ' . $stock . ' in stock.'; }
        $_SESSION['cart'][$pid] = $qty;
        break;

    case 'remove':
        unset($_SESSION['cart'][$pid]);
        $msg = 'Item removed';
        break;

    case 'count':
        json_response(['ok' => true, 'count' => cart_count()]);

    default:
        json_response(['ok' => false, 'message' => 'Unknown action'], 400);
}

$summary = cart_summary();
ob_start();
require __DIR__ . '/includes/cart_view.php';
$html = ob_get_clean();
json_response(['ok' => true, 'count' => cart_count(), 'html' => $html, 'message' => $msg ?? '']);
