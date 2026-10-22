<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/support.php';
require_once __DIR__ . '/../config/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
csrf_require(true);
if (trim((string)($_POST['website'] ?? '')) !== '') json_response(['ok' => true, 'message' => 'Thanks!']);       // honeypot

$hour = array_filter($_SESSION['support_sent'] ?? [], fn($t) => $t > time() - 3600);
if (count($hour) >= 3) json_response(['ok' => false, 'message' => 'You have sent several messages recently. Please try again later.'], 429);

$name = clean((string)($_POST['name'] ?? ''));
$email = strtolower(trim((string)($_POST['email'] ?? '')));
$orderId = (int)($_POST['order_id'] ?? 0);
$msg = trim(strip_tags((string)($_POST['message'] ?? '')));
if (mb_strlen($name) < 2 || mb_strlen($name) > 120) json_response(['ok' => false, 'message' => 'Please enter your name.']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(['ok' => false, 'message' => 'Please enter a valid email address.']);
if (mb_strlen($msg) < 10 || mb_strlen($msg) > 2000) json_response(['ok' => false, 'message' => 'Please write your query (10-2000 characters).']);

$db = Database::getConnection();
$user = current_user();

/* 1) SAVE FIRST - the admin panel is the source of truth; if this fails nothing is sent */
try {
    $db->prepare("INSERT INTO support_queries (user_id, name, email, order_id, message, status) VALUES (?,?,?,?,?, 'Pending')")
       ->execute([$user['user_id'] ?? null, $name, $email, $orderId ?: null, $msg]);
    $queryId = (int)$db->lastInsertId();
} catch (Throwable $e) {
    error_log('support insert failed: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'We could not save your message right now. Please try again or call us.'], 500);
}
$hour[] = time(); $_SESSION['support_sent'] = array_values($hour);

/* 2) THEN email the admin (a mail failure no longer loses the query) */
$html = '<h3>New support query #' . $queryId . ' - ' . e(APP_NAME) . '</h3><table cellpadding="6" style="font-family:Arial;font-size:14px">
<tr><td><b>Name</b></td><td>' . e($name) . '</td></tr><tr><td><b>Email</b></td><td>' . e($email) . '</td></tr>
<tr><td><b>Order ID</b></td><td>' . ($orderId ? '#' . $orderId : '-') . '</td></tr><tr><td valign="top"><b>Query</b></td><td>' . nl2br(e($msg)) . '</td></tr></table>
<p><a href="' . e(APP_URL) . '/admin/queries.php">Open in the admin panel</a></p>';
try {
    $m = create_mailer();
    $m->addAddress(SUPPORT_EMAIL);
    $m->addReplyTo($email, $name);
    $m->Subject = 'Support query #' . $queryId . ($orderId ? ' (Order #' . $orderId . ')' : '') . ' from ' . $name;
    $m->Body = $html; $m->AltBody = "Query #$queryId from $name <$email>\nOrder: $orderId\n\n$msg";
    $m->send();
} catch (Throwable $e) {
    error_log('support mail failed for query ' . $queryId . ': ' . $e->getMessage());
}
json_response(['ok' => true, 'query_id' => $queryId, 'message' => 'Thank you! Your query #' . $queryId . ' has been received. We will reply to ' . $email . ' soon.']);
