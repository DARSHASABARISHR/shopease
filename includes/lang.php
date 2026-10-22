<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

/** Active language: session first, then cookie, default English. */
function lang_code(): string
{
    $l = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'en');
    return in_array($l, ['en', 'ta'], true) ? $l : 'en';
}

/** t('add_to_cart') or t('only_left', ['n' => 3]); falls back to English, then to the key itself. */
function t(string $key, array $repl = []): string
{
    static $dict = [];
    $l = lang_code();
    foreach (['en', $l] as $c) if (!isset($dict[$c])) $dict[$c] = require __DIR__ . '/../lang/' . $c . '.php';
    $s = $dict[$l][$key] ?? $dict['en'][$key] ?? $key;
    foreach ($repl as $k => $v) $s = str_replace(':' . $k, (string)$v, $s);
    return $s;
}

function cat_name(string $slug, string $fallback): string { $k = 'cat_' . $slug; $s = t($k); return $s === $k ? $fallback : $s; }
