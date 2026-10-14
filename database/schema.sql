CREATE DATABASE IF NOT EXISTS agap_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE agap_db;
CREATE TABLE IF NOT EXISTS users (
 id TINYINT UNSIGNED NOT NULL,
 username VARCHAR(50) NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id), UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO users (id,username,password_hash,is_active) VALUES (1,'admin','$2b$12$bHV3t9S5cOS4y0cn2Wm7negLVyGVFB9M/rBTDuUjz448.mOaj6y.O',1)
ON DUPLICATE KEY UPDATE username=VALUES(username),password_hash=VALUES(password_hash),is_active=1;
