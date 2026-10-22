-- ShopEase: pickup OTP for returns + customer-query status.  Run ONCE in phpMyAdmin (database: shopease -> SQL tab).
USE shopease;

ALTER TABLE returns
  ADD COLUMN pickup_otp          VARCHAR(255) NULL,                     -- bcrypt hash of the 6-digit pickup OTP
  ADD COLUMN pickup_otp_expiry   DATETIME NULL,                         -- valid 10 minutes
  ADD COLUMN pickup_otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN pickup_otp_sent_at  DATETIME NULL,                         -- resend cooldown (45 s)
  ADD COLUMN picked_up_at        DATETIME NULL;

ALTER TABLE support_queries
  ADD COLUMN status      ENUM('Pending','Resolved') NOT NULL DEFAULT 'Pending' AFTER message,
  ADD COLUMN resolved_at DATETIME NULL,
  ADD COLUMN resolved_by INT UNSIGNED NULL,
  ADD KEY idx_sq_status (status, created_at);
