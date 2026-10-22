<?php
declare(strict_types=1);
$adminPage = 'products';
require_once __DIR__ . '/../includes/functions.php';
require_admin();
$db = Database::getConnection();
$id = (int)($_GET['id'] ?? 0);
$cats = $db->query('SELECT category_id, name FROM categories ORDER BY name')->fetchAll();

$p = ['name' => '', 'category_id' => $cats[0]['category_id'] ?? 0, 'description' => '', 'specifications' => '', 'mrp_price' => '', 'selling_price' => '', 'stock_quantity' => 0, 'image_path' => null, 'rating' => '4.0', 'rating_count' => 0, 'is_returnable' => 0, 'return_window_days' => 2];
$gallery = [];
if ($id) {
    $st = $db->prepare('SELECT * FROM products WHERE product_id = ?'); $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) { flash('error', 'Product not found.'); redirect('admin/products.php'); }
    $p = $row;
    $g = $db->prepare('SELECT * FROM product_images WHERE product_id = ?'); $g->execute([$id]); $gallery = $g->fetchAll();
}

/** Validates + stores an uploaded image, returns new filename. */
function save_image(array $f): string
{
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Image upload failed (code ' . $f['error'] . ').');
    if ($f['size'] > 2 * 1024 * 1024) throw new RuntimeException('Each image must be 2 MB or smaller.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null;
    if (!$ext || !@getimagesize($f['tmp_name'])) throw new RuntimeException('Only JPG, PNG, WEBP or GIF images are allowed.');
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . $name)) throw new RuntimeException('Could not save the uploaded image. Check folder permissions on /uploads/products.');
    return $name;
}
function safe_unlink(?string $n): void { if ($n && preg_match('/^[A-Za-z0-9._-]+$/', $n) && is_file(UPLOAD_DIR . $n)) @unlink(UPLOAD_DIR . $n); }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    // delete a gallery image
    if (isset($_POST['del_image'])) {
        $iid = (int)$_POST['del_image'];
        $q = $db->prepare('SELECT image_path FROM product_images WHERE image_id=? AND product_id=?'); $q->execute([$iid, $id]);
        if ($path = $q->fetchColumn()) { $db->prepare('DELETE FROM product_images WHERE image_id=?')->execute([$iid]); safe_unlink($path); flash('success', 'Image removed.'); }
        redirect('admin/product_form.php?id=' . $id);
    }

    $in = [
        'name' => clean((string)($_POST['name'] ?? '')),
        'category_id' => (int)($_POST['category_id'] ?? 0),
        'description' => trim(strip_tags((string)($_POST['description'] ?? ''))),
        'specifications' => trim(strip_tags((string)($_POST['specifications'] ?? ''))),
        'mrp_price' => (string)($_POST['mrp_price'] ?? ''),
        'selling_price' => (string)($_POST['selling_price'] ?? ''),
        'stock_quantity' => (string)($_POST['stock_quantity'] ?? ''),
        'rating' => (string)($_POST['rating'] ?? '4.0'),
        'rating_count' => (string)($_POST['rating_count'] ?? '0'),
        'is_returnable' => isset($_POST['is_returnable']) ? 1 : 0,
        'return_window_days' => (string)($_POST['return_window_days'] ?? '2'),
    ];
    if ($in['name'] === '' || mb_strlen($in['name']) > 200) $errors[] = 'Product name is required (max 200 chars).';
    $cq = $db->prepare('SELECT 1 FROM categories WHERE category_id=?'); $cq->execute([$in['category_id']]);
    if (!$cq->fetchColumn()) $errors[] = 'Choose a valid category.';
    if (!is_numeric($in['mrp_price']) || (float)$in['mrp_price'] <= 0) $errors[] = 'Enter a valid MRP in ₹.';
    if (!is_numeric($in['selling_price']) || (float)$in['selling_price'] <= 0) $errors[] = 'Enter a valid selling price in ₹.';
    if (is_numeric($in['mrp_price']) && is_numeric($in['selling_price']) && (float)$in['selling_price'] > (float)$in['mrp_price']) $errors[] = 'Selling price cannot exceed MRP.';
    if (!ctype_digit($in['stock_quantity'])) $errors[] = 'Stock must be a whole number (0 or more).';
    if (!is_numeric($in['rating']) || $in['rating'] < 0 || $in['rating'] > 5) $errors[] = 'Rating must be between 0 and 5.';
    if (!ctype_digit($in['return_window_days']) || (int)$in['return_window_days'] < 1 || (int)$in['return_window_days'] > 30) $errors[] = 'Return window must be 1-30 days.';
    if (!ctype_digit($in['rating_count'])) $errors[] = 'Rating count must be a whole number.';
    $p = array_merge($p, $in);

    $newMain = null; $newGallery = [];
    if (!$errors) {
        try {
            if (!empty($_FILES['image']['name'])) $newMain = save_image($_FILES['image']);
            if (!empty($_FILES['gallery']['name'][0])) {
                $n = count($_FILES['gallery']['name']);
                for ($i = 0; $i < min($n, 6); $i++) {
                    if ($_FILES['gallery']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                    $newGallery[] = save_image(['name' => $_FILES['gallery']['name'][$i], 'tmp_name' => $_FILES['gallery']['tmp_name'][$i], 'error' => $_FILES['gallery']['error'][$i], 'size' => $_FILES['gallery']['size'][$i]]);
                }
            }
        } catch (RuntimeException $e) { $errors[] = $e->getMessage(); foreach (array_filter([$newMain, ...$newGallery]) as $f) safe_unlink($f); $newMain = null; $newGallery = []; }
    }

    if (!$errors) {
        $vals = [$in['category_id'], $in['name'], $in['description'], $in['specifications'], round((float)$in['mrp_price'], 2), round((float)$in['selling_price'], 2), (int)$in['stock_quantity'], round((float)$in['rating'], 1), (int)$in['rating_count'], (int)$in['is_returnable'], (int)$in['return_window_days']];
        if ($id) {
            $oldMain = $row['image_path'];
            $db->prepare('UPDATE products SET category_id=?, name=?, description=?, specifications=?, mrp_price=?, selling_price=?, stock_quantity=?, rating=?, rating_count=?, is_returnable=?, return_window_days=?, image_path=? WHERE product_id=?')
               ->execute([...$vals, $newMain ?? $oldMain, $id]);
            if ($newMain && $oldMain) safe_unlink($oldMain);
            $diff = (int)$in['stock_quantity'] - (int)$row['stock_quantity'];          // manual stock correction -> stock log
            if ($diff !== 0) $db->prepare("INSERT INTO stock_log (product_id, change_qty, reason) VALUES (?,?,'admin_edit')")->execute([$id, $diff]);
        } else {
            $db->prepare('INSERT INTO products (category_id,name,description,specifications,mrp_price,selling_price,stock_quantity,rating,rating_count,is_returnable,return_window_days,image_path) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
               ->execute([...$vals, $newMain]);
            $id = (int)$db->lastInsertId();
            if ((int)$in['stock_quantity'] > 0) $db->prepare("INSERT INTO stock_log (product_id, change_qty, reason) VALUES (?,?,'admin_edit')")->execute([$id, (int)$in['stock_quantity']]);
        }
        $gi = $db->prepare('INSERT INTO product_images (product_id, image_path) VALUES (?,?)');
        foreach ($newGallery as $g) $gi->execute([$id, $g]);
        flash('success', 'Product saved.');
        redirect('admin/products.php');
    }
}

$pageTitle = $id ? 'Edit product' : 'Add product';
require __DIR__ . '/_header.php';
?>
<h1><?= e($pageTitle) ?></h1>
<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>
<form class="panel" method="post" enctype="multipart/form-data" style="max-width:900px">
  <?= csrf_field() ?>
  <div class="field"><label>Product name</label><input name="name" value="<?= e((string)$p['name']) ?>" required maxlength="200"></div>
  <div class="row2">
    <div class="field"><label>Category</label><select name="category_id"><?php foreach ($cats as $c): ?><option value="<?= (int)$c['category_id'] ?>" <?= (int)$p['category_id'] === (int)$c['category_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Stock quantity</label><input name="stock_quantity" type="number" min="0" value="<?= e((string)$p['stock_quantity']) ?>" required></div>
  </div>
  <div class="row2">
    <div class="field"><label>MRP (₹)</label><input name="mrp_price" type="number" step="0.01" min="0" value="<?= e((string)$p['mrp_price']) ?>" required></div>
    <div class="field"><label>Selling price (₹)</label><input name="selling_price" type="number" step="0.01" min="0" value="<?= e((string)$p['selling_price']) ?>" required></div>
  </div>
  <div class="field"><label>Description</label><textarea name="description" rows="4"><?= e((string)$p['description']) ?></textarea></div>
  <div class="field"><label>Specifications</label><textarea name="specifications" rows="5" placeholder="Brand: Acme&#10;Warranty: 1 Year"><?= e((string)$p['specifications']) ?></textarea><small>One per line as "Name: Value"</small></div>
  <div class="row2">
    <div class="field"><label>Rating (0-5)</label><input name="rating" type="number" step="0.1" min="0" max="5" value="<?= e((string)$p['rating']) ?>"></div>
    <div class="field"><label>Rating count</label><input name="rating_count" type="number" min="0" value="<?= e((string)$p['rating_count']) ?>"></div>
  </div>
  <div class="row2">
    <div class="field"><label>Allow Return?</label>
      <label class="switch"><input type="checkbox" name="is_returnable" value="1" id="isRet" <?= (int)$p['is_returnable'] ? 'checked' : '' ?>><span class="slider"></span><span id="isRetTxt"><?= (int)$p['is_returnable'] ? 'Returnable' : 'Not returnable' ?></span></label></div>
    <div class="field"><label>Return window (days after delivery)</label><input name="return_window_days" id="retDays" type="number" min="1" max="30" value="<?= e((string)$p['return_window_days']) ?>"></div>
  </div>
  <script>(function(){var c=document.getElementById('isRet'),d=document.getElementById('retDays'),t=document.getElementById('isRetTxt');function s(){d.disabled=!c.checked;t.textContent=c.checked?'Returnable':'Not returnable';}c.addEventListener('change',s);s();})();</script>
  <div class="field"><label>Main image <?= $p['image_path'] ? '(upload to replace)' : '' ?></label>
    <?php if ($p['image_path']): ?><img class="thumb-sm" style="width:90px;height:90px" src="<?= e(product_image($p['image_path'])) ?>" alt=""><br><?php endif; ?>
    <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif"><small>JPG/PNG/WEBP/GIF, max 2 MB</small></div>
  <div class="field"><label>Gallery images (up to 6)</label><input type="file" name="gallery[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple></div>
  <button class="btn btn-orange" type="submit">Save product</button>
  <a class="btn btn-light" href="<?= url('admin/products.php') ?>">Cancel</a>
</form>

<?php if ($gallery): ?>
<div class="panel" style="max-width:900px;margin-top:16px"><h3 style="margin-top:0">Gallery</h3>
  <div style="display:flex;gap:14px;flex-wrap:wrap">
  <?php foreach ($gallery as $g): ?>
    <form method="post" style="text-align:center"><?= csrf_field() ?><input type="hidden" name="del_image" value="<?= (int)$g['image_id'] ?>">
      <img class="thumb-sm" style="width:90px;height:90px" src="<?= e(product_image($g['image_path'])) ?>" alt=""><br><button class="linkbtn" onclick="return confirm('Remove this image?')">Remove</button></form>
  <?php endforeach; ?></div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
