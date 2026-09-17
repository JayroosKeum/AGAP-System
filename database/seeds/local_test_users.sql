-- Local-development seed only. Every account uses password: password
-- Do not run this script in a production database.
USE agap_db;

INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
SELECT 'Admin', 'User', 'admin', 'admin@agap.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', role_id, 'Active'
FROM roles WHERE role_name = 'Administrator'
AND NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin');

INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
SELECT 'Clerk', 'User', 'clerk', 'clerk@agap.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', role_id, 'Active'
FROM roles WHERE role_name = 'Lupon Clerk'
AND NOT EXISTS (SELECT 1 FROM users WHERE username = 'clerk');

INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
SELECT 'Lupon', 'Head', 'luponhead', 'luponhead@agap.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', role_id, 'Active'
FROM roles WHERE role_name = 'Lupon Member'
AND NOT EXISTS (SELECT 1 FROM users WHERE username = 'luponhead');

INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
SELECT 'Lupon', 'Secretary', 'luponsecretary', 'luponsecretary@agap.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', role_id, 'Active'
FROM roles WHERE role_name = 'Lupon Member'
AND NOT EXISTS (SELECT 1 FROM users WHERE username = 'luponsecretary');

INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
SELECT 'Lupon', 'Member', 'luponmember', 'luponmember@agap.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', role_id, 'Active'
FROM roles WHERE role_name = 'Lupon Member'
AND NOT EXISTS (SELECT 1 FROM users WHERE username = 'luponmember');

INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
SELECT 'Summons', 'Server', 'summonsserver', 'summonsserver@agap.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', role_id, 'Active'
FROM roles WHERE role_name = 'Summons Server'
AND NOT EXISTS (SELECT 1 FROM users WHERE username = 'summonsserver');
