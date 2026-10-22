<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/OtpService.php';

if (current_user()) redirect('index.php');
if (!wa_enabled()) { flash('info', 'WhatsApp sign in is not enabled yet.'); redirect('login.php'); }

$db  = Database::getConnection();
$svc = new OtpService($db);
$phone = (string)($_SESSION['wl_phone'] ?? '');
$error = '';

/** Customers only: admins must always use their password. */
$findUser = function (string $number) use ($db): ?array {
    $st = $db->prepare("SELECT user_id, full_name, email, role FROM users WHERE whatsapp_number = ? AND whatsapp_verified = 1 AND is_verified = 1 AND role = 'customer' LIMIT 1");
    $st->execute([$number]);
    return $st->fetch() ?: null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'cancel') { foreach (['wl_phone', 'wl_sent_at', 'wl_fake_exp'] as $k) unset($_SESSION[$k]); redirect('login_whatsapp.php'); }

    if ($action === 'start' || $action === 'resend') {
        $num  = $action === 'start' ? preg_replace('/\D/', '', (string)($_POST['whatsapp'] ?? '')) : $phone;
        $via  = ($action === 'resend' && ($_POST['channel'] ?? '') === 'email') ? 'email' : 'whatsapp';
        $wait = OtpService::RESEND_SECONDS - (time() - (int)($_SESSION['wl_sent_at'] ?? 0));
        if (!valid_phone($num)) $error = 'Enter a valid 10-digit WhatsApp number.';
        elseif ($wait > 0) $error = "Please wait $wait seconds before requesting another OTP.";
        else {
            $user = $findUser($num);
            if ($user) {
                [$otp, $err] = $svc->issue((int)$user['user_id'], 'wa_login', $num);
                $err = $err ?? OtpService::deliver($via, $user, $num, $otp);
                if ($err) $error = $err . ($via === 'whatsapp' ? ' You can also use email OTP below, or password sign in.' : '');
            }
            if ($error === '') {   // identical response whether or not the number is registered
                $_SESSION['wl_phone'] = $num; $_SESSION['wl_sent_at'] = time(); $_SESSION['wl_fake_exp'] = time() + OTP_TTL_MINUTES * 60;
                flash('success', 'If this number is registered, a 6-digit OTP has been sent' . ($via === 'email' ? ' to the account email.' : ' on WhatsApp.'));
                redirect('login_whatsapp.php');
            }
        }
        $phone = $action === 'resend' ? $phone : '';
    } elseif ($action === 'verify' && $phone !== '') {
        $user = $findUser($phone);
        $res  = $user ? $svc->verify('wa_login', $phone, (string)($_POST['otp'] ?? ''), (int)$user['user_id']) : 'invalid';
        if ($res === 'ok') {
            $cart = $_SESSION['cart'] ?? [];
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['user_id'];
            $_SESSION['cart'] = $cart;
            foreach (['wl_phone', 'wl_sent_at', 'wl_fake_exp', 'login_fail'] as $k) unset($_SESSION[$k]);
            $next = $_SESSION['after_login'] ?? ''; unset($_SESSION['after_login']);
            if ($next !== '' && str_starts_with($next, BASE_URL . '/') && !str_contains($next, '//')) redirect($next);
            redirect('index.php');
        }
        $error = OtpService::message($res);
    }
}

$stage    = $phone !== '' ? 'otp' : 'phone';
$user     = $stage === 'otp' ? $findUser($phone) : null;
$expires  = $stage === 'otp' ? ($user ? $svc->expiresAt('wa_login', $phone) : (int)($_SESSION['wl_fake_exp'] ?? 0)) : 0;
$remain   = max(0, $expires - time());
$resendIn = max(0, OtpService::RESEND_SECONDS - (time() - (int)($_SESSION['wl_sent_at'] ?? 0)));
$pageTitle = 'Sign in with WhatsApp';
require __DIR__ . '/includes/header.php';
?>
<div class="panel auth-box">
  <h1>Sign in with WhatsApp</h1>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

  <?php if ($stage === 'phone'): ?>
    <p>Enter the WhatsApp number linked to your account. We'll send a 6-digit OTP.</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="start">
      <div class="field"><label>WhatsApp number</label><input name="whatsapp" inputmode="numeric" maxlength="10" placeholder="10-digit number" required autofocus></div>
      <button class="btn btn-wa btn-block" type="submit">Send OTP on WhatsApp</button>
    </form>
  <?php else: ?>
    <p>Enter the code sent to <strong><?= e(wa_mask($phone)) ?></strong>.</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="verify">
      <div class="field"><input class="otp-input" name="otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="••••••" required autofocus></div>
      <p class="timer">OTP expires in <strong data-countdown="<?= $remain ?>" data-expired-text="expired">--:--</strong></p>
      <button class="btn btn-orange btn-block" type="submit">Verify &amp; sign in</button>
    </form>
    <div class="divider">Didn't get the code?</div>
    <div style="display:grid;gap:8px">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><input type="hidden" name="channel" value="whatsapp">
        <button class="btn btn-wa btn-block" data-resend-wait="<?= $resendIn ?>" data-label="Resend on WhatsApp" <?= $resendIn > 0 ? 'disabled' : '' ?>>Resend on WhatsApp</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><input type="hidden" name="channel" value="email">
        <button class="btn btn-light btn-block" data-resend-wait="<?= $resendIn ?>" data-label="Send via Email instead" <?= $resendIn > 0 ? 'disabled' : '' ?>>Send via Email instead</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="linkbtn" type="submit">Use a different number</button></form>
    </div>
  <?php endif; ?>
  <p style="margin-top:16px;text-align:center"><a href="<?= url('login.php') ?>">← Sign in with password</a></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
