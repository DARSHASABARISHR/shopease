<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/OrderService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
csrf_require(true);
$user = current_user();
if (!$user) json_response(['ok' => false, 'message' => 'Please sign in again.'], 401);

try {
    $r = (new OrderService(Database::getConnection()))->customerCancel((int)($_POST['order_id'] ?? 0), (int)$user['user_id'], (string)($_POST['reason'] ?? ''));
} catch (Throwable $e) {
    error_log('customer cancel: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Something went wrong. Please try again or contact support.'], 500);
}
json_response(['ok' => $r['ok'], 'message' => $r['msg'], 'refund_id' => $r['refund_id'] ?? null, 'payment_status' => $r['payment_status'] ?? null]);
