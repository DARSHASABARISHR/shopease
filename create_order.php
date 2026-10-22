<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/OrderService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
csrf_require(true);
$user = current_user();
if (!$user) json_response(['ok' => false, 'message' => 'Please sign in again.'], 401);

$ship = [
    'name'    => clean((string)($_POST['name'] ?? '')),
    'phone'   => preg_replace('/\D/', '', (string)($_POST['phone'] ?? '')),
    'street'  => clean((string)($_POST['street'] ?? '')),
    'city'    => clean((string)($_POST['city'] ?? '')),
    'state'   => clean((string)($_POST['state'] ?? '')),
    'pincode' => preg_replace('/\D/', '', (string)($_POST['pincode'] ?? '')),
];
$method = ($_POST['method'] ?? '') === 'cod' ? 'COD' : 'Razorpay';

if (mb_strlen($ship['name']) < 2)        json_response(['ok' => false, 'message' => 'Enter the full name.']);
if (!valid_phone($ship['phone']))        json_response(['ok' => false, 'message' => 'Enter a valid 10-digit Indian mobile number.']);
if (mb_strlen($ship['street']) < 5)      json_response(['ok' => false, 'message' => 'Enter the street address.']);
if ($ship['city'] === '' || $ship['state'] === '') json_response(['ok' => false, 'message' => 'Enter city and state.']);
if (!valid_pincode($ship['pincode']))    json_response(['ok' => false, 'message' => 'Enter a valid 6-digit pincode.']);

// Double-check the state against India Post (skipped silently if the API is unreachable)
require_once __DIR__ . '/includes/pincode.php';
$pinInfo = pincode_lookup($ship['pincode']);
if ($pinInfo && !same_state($pinInfo['state'], $ship['state'])) {
    json_response(['ok' => false, 'message' => 'Pincode ' . $ship['pincode'] . ' belongs to ' . $pinInfo['state'] . ', not ' . $ship['state'] . '. Please re-enter the pincode.']);
}

if ($method === 'COD') {
    $expected = $_SESSION['cod_captcha'] ?? null;
    $a = random_int(2, 9); $b = random_int(2, 9);
    $fresh = $a . ' + ' . $b;
    if ($expected === null || (int)($_POST['captcha'] ?? -1) !== (int)$expected) {
        $_SESSION['cod_captcha'] = $a + $b;
        json_response(['ok' => false, 'message' => 'Incorrect captcha answer.', 'captcha' => $fresh]);
    }
    if (($_POST['confirm_cod'] ?? '') !== '1') {
        $_SESSION['cod_captcha'] = $a + $b;
        json_response(['ok' => false, 'message' => 'Please tick the box to confirm your Cash on Delivery order.', 'captcha' => $fresh]);
    }
    unset($_SESSION['cod_captcha']);
}

cart_summary();                                  // normalises quantities against live stock
$cart = $_SESSION['cart'] ?? [];
$svc  = new OrderService(Database::getConnection());

try {
    $orderId = $svc->create((int)$user['user_id'], $ship, $method, $cart);
} catch (RuntimeException $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Order create failed: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Could not place your order. Please try again.'], 500);
}

/* ---------- Cash on Delivery: done ---------- */
if ($method === 'COD') {
    $_SESSION['cart'] = [];
    $_SESSION['last_order'] = $orderId;
    $svc->sendInvoiceEmail($orderId);
    json_response(['ok' => true, 'redirect' => url('order_success.php?id=' . $orderId)]);
}

/* ---------- Razorpay: create gateway order ---------- */
$order  = $svc->find($orderId);
$paise  = (int)round(((float)$order['total_amount']) * 100);
$payload = json_encode([
    'amount' => $paise, 'currency' => 'INR', 'receipt' => 'SE' . $orderId,
    'notes'  => ['shopease_order_id' => (string)$orderId],
]);
$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 20,
]);
$resp = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);
curl_close($ch);

$data = is_string($resp) ? json_decode($resp, true) : null;
if ($http !== 200 || empty($data['id'])) {
    error_log("Razorpay order error HTTP $http: $cerr " . (is_string($resp) ? $resp : ''));
    $svc->markFailed($orderId);       // releases reserved stock
    json_response(['ok' => false, 'message' => 'Payment gateway is unavailable. Please try again or choose Cash on Delivery.']);
}
$svc->attachGatewayOrder($orderId, $data['id']);

json_response([
    'ok' => true, 'order_id' => $orderId, 'razorpay_order_id' => $data['id'],
    'amount' => $paise, 'key' => RAZORPAY_KEY_ID, 'email' => $user['email'],
]);
