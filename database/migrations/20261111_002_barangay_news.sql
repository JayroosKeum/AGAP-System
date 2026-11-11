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

INSERT INTO barangay_news (title, category, summary, content, is_pinned, status, published_at)
SELECT * FROM (
    SELECT
        'Online Complaint Filing & Residency Verification Guide' AS title,
        'Public Service' AS category,
        'Submit complaints or editable drafts online via AGAP and complete your Mobile Number and Barangay Tumana Address in My Profile for official verification.' AS summary,
        'Barangay Tumana residents may file Katarungang Pambarangay complaints online anytime or save drafts to finish later through the AGAP Resident Portal.\n\nTo receive official "Resident Verified" status, please open Profile & Security and ensure your Email Address, Mobile Number, Purok / Area in Tumana, and Complete Street Address are saved for review by the Barangay Administrator or Secretary.' AS content,
        1 AS is_pinned,
        'Published' AS status,
        NOW() AS published_at
    UNION ALL
    SELECT
        'Tracking Your Paanyaya & Formal Lupon Hearing Schedules' AS title,
        'Lupon Notice' AS category,
        'Monitor your workflow stage (For Review, Approved, Paanyaya, Mediation, Conciliation) and upcoming Barangay Tumana Hall schedule inside each case record.' AS summary,
        'Each submitted or walk-in complaint displays its live procedural stage—from LISO screening and Jurisdiction Approval, to informal Paanyaya dialogue, formal Punong Barangay Mediation (KP Form 9), and Pangkat Conciliation or Arbitration.\n\nClick View on any complaint in your dashboard or My Complaints list to review both your full case details and scheduled hearing dates.' AS content,
        0 AS is_pinned,
        'Published' AS status,
        DATE_SUB(NOW(), INTERVAL 1 DAY) AS published_at
    UNION ALL
    SELECT
        'Barangay Tumana Lupong Tagapamayapa Weekly Desk Hours' AS title,
        'Announcement' AS category,
        'In-person walk-in complaint assistance and scheduled Katarungang Pambarangay sessions continue at the Office of the Punong Barangay, Barangay Tumana Hall.' AS summary,
        'In accordance with Republic Act No. 7160 (Local Government Code of 1991), the Lupong Tagapamayapa of Barangay Tumana, Marikina City provides fair, timely, and peaceful community dispute resolution.\n\nResidents preferring personal assistance may visit the Lupon Secretariat at Barangay Tumana Hall, where walk-in records are linked cleanly with your AGAP Reference ID.' AS content,
        0 AS is_pinned,
        'Published' AS status,
        DATE_SUB(NOW(), INTERVAL 3 DAY) AS published_at
) AS seed_rows
WHERE NOT EXISTS (SELECT 1 FROM barangay_news LIMIT 1);
