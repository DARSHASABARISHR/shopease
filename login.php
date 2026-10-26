<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

if (current_user()) redirect('index.php');
$db = Database::getConnection();
$error = ''; $email = '';

// உங்கள் புதிய ShopEase Google Client ID
$googleClientId = '1070726770669-a66foh50dij6emvgngtnmfcau7ojjeug.apps.googleusercontent.com';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass  = (string)($_POST['password'] ?? '');

    // basic brute-force throttle per session
    $_SESSION['login_fail'] = $_SESSION['login_fail'] ?? ['n' => 0, 't' => time()];
    if ($_SESSION['login_fail']['n'] >= 5 && time() - $_SESSION['login_fail']['t'] < 300) {
        $error = 'Too many failed attempts. Try again in a few minutes.';
    } else {
        $st = $db->prepare('SELECT * FROM users WHERE email = ?');
        $st->execute([$email]);
        $u = $st->fetch();
        if (!$u || !password_verify($pass, $u['password_hash'])) {
            $_SESSION['login_fail']['n']++; $_SESSION['login_fail']['t'] = time();
            $error = 'Incorrect email or password.';
        } elseif (!(int)$u['is_verified']) {
            $_SESSION['pending_email'] = $u['email'];
            flash('info', 'Your email is not verified yet. Enter the OTP, or request a new one.');
            redirect('verify_otp.php');
        } else {
            $cart = $_SESSION['cart'] ?? [];
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$u['user_id'];
            $_SESSION['cart'] = $cart;
            unset($_SESSION['login_fail']);
            if (password_needs_rehash($u['password_hash'], PASSWORD_BCRYPT)) {
                $db->prepare('UPDATE users SET password_hash=? WHERE user_id=?')->execute([password_hash($pass, PASSWORD_BCRYPT), $u['user_id']]);
            }
            $next = $_SESSION['after_login'] ?? '';
            unset($_SESSION['after_login']);
            if ($u['role'] === 'admin') redirect('admin/index.php');
            // only allow same-site relative redirects
            if ($next !== '' && str_starts_with($next, BASE_URL . '/') && !str_contains($next, '//')) redirect($next);
            redirect('index.php');
        }
    }
}
$pageTitle = 'Sign in';
require __DIR__ . '/includes/header.php';
?>
<!-- Google Identity Services SDK -->
<script src="https://accounts.google.com/gsi/client" async defer></script>

<div class="panel auth-box">
  <h1>Sign in</h1>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

  <!-- Google Sign-In பட்டன் அமைப்பு -->
  <div style="display:flex; justify-content:center; margin-bottom:15px;">
    <div id="g_id_onload"
         data-client_id="<?= e($googleClientId) ?>"
         data-callback="handleGoogleLogin"
         data-auto_prompt="false">
    </div>
    <div class="g_id_signin"
         data-type="standard"
         data-shape="rectangular"
         data-theme="outline"
         data-text="signin_with"
         data-size="large"
         data-logo_alignment="left">
    </div>
  </div>

  <div class="divider">or sign in with email</div>

  <form method="post">
    <?= csrf_field() ?>
    <div class="field"><label>Email</label><input type="email" name="email" value="<?= e($email) ?>" required autofocus></div>
    <div class="field"><label>Password</label><input type="password" name="password" autocomplete="current-password" required><small><a href="<?= url('forgot_password.php') ?>">Forgot password?</a></small></div>
    <button class="btn btn-orange btn-block" type="submit">Sign in</button>
  </form>

  <?php require_once __DIR__ . '/includes/whatsapp.php'; if (wa_enabled()): ?>
    <div class="divider">or</div>
    <a class="btn btn-wa btn-block" href="<?= url('login_whatsapp.php') ?>">Sign in with WhatsApp OTP</a>
  <?php endif; ?>
  <p style="margin-top:14px">New to ShopEase? <a href="<?= url('register.php') ?>">Create your account</a></p>
</div>

<script>
function handleGoogleLogin(response) {
  fetch('google_auth.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ credential: response.credential })
  })
  .then(res => res.json())
  .then(data => {
    if (data.ok) {
      window.location.href = data.redirect || 'index.php';
    } else {
      alert('Google Sign-in failed: ' + (data.error || 'Unknown error'));
    }
  })
  .catch(() => alert('Network error during Google sign-in.'));
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>