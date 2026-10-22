<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
$st = Database::getConnection()->prepare('SELECT product_id, name, description, mrp_price, selling_price, stock_quantity, image_path, rating, rating_count FROM products WHERE product_id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$p = $st->fetch();
if (!$p) json_response(['ok' => false], 404);
json_response(['ok' => true, 'id' => (int)$p['product_id'], 'name' => $p['name'], 'description' => mb_strimwidth((string)$p['description'], 0, 260, '…'),
    'price' => money($p['selling_price']), 'mrp' => (float)$p['mrp_price'] > (float)$p['selling_price'] ? money($p['mrp_price']) : '',
    'off' => discount_percent((float)$p['mrp_price'], (float)$p['selling_price']), 'stock' => (int)$p['stock_quantity'],
    'image' => product_image($p['image_path']), 'rating' => (float)$p['rating'], 'count' => (int)$p['rating_count'], 'url' => url('product.php?id=' . (int)$p['product_id'])]);
