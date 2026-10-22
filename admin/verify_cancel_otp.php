<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/OrderService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
csrf_require(true);
$admin = current_user();
if (!$admin || $admin['role'] !== 'admin') json_response(['ok' => false, 'message' => 'Forbidden'], 403);

try {
    $r = (new OrderService(Database::getConnection()))->verifyCancel((int)($_POST['order_id'] ?? 0), (string)($_POST['otp'] ?? ''));
} catch (Throwable $e) {
    error_log('verify_cancel_otp: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
json_response(['ok' => $r['ok'], 'message' => $r['msg'], 'status' => $r['ok'] ? 'Cancelled' : null]);
