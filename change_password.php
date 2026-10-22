<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/PasswordService.php';

$user = require_login();                       // works for customers and admins
$db   = Database::getConnection();
$svc  = new PasswordService($db);
$uid  = (int)$user['user_id'];
$mode = (($_GET['mode'] ?? '') === 'otp') ? 'otp' : 'current';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? 'change');

    if ($action === 'send_otp') {
        $wait = PasswordService::RESEND_SECONDS - (time() - (int)($_SESSION['pw_change_sent'] ?? 0));
        if ($wait > 0) { flash('error', "Please wait $wait seconds before requesting a new OTP."); }
        else {
            $err = $svc->issueOtp($user, 'change');
            if ($err) flash('error', $err);
            else { $_SESSION['pw_change_sent'] = time(); flash('success', 'OTP sent to ' . PasswordService::maskEmail($user['email']) . '.'); }
        }
        redirect('change_password.php?mode=otp');
    }

    $new = (string)($_POST['new_password'] ?? ''); $confirm = (string)($_POST['confirm_password'] ?? '');
    $error = PasswordService::validatePassword($new, $confirm) ?? '';

    if ($error === '') {
        if ($mode === 'current') {
            $fails = $_SESSION['pw_change_fail'] ?? ['n' => 0, 't' => time()];
            if ($fails['n'] >= 5 && time() - $fails['t'] < 900) {
                $error = 'Too many wrong attempts. Try again in 15 minutes or use the Email OTP method.';
            } else {
                $h = $db->prepare('SELECT password_hash FROM users WHERE user_id = ?'); $h->execute([$uid]);
                if (!password_verify((string)($_POST['current_password'] ?? ''), (string)$h->fetchColumn())) {
                    $_SESSION['pw_change_fail'] = ['n' => $fails['n'] + 1, 't' => time()];
                    $error = 'Current password is incorrect.';
                }
            }
        } else {
            $res = $svc->verifyOtp($uid, 'change', (string)($_POST['otp'] ?? ''));
            if ($res !== 'ok') $error = PasswordService::otpMessage($res);
        }
    }

    if ($error === '') {
        $svc->setPassword($uid, $new);
        $svc->markUsed($uid, 'change');
        unset($_SESSION['pw_change_fail'], $_SESSION['pw_change_sent']);
        session_regenerate_id(true);
        $t = tpl_password_changed($user['full_name']);
        send_mail($user['email'], $user['full_name'], $t['subject'], $t['html'], $t['text']);
        flash('success', 'Your password has been changed.');
        redirect($user['role'] === 'admin' ? 'admin/index.php' : 'index.php');
    }
}

$expires  = $mode === 'otp' ? $svc->expiresAt($uid, 'change') : 0;
$remain   = max(0, $expires - time());
$resendIn = max(0, PasswordService::RESEND_SECONDS - (time() - (int)($_SESSION['pw_change_sent'] ?? 0)));
$pageTitle = 'Change Password';
require __DIR__ . '/includes/header.php';
?>
<div class="panel auth-box" style="max-width:480px">
  <h1>Change password</h1>
  <div class="tabs">
    <a class="<?= $mode === 'current' ? 'on' : '' ?>" href="<?= url('change_password.php') ?>">Current password</a>
    <a class="<?= $mode === 'otp' ? 'on' : '' ?>" href="<?= url('change_password.php?mode=otp') ?>">Email OTP</a>
  </div>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

  <?php if ($mode === 'otp'): ?>
    <form method="post" style="margin-bottom:16px">
      <?= csrf_field() ?><input type="hidden" name="action" value="send_otp">
      <p>We'll email a 6-digit OTP to <strong><?= e(PasswordService::maskEmail($user['email'])) ?></strong>.</p>
      <button class="btn btn-light btn-block" type="submit" data-resend-wait="<?= $resendIn ?>" data-label="<?= $remain > 0 ? 'Resend OTP' : 'Send OTP' ?>" <?= $resendIn > 0 ? 'disabled' : '' ?>><?= $remain > 0 ? 'Resend OTP' : 'Send OTP' ?></button>
    </form>
  <?php endif; ?>

  <form method="post" data-pw-form data-pw-strict novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="change">
    <?php if ($mode === 'current'): ?>
      <div class="field"><label>Current Password</label><input type="password" name="current_password" autocomplete="current-password" required></div>
    <?php else: ?>
      <div class="field"><label>OTP</label><input class="otp-input" name="otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="••••••" required>
        <?php if ($remain > 0): ?><p class="timer">OTP expires in <strong data-countdown="<?= $remain ?>" data-expired-text="expired">--:--</strong></p><?php endif; ?></div>
    <?php endif; ?>
    <div class="field"><label>New Password</label><input type="password" name="new_password" data-pw-new autocomplete="new-password" required></div>
    <ul class="pw-rules"><li data-rule="len">At least 8 characters</li><li data-rule="alpha">Contains a letter</li><li data-rule="num">Contains a number</li></ul>
    <div class="field"><label>Confirm Password</label><input type="password" name="confirm_password" data-pw-confirm autocomplete="new-password" required><div class="pw-msg" role="alert" aria-live="polite"></div></div>
    <button class="btn btn-orange btn-block" type="submit" data-pw-submit disabled>Change password</button>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
