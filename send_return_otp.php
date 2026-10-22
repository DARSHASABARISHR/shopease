<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/returns.php';
require_once __DIR__ . '/includes/email_templates.php';
require_once __DIR__ . '/config/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
csrf_require(true);
$user = current_user();
if (!$user) json_response(['ok' => false, 'message' => 'Please sign in again.'], 401);

$db = Database::getConnection();
$orderId = (int)($_POST['order_id'] ?? 0);
$productId = (int)($_POST['product_id'] ?? 0);
$reason = trim(strip_tags((string)($_POST['return_reason'] ?? '')));
if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) json_response(['ok' => false, 'message' => 'Please describe the reason (10-500 characters).']);

// The line must belong to this customer
$st = $db->prepare('SELECT o.order_status, o.delivered_at, o.created_at, p.name, p.is_returnable, p.return_window_days
                    FROM orders o JOIN order_items oi ON oi.order_id = o.order_id JOIN products p ON p.product_id = oi.product_id
                    WHERE o.order_id = ? AND o.user_id = ? AND oi.product_id = ?');
$st->execute([$orderId, $user['user_id'], $productId]);
$row = $st->fetch();
if (!$row) json_response(['ok' => false, 'message' => 'Order item not found.'], 404);
$elig = return_eligibility($row);
if (!$elig['ok']) json_response(['ok' => false, 'message' => 'This item cannot be returned: ' . $elig['reason'] . '.']);

$ex = $db->prepare('SELECT id, return_status FROM returns WHERE order_id = ? AND product_id = ?');
$ex->execute([$orderId, $productId]);
$existing = $ex->fetch();
if ($existing && $existing['return_status'] !== 'Pending') json_response(['ok' => false, 'message' => 'A return request already exists: ' . return_status_label($existing['return_status']) . '.']);

// Throttle OTP sending (cooldown + hourly cap per session)
$key = $orderId . '-' . $productId;
$wait = 45 - (time() - (int)($_SESSION['ret_sent'][$key] ?? 0));
$hour = array_filter($_SESSION['ret_hour'] ?? [], fn($t) => $t > time() - 3600);
if ($wait > 0) json_response(['ok' => false, 'message' => "Please wait $wait seconds before requesting another OTP."]);
if (count($hour) >= 5) json_response(['ok' => false, 'message' => 'Too many OTP requests. Try again after an hour.']);

$otp = (string)random_int(100000, 999999);
$hash = password_hash($otp, PASSWORD_BCRYPT);
$exp = date('Y-m-d H:i:s', time() + 600);
if ($existing) {
    $id = (int)$existing['id'];
    $db->prepare("UPDATE returns SET return_reason=?, otp_code=?, otp_expiry=?, otp_attempts=0 WHERE id=?")->execute([$reason, $hash, $exp, $id]);
} else {
    $db->prepare("INSERT INTO returns (order_id, product_id, user_id, return_reason, return_status, otp_code, otp_expiry) VALUES (?,?,?,?, 'Pending', ?, ?)")
       ->execute([$orderId, $productId, $user['user_id'], $reason, $hash, $exp]);
    $id = (int)$db->lastInsertId();
}
$t = tpl_return_otp($user['full_name'], $otp, $row['name']);
if (!send_mail($user['email'], $user['full_name'], $t['subject'], $t['html'], $t['text'])) {
    if (!$existing) $db->prepare('DELETE FROM returns WHERE id = ?')->execute([$id]);
    json_response(['ok' => false, 'message' => 'Could not email the OTP. Please try again.'], 500);
}
$_SESSION['ret_sent'][$key] = time(); $hour[] = time(); $_SESSION['ret_hour'] = array_values($hour);
[$n, $d] = explode('@', $user['email'], 2);
json_response(['ok' => true, 'return_id' => $id, 'expires_in' => 600, 'resend_in' => 45, 'email' => substr($n, 0, 1) . '***@' . $d]);
