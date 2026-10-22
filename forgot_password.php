<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/PasswordService.php';

if (current_user()) redirect('change_password.php');
$svc = new PasswordService(Database::getConnection());
$error = ''; $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (time() - (int)($_SESSION['pw_sent_at'] ?? 0) < PasswordService::RESEND_SECONDS) {
        $error = 'Please wait a few seconds before requesting another OTP.';
    } else {
        $user = $svc->findUserByEmail($email);
        $err = $user ? $svc->issueOtp($user, 'reset') : null;
        if ($err) {
            $error = $err;
        } else {
            // Same response whether or not the email exists (prevents account enumeration)
            $_SESSION['pw_reset_email'] = $email;
            $_SESSION['pw_sent_at']     = time();
            $_SESSION['pw_fake_exp']    = time() + PasswordService::OTP_MINUTES * 60;
            unset($_SESSION['pw_reset_user']);
            flash('success', 'If this email is registered, a 6-digit OTP has been sent to it.');
            redirect('reset_otp.php');
        }
    }
}
$pageTitle = 'Forgot Password';
require __DIR__ . '/includes/header.php';
?>
<div class="panel auth-box">
  <h1>Forgot password</h1>
  <p>Enter your registered email address. We'll send a 6-digit OTP to reset your password.</p>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="field"><label>Email</label><input type="email" name="email" value="<?= e($email) ?>" required autofocus></div>
    <button class="btn btn-orange btn-block" type="submit">Send OTP</button>
  </form>
  <p style="margin-top:14px"><a href="<?= url('login.php') ?>">← Back to sign in</a></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
