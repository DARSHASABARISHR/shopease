<?php
/**
 * ShopEase - central configuration.
 * Any value can be overridden with environment variables (recommended in production).
 */
declare(strict_types=1);

function env(string $key, string $default = ''): string {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

// ---- Application ----------------------------------------------------------
define('APP_NAME',  'ShopEase');
// URL path where the app lives, no trailing slash ('' if at web root).
define('BASE_URL',  env('SHOPEASE_BASE_URL', '/shopease'));
// Absolute URL used inside emails (no trailing slash)
define('APP_URL',   env('SHOPEASE_APP_URL', 'http://localhost/shopease'));
define('ROOT_PATH', dirname(__DIR__));
define('UPLOAD_DIR', ROOT_PATH . '/uploads/products/');
define('UPLOAD_URL', BASE_URL . '/uploads/products/');
define('APP_DEBUG', env('SHOPEASE_DEBUG', '0') === '1');

// ---- Database -------------------------------------------------------------
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'shopease'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

// ---- Gmail SMTP (use a Google *App Password*, not your normal password) ----
define('SMTP_HOST',  'smtp.gmail.com');
define('SMTP_PORT',  587);
define('SMTP_USER',  env('SMTP_USER', 'rdarshasabarish@gmail.com'));
define('SMTP_PASS',  env('SMTP_PASS', 'yfmz akha jjer nblo'));   // 16-char app password
define('MAIL_FROM',  env('MAIL_FROM', SMTP_USER));
define('MAIL_FROM_NAME', APP_NAME);

// ---- Razorpay (https://dashboard.razorpay.com/app/keys) --------------------
define('RAZORPAY_KEY_ID',     env('RAZORPAY_KEY_ID', 'rzp_test_ToXyDJXKuTbsu4'));
define('RAZORPAY_KEY_SECRET', env('RAZORPAY_KEY_SECRET', 'xt5wF5Yq6OQl1pwT3lM8UyL6'));

// ---- Pricing rules ---------------------------------------------------------
define('GST_RATE', 18.0);                 // % added on items total
define('FREE_DELIVERY_ABOVE', 500.00);    // ₹
define('DELIVERY_CHARGE', 40.00);         // ₹
define('LOW_STOCK_THRESHOLD', 10);
define('OTP_TTL_MINUTES', 10);
define('OTP_MAX_ATTEMPTS', 5);

date_default_timezone_set('Asia/Kolkata');

if (APP_DEBUG) { ini_set('display_errors', '1'); error_reporting(E_ALL); }
else           { ini_set('display_errors', '0'); error_reporting(E_ALL); ini_set('log_errors', '1'); }
