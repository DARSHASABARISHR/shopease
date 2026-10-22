<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/OtpService.php';

$user = require_login();
$db   = Database::getConnection();
$svc  = new OtpService($db);
$uid  = (int)$user['user_id'];
$waOn = wa_enabled();

$cur = $db->prepare('SELECT whatsapp_number, whatsapp_verified FROM users WHERE user_id = ?');
$cur->execute([$uid]);
$cur = $cur->fetch();
$pending = (string)($_SESSION['wl_link_phone'] ?? '');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? '');

    if (!$waOn && $action !== 'remove') { $error = 'WhatsApp OTP is not enabled on this store yet.'; }
    elseif ($action === 'send') {
        $num  = preg_replace('/\D/', '', (string)($_POST['whatsapp'] ?? ''));
        $wait = OtpService::RESEND_SECONDS - (time() - (int)($_SESSION['wl_link_sent'] ?? 0));
        if (!valid_phone($num)) $error = 'Enter a valid 10-digit WhatsApp number.';
        elseif ($wait > 0) $error = "Please wait $wait seconds before requesting another OTP.";
        elseif (OtpService::numberTaken($db, $num, $uid)) $error = 'This WhatsApp number is already linked to another account.';
        else {
            [$otp, $err] = $svc->issue($uid, 'wa_link', $num);
            $err = $err ?? wa_send($num, (string)$otp);
            if ($err) $error = $err;
            else { $_SESSION['wl_link_phone'] = $num; $_SESSION['wl_link_sent'] = time(); flash('success', 'OTP sent on WhatsApp to ' . wa_mask($num) . '.'); redirect('whatsapp_settings.php'); }
        }
    } elseif ($action === 'verify' && $pending !== '') {
        $res = $svc->verify('wa_link', $pending, (string)($_POST['otp'] ?? ''), $uid);
        if ($res === 'ok') {
            if (OtpService::numberTaken($db, $pending, $uid)) { $error = 'This WhatsApp number is already linked to another account.'; }
            else {
                $db->prepare('UPDATE users SET whatsapp_number = ?, whatsapp_verified = 1 WHERE user_id = ?')->execute([$pending, $uid]);
                unset($_SESSION['wl_link_phone'], $_SESSION['wl_link_sent']);
                flash('success', 'WhatsApp number verified. You can now sign in with a WhatsApp OTP.');
                redirect('whatsapp_settings.php');
            }
        } else $error = OtpService::message($res);
    } elseif ($action === 'cancel') { unset($_SESSION['wl_link_phone']); redirect('whatsapp_settings.php'); }
    elseif ($action === 'remove') {
        $db->prepare('UPDATE users SET whatsapp_number = NULL, whatsapp_verified = 0 WHERE user_id = ?')->execute([$uid]);
        flash('success', 'WhatsApp number removed.'); redirect('whatsapp_settings.php');
    }
}

$remain   = $pending !== '' ? max(0, $svc->expiresAt('wa_link', $pending) - time()) : 0;
$resendIn = max(0, OtpService::RESEND_SECONDS - (time() - (int)($_SESSION['wl_link_sent'] ?? 0)));
$pageTitle = 'WhatsApp Login';
require __DIR__ . '/includes/header.php';
?>
<div class="panel auth-box" style="max-width:480px">
  <h1>WhatsApp sign in</h1>
  <?php if (!$waOn): ?><div class="alert alert-info">WhatsApp OTP isn't enabled on this store yet.</div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

  <?php if ($cur['whatsapp_number'] && (int)$cur['whatsapp_verified'] === 1): ?>
    <p>✅ Verified WhatsApp number: <strong><?= e(wa_display((string)$cur['whatsapp_number'])) ?></strong></p>
    <p>You can sign in using a WhatsApp OTP from the sign in page.</p>
    <form method="post" onsubmit="return confirm('Remove this WhatsApp number?')"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><button class="btn btn-danger btn-sm">Remove number</button></form>
    <div class="divider">or link a different number</div>
  <?php endif; ?>

  <?php if ($pending === ''): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="send">
      <div class="field"><label>WhatsApp number</label><input name="whatsapp" inputmode="numeric" maxlength="10" placeholder="10-digit number" required></div>
      <button class="btn btn-wa btn-block" type="submit" <?= $waOn ? '' : 'disabled' ?>>Send OTP on WhatsApp</button>
    </form>
  <?php else: ?>
    <p>Enter the code sent to <strong><?= e(wa_mask($pending)) ?></strong>.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="verify">
      <div class="field"><input class="otp-input" name="otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="••••••" required autofocus></div>
      <p class="timer">OTP expires in <strong data-countdown="<?= $remain ?>" data-expired-text="expired">--:--</strong></p>
      <button class="btn btn-orange btn-block" type="submit">Verify number</button>
    </form>
    <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="send"><input type="hidden" name="whatsapp" value="<?= e($pending) ?>">
      <button class="btn btn-light btn-block" data-resend-wait="<?= $resendIn ?>" data-label="Resend OTP" <?= $resendIn > 0 ? 'disabled' : '' ?>>Resend OTP</button></form>
    <form method="post" style="margin-top:8px;text-align:center"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="linkbtn">Use a different number</button></form>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
