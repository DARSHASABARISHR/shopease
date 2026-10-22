<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/PasswordService.php';

$email = (string)($_SESSION['pw_reset_email'] ?? '');
if ($email === '') redirect('forgot_password.php');
$svc  = new PasswordService(Database::getConnection());
$user = $svc->findUserByEmail($email);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    if (($_POST['action'] ?? '') === 'resend') {
        $wait = PasswordService::RESEND_SECONDS - (time() - (int)($_SESSION['pw_sent_at'] ?? 0));
        if ($wait > 0) {
            $error = "Please wait $wait seconds before requesting a new OTP.";
        } else {
            $err = $user ? $svc->issueOtp($user, 'reset') : null;
            if ($err) $error = $err;
            else {
                $_SESSION['pw_sent_at'] = time(); $_SESSION['pw_fake_exp'] = time() + PasswordService::OTP_MINUTES * 60;
                flash('success', 'If this email is registered, a new OTP has been sent.');
                redirect('reset_otp.php');
            }
        }
    } else {
        $res = $user ? $svc->verifyOtp((int)$user['user_id'], 'reset', (string)($_POST['otp'] ?? '')) : 'invalid';
        if ($res === 'ok') {
            session_regenerate_id(true);
            $_SESSION['pw_reset_user'] = (int)$user['user_id'];
            redirect('reset_password.php');
        }
        $error = PasswordService::otpMessage($res);
    }
}

$expires  = $user ? $svc->expiresAt((int)$user['user_id'], 'reset') : (int)($_SESSION['pw_fake_exp'] ?? 0);
$remain   = max(0, $expires - time());
$resendIn = max(0, PasswordService::RESEND_SECONDS - (time() - (int)($_SESSION['pw_sent_at'] ?? 0)));
$pageTitle = 'Enter OTP';
require __DIR__ . '/includes/header.php';
?>
<div class="panel auth-box">
  <h1>Enter OTP</h1>
  <p>We sent a 6-digit code to <strong><?= e(PasswordService::maskEmail($email)) ?></strong>.</p>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="verify">
    <div class="field"><input class="otp-input" name="otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="••••••" required autofocus></div>
    <p class="timer">OTP expires in <strong data-countdown="<?= $remain ?>" data-expired-text="expired">--:--</strong></p>
    <button class="btn btn-orange btn-block" type="submit">Verify OTP</button>
  </form>
  <form method="post" style="margin-top:12px;text-align:center">
    <?= csrf_field() ?><input type="hidden" name="action" value="resend">
    <button class="btn btn-light" type="submit" data-resend-wait="<?= $resendIn ?>" data-label="Resend OTP" <?= $resendIn > 0 ? 'disabled' : '' ?>>Resend OTP</button>
  </form>
  <p style="margin-top:14px;text-align:center"><a href="<?= url('forgot_password.php') ?>">Use a different email</a></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
