<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/OrderService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
csrf_require(true);
$admin = current_user();
if (!$admin || $admin['role'] !== 'admin') json_response(['ok' => false, 'message' => 'Forbidden'], 403);

// Overwrite-safe + spam-safe: per-order cooldown lives in the DB (45 s); this adds an hourly cap per admin session.
$hour = array_filter($_SESSION['cancel_otp_hour'] ?? [], fn($t) => $t > time() - 3600);
if (count($hour) >= 10) json_response(['ok' => false, 'message' => 'Too many cancellation OTPs requested. Try again later.'], 429);

$orderId = (int)($_POST['order_id'] ?? 0);
$reason  = trim(strip_tags((string)($_POST['reason'] ?? '')));
try {
    $r = (new OrderService(Database::getConnection()))->requestCancelOtp($orderId, $reason);
} catch (Throwable $e) {
    error_log('send_cancel_otp: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
if (!$r['ok']) json_response(['ok' => false, 'message' => $r['msg']]);
$hour[] = time(); $_SESSION['cancel_otp_hour'] = array_values($hour);
json_response(['ok' => true, 'message' => $r['msg'], 'email' => $r['email'], 'expires_in' => $r['expires_in'], 'resend_in' => $r['resend_in']]);
