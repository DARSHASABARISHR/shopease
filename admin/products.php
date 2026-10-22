<?php
declare(strict_types=1);
$adminPage = 'products'; $pageTitle = 'Products';
require __DIR__ . '/_header.php';
$db = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_require();
    $id = (int)($_POST['product_id'] ?? 0);
    $used = $db->prepare('SELECT COUNT(*) FROM order_items WHERE product_id = ?');
    $used->execute([$id]);
    if ((int)$used->fetchColumn() > 0) {
        flash('error', 'This product exists in past orders and cannot be deleted. Set its stock to 0 instead.');
    } else {
        $imgs = $db->prepare('SELECT image_path FROM products WHERE product_id = ? UNION SELECT image_path FROM product_images WHERE product_id = ?');
        $imgs->execute([$id, $id]);
        $files = array_column($imgs->fetchAll(), 'image_path');
        $db->prepare('DELETE FROM products WHERE product_id = ?')->execute([$id]);
        foreach ($files as $f) if ($f && preg_match('/^[A-Za-z0-9._-]+$/', $f) && is_file(UPLOAD_DIR . $f)) @unlink(UPLOAD_DIR . $f);
        flash('success', 'Product deleted.');
    }
    redirect('admin/products.php');
}

$products = $db->query('SELECT p.*, c.name AS cat FROM products p JOIN categories c ON c.category_id=p.category_id ORDER BY p.product_id DESC')->fetchAll();
?>
<div style="display:flex;justify-content:space-between;align-items:center"><h1>Products</h1><a class="btn btn-orange" href="<?= url('admin/product_form.php') ?>">+ Add product</a></div>
<div class="panel" style="overflow-x:auto">
<table class="tbl">
  <thead><tr><th>Image</th><th>Name</th><th>Category</th><th class="r">MRP</th><th class="r">Price</th><th class="r">Stock</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($products as $p): $s = (int)$p['stock_quantity']; ?>
    <tr>
      <td><img class="thumb-sm" src="<?= e(product_image($p['image_path'])) ?>" alt=""></td>
      <td><?= e($p['name']) ?></td><td><?= e($p['cat']) ?></td>
      <td class="r"><?= money($p['mrp_price']) ?></td><td class="r"><?= money($p['selling_price']) ?></td>
      <td class="r"><span class="pill <?= $s === 0 ? 'Failed' : ($s <= LOW_STOCK_THRESHOLD ? 'Pending' : 'Paid') ?>"><?= $s ?></span></td>
      <td><div class="inline-form">
        <a class="btn btn-light btn-sm" href="<?= url('admin/product_form.php?id=' . (int)$p['product_id']) ?>">Edit</a>
        <form method="post" onsubmit="return confirm('Delete this product permanently?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="product_id" value="<?= (int)$p['product_id'] ?>"><button class="btn btn-danger btn-sm">Delete</button></form>
      </div></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php require __DIR__ . '/_footer.php'; ?>
