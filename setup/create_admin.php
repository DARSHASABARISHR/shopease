<?php
/**
 * Creates (or promotes) an admin account.   Usage (CLI only):
 *   php setup/create_admin.php "Admin Name" admin@example.com 9876543210 "StrongPass123"
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../config/db.php';

[$script, $name, $email, $phone, $pass] = $argv + [null, null, null, null, null];
if (!$name || !$email || !$phone || !$pass) exit("Usage: php setup/create_admin.php \"Name\" email phone password\n");
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) exit("Invalid email.\n");
if (!preg_match('/^[6-9][0-9]{9}$/', $phone)) exit("Phone must be a 10-digit Indian mobile number.\n");
if (strlen($pass) < 8) exit("Password must be at least 8 characters.\n");

$db = Database::getConnection();
$hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
$st = $db->prepare('SELECT user_id FROM users WHERE email = ?'); $st->execute([strtolower($email)]);
if ($id = $st->fetchColumn()) {
    $db->prepare("UPDATE users SET role='admin', is_verified=1, password_hash=? WHERE user_id=?")->execute([$hash, $id]);
    echo "Existing user promoted to admin.\n";
} else {
    $db->prepare("INSERT INTO users (full_name,email,phone,password_hash,address,is_verified,role) VALUES (?,?,?,?, 'Admin', 1, 'admin')")
       ->execute([$name, strtolower($email), $phone, $hash]);
    echo "Admin created: $email\n";
}
