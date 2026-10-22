-- ShopEase: Delivery OTP + COD cash collection.  Run ONCE in phpMyAdmin (database: shopease -> SQL tab).
USE shopease;

ALTER TABLE orders
  MODIFY order_status ENUM('Pending','Shipped','Out for Delivery','Delivered','Cancelled') NOT NULL DEFAULT 'Pending',
  ADD COLUMN delivery_otp_hash     VARCHAR(255) NULL AFTER order_status,          -- bcrypt hash of the 4-digit OTP
  ADD COLUMN delivery_otp_expires  DATETIME NULL AFTER delivery_otp_hash,         -- OTP valid for 24 hours
  ADD COLUMN delivery_otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER delivery_otp_expires,
  ADD COLUMN delivery_otp_sent_at  DATETIME NULL AFTER delivery_otp_attempts,
  ADD COLUMN delivered_at          DATETIME NULL AFTER delivery_otp_sent_at,
  ADD COLUMN cod_collected_at      DATETIME NULL AFTER delivered_at;

-- Old COD orders that were already "Delivered" are treated as cash received, so the dashboard stays correct
UPDATE orders SET payment_status = 'Paid', delivered_at = COALESCE(delivered_at, created_at)
 WHERE payment_method = 'COD' AND order_status = 'Delivered';

UPDATE payments p JOIN orders o ON o.order_id = p.order_id
   SET p.status = 'Success', p.payment_date = COALESCE(p.payment_date, NOW())
 WHERE o.payment_method = 'COD' AND o.order_status = 'Delivered';
