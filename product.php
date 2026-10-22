<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
$db = Database::getConnection();
$id = (int)($_GET['id'] ?? 0);
$st = $db->prepare('SELECT p.*, c.name AS category_name, c.slug FROM products p JOIN categories c ON c.category_id = p.category_id WHERE p.product_id = ?');
$st->execute([$id]);
$p = $st->fetch();
if (!$p) { http_response_code(404); $pageTitle = 'Not found'; require __DIR__ . '/includes/header.php'; echo '<div class="panel empty"><h2>Product not found</h2><a class="btn" href="' . url('index.php') . '">Continue shopping</a></div>'; require __DIR__ . '/includes/footer.php'; exit; }

$imgs = [];
if ($p['image_path']) $imgs[] = $p['image_path'];
$gi = $db->prepare('SELECT image_path FROM product_images WHERE product_id = ? ORDER BY image_id');
$gi->execute([$id]);
foreach ($gi->fetchAll() as $r) $imgs[] = $r['image_path'];
$mainSrc = product_image($imgs[0] ?? null);

$specs = [];
foreach (preg_split('/\r?\n/', (string)$p['specifications']) as $line) {
    if (str_contains($line, ':')) { [$k, $v] = array_map('trim', explode(':', $line, 2)); if ($k !== '') $specs[$k] = $v; }
}
$off = discount_percent((float)$p['mrp_price'], (float)$p['selling_price']);
$stock = (int)$p['stock_quantity'];
$pageTitle = $p['name'];
require __DIR__ . '/includes/header.php';
?>
<p><a href="<?= url('index.php') ?>">Home</a> › <a href="<?= url('index.php?cat=' . urlencode($p['slug'])) ?>"><?= e($p['category_name']) ?></a></p>
<div class="detail panel">
  <div class="gallery">
    <div class="main"><img id="mainImg" src="<?= e($mainSrc) ?>" alt="<?= e($p['name']) ?>"></div>
    <?php if (count($imgs) > 1): ?>
    <div class="thumbs">
      <?php foreach ($imgs as $n => $im): $src = product_image($im); ?>
        <img src="<?= e($src) ?>" data-full="<?= e($src) ?>" alt="Preview <?= $n + 1 ?>" class="<?= $n === 0 ? 'on' : '' ?>">
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <h1><?= e($p['name']) ?></h1>
    <div class="rating"><?= stars_html((float)$p['rating']) ?> <span><?= number_format((float)$p['rating'], 1) ?> · <?= number_format((int)$p['rating_count']) ?> ratings</span></div>
    <hr>
    <div class="price-row">
      <?php if ($off > 0): ?><span style="color:#cc0c39;font-size:26px">-<?= $off ?>%</span><?php endif; ?>
      <span class="price" style="font-size:30px"><?= money($p['selling_price']) ?></span>
    </div>
    <?php if ($off > 0): ?><div class="mrp">M.R.P.: <?= money($p['mrp_price']) ?> · You save <?= money((float)$p['mrp_price'] - (float)$p['selling_price']) ?></div><?php endif; ?>
    <p style="line-height:1.6"><?= nl2br(e($p['description'])) ?></p>
    <?php if ($specs): ?>
      <h3>Specifications</h3>
      <table class="spec"><?php foreach ($specs as $k => $v): ?><tr><td><?= e($k) ?></td><td><?= e($v) ?></td></tr><?php endforeach; ?></table>
    <?php endif; ?>
  </div>

  <aside class="buybox">
    <div class="price" style="font-size:24px"><?= money($p['selling_price']) ?></div>
    <p><?= $stock <= 0 ? '<span class="stock out">Out of stock</span>' : ($stock <= LOW_STOCK_THRESHOLD ? '<span class="stock low">Only ' . $stock . ' left in stock</span>' : '<span class="stock in">In stock</span>') ?></p>
    <p><?= (float)$p['selling_price'] >= FREE_DELIVERY_ABOVE ? 'FREE delivery' : 'Delivery ' . e(money(DELIVERY_CHARGE)) . ' (free above ' . e(money(FREE_DELIVERY_ABOVE)) . ')' ?></p>
    <?php if ($stock > 0): ?>
      <p>Quantity<br>
        <span class="qty" data-detail><button type="button" data-d="-">−</button><input id="detailQty" type="number" value="1" min="1" max="<?= $stock ?>" readonly><button type="button" data-d="+">+</button></span>
      </p>
      <button class="btn btn-block" data-add-cart="<?= (int)$p['product_id'] ?>">Add to Cart</button><br>
      <button class="btn btn-orange btn-block" data-add-cart="<?= (int)$p['product_id'] ?>" data-buy-now="1">Buy Now</button>
    <?php else: ?><button class="btn btn-block" disabled>Currently unavailable</button><?php endif; ?>
  </aside>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
