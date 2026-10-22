<?php /** Renders the cart body. Expects $summary from cart_summary(). Used by cart.php and ajax_cart.php */ ?>
<?php if (!$summary['items']): ?>
  <div class="panel empty"><div style="font-size:60px">🛒</div><h2>Your ShopEase cart is empty</h2><p>Add something you love.</p><a class="btn btn-orange" href="<?= url('index.php') ?>">Continue shopping</a></div>
<?php else: ?>
<div class="layout2">
  <section class="panel">
    <h2 style="margin-top:0">Shopping Cart</h2>
    <?php foreach ($summary['items'] as $it): $off = discount_percent((float)$it['mrp_price'], (float)$it['selling_price']); $out = !empty($it['out']); ?>
    <div class="cart-item" data-pid="<?= (int)$it['product_id'] ?>">
      <a href="<?= url('product.php?id=' . (int)$it['product_id']) ?>"><img src="<?= e(product_image($it['image_path'])) ?>" alt=""></a>
      <div>
        <h3><a href="<?= url('product.php?id=' . (int)$it['product_id']) ?>"><?= e($it['name']) ?></a></h3>
        <?php if ($out): ?><div class="stock out">Out of stock - remove to continue</div>
        <?php elseif ((int)$it['stock_quantity'] <= LOW_STOCK_THRESHOLD): ?><div class="stock low">Only <?= (int)$it['stock_quantity'] ?> left</div>
        <?php else: ?><div class="stock in">In stock</div><?php endif; ?>
        <div class="cart-actions">
          <span class="qty"><button type="button" data-cart-act="dec" aria-label="Decrease">−</button><input class="q" type="number" min="1" max="<?= (int)$it['stock_quantity'] ?>" value="<?= (int)$it['qty'] ?>" <?= $out ? 'disabled' : '' ?>><button type="button" data-cart-act="inc" aria-label="Increase">+</button></span>
          <button class="linkbtn" type="button" data-cart-act="remove">Delete</button>
        </div>
      </div>
      <div class="price-col" style="text-align:right">
        <div class="price"><?= money($it['line_total']) ?></div>
        <div class="mrp"><?= $off > 0 ? money((float)$it['mrp_price'] * (int)$it['qty']) : '' ?></div>
        <div style="color:#565959;font-size:12px"><?= money($it['selling_price']) ?> each</div>
      </div>
    </div>
    <?php endforeach; ?>
  </section>

  <aside class="panel summary">
    <h3>Price details (<?= (int)$summary['count'] ?> item<?= $summary['count'] === 1 ? '' : 's' ?>)</h3>
    <div class="sum-row"><span>Items total</span><span><?= money($summary['items_total']) ?></span></div>
    <?php if ($summary['mrp_total'] > $summary['items_total']): ?>
    <div class="sum-row"><span>Discount on MRP</span><span class="save">- <?= money($summary['mrp_total'] - $summary['items_total']) ?></span></div>
    <?php endif; ?>
    <div class="sum-row"><span>Delivery charges</span><span><?= $summary['delivery'] > 0 ? money($summary['delivery']) : '<span class="save">FREE</span>' ?></span></div>
    <div class="sum-row"><span>GST (<?= rtrim(rtrim(number_format(GST_RATE, 2), '0'), '.') ?>%)</span><span><?= money($summary['gst']) ?></span></div>
    <div class="sum-row total"><span>Net payable</span><span><?= money($summary['total']) ?></span></div>
    <?php if ($summary['delivery'] > 0): ?><p style="color:#565959;font-size:12px">Add items worth <?= money(FREE_DELIVERY_ABOVE - $summary['items_total']) ?> more for FREE delivery.</p><?php endif; ?>
    <?php $hasOut = array_filter($summary['items'], fn($i) => !empty($i['out'])); ?>
    <a class="btn btn-orange btn-block" href="<?= $hasOut ? '#' : url('checkout.php') ?>" <?= $hasOut ? 'style="opacity:.5;pointer-events:none"' : '' ?>>Proceed to Checkout</a>
  </aside>
</div>
<?php endif; ?>
