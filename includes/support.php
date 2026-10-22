<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
// Override these in config/config.php (define before this file is loaded)
if (!defined('SUPPORT_PHONE')) define('SUPPORT_PHONE', '+917845156026');      // e.g. +919876543210
if (!defined('SUPPORT_EMAIL')) define('SUPPORT_EMAIL', SMTP_USER);            // where customer queries are delivered
function support_tel(): string { return 'tel:' . preg_replace('/[^+0-9]/', '', SUPPORT_PHONE); }
