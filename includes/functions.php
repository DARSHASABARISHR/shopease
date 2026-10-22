<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

/* ---------- Output / formatting ---------- */
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** Indian rupee formatting with lakh/crore grouping, e.g. ₹1,23,456.00 */
function money(float|int|string $amount): string
{
    $amount = round((float)$amount, 2);
    $neg = $amount < 0 ? '-' : '';
    [$int, $dec] = explode('.', number_format(abs($amount), 2, '.', ''));
    if (strlen($int) > 3) {
        $last3 = substr($int, -3);
        $rest  = substr($int, 0, -3);
        $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int   = $rest . ',' . $last3;
    }
    return $neg . '₹' . $int . '.' . $dec;
}

function discount_percent(float $mrp, float $price): int
{
    return ($mrp > 0 && $price < $mrp) ? (int)round((($mrp - $price) / $mrp) * 100) : 0;
}

function product_image(?string $path): string
{
    return $path ? UPLOAD_URL . rawurlencode($path) : BASE_URL . '/assets/img/placeholder.svg';
}

function stars_html(float $rating): string
{
    $full = (int)floor($rating); $half = ($rating - $full) >= 0.5;
    $out = '';
    for ($i = 1; $i <= 5; $i++) {
        $out .= $i <= $full ? '<span class="star on">★</span>' : (($i === $full + 1 && $half) ? '<span class="star half">★</span>' : '<span class="star">★</span>');
    }
    return $out;
}

function url(string $path = ''): string { return BASE_URL . '/' . ltrim($path, '/'); }
function redirect(string $path): never 
{ 
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        header('Location: ' . $path);
    } elseif (str_starts_with($path, BASE_URL . '/') || $path === BASE_URL) {
        header('Location: ' . $path);
    } else {
        header('Location: ' . url($path));
    }
    exit; 
}

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_valid(): bool
{
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals($_SESSION['csrf'] ?? '', $sent);
}
function csrf_require(bool $json = false): void
{
    if (!csrf_valid()) {
        if ($json) json_response(['ok' => false, 'message' => 'Session expired. Please refresh the page.'], 419);
        http_response_code(419); exit('Invalid or expired form token. Go back and try again.');
    }
}

/* ---------- Flash messages ---------- */
function flash(string $type, string $msg): void { $_SESSION['flash'][] = ['t' => $type, 'm' => $msg]; }
function flash_render(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as $f) $out .= '<div class="alert alert-' . e($f['t']) . '">' . e($f['m']) . '</div>';
    unset($_SESSION['flash']);
    return $out;
}

/* ---------- JSON ---------- */
function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- Auth ---------- */
function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $st = Database::getConnection()->prepare('SELECT user_id, full_name, email, phone, address, city, pincode, role FROM users WHERE user_id = ? AND is_verified = 1');
            $st->execute([$_SESSION['user_id']]);
            $user = $st->fetch() ?: null;
        }
    }
    return $user;
}
function require_login(): array
{
    $u = current_user();
    if (!$u) { $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? ''; flash('info', 'Please sign in to continue.'); redirect('login.php'); }
    return $u;
}
function require_admin(): array
{
    $u = current_user();
    if (!$u || $u['role'] !== 'admin') { http_response_code(403); redirect('login.php'); }
    return $u;
}

/* ---------- Validation ---------- */
function valid_phone(string $p): bool { return (bool)preg_match('/^[6-9][0-9]{9}$/', $p); }
function valid_pincode(string $p): bool { return (bool)preg_match('/^[1-9][0-9]{5}$/', $p); }
function clean(string $s): string { return trim(preg_replace('/\s+/', ' ', strip_tags($s))); }

/* ---------- Cart (session: product_id => qty) ---------- */
function cart_count(): int { return array_sum($_SESSION['cart'] ?? []); }

/** Returns ['items'=>[], 'items_total'=>, 'delivery'=>, 'gst'=>, 'total'=>, 'mrp_total'=>] from live DB prices. */
function cart_summary(): array
{
    $cart = $_SESSION['cart'] ?? [];
    $sum = ['items' => [], 'items_total' => 0.0, 'mrp_total' => 0.0, 'delivery' => 0.0, 'gst' => 0.0, 'total' => 0.0, 'count' => 0];
    if (!$cart) return $sum;

    $ids = array_map('intval', array_keys($cart));
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = Database::getConnection()->prepare("SELECT product_id, name, mrp_price, selling_price, stock_quantity, image_path FROM products WHERE product_id IN ($in)");
    $st->execute($ids);
    $found = [];
    foreach ($st->fetchAll() as $p) $found[(int)$p['product_id']] = $p;

    foreach ($cart as $pid => $qty) {
        $pid = (int)$pid;
        if (!isset($found[$pid])) { unset($_SESSION['cart'][$pid]); continue; }
        $p = $found[$pid];
        $qty = max(1, min((int)$qty, (int)$p['stock_quantity']));
        if ((int)$p['stock_quantity'] <= 0) { $p['out'] = true; $qty = 0; }
        $_SESSION['cart'][$pid] = $qty ?: 1;
        $line = (float)$p['selling_price'] * $qty;
        $p['qty'] = $qty; $p['line_total'] = $line;
        $sum['items'][] = $p;
        if ($qty > 0) { $sum['items_total'] += $line; $sum['mrp_total'] += (float)$p['mrp_price'] * $qty; $sum['count'] += $qty; }
    }
    $sum = array_merge($sum, price_breakdown($sum['items_total']));
    return $sum;
}

function price_breakdown(float $itemsTotal): array
{
    $delivery = ($itemsTotal <= 0 || $itemsTotal >= FREE_DELIVERY_ABOVE) ? 0.0 : DELIVERY_CHARGE;
    $gst      = round($itemsTotal * GST_RATE / 100, 2);
    return ['delivery' => $delivery, 'gst' => $gst, 'total' => round($itemsTotal + $delivery + $gst, 2)];
}

function slugify(string $s): string
{
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
    return $s !== '' ? $s : 'item';
}
