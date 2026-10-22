<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
$admin = require_admin();
$adminPage = $adminPage ?? '';
$pageTitle = $pageTitle ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title><?= e($pageTitle) ?> | ShopEase Seller</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<script>window.BASE_URL = <?= json_encode(BASE_URL) ?>;</script>
<script defer src="<?= BASE_URL ?>/assets/js/app.js"></script>
</head><body>
<div class="admin-wrap">
  <aside class="admin-side">
    <a class="logo" href="<?= url('admin/index.php') ?>">Shop<span>Ease</span> <small style="font-size:11px;color:#ccc">Seller</small></a>
    <a class="m <?= $adminPage === 'dash' ? 'on' : '' ?>" href="<?= url('admin/index.php') ?>">📊 Dashboard</a>
    <a class="m <?= $adminPage === 'products' ? 'on' : '' ?>" href="<?= url('admin/products.php') ?>">📦 Products</a>
    <a class="m <?= $adminPage === 'orders' ? 'on' : '' ?>" href="<?= url('admin/orders.php') ?>">🧾 Orders</a>
    <a class="m <?= $adminPage === 'returns' ? 'on' : '' ?>" href="<?= url('admin/returns.php') ?>">↩ Returns</a>
    <?php $__pq = 0; try { $__pq = (int)Database::getConnection()->query("SELECT COUNT(*) FROM support_queries WHERE status = 'Pending'")->fetchColumn(); } catch (Throwable $e) {} ?>
    <a class="m <?= $adminPage === 'queries' ? 'on' : '' ?>" href="<?= url('admin/queries.php') ?>">💬 Customer Queries<?= $__pq ? ' <span class="pill Pending">' . $__pq . '</span>' : '' ?></a>
    <a class="m" href="<?= url('change_password.php') ?>">🔑 Change password</a>
    <a class="m" href="<?= url('index.php') ?>">🏬 View store</a>
    <a class="m" href="<?= url('logout.php') ?>">⎋ Sign out</a>
  </aside>
  <section class="admin-main">
    <?= flash_render() ?>
