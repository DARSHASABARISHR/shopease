<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/support.php';
$user = current_user();
$cats = Database::getConnection()->query('SELECT category_id, name, slug FROM categories ORDER BY name')->fetchAll();
$pageTitle = $pageTitle ?? APP_NAME;
$q = trim((string)($_GET['q'] ?? ''));
$activeCat = (string)($_GET['cat'] ?? '');
$L = lang_code();
$back = urlencode($_SERVER['REQUEST_URI'] ?? '/');
?>
<!DOCTYPE html>
<html lang="<?= $L ?>" data-speech="<?= $L === 'ta' ? 'ta-IN' : 'en-IN' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
<script>try{var th=localStorage.getItem('se-theme')||(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.dataset.theme=th;}catch(e){}</script>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/theme.css">
<script>window.BASE_URL = <?= json_encode(BASE_URL) ?>; window.I18N = <?= json_encode(['listening' => t('listening'), 'search' => t('search_placeholder'), 'add' => t('add_to_cart'), 'in' => t('in_stock'), 'out' => t('out_of_stock')], JSON_UNESCAPED_UNICODE) ?>;</script>
<script defer src="<?= BASE_URL ?>/assets/js/app.js"></script>
<script defer src="<?= BASE_URL ?>/assets/js/ui.js"></script>
<script defer src="<?= BASE_URL ?>/assets/js/tilt.js"></script>
<script defer src="<?= BASE_URL ?>/assets/js/voice_search.js"></script>
<script defer src="<?= BASE_URL ?>/assets/js/support.js"></script>
</head>
<body>
<header class="site-header">
  <div class="nav-top">
    <a class="logo" href="<?= url('index.php') ?>">Shop<span>Ease</span></a>

    <form class="search" action="<?= url('index.php') ?>" method="get" autocomplete="off" role="search">
      <select name="cat" aria-label="Category">
        <option value=""><?= e(t('all')) ?></option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= e($c['slug']) ?>" <?= $activeCat === $c['slug'] ? 'selected' : '' ?>><?= e(cat_name($c['slug'], $c['name'])) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="search" name="q" id="searchInput" value="<?= e($q) ?>" placeholder="<?= e(t('search_placeholder')) ?>" aria-label="<?= e(t('search')) ?>">
      <button type="button" id="voiceBtn" class="voice-btn" aria-label="<?= e(t('voice_search')) ?>" title="<?= e(t('voice_search')) ?>">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v4M8 22h8"/></svg></button>
      <button type="submit" aria-label="<?= e(t('search')) ?>">🔍</button>
      <div id="suggestBox" class="suggest" hidden></div>
    </form>

    <div class="nav-right">
      <div class="dropdown">
        <a class="nav-link" href="#" aria-label="<?= e(t('language')) ?>"><small>🌐 <?= e(t('language')) ?></small><strong><?= $L === 'ta' ? 'தமிழ்' : 'English' ?> ▾</strong></a>
        <div class="dropdown-menu">
          <a href="<?= url('lang.php?set=en&back=' . $back) ?>">English</a>
          <a href="<?= url('lang.php?set=ta&back=' . $back) ?>">தமிழ்</a>
        </div>
      </div>
      <button type="button" id="themeBtn" class="theme-btn" aria-label="<?= e(t('theme')) ?>" title="<?= e(t('theme')) ?>">🌓</button>
      <div class="dropdown">
        <a class="nav-link" href="<?= $user ? '#' : url('login.php') ?>">
          <small><?= e(t('hello')) ?>, <?= $user ? e(explode(' ', $user['full_name'])[0]) : e(t('sign_in_short')) ?></small>
          <strong><?= e(t('account')) ?> ▾</strong>
        </a>
        <div class="dropdown-menu">
          <?php if ($user): ?>
            <?php if ($user['role'] === 'admin'): ?><a href="<?= url('admin/index.php') ?>"><?= e(t('admin_panel')) ?></a><?php endif; ?>
            <a href="<?= url('my_orders.php') ?>"><?= e(t('my_orders')) ?></a>
            <a href="<?= url('change_password.php') ?>"><?= e(t('change_password')) ?></a>
            <a href="<?= url('whatsapp_settings.php') ?>"><?= e(t('whatsapp_login')) ?></a>
            <a href="<?= url('logout.php') ?>"><?= e(t('sign_out')) ?></a>
          <?php else: ?>
            <a href="<?= url('login.php') ?>"><?= e(t('sign_in')) ?></a>
            <a href="<?= url('register.php') ?>"><?= e(t('create_account')) ?></a>
          <?php endif; ?>
        </div>
      </div>
      <a class="nav-link" href="<?= url('my_orders.php') ?>"><small><?= e(t('returns')) ?></small><strong><?= e(t('orders')) ?></strong></a>
      <a class="cart-link" href="<?= url('cart.php') ?>" aria-label="<?= e(t('cart')) ?>">
        <span class="cart-icon">🛒</span><span class="cart-badge" id="cartBadge"><?= cart_count() ?></span><strong><?= e(t('cart')) ?></strong>
      </a>
    </div>
  </div>
  <nav class="nav-sub" aria-label="Categories">
    <a href="<?= url('index.php') ?>" class="<?= $activeCat === '' ? 'active' : '' ?>"><?= e(t('all')) ?></a>
    <?php foreach ($cats as $c): ?>
      <a href="<?= url('index.php?cat=' . urlencode($c['slug'])) ?>" class="<?= $activeCat === $c['slug'] ? 'active' : '' ?>"><?= e(cat_name($c['slug'], $c['name'])) ?></a>
    <?php endforeach; ?>
  </nav>
</header>
<main class="container">
<?= flash_render() ?>
