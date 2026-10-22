-- Run once on the existing `shopease` database (phpMyAdmin -> SQL tab)
USE shopease;

CREATE TABLE IF NOT EXISTS password_resets (
    reset_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    purpose     ENUM('reset','change') NOT NULL DEFAULT 'reset',  -- reset = forgot password, change = logged-in change
    otp_hash    VARCHAR(255) NOT NULL,          -- bcrypt hash of the 6-digit OTP (never plain text)
    expires_at  DATETIME NOT NULL,              -- now + 10 minutes
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    verified_at DATETIME NULL,                  -- set when the correct OTP was entered
    used_at     DATETIME NULL,                  -- set when the password was changed / OTP superseded
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pr_lookup (user_id, purpose, created_at),
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;
