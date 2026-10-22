-- ShopEase update: Analytics charts + WhatsApp OTP.  Run ONCE in phpMyAdmin (database: shopease -> SQL tab).
USE shopease;

-- 1) WhatsApp number on users --------------------------------------------------
ALTER TABLE users
  ADD COLUMN whatsapp_number   CHAR(10) NULL AFTER phone,            -- 10-digit number, country code comes from config
  ADD COLUMN whatsapp_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER whatsapp_number,
  ADD KEY idx_users_whatsapp (whatsapp_number, whatsapp_verified);

-- 2) Generic OTP store (WhatsApp login / link number). OTPs are stored hashed. --
CREATE TABLE IF NOT EXISTS otp_codes (
    otp_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purpose    ENUM('wa_login','wa_link') NOT NULL,
    user_id    INT UNSIGNED NULL,
    target     VARCHAR(20) NOT NULL,            -- 10-digit WhatsApp number
    otp_hash   VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    used_at    DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_otp_lookup (purpose, target, created_at),
    CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3) Stock movement log (drives the inventory depletion charts) ----------------
CREATE TABLE IF NOT EXISTS stock_log (
    log_id     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    order_id   INT UNSIGNED NULL,
    change_qty INT NOT NULL,                    -- negative = stock reduced, positive = stock added back
    reason     ENUM('order','cancel','admin_edit') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sl_product_time (product_id, created_at),
    KEY idx_sl_time (created_at),
    CONSTRAINT fk_sl_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
    CONSTRAINT fk_sl_order   FOREIGN KEY (order_id)   REFERENCES orders(order_id)     ON DELETE SET NULL
) ENGINE=InnoDB;

-- Back-fill history from the orders you already have, so the charts are not empty
INSERT INTO stock_log (product_id, order_id, change_qty, reason, created_at)
SELECT oi.product_id, oi.order_id, -oi.quantity, 'order', o.created_at
  FROM order_items oi JOIN orders o ON o.order_id = oi.order_id;

INSERT INTO stock_log (product_id, order_id, change_qty, reason, created_at)
SELECT oi.product_id, oi.order_id, oi.quantity, 'cancel', o.created_at
  FROM order_items oi JOIN orders o ON o.order_id = oi.order_id
 WHERE o.order_status = 'Cancelled';
