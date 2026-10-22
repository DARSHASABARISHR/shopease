-- ShopEase: Admin order cancellation with customer OTP.  Run ONCE in phpMyAdmin (database: shopease -> SQL tab).
USE shopease;

ALTER TABLE orders
  ADD COLUMN cancel_otp          VARCHAR(255) NULL,                      -- bcrypt hash of the 6-digit OTP (never plain text)
  ADD COLUMN cancel_otp_expiry   DATETIME NULL,                          -- valid for 10 minutes
  ADD COLUMN cancel_otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,    -- wrong tries (locks at 5)
  ADD COLUMN cancel_otp_sent_at  DATETIME NULL,                          -- resend cooldown (45 s)
  ADD COLUMN cancel_reason       TEXT NULL,
  ADD COLUMN cancelled_at        DATETIME NULL;
