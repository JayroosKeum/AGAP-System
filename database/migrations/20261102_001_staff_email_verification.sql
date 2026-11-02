-- Apply once to existing databases before enabling staff email verification.
-- Tokens stored here are SHA-256 hashes; the raw token is sent only by email.
ALTER TABLE users
    MODIFY COLUMN password_hash VARCHAR(255) NULL,
    ADD COLUMN email_verification_token CHAR(64) NULL AFTER status,
    ADD COLUMN email_verification_expires_at DATETIME NULL AFTER email_verification_token,
    ADD UNIQUE KEY uq_users_email_verification_token (email_verification_token),
    ADD KEY idx_users_email_verification_expiry (email_verification_expires_at);
