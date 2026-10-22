<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/returns.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
csrf_require(true);
$user = current_user();
if (!$user) json_response(['ok' => false, 'message' => 'Please sign in again.'], 401);

$db = Database::getConnection();
$id = (int)($_POST['return_id'] ?? 0);
$code = preg_replace('/\D/', '', (string)($_POST['otp'] ?? ''));

$db->beginTransaction();
try {
    $st = $db->prepare('SELECT * FROM returns WHERE id = ? AND user_id = ? FOR UPDATE');
    $st->execute([$id, $user['user_id']]);
    $r = $st->fetch();
    if (!$r) { $db->rollBack(); json_response(['ok' => false, 'message' => 'Return request not found.'], 404); }
    if ($r['return_status'] !== 'Pending' || !$r['otp_code']) { $db->rollBack(); json_response(['ok' => false, 'message' => 'This request is already verified.']); }
    if ((int)$r['otp_attempts'] >= 5) { $db->rollBack(); json_response(['ok' => false, 'message' => 'Too many wrong attempts. Please request a new OTP.']); }
    if (strtotime((string)$r['otp_expiry']) < time()) { $db->rollBack(); json_response(['ok' => false, 'message' => 'The OTP has expired. Please request a new one.']); }
    if (strlen($code) !== 6 || !password_verify($code, (string)$r['otp_code'])) {
        $db->prepare('UPDATE returns SET otp_attempts = otp_attempts + 1 WHERE id = ?')->execute([$id]);
        $db->commit();
        json_response(['ok' => false, 'message' => 'Incorrect OTP. ' . max(0, 4 - (int)$r['otp_attempts']) . ' attempt(s) left.']);
    }
    $db->prepare("UPDATE returns SET return_status = 'OTP_Verified', otp_code = NULL, otp_expiry = NULL, otp_attempts = 0 WHERE id = ?")->execute([$id]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('verify_return_otp: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
json_response(['ok' => true, 'status' => 'OTP_Verified', 'label' => return_status_label('OTP_Verified'), 'message' => 'OTP verified. Your return request has been submitted for approval.']);
