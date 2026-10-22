<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email_templates.php';
require_once __DIR__ . '/includes/whatsapp.php';
require_once __DIR__ . '/config/mailer.php';

if (current_user()) redirect('index.php');
$db = Database::getConnection();
$waOn = wa_enabled();
$errors = []; $old = ['full_name' => '', 'email' => '', 'phone' => '', 'whatsapp' => '', 'address' => '', 'city' => '', 'pincode' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    foreach ($old as $k => $_) $old[$k] = clean((string)($_POST[$k] ?? ''));
    $old['email'] = strtolower($old['email']);
    $old['phone'] = preg_replace('/\D/', '', $old['phone']);
    $old['whatsapp'] = preg_replace('/\D/', '', $old['whatsapp']);
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm'] ?? '');

    if (mb_strlen($old['full_name']) < 2 || mb_strlen($old['full_name']) > 120) $errors[] = 'Enter your full name.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if (!valid_phone($old['phone'])) $errors[] = 'Enter a valid 10-digit Indian mobile number (starts with 6-9).';
    if ($waOn && !valid_phone($old['whatsapp'])) $errors[] = 'Enter a valid 10-digit WhatsApp number.';
    if (!$waOn && $old['whatsapp'] !== '' && !valid_phone($old['whatsapp'])) $errors[] = 'Enter a valid 10-digit WhatsApp number.';
    if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) $errors[] = 'Password must be at least 8 characters with letters and numbers.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';
    if ($old['address'] === '') $errors[] = 'Address is required.';
    if ($old['pincode'] !== '' && !valid_pincode($old['pincode'])) $errors[] = 'Enter a valid 6-digit pincode.';

    if (!$errors) {
        $st = $db->prepare('SELECT user_id, is_verified FROM users WHERE email = ?');
        $st->execute([$old['email']]);
        $existing = $st->fetch();
        if ($existing && (int)$existing['is_verified'] === 1) {
            $errors[] = 'An account with this email already exists. Please sign in.';
        } else {
            $otp  = (string)random_int(100000, 999999);
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $otpH = password_hash($otp, PASSWORD_BCRYPT);
            $exp  = date('Y-m-d H:i:s', time() + OTP_TTL_MINUTES * 60);
            $wa   = $old['whatsapp'] !== '' ? $old['whatsapp'] : null;
            if ($existing) {   // earlier unverified attempt: refresh details
                $db->prepare('UPDATE users SET full_name=?, phone=?, whatsapp_number=?, whatsapp_verified=0, password_hash=?, address=?, city=?, pincode=?, otp_code=?, otp_expires_at=?, otp_attempts=0 WHERE user_id=?')
                   ->execute([$old['full_name'], $old['phone'], $wa, $hash, $old['address'], $old['city'] ?: null, $old['pincode'] ?: null, $otpH, $exp, $existing['user_id']]);
            } else {
                $db->prepare('INSERT INTO users (full_name,email,phone,whatsapp_number,whatsapp_verified,password_hash,address,city,pincode,is_verified,otp_code,otp_expires_at,role) VALUES (?,?,?,?,0,?,?,?,?,0,?,?,\'customer\')')
                   ->execute([$old['full_name'], $old['email'], $old['phone'], $wa, $hash, $old['address'], $old['city'] ?: null, $old['pincode'] ?: null, $otpH, $exp]);
            }

            // Primary channel = WhatsApp (when configured); automatic fallback to email
            $channel = 'email'; $note = '';
            if ($waOn && $wa) {
                $err = wa_send($wa, $otp);
                if ($err === null) $channel = 'whatsapp'; else $note = $err . ' We emailed the OTP instead.';
            }
            $sent = true;
            if ($channel === 'email') {
                $t = tpl_otp($old['full_name'], $otp);
                $sent = send_mail($old['email'], $old['full_name'], $t['subject'], $t['html'], $t['text']);
            }
            $_SESSION['pending_email']   = $old['email'];
            $_SESSION['pending_channel'] = $channel;
            $_SESSION['otp_sent_at']     = time();
            $_SESSION['otp_sends']       = [time()];
            if ($note !== '') flash('info', $note);
            flash($sent ? 'success' : 'error', $sent
                ? ($channel === 'whatsapp' ? 'We sent a 6-digit OTP to your WhatsApp ' . wa_mask($wa) . '.' : 'We sent a 6-digit OTP to ' . $old['email'] . '.')
                : 'Account created but the OTP could not be sent. Use a resend option below.');
            redirect('verify_otp.php');
        }
    }
}
$pageTitle = 'Create Account';
require __DIR__ . '/includes/header.php';
?>
<div class="panel auth-box" style="max-width:560px">
  <h1>Create account</h1>
  <?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post" data-pw-form novalidate>
    <?= csrf_field() ?>
    <div class="field"><label>Full name</label><input name="full_name" value="<?= e($old['full_name']) ?>" required maxlength="120"></div>
    <div class="field"><label>Email (Gmail recommended)</label><input type="email" name="email" value="<?= e($old['email']) ?>" required></div>
    <div class="field"><label>Mobile number</label><input name="phone" id="regPhone" inputmode="numeric" maxlength="10" pattern="[6-9][0-9]{9}" value="<?= e($old['phone']) ?>" placeholder="10-digit Indian mobile" required></div>
    <div class="field"><label>WhatsApp number <?= $waOn ? '' : '<small>(optional)</small>' ?></label>
      <input name="whatsapp" id="regWa" inputmode="numeric" maxlength="10" pattern="[6-9][0-9]{9}" value="<?= e($old['whatsapp']) ?>" placeholder="10-digit WhatsApp number" <?= $waOn ? 'required' : '' ?>>
      <label class="same-row"><input type="checkbox" id="waSame"> Same as mobile number</label>
      <?php if ($waOn): ?><small>We'll send your verification OTP on WhatsApp (email is the fallback).</small><?php endif; ?></div>
    <div class="row2">
      <div class="field"><label>Password</label><input type="password" name="password" data-pw-new autocomplete="new-password" minlength="8" required><small>Min 8 chars, letters &amp; numbers</small></div>
      <div class="field"><label>Confirm password</label><input type="password" name="confirm" data-pw-confirm autocomplete="new-password" minlength="8" required><div class="pw-msg" role="alert" aria-live="polite"></div></div>
    </div>
    <div class="field"><label>Address</label><textarea name="address" rows="2" required><?= e($old['address']) ?></textarea></div>
    <div class="row2">
      <div class="field"><label>Pincode</label><input name="pincode" inputmode="numeric" maxlength="6" value="<?= e($old['pincode']) ?>" data-pincode data-pin-lock="0"><div class="pin-status" data-pin-status></div></div>
      <div class="field"><label>City / District</label><input name="city" value="<?= e($old['city']) ?>" data-pin-city></div>
    </div>
    <button class="btn btn-orange btn-block" type="submit">Create account &amp; send OTP</button>
  </form>
  <p style="margin-top:14px">Already have an account? <a href="<?= url('login.php') ?>">Sign in</a></p>
</div>
<script>
(function () {
  const same = document.getElementById('waSame'), phone = document.getElementById('regPhone'), wa = document.getElementById('regWa');
  function sync() { if (same.checked) { wa.value = phone.value; wa.readOnly = true; } else wa.readOnly = false; }
  same.addEventListener('change', sync); phone.addEventListener('input', sync);
  if (phone.value && phone.value === wa.value) { same.checked = true; sync(); }
})();
</script>
<script src="<?= BASE_URL ?>/assets/js/pincode.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
