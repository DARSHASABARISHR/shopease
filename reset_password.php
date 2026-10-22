<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/PasswordService.php';

$uid = (int)($_SESSION['pw_reset_user'] ?? 0);
$db  = Database::getConnection();
$svc = new PasswordService($db);
// Must have a verified, unexpired OTP in the database - a session flag alone is not enough
if ($uid < 1 || !$svc->isVerified($uid, 'reset')) {
    unset($_SESSION['pw_reset_user']);
    flash('error', 'Your reset session has expired. Please start again.');
    redirect('forgot_password.php');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $new = (string)($_POST['new_password'] ?? ''); $confirm = (string)($_POST['confirm_password'] ?? '');
    $error = PasswordService::validatePassword($new, $confirm) ?? '';
    if ($error === '') {
        $svc->setPassword($uid, $new);
        $svc->markUsed($uid, 'reset');
        $u = $db->prepare('SELECT full_name, email FROM users WHERE user_id = ?'); $u->execute([$uid]); $u = $u->fetch();
        if ($u) { $t = tpl_password_changed($u['full_name']); send_mail($u['email'], $u['full_name'], $t['subject'], $t['html'], $t['text']); }
        foreach (['pw_reset_user', 'pw_reset_email', 'pw_sent_at', 'pw_fake_exp', 'login_fail'] as $k) unset($_SESSION[$k]);
        session_regenerate_id(true);
        flash('success', 'Password updated successfully. Please sign in with your new password.');
        redirect('login.php');
    }
}
$pageTitle = 'Set New Password';
require __DIR__ . '/includes/header.php';
?>
<div class="panel auth-box">
  <h1>Set new password</h1>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" data-pw-form data-pw-strict novalidate>
    <?= csrf_field() ?>
    <div class="field"><label>New Password</label><input type="password" name="new_password" data-pw-new autocomplete="new-password" required autofocus></div>
    <ul class="pw-rules"><li data-rule="len">At least 8 characters</li><li data-rule="alpha">Contains a letter</li><li data-rule="num">Contains a number</li></ul>
    <div class="field"><label>Confirm Password</label><input type="password" name="confirm_password" data-pw-confirm autocomplete="new-password" required><div class="pw-msg" role="alert" aria-live="polite"></div></div>
    <button class="btn btn-orange btn-block" type="submit" data-pw-submit disabled>Update password</button>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
