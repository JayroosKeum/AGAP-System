<?php

require_once __DIR__ . '/../config/database.php';

class News
{
    public const ALLOWED_CATEGORIES = [
        'Announcement',
        'Community Advisory',
        'Lupon Notice',
        'Public Service',
    ];

    public const ALLOWED_STATUSES = ['Published', 'Archived'];

    private PDO $conn;
    private static bool $schemaChecked = false;

    public function __construct(?PDO $connection = null)
    {
        $this->conn = $connection ?? (new Database())->connect();
        $this->ensureSchema();
    }

    /**
     * Ensures the barangay_news table exists and seeds default advisories on an empty table.
     */
    public function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        $this->conn->exec("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $count = (int) ($this->conn->query('SELECT COUNT(*) FROM barangay_news')->fetchColumn() ?: 0);
        if ($count === 0) {
            $stmt = $this->conn->prepare('
                INSERT INTO barangay_news (title, category, summary, content, is_pinned, status, published_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $now = time();
            $seeds = [
                [
                    'Online Complaint Filing & Residency Verification Guide',
                    'Public Service',
                    'Submit complaints or editable drafts online via AGAP and complete your Mobile Number and Barangay Tumana Address in My Profile for official verification.',
                    "Barangay Tumana residents may file Katarungang Pambarangay complaints online anytime or save drafts to finish later through the AGAP Resident Portal.\n\nTo receive official \"Resident Verified\" status, please open Profile & Security and ensure your Email Address, Mobile Number, Purok / Area in Tumana, and Complete Street Address are saved for review by the Barangay Administrator or Secretary.",
                    1,
                    'Published',
                    date('Y-m-d H:i:s', $now),
                ],
                [
                    'Tracking Your Paanyaya & Formal Lupon Hearing Schedules',
                    'Lupon Notice',
                    'Monitor your workflow stage (For Review, Approved, Paanyaya, Mediation, Conciliation) and upcoming Barangay Tumana Hall schedule inside each case record.',
                    "Each submitted or walk-in complaint displays its live procedural stage—from LISO screening and Jurisdiction Approval, to informal Paanyaya dialogue, formal Punong Barangay Mediation (KP Form 9), and Pangkat Conciliation or Arbitration.\n\nClick View on any complaint in your dashboard or My Complaints list to review both your full case details and scheduled hearing dates.",
                    0,
                    'Published',
                    date('Y-m-d H:i:s', $now - 86400),
                ],
                [
                    'Barangay Tumana Lupong Tagapamayapa Desk Advisory',
                    'Announcement',
                    'In-person walk-in complaint assistance and scheduled Katarungang Pambarangay sessions continue at the Office of the Punong Barangay, Barangay Tumana Hall.',
                    "In accordance with Republic Act No. 7160 (Local Government Code of 1991), the Lupong Tagapamayapa of Barangay Tumana, Marikina City provides fair, timely, and peaceful community dispute resolution.\n\nResidents preferring personal assistance may visit the Lupon Secretariat at Barangay Tumana Hall, where walk-in records are linked cleanly with your AGAP Reference ID.",
                    0,
                    'Published',
                    date('Y-m-d H:i:s', $now - 259200),
                ],
            ];
            foreach ($seeds as $row) {
                $stmt->execute($row);
            }
        }
    }

    public function listNews(bool $includeArchived = false, int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));
        $where = $includeArchived ? '' : "WHERE n.status = 'Published'";
        $sql = "
            SELECT
                n.news_id,
                n.title,
                n.category,
                n.summary,
                n.content,
                n.is_pinned,
                n.status,
                n.published_at,
                n.created_by,
                n.created_at,
                n.updated_at,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''), 'Office of the Punong Barangay') AS author_name
            FROM barangay_news n
            LEFT JOIN users u ON u.user_id = n.created_by
            {$where}
            ORDER BY n.is_pinned DESC, n.published_at DESC, n.news_id DESC
            LIMIT {$limit}
        ";
        $rows = $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['news_id'] = (int) $row['news_id'];
            $row['is_pinned'] = !empty($row['is_pinned']) ? 1 : 0;
        }
        unset($row);
        return $rows;
    }

    public function findById(int $newsId): ?array
    {
        if ($newsId <= 0) {
            return null;
        }
        $stmt = $this->conn->prepare("
            SELECT
                n.*,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''), 'Office of the Punong Barangay') AS author_name
            FROM barangay_news n
            LEFT JOIN users u ON u.user_id = n.created_by
            WHERE n.news_id = ?
            LIMIT 1
        ");
        $stmt->execute([$newsId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['news_id'] = (int) $row['news_id'];
        $row['is_pinned'] = !empty($row['is_pinned']) ? 1 : 0;
        return $row;
    }

    private function validatePayload(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $content = trim((string) ($data['content'] ?? ''));
        $summary = trim((string) ($data['summary'] ?? ''));
        $category = trim((string) ($data['category'] ?? 'Announcement'));
        $status = trim((string) ($data['status'] ?? 'Published'));
        $isPinned = !empty($data['is_pinned']) ? 1 : 0;

        if ($title === '' || mb_strlen($title, 'UTF-8') < 3 || mb_strlen($title, 'UTF-8') > 180) {
            throw new InvalidArgumentException('News title must be between 3 and 180 characters.');
        }
        if ($content === '' || mb_strlen($content, 'UTF-8') < 10 || mb_strlen($content, 'UTF-8') > 4000) {
            throw new InvalidArgumentException('News details must be between 10 and 4,000 characters.');
        }
        if ($summary === '') {
            $plain = preg_replace('/\s+/', ' ', $content);
            $summary = mb_strlen((string) $plain, 'UTF-8') > 220
                ? rtrim(mb_substr((string) $plain, 0, 217, 'UTF-8')) . '...'
                : (string) $plain;
        }
        if (mb_strlen($summary, 'UTF-8') > 320) {
            throw new InvalidArgumentException('Summary must be 320 characters or fewer.');
        }
        if (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
            $category = 'Announcement';
        }
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            $status = 'Published';
        }

        return [
            'title' => $title,
            'category' => $category,
            'summary' => $summary,
            'content' => $content,
            'is_pinned' => $isPinned,
            'status' => $status,
        ];
    }

    public function create(array $data, int $userId): array
    {
        $clean = $this->validatePayload($data);
        $stmt = $this->conn->prepare('
            INSERT INTO barangay_news (title, category, summary, content, is_pinned, status, published_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
        ');
        $stmt->execute([
            $clean['title'],
            $clean['category'],
            $clean['summary'],
            $clean['content'],
            $clean['is_pinned'],
            $clean['status'],
            $userId > 0 ? $userId : null,
        ]);
        $id = (int) $this->conn->lastInsertId();
        return ['success' => true, 'news_id' => $id, 'item' => $this->findById($id)];
    }

    public function update(int $newsId, array $data): array
    {
        if (!$this->findById($newsId)) {
            throw new InvalidArgumentException('News item not found.');
        }
        $clean = $this->validatePayload($data);
        $stmt = $this->conn->prepare('
            UPDATE barangay_news
            SET title = ?, category = ?, summary = ?, content = ?, is_pinned = ?, status = ?
            WHERE news_id = ?
        ');
        $stmt->execute([
            $clean['title'],
            $clean['category'],
            $clean['summary'],
            $clean['content'],
            $clean['is_pinned'],
            $clean['status'],
            $newsId,
        ]);
        return ['success' => true, 'news_id' => $newsId, 'item' => $this->findById($newsId)];
    }

    public function setField(int $newsId, string $field, mixed $value): array
    {
        if (!$this->findById($newsId)) {
            throw new InvalidArgumentException('News item not found.');
        }
        if ($field === 'is_pinned') {
            $val = !empty($value) ? 1 : 0;
            $this->conn->prepare('UPDATE barangay_news SET is_pinned = ? WHERE news_id = ?')->execute([$val, $newsId]);
        } elseif ($field === 'status') {
            $val = in_array((string) $value, self::ALLOWED_STATUSES, true) ? (string) $value : 'Published';
            $this->conn->prepare('UPDATE barangay_news SET status = ? WHERE news_id = ?')->execute([$val, $newsId]);
        } else {
            throw new InvalidArgumentException('Unsupported field update.');
        }
        return ['success' => true, 'news_id' => $newsId, 'item' => $this->findById($newsId)];
    }

    public function delete(int $newsId): array
    {
        $stmt = $this->conn->prepare('DELETE FROM barangay_news WHERE news_id = ?');
        $stmt->execute([$newsId]);
        return ['success' => $stmt->rowCount() > 0, 'news_id' => $newsId];
    }
}
