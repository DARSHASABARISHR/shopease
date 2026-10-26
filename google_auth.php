<?php
declare(strict_types=1);

// Error logging
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
    $cred = $body['credential'] ?? '';

    if (empty($cred) || !is_string($cred) || substr_count($cred, '.') !== 2) {
        throw new Exception('Invalid credential');
    }

    // Google Tokeninfo சரிபார்ப்பு
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($cred);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($res === false || !empty($curlErr)) {
        throw new Exception('cURL Error: ' . $curlErr);
    }

    $p = json_decode((string)$res, true);

    if (!$p || empty($p['sub']) || empty($p['email'])) {
        throw new Exception('Google Token verification failed: ' . ($p['error_description'] ?? 'Token invalid'));
    }

    $db = Database::getConnection();
    $email = strtolower(trim((string)$p['email']));
    $sub   = (string)$p['sub'];

    // பயனர் ஏற்கனவே உள்ளாரா எனப் பார்த்தல்
    $st = $db->prepare('SELECT * FROM users WHERE google_sub = ? OR email = ? LIMIT 1');
    $st->execute([$sub, $email]);
    $u = $st->fetch();

    if ($u) {
        // பழைய பயனருக்கு google_sub இணைத்து verification உறுதி செய்தல்
        $db->prepare('UPDATE users SET google_sub = ?, is_verified = 1 WHERE user_id = ?')
           ->execute([$sub, $u['user_id']]);
        $userId = (int)$u['user_id'];
        $role = $u['role'] ?? 'customer';
    } else {
        // புதிய பயனரை பதிவு செய்தல் (name காலம் இல்லாமல்)
        $st = $db->prepare('INSERT INTO users (email, google_sub, is_verified, role, created_at) VALUES (?, ?, 1, "customer", NOW())');
        $st->execute([$email, $sub]);
        $userId = (int)$db->lastInsertId();
        $role = 'customer';
    }

    // Session ஆரம்பித்தல்
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $cart = $_SESSION['cart'] ?? [];
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['cart'] = $cart;
    unset($_SESSION['login_fail']);

    $redirect = ($role === 'admin') ? 'admin/index.php' : 'index.php';
    echo json_encode(['ok' => true, 'redirect' => $redirect]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}