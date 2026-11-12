-- Migration: Barangay News & Advisories Feed for Resident & Staff Dashboards
-- Date: 2026-11-11

CREATE TABLE IF NOT EXISTS barangay_news (
    news_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    category VARCHAR(60) NOT NULL DEFAULT 'Announcement',
    summary VARCHAR(320) NOT NULL,
    content TEXT NOT NULL,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('Published', 'Archived') NOT NULL DEFAULT 'Published',
    published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_barangay_news_feed (status, is_pinned, published_at),
    CONSTRAINT fk_barangay_news_author FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
