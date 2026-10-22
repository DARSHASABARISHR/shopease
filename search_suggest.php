<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) { echo '[]'; exit; }
$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
$st = Database::getConnection()->prepare('SELECT product_id, name, selling_price, image_path FROM products WHERE name LIKE ? ORDER BY stock_quantity > 0 DESC, name LIMIT 6');
$st->execute([$like]);
$out = [];
foreach ($st->fetchAll() as $p) $out[] = ['id' => (int)$p['product_id'], 'name' => $p['name'], 'price' => money($p['selling_price']), 'image' => product_image($p['image_path'])];
echo json_encode($out, JSON_UNESCAPED_UNICODE);
