<?php
declare(strict_types=1);
$pageTitle = 'Online Shopping India';
require_once __DIR__ . '/includes/functions.php';
$db = Database::getConnection();

$q    = trim((string)($_GET['q'] ?? ''));
$cat  = trim((string)($_GET['cat'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'new');
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 12;

$where = []; $args = [];
if ($q !== '') { $where[] = '(p.name LIKE ? OR p.description LIKE ?)'; $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%'; $args[] = $like; $args[] = $like; }
if ($cat !== '') { $where[] = 'c.slug = ?'; $args[] = $cat; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$orderBy = match ($sort) {
    'low'  => 'p.selling_price ASC',
    'high' => 'p.selling_price DESC',
    'disc' => '((p.mrp_price - p.selling_price) / p.mrp_price) DESC',
    'rate' => 'p.rating DESC',
    default => 'p.created_at DESC, p.product_id DESC',
};

$cnt = $db->prepare("SELECT COUNT(*) FROM products p JOIN categories c ON c.category_id = p.category_id $w");
$cnt->execute($args);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);
$offset = ($page - 1) * $per;

$st = $db->prepare("SELECT p.*, c.name AS category_name, c.slug AS category_slug FROM products p JOIN categories c ON c.category_id = p.category_id $w ORDER BY $orderBy LIMIT $per OFFSET $offset");
$st->execute($args);
$products = $st->fetchAll();

function page_url(array $over): string {
    $p = array_merge($_GET, $over); unset($p['page']); if (isset($over['page']) && $over['page'] > 1) $p['page'] = $over['page'];
    return url('index.php') . ($p ? '?' . http_build_query($p) : '');
}
require __DIR__ . '/includes/header.php';
?>
<?php if ($q === '' && $cat === '' && $page === 1): ?>
<section class="carousel" aria-label="Offers">
  <div class="slide s1"><h2>Mega Savings Week</h2><p>Up to 60% off on Electronics, Fashion and Home essentials.</p><a class="btn btn-orange" href="<?= url('index.php?sort=disc') ?>">Shop top deals</a></div>
  <div class="slide s2"><h2>Free Delivery</h2><p>Free shipping on orders above <?= e(money(FREE_DELIVERY_ABOVE)) ?>. Pay via UPI, GPay, PhonePe or COD.</p><a class="btn btn-orange" href="<?= url('index.php?cat=electronics') ?>">Explore Electronics</a></div>
  <div class="slide s3"><h2>New Season Fashion</h2><p>Fresh styles for everyone at unbeatable prices.</p><a class="btn btn-orange" href="<?= url('index.php?cat=fashion') ?>">Browse Fashion</a></div>
  <button class="c-btn c-prev" aria-label="Previous">‹</button><button class="c-btn c-next" aria-label="Next">›</button>
  <div class="dots"></div>
</section>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
  <h2 class="section-title">
    <?php if ($q !== ''): ?><?= e(t('results_for')) ?> "<?= e($q) ?>" <small style="color:#565959;font-weight:400">(<?= $total ?>)</small>
    <?php elseif ($cat !== ''): ?><?= e(cat_name($cat, ucwords(str_replace('-', ' ', $cat)))) ?>
    <?php else: ?><?= e(t('recommended')) ?><?php endif; ?>
  </h2>
  <form method="get">
    <?php foreach (['q', 'cat'] as $k) if (($_GET[$k] ?? '') !== '') echo '<input type="hidden" name="' . $k . '" value="' . e((string)$_GET[$k]) . '">'; ?>
    <select name="sort" onchange="this.form.submit()" style="padding:7px;border-radius:6px;border:1px solid #bbb">
      <?php foreach (['new' => t('sort_new'), 'low' => t('sort_low'), 'high' => t('sort_high'), 'disc' => t('sort_disc'), 'rate' => t('sort_rate')] as $k => $l): ?>
        <option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if (!$products): ?>
  <div class="panel empty"><h2><?= e(t('no_products')) ?></h2><p>Try a different keyword or category.</p><a class="btn" href="<?= url('index.php') ?>">Back to store</a></div>
<?php else: ?>
<div class="grid">
<?php foreach ($products as $p):
    $off = discount_percent((float)$p['mrp_price'], (float)$p['selling_price']);
    $stock = (int)$p['stock_quantity']; ?>
  <article class="card">
    <?php if ($off > 0): ?><span class="badge-off">-<?= $off ?>%</span><?php endif; ?>
    <div class="fab">
      <button type="button" class="fab-btn" data-add-cart="<?= (int)$p['product_id'] ?>" aria-label="<?= e(t('add_to_cart')) ?>" title="<?= e(t('add_to_cart')) ?>" <?= $stock <= 0 ? 'disabled' : '' ?>>🛒</button>
      <button type="button" class="fab-btn" data-quick="<?= (int)$p['product_id'] ?>" aria-label="<?= e(t('quick_view')) ?>" title="<?= e(t('quick_view')) ?>">👁</button>
    </div>
    <a class="img skeleton" href="<?= url('product.php?id=' . (int)$p['product_id']) ?>"><img loading="lazy" src="<?= e(product_image($p['image_path'])) ?>" alt="<?= e($p['name']) ?>"></a>
    <div class="cat"><?= e(cat_name((string)$p['category_slug'], $p['category_name'])) ?></div>
    <h3><a href="<?= url('product.php?id=' . (int)$p['product_id']) ?>"><?= e($p['name']) ?></a></h3>
    <div class="rating"><?= stars_html((float)$p['rating']) ?> <span><?= number_format((float)$p['rating'], 1) ?> (<?= number_format((int)$p['rating_count']) ?>)</span></div>
    <div class="price-row">
      <span class="price"><?= money($p['selling_price']) ?></span>
      <?php if ($off > 0): ?><span class="mrp"><?= money($p['mrp_price']) ?></span><span class="off"><?= $off ?>% off</span><?php endif; ?>
    </div>
    <?php if ($stock <= 0): ?><div class="stock out"><?= e(t('out_of_stock')) ?></div>
    <?php elseif ($stock <= LOW_STOCK_THRESHOLD): ?><div class="stock low"><?= e(t('only_left', ['n' => $stock])) ?></div>
    <?php else: ?><div class="stock in"><?= e(t('in_stock')) ?></div><?php endif; ?>
    <button class="btn" data-add-cart="<?= (int)$p['product_id'] ?>" <?= $stock <= 0 ? 'disabled' : '' ?>><?= e(t('add_to_cart')) ?></button>
  </article>
<?php endforeach; ?>
</div>

<?php if ($pages > 1): ?>
<nav class="pagination" aria-label="Pages">
  <?php if ($page > 1): ?><a href="<?= e(page_url(['page' => $page - 1])) ?>">‹ Prev</a><?php endif; ?>
  <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
    <?= $i === $page ? '<span class="cur">' . $i . '</span>' : '<a href="' . e(page_url(['page' => $i])) . '">' . $i . '</a>' ?>
  <?php endfor; ?>
  <?php if ($page < $pages): ?><a href="<?= e(page_url(['page' => $page + 1])) ?>">Next ›</a><?php endif; ?>
</nav>
<?php endif; endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
