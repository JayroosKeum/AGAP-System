-- AGAP-Remastered
-- Initial database, access-control tables, and predetermined administrator
-- Compatible with MySQL and MariaDB

CREATE DATABASE IF NOT EXISTS remastered_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE remastered_db;

START TRANSACTION;

CREATE TABLE IF NOT EXISTS roles (
    role_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_roles_role_name (role_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(150) NULL,
    contact_no VARCHAR(20) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_id (role_id),
    CONSTRAINT fk_users_role
        FOREIGN KEY (role_id) REFERENCES roles (role_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (role_name)
VALUES ('Administrator')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

INSERT INTO users (
    first_name,
    middle_name,
    last_name,
    username,
    email,
    contact_no,
    password_hash,
    role_id,
    status
)
SELECT
    'System',
    NULL,
    'Administrator',
    'admin',
    'admin@agap-remastered.test',
    NULL,
    '$2b$12$bHV3t9S5cOS4y0cn2Wm7negLVyGVFB9M/rBTDuUjz448.mOaj6y.O',
    roles.role_id,
    'Active'
FROM roles
WHERE roles.role_name = 'Administrator'
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    middle_name = VALUES(middle_name),
    last_name = VALUES(last_name),
    email = VALUES(email),
    contact_no = VALUES(contact_no),
    password_hash = VALUES(password_hash),
    role_id = VALUES(role_id),
    status = VALUES(status);

COMMIT;
