# ShopEase – PHP + MySQL E-Commerce (Razorpay, Gmail OTP, Admin Panel)

## Requirements
PHP 8.0+ (pdo_mysql, curl, fileinfo, mbstring, openssl), MySQL 5.7+/8, Apache (XAMPP/WAMP/LAMP), Composer.

## Setup
1. Copy the `shopease` folder into your web root (e.g. `htdocs/shopease`).
2. Install PHPMailer: run `composer install` inside the folder.
   (No Composer? Download PHPMailer and place `Exception.php`, `PHPMailer.php`, `SMTP.php` in `PHPMailer/src/`.)
3. Import the database: `mysql -u root -p < sql/schema.sql` (or import in phpMyAdmin).
4. Edit `config/config.php` (or set environment variables):
   - `DB_*` – database credentials
   - `SMTP_USER` / `SMTP_PASS` – your Gmail address and a **Google App Password**
     (Google Account → Security → 2-Step Verification → App passwords)
   - `RAZORPAY_KEY_ID` / `RAZORPAY_KEY_SECRET` – from the Razorpay dashboard (use Test keys first)
   - `BASE_URL` (e.g. `/shopease`) and `APP_URL` (e.g. `http://localhost/shopease`)
5. Make `uploads/products/` writable by the web server (`chmod 775`).
6. Create the admin account (CLI):
   `php setup/create_admin.php "Admin" you@gmail.com 9876543210 "StrongPass123"`
7. Open `http://localhost/shopease/` – admin panel: sign in, then Account → Admin Panel.

## Flow
- Register → OTP email (hashed in DB, 10 min expiry, 5 attempts) → verify → Welcome email → login.
- Cart is session based (`ajax_cart.php`); prices are always re-read from the DB.
- Checkout → `create_order.php` (stock reserved in a transaction)
  - COD: captcha + confirmation → order placed → invoice email.
  - Razorpay: server creates order via API, `checkout.js` opens modal (amount in paise),
    `verify_payment.php` validates the HMAC-SHA256 signature, marks the order/payment paid, sends invoice.
- Cancelling an order (admin) or a failed/dismissed payment returns stock automatically.

## Test payments
Razorpay test mode: card `4111 1111 1111 1111`, any future expiry, any CVV; UPI `success@razorpay`.

## Security notes
PDO prepared statements everywhere, `htmlspecialchars` output escaping, CSRF tokens on all POSTs,
bcrypt for passwords and OTPs, session ID regeneration on login, upload MIME validation with execution blocked in `uploads/`,
`config/`, `includes/` and `setup/` denied via `.htaccess`. In production serve over HTTPS and keep `SHOPEASE_DEBUG` off.
Change GST/delivery rules in `config/config.php`.
