-- Run once against capstone_culture_arts. This migration does not drop or rewrite existing data.
CREATE TABLE IF NOT EXISTS otp_challenges (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    purpose ENUM('registration','password_reset') NOT NULL,
    email VARCHAR(100) NOT NULL,
    account_table VARCHAR(32) DEFAULT NULL,
    account_id INT DEFAULT NULL,
    challenge_token_hash CHAR(64) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    resend_available_at DATETIME NOT NULL,
    resend_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    resend_locked_until DATETIME DEFAULT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) DEFAULT NULL,
    used_at DATETIME DEFAULT NULL,
    invalidated_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_otp_lookup (purpose, email, invalidated_at, used_at),
    KEY idx_otp_expiry (expires_at),
    KEY idx_otp_account (account_table, account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE applications ADD COLUMN IF NOT EXISTS registration_password_hash VARCHAR(255) DEFAULT NULL;
ALTER TABLE otp_challenges ADD COLUMN IF NOT EXISTS resend_count TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE otp_challenges ADD COLUMN IF NOT EXISTS resend_locked_until DATETIME DEFAULT NULL;
