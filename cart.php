<?php
declare(strict_types=1);
$pageTitle = 'Shopping Cart';
require_once __DIR__ . '/includes/functions.php';
$summary = cart_summary();
require __DIR__ . '/includes/header.php';
?>
<div id="cartRoot"><?php require __DIR__ . '/includes/cart_view.php'; ?></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
