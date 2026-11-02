-- Migration: Create lupon_duty_schedules and add Secretary role
INSERT INTO roles (role_id, role_name, created_at)
VALUES (5, 'Secretary', NOW())
ON DUPLICATE KEY UPDATE role_name = 'Secretary';

CREATE TABLE IF NOT EXISTS lupon_duty_schedules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    duty_day VARCHAR(20) NOT NULL COMMENT 'Monday, Tuesday, Wednesday, Thursday, Friday, Saturday, Sunday',
    start_time TIME NULL,
    end_time TIME NULL,
    notes VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_day (user_id, duty_day),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
