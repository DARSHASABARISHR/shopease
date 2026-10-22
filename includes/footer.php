</main>
<?php require_once __DIR__ . '/lang.php'; require_once __DIR__ . '/support.php'; $fu = current_user(); ?>
<footer class="site-footer">
  <a href="#top" class="to-top" onclick="window.scrollTo({top:0,behavior:'smooth'});return false;"><?= e(t('back_to_top')) ?></a>
  <div class="foot-inner">
    <div><h4>ShopEase</h4><p>Genuine products, secure payments, fast delivery across India.</p></div>
    <div><h4>Payments</h4><p>UPI • GPay • PhonePe • Cards • Net Banking • Cash on Delivery</p></div>
    <div><h4><?= e(t('support')) ?></h4><p><a href="<?= e(support_tel()) ?>">📞 <?= e(SUPPORT_PHONE) ?></a><br>Free delivery on orders above <?= e(money(FREE_DELIVERY_ABOVE)) ?>.</p></div>
  </div>
  <div class="foot-bottom">&copy; <?= date('Y') ?> ShopEase. All prices in Indian Rupees (₹).</div>
</footer>

<!-- Floating customer-support widget -->
<div class="support-widget" id="supportWidget">
  <div class="support-panel" id="supportPanel" hidden>
    <strong><?= e(t('support')) ?></strong>
    <a class="btn btn-block" href="<?= e(support_tel()) ?>">📞 <?= e(t('call_us')) ?></a>
    <button type="button" class="btn btn-orange btn-block" id="supportWrite">✉ <?= e(t('write_to_us')) ?></button>
  </div>
  <button type="button" class="support-fab" id="supportFab" aria-label="<?= e(t('support')) ?>" aria-expanded="false">💬</button>
</div>

<dialog id="supportDialog" class="otp-dialog" style="width:min(460px,94vw)">
  <h3 style="margin-top:0"><?= e(t('write_to_us')) ?></h3>
  <div id="supMsg" class="alert" hidden></div>
  <form id="supForm" novalidate>
    <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
    <div class="field"><label><?= e(t('your_name')) ?></label><input name="name" value="<?= e((string)($fu['full_name'] ?? '')) ?>" required maxlength="120"></div>
    <div class="field"><label><?= e(t('your_email')) ?></label><input type="email" name="email" value="<?= e((string)($fu['email'] ?? '')) ?>" required></div>
    <div class="field"><label><?= e(t('order_id_optional')) ?></label><input name="order_id" inputmode="numeric" maxlength="10"></div>
    <div class="field"><label><?= e(t('your_query')) ?></label><textarea name="message" rows="4" maxlength="2000" required></textarea></div>
    <div style="display:flex;gap:8px"><button class="btn btn-orange" type="submit" id="supSend"><?= e(t('send')) ?></button><button class="btn btn-light" type="button" id="supClose"><?= e(t('close')) ?></button></div>
  </form>
</dialog>

<!-- Quick view -->
<dialog id="quickDialog" class="otp-dialog" style="width:min(720px,95vw)">
  <div class="qv" id="qvBody">
    <div class="qv-img skeleton"><img id="qvImg" alt=""></div>
    <div>
      <h3 id="qvName" class="skeleton-line"></h3><div id="qvRating" class="rating"></div>
      <div class="price-row"><span class="price" id="qvPrice"></span><span class="mrp" id="qvMrp"></span><span class="off" id="qvOff"></span></div>
      <p id="qvDesc" style="line-height:1.5"></p><div id="qvStock" class="stock"></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-orange" id="qvAdd" type="button"><?= e(t('add_to_cart')) ?></button><a class="btn btn-light" id="qvLink" href="#">Details</a><button class="btn btn-light" type="button" id="qvClose"><?= e(t('close')) ?></button></div>
    </div>
  </div>
</dialog>
</body>
</html>
