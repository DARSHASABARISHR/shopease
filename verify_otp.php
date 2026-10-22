<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email_templates.php';
require_once __DIR__ . '/includes/OtpService.php';

$db = Database::getConnection();
$email = (string)($_SESSION['pending_email'] ?? '');
if ($email === '') { flash('info', 'Please register first.'); redirect('register.php'); }

$st = $db->prepare('SELECT * FROM users WHERE email = ?');
$st->execute([$email]);
$u = $st->fetch();
if (!$u) { unset($_SESSION['pending_email']); redirect('register.php'); }
if ((int)$u['is_verified'] === 1) { unset($_SESSION['pending_email']); flash('success', 'Account already verified. Please sign in.'); redirect('login.php'); }

$waOn     = wa_enabled();
$hasWa    = $waOn && !empty($u['whatsapp_number']);
$channel  = ($_SESSION['pending_channel'] ?? 'email') === 'whatsapp' && $hasWa ? 'whatsapp' : 'email';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? 'verify');

    if ($action === 'resend') {
        $want = ($_POST['channel'] ?? 'email') === 'whatsapp' ? 'whatsapp' : 'email';
        $wait = OtpService::RESEND_SECONDS - (time() - (int)($_SESSION['otp_sent_at'] ?? 0));
        $sends = array_filter($_SESSION['otp_sends'] ?? [], fn($t) => $t > time() - 3600);
        if ($wait > 0) $error = "Please wait $wait seconds before requesting another OTP.";
        elseif (count($sends) >= 5) $error = 'Too many OTP requests. Please try again after an hour.';
        elseif ($want === 'whatsapp' && !$hasWa) $error = 'WhatsApp OTP is not available for this account.';
        else {
            $otp = (string)random_int(100000, 999999);
            $db->prepare('UPDATE users SET otp_code=?, otp_expires_at=?, otp_attempts=0 WHERE user_id=?')
               ->execute([password_hash($otp, PASSWORD_BCRYPT), date('Y-m-d H:i:s', time() + OTP_TTL_MINUTES * 60), $u['user_id']]);
            $err = OtpService::deliver($want, $u, (string)$u['whatsapp_number'], $otp);
            if ($err) $error = $err . ($want === 'whatsapp' ? ' You can use "Send via Email instead".' : '');
            else {
                $_SESSION['otp_sent_at'] = time(); $sends[] = time(); $_SESSION['otp_sends'] = array_values($sends);
                $_SESSION['pending_channel'] = $want;
                flash('success', $want === 'whatsapp' ? 'A new OTP was sent to your WhatsApp ' . wa_mask((string)$u['whatsapp_number']) . '.' : 'A new OTP was sent to ' . $u['email'] . '.');
                redirect('verify_otp.php');
            }
        }
    } else {
        $code = preg_replace('/\D/', '', (string)($_POST['otp'] ?? ''));
        if ((int)$u['otp_attempts'] >= OTP_MAX_ATTEMPTS) {
            $error = 'Too many wrong attempts. Please request a new OTP.';
        } elseif (!$u['otp_code'] || !$u['otp_expires_at'] || strtotime((string)$u['otp_expires_at']) < time()) {
            $error = 'This OTP has expired. Please request a new one.';
        } elseif (strlen($code) !== 6 || !password_verify($code, (string)$u['otp_code'])) {
            $db->prepare('UPDATE users SET otp_attempts = otp_attempts + 1 WHERE user_id=?')->execute([$u['user_id']]);
            $error = 'Incorrect OTP. Please try again.';
        } else {
            // OTP received on WhatsApp proves ownership of that number (unless another account already owns it)
            $waVerified = ($channel === 'whatsapp' && !empty($u['whatsapp_number']) && !OtpService::numberTaken($db, (string)$u['whatsapp_number'], (int)$u['user_id'])) ? 1 : 0;
            $db->prepare('UPDATE users SET is_verified=1, whatsapp_verified=?, otp_code=NULL, otp_expires_at=NULL, otp_attempts=0 WHERE user_id=?')->execute([$waVerified, $u['user_id']]);
            $t = tpl_welcome($u['full_name']);
            send_mail($u['email'], $u['full_name'], $t['subject'], $t['html'], $t['text']);
            foreach (['pending_email', 'pending_channel', 'otp_sent_at', 'otp_sends'] as $k) unset($_SESSION[$k]);
            flash('success', 'Verified! Your account is active. Please sign in.');
            redirect('login.php');
        }
    }
}

$remain   = ($u['otp_expires_at'] && $u['otp_code']) ? max(0, (int)strtotime((string)$u['otp_expires_at']) - time()) : 0;
$resendIn = max(0, OtpService::RESEND_SECONDS - (time() - (int)($_SESSION['otp_sent_at'] ?? 0)));
$pageTitle = 'Verify OTP';
require __DIR__ . '/includes/header.php';
?>
<div class="panel auth-box">
  <h1>Verify your account</h1>
  <p>Enter the 6-digit code sent to
    <?php if ($channel === 'whatsapp'): ?>your WhatsApp <strong><?= e(wa_mask((string)$u['whatsapp_number'])) ?></strong>.
    <?php else: ?><strong><?= e($u['email']) ?></strong>.<?php endif; ?></p>
  <?php if ($channel === 'whatsapp' && str_starts_with(TWILIO_WHATSAPP_FROM, '+1415')): ?>
    <div class="wa-hint">Testing with the Twilio sandbox? The number must first join it: send <strong><?= e(TWILIO_SANDBOX_JOIN) ?></strong> to <strong>+<?= e(ltrim(TWILIO_WHATSAPP_FROM, '+')) ?></strong> on WhatsApp.</div>
  <?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="verify">
    <div class="field"><input class="otp-input" name="otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="••••••" required autofocus></div>
    <p class="timer">OTP expires in <strong data-countdown="<?= $remain ?>" data-expired-text="expired">--:--</strong></p>
    <button class="btn btn-orange btn-block" type="submit">Verify &amp; activate</button>
  </form>

  <div class="divider">Didn't get the code?</div>
  <div style="display:grid;gap:8px">
    <?php if ($hasWa): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><input type="hidden" name="channel" value="whatsapp">
      <button class="btn btn-wa btn-block" type="submit" data-resend-wait="<?= $resendIn ?>" data-label="Resend on WhatsApp" <?= $resendIn > 0 ? 'disabled' : '' ?>>Resend on WhatsApp</button></form>
    <?php endif; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><input type="hidden" name="channel" value="email">
      <button class="btn btn-light btn-block" type="submit" data-resend-wait="<?= $resendIn ?>" data-label="<?= $hasWa ? 'Send via Email instead' : 'Resend OTP by email' ?>" <?= $resendIn > 0 ? 'disabled' : '' ?>><?= $hasWa ? 'Send via Email instead' : 'Resend OTP by email' ?></button></form>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
