-- ShopEase: Returns & Refunds + payment analytics.  Run ONCE in phpMyAdmin (database: shopease -> SQL tab).
USE shopease;

-- 1) Per-product return policy
ALTER TABLE products
  ADD COLUMN is_returnable       TINYINT(1)   NOT NULL DEFAULT 0 AFTER stock_quantity,
  ADD COLUMN return_window_days  INT UNSIGNED NOT NULL DEFAULT 2 AFTER is_returnable;

-- 2) Return requests (one request per order line). OTP is stored as a bcrypt hash, hence VARCHAR(255).
CREATE TABLE IF NOT EXISTS returns (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id      INT UNSIGNED NOT NULL,
    product_id    INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED NOT NULL,
    return_reason TEXT NOT NULL,
    return_status ENUM('Pending','OTP_Verified','Approved','Rejected','Picked_Up') NOT NULL DEFAULT 'Pending',
    otp_code      VARCHAR(255) NULL,
    otp_expiry    DATETIME NULL,
    otp_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    request_date  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_return_item (order_id, product_id),
    KEY idx_ret_user (user_id),
    KEY idx_ret_status (return_status, request_date),
    CONSTRAINT fk_ret_order   FOREIGN KEY (order_id)   REFERENCES orders(order_id)     ON DELETE CASCADE,
    CONSTRAINT fk_ret_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT,
    CONSTRAINT fk_ret_user    FOREIGN KEY (user_id)    REFERENCES users(user_id)       ON DELETE CASCADE
) ENGINE=InnoDB;

-- Optional demo: make Books and Fashion returnable for 7 days
-- UPDATE products SET is_returnable = 1, return_window_days = 7 WHERE category_id IN (2, 4);

-- 3) MODULE 1 - single-pass payment breakdown (also embedded in admin/index.php)
-- SELECT
--  COALESCE(SUM(CASE WHEN payment_method='Razorpay' AND payment_status='Paid' AND order_status<>'Cancelled' THEN total_amount END),0) AS online_amt,
--  COUNT(CASE WHEN payment_method='Razorpay' AND payment_status='Paid' AND order_status<>'Cancelled' THEN 1 END)                     AS online_cnt,
--  COALESCE(SUM(CASE WHEN payment_method='COD' AND payment_status='Paid' AND order_status<>'Cancelled' THEN total_amount END),0)      AS cod_amt,
--  COUNT(CASE WHEN payment_method='COD' AND payment_status='Paid' AND order_status<>'Cancelled' THEN 1 END)                          AS cod_cnt,
--  COALESCE(SUM(CASE WHEN payment_method='COD' AND payment_status<>'Paid' AND order_status<>'Cancelled' THEN total_amount END),0)    AS pend_amt,
--  COUNT(CASE WHEN payment_method='COD' AND payment_status<>'Paid' AND order_status<>'Cancelled' THEN 1 END)                         AS pend_cnt,
--  COALESCE(SUM(CASE WHEN payment_method NOT IN ('Razorpay','COD') AND payment_status='Paid' AND order_status<>'Cancelled' THEN total_amount END),0) AS other_amt,
--  COUNT(CASE WHEN payment_method NOT IN ('Razorpay','COD') AND payment_status='Paid' AND order_status<>'Cancelled' THEN 1 END)                     AS other_cnt,
--  COUNT(*) AS total_orders
-- FROM orders;
