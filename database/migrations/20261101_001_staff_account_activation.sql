-- Apply once to existing databases before enabling staff SMS activation.
ALTER TABLE users
    MODIFY COLUMN password_hash VARCHAR(255) NULL,
    ADD COLUMN activation_token CHAR(64) NULL AFTER status,
    ADD COLUMN activation_expires_at DATETIME NULL AFTER activation_token,
    ADD UNIQUE KEY uq_users_activation_token (activation_token),
    ADD KEY idx_users_activation_expiry (activation_expires_at);
