-- ShopEase: notifications, self-cancel + Razorpay refund, support.  Run ONCE in phpMyAdmin (database: shopease -> SQL tab).
-- Prerequisite: sql/cancel_otp_update.sql was already run (it created cancel_reason and cancelled_at).
USE shopease;

ALTER TABLE orders
  MODIFY payment_status ENUM('Pending','Paid','Failed','COD','Refunded','Cancelled') NOT NULL DEFAULT 'Pending',
  ADD COLUMN refund_id        VARCHAR(60) NULL,                                   -- Razorpay rfnd_xxx
  ADD COLUMN cancelled_by     ENUM('customer','admin','system') NULL,
  ADD COLUMN courier_name     VARCHAR(80) NULL,
  ADD COLUMN tracking_number  VARCHAR(80) NULL,
  ADD COLUMN shipped_at       DATETIME NULL;

ALTER TABLE payments
  MODIFY status ENUM('Created','Success','Failed','Pending','Refunded','Cancelled') NOT NULL DEFAULT 'Created';

UPDATE orders SET cancelled_by = 'admin' WHERE order_status = 'Cancelled' AND cancelled_by IS NULL AND cancel_reason IS NOT NULL;

CREATE TABLE IF NOT EXISTS support_queries (
    query_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NULL,
    name       VARCHAR(120) NOT NULL,
    email      VARCHAR(190) NOT NULL,
    order_id   INT UNSIGNED NULL,
    message    TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sq_created (created_at)
) ENGINE=InnoDB;
