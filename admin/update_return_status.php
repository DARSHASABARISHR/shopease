<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/returns.php';
require_once __DIR__ . '/../includes/email_templates.php';
require_once __DIR__ . '/../config/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
csrf_require(true);
$admin = current_user();
if (!$admin || $admin['role'] !== 'admin') json_response(['ok' => false, 'message' => 'Forbidden'], 403);

$db = Database::getConnection();
$id = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? 'status');     // status | send_pickup_otp | verify_pickup_otp
const PICKUP_TTL = 600; const PICKUP_COOLDOWN = 45; const PICKUP_MAX_ATTEMPTS = 5;

$db->beginTransaction();
try {
    $st = $db->prepare('SELECT r.*, u.full_name, u.email, p.name AS product FROM returns r JOIN users u ON u.user_id = r.user_id JOIN products p ON p.product_id = r.product_id WHERE r.id = ? FOR UPDATE');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) { $db->rollBack(); json_response(['ok' => false, 'message' => 'Return request not found.'], 404); }

    /* ---------- 1) Approve / Reject (Picked_Up is NOT allowed here) ---------- */
    if ($action === 'status') {
        $new = (string)($_POST['status'] ?? '');
        if ($new === 'Picked_Up') { $db->rollBack(); json_response(['ok' => false, 'message' => 'Pickup must be confirmed with the customer\'s pickup OTP.']); }
        $allowed = ['OTP_Verified' => ['Approved', 'Rejected'], 'Approved' => ['Rejected']];
        if (!in_array($new, $allowed[$r['return_status']] ?? [], true)) { $db->rollBack(); json_response(['ok' => false, 'message' => 'Cannot change a ' . return_status_label($r['return_status']) . ' request to ' . $new . '.']); }
        $db->prepare('UPDATE returns SET return_status = ?, pickup_otp = NULL, pickup_otp_expiry = NULL, pickup_otp_attempts = 0 WHERE id = ?')->execute([$new, $id]);
        $db->commit();
        $t = tpl_return_update($r['full_name'], $r['product'], (int)$r['order_id'], $new);
        send_mail($r['email'], $r['full_name'], $t['subject'], $t['html'], $t['text']);
        json_response(['ok' => true, 'status' => $new, 'label' => return_status_label($new), 'message' => 'Return #' . $id . ' marked ' . return_status_label($new) . '. Customer notified.']);
    }

    if ($r['return_status'] !== 'Approved') { $db->rollBack(); json_response(['ok' => false, 'message' => 'Only approved returns can be picked up.']); }

    /* ---------- 2) Generate + email the pickup OTP ---------- */
    if ($action === 'send_pickup_otp') {
        $wait = PICKUP_COOLDOWN - (time() - (int)strtotime((string)$r['pickup_otp_sent_at']));
        if ($r['pickup_otp_sent_at'] && $wait > 0) { $db->rollBack(); json_response(['ok' => false, 'message' => "Please wait $wait seconds before sending another OTP."]); }
        $otp = (string)random_int(100000, 999999);
        $db->prepare('UPDATE returns SET pickup_otp = ?, pickup_otp_expiry = ?, pickup_otp_attempts = 0, pickup_otp_sent_at = NOW() WHERE id = ?')
           ->execute([password_hash($otp, PASSWORD_BCRYPT), date('Y-m-d H:i:s', time() + PICKUP_TTL), $id]);
        $db->commit();
        $t = tpl_pickup_otp($r['full_name'], $otp, $r['product'], (int)$r['order_id']);
        if (!send_mail($r['email'], $r['full_name'], $t['subject'], $t['html'], $t['text'])) {
            $db->prepare('UPDATE returns SET pickup_otp = NULL, pickup_otp_expiry = NULL, pickup_otp_sent_at = NULL WHERE id = ?')->execute([$id]);
            json_response(['ok' => false, 'message' => 'Could not email the OTP to the customer. Check the SMTP settings.'], 500);
        }
        [$n, $d] = explode('@', (string)$r['email'], 2);
        json_response(['ok' => true, 'message' => 'Pickup OTP emailed to the customer.', 'email' => substr($n, 0, 1) . '***@' . $d, 'expires_in' => PICKUP_TTL, 'resend_in' => PICKUP_COOLDOWN]);
    }

    /* ---------- 3) Verify OTP -> Picked_Up ---------- */
    if ($action === 'verify_pickup_otp') {
        $code = preg_replace('/\D/', '', (string)($_POST['otp'] ?? ''));
        if (!$r['pickup_otp']) { $db->rollBack(); json_response(['ok' => false, 'message' => 'No pickup OTP has been sent yet.']); }
        if ((int)$r['pickup_otp_attempts'] >= PICKUP_MAX_ATTEMPTS) { $db->rollBack(); json_response(['ok' => false, 'message' => 'Too many wrong attempts. Send a new OTP.']); }
        if (strtotime((string)$r['pickup_otp_expiry']) < time()) { $db->rollBack(); json_response(['ok' => false, 'message' => 'The OTP has expired. Send a new OTP.']); }
        if (strlen($code) !== 6 || !password_verify($code, (string)$r['pickup_otp'])) {
            $db->prepare('UPDATE returns SET pickup_otp_attempts = pickup_otp_attempts + 1 WHERE id = ?')->execute([$id]);
            $db->commit();
            json_response(['ok' => false, 'message' => 'Incorrect OTP. ' . max(0, PICKUP_MAX_ATTEMPTS - ((int)$r['pickup_otp_attempts'] + 1)) . ' attempt(s) left.']);
        }
        $db->prepare("UPDATE returns SET return_status = 'Picked_Up', picked_up_at = NOW(), pickup_otp = NULL, pickup_otp_expiry = NULL, pickup_otp_attempts = 0, pickup_otp_sent_at = NULL WHERE id = ?")->execute([$id]);
        $db->commit();
        $t = tpl_return_update($r['full_name'], $r['product'], (int)$r['order_id'], 'Picked_Up');
        send_mail($r['email'], $r['full_name'], $t['subject'], $t['html'], $t['text']);
        json_response(['ok' => true, 'status' => 'Picked_Up', 'label' => return_status_label('Picked_Up'), 'message' => 'Return #' . $id . ' picked up. Customer notified.']);
    }

    $db->rollBack();
    json_response(['ok' => false, 'message' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('update_return_status: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Could not update the request.'], 500);
}
