<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/OrderService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
csrf_require(true);
$user = current_user();
if (!$user) json_response(['ok' => false, 'message' => 'Please sign in again.'], 401);

$svc = new OrderService(Database::getConnection());
$rzpOrderId = trim((string)($_POST['razorpay_order_id'] ?? ''));
if (!preg_match('/^order_[A-Za-z0-9]+$/', $rzpOrderId)) json_response(['ok' => false, 'message' => 'Invalid payment reference.'], 400);

$order = $svc->findByGatewayOrder($rzpOrderId, (int)$user['user_id']);
if (!$order) json_response(['ok' => false, 'message' => 'Order not found.'], 404);
$orderId = (int)$order['order_id'];

/* Customer closed the Razorpay modal / payment failed */
if (($_POST['failed'] ?? '') === '1') {
    $svc->markFailed($orderId);       // no-op if already paid
    json_response(['ok' => true, 'failed' => true]);
}

$paymentId = trim((string)($_POST['razorpay_payment_id'] ?? ''));
$signature = trim((string)($_POST['razorpay_signature'] ?? ''));
if (!preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
    json_response(['ok' => false, 'message' => 'Invalid payment response.'], 400);
}

/* Signature = HMAC_SHA256(order_id + "|" + payment_id, key_secret) */
$expected = hash_hmac('sha256', $rzpOrderId . '|' . $paymentId, RAZORPAY_KEY_SECRET);
if (!hash_equals($expected, $signature)) {
    error_log("Razorpay signature mismatch for order $orderId");
    $svc->markFailed($orderId);
    json_response(['ok' => false, 'message' => 'Payment verification failed. Order #' . $orderId . ' was not confirmed.'], 400);
}

if ($order['order_status'] === 'Cancelled' && $order['payment_status'] === 'Failed') {
    // Paid after the modal was closed/cancelled: re-reserve is not safe -> ask support
    json_response(['ok' => false, 'message' => 'This order was cancelled before the payment completed. Contact support with payment ID ' . $paymentId . ' for a refund.'], 409);
}

try {
    $first = $svc->markPaid($orderId, $paymentId);
} catch (Throwable $e) {
    error_log('markPaid failed: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Could not record payment. Contact support with payment ID ' . $paymentId], 500);
}
if ($first) {
    $_SESSION['cart'] = [];
    $svc->sendInvoiceEmail($orderId);      // automated HTML invoice
}
$_SESSION['last_order'] = $orderId;
json_response(['ok' => true, 'redirect' => url('order_success.php?id=' . $orderId)]);
