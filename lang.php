<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
$l = (($_GET['set'] ?? '') === 'ta') ? 'ta' : 'en';
$_SESSION['lang'] = $l;
setcookie('lang', $l, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
$back = (string)($_GET['back'] ?? '');
header("Location: " . (!empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php')); exit;