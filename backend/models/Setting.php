<?php

require_once __DIR__ . '/../config/Database.php';

class Setting
{
    private PDO $conn;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?: (new Database())->connect();
    }

    public static function all(?PDO $conn = null): array
    {
        return (new self($conn))->getAllSettings();
    }

    public static function getSetting(string $key, $default = null, ?PDO $conn = null): mixed
    {
        return (new self($conn))->get($key, $default);
    }

    /**
     * Retrieves all system settings as an associative key => value array.
     */
    public function getAllSettings(): array
    {
        $stmt = $this->conn->query("SELECT setting_key, setting_value, description FROM system_settings ORDER BY setting_key ASC");
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[$row['setting_key']] = $row['setting_value'];
        }
        return $results;
    }

    /**
     * Gets a single setting value with optional default fallback.
     */
    public function get(string $key, $default = null): mixed
    {
        $stmt = $this->conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false) ? $val : $default;
    }

    /**
     * Updates or inserts a single setting.
     */
    public function set(string $key, string $value, ?string $description = null): bool
    {
        if ($description !== null) {
            $stmt = $this->conn->prepare("
                INSERT INTO system_settings (setting_key, setting_value, description)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description), updated_at = CURRENT_TIMESTAMP
            ");
            return $stmt->execute([$key, $value, $description]);
        }

        $stmt = $this->conn->prepare("
            INSERT INTO system_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP
        ");
        return $stmt->execute([$key, $value]);
    }

    /**
     * Atomically saves multiple key-value pairs.
     */
    public function setMultiple(array $settings): bool
    {
        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO system_settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP
            ");
            foreach ($settings as $key => $val) {
                if (is_array($val)) {
                    $val = json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                $stmt->execute([$key, (string)$val]);
            }
            $this->conn->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Failed to setMultiple settings: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetches all dispute categories with translations and LTIA tracking codes.
     */
    public function getCategories(): array
    {
        $stmt = $this->conn->query("
            SELECT cc.category_id, cc.category_name, cc.tagalog_name, cc.ltia_code, cc.description, cc.is_active,
                   COUNT(c.complaint_id) AS complaint_count
            FROM complaint_categories cc
            LEFT JOIN complaints c ON c.category_id = cc.category_id
            GROUP BY cc.category_id, cc.category_name, cc.tagalog_name, cc.ltia_code, cc.description, cc.is_active
            ORDER BY cc.category_id ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Creates or updates a dispute category.
     */
    public function saveCategory(array $data): array
    {
        $categoryId = !empty($data['category_id']) ? (int)$data['category_id'] : 0;
        $name = trim((string)($data['category_name'] ?? ''));
        $tagalog = trim((string)($data['tagalog_name'] ?? ''));
        $ltiaCode = trim((string)($data['ltia_code'] ?? ''));
        $desc = trim((string)($data['description'] ?? ''));
        $isActive = isset($data['is_active']) ? ((int)$data['is_active'] ? 1 : 0) : 1;

        if ($name === '') {
            return ['success' => false, 'message' => 'Category name is required.'];
        }

        try {
            if ($categoryId > 0) {
                // Check duplicate name excluding self
                $dup = $this->conn->prepare("SELECT COUNT(*) FROM complaint_categories WHERE category_name = ? AND category_id != ?");
                $dup->execute([$name, $categoryId]);
                if ($dup->fetchColumn() > 0) {
                    return ['success' => false, 'message' => "Category '{$name}' already exists."];
                }

                $stmt = $this->conn->prepare("
                    UPDATE complaint_categories
                    SET category_name = ?, tagalog_name = ?, ltia_code = ?, description = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE category_id = ?
                ");
                $stmt->execute([$name, $tagalog ?: null, $ltiaCode ?: null, $desc ?: null, $isActive, $categoryId]);
                return ['success' => true, 'message' => 'Category updated successfully.', 'category_id' => $categoryId];
            } else {
                // Check duplicate name
                $dup = $this->conn->prepare("SELECT COUNT(*) FROM complaint_categories WHERE category_name = ?");
                $dup->execute([$name]);
                if ($dup->fetchColumn() > 0) {
                    return ['success' => false, 'message' => "Category '{$name}' already exists."];
                }

                $stmt = $this->conn->prepare("
                    INSERT INTO complaint_categories (category_name, tagalog_name, ltia_code, description, is_active)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $tagalog ?: null, $ltiaCode ?: null, $desc ?: null, $isActive]);
                $newId = (int)$this->conn->lastInsertId();
                return ['success' => true, 'message' => 'Category created successfully.', 'category_id' => $newId];
            }
        } catch (Throwable $e) {
            error_log("saveCategory error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Toggles active state or deletes a category if unreferenced.
     */
    public function deleteCategory(int $categoryId): array
    {
        // Check if complaints are linked to this category
        $countStmt = $this->conn->prepare("SELECT COUNT(*) FROM complaints WHERE category_id = ?");
        $countStmt->execute([$categoryId]);
        $linked = (int)$countStmt->fetchColumn();

        if ($linked > 0) {
            // Soft-deactivate to preserve historical complaint linkages
            $stmt = $this->conn->prepare("UPDATE complaint_categories SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE category_id = ?");
            $stmt->execute([$categoryId]);
            return ['success' => true, 'message' => "Category has {$linked} existing complaint(s). It has been marked Inactive instead of permanently deleted."];
        }

        $stmt = $this->conn->prepare("DELETE FROM complaint_categories WHERE category_id = ?");
        $stmt->execute([$categoryId]);
        return ['success' => true, 'message' => 'Category deleted permanently.'];
    }

    /**
     * Calculates storage directory usage.
     */
    public function getStorageBreakdown(): array
    {
        $baseStorage = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';
        $dirs = [
            'evidence' => $baseStorage . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'complaint-evidence',
            'scans' => $baseStorage . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'complaint-scans',
            'seals' => $baseStorage . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'seals',
            'paanyaya' => $baseStorage . DIRECTORY_SEPARATOR . 'paanyaya',
            'documents' => $baseStorage . DIRECTORY_SEPARATOR . 'generated-documents',
            'backups' => $baseStorage . DIRECTORY_SEPARATOR . 'backups'
        ];

        $results = [];
        $totalBytes = 0;

        foreach ($dirs as $key => $dir) {
            $bytes = 0;
            $count = 0;
            if (is_dir($dir)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($it as $file) {
                    if ($file->isFile()) {
                        $bytes += $file->getSize();
                        $count++;
                    }
                }
            }
            $results[$key] = [
                'bytes' => $bytes,
                'readable' => $this->formatBytes($bytes),
                'file_count' => $count
            ];
            $totalBytes += $bytes;
        }

        $results['total'] = [
            'bytes' => $totalBytes,
            'readable' => $this->formatBytes($totalBytes)
        ];

        // Disk space
        $diskTotal = @disk_total_space($baseStorage) ?: 0;
        $diskFree = @disk_free_space($baseStorage) ?: 0;
        $results['disk'] = [
            'total_bytes' => $diskTotal,
            'free_bytes' => $diskFree,
            'total_readable' => $this->formatBytes($diskTotal),
            'free_readable' => $this->formatBytes($diskFree),
            'used_percentage' => $diskTotal > 0 ? round((($diskTotal - $diskFree) / $diskTotal) * 100, 1) : 0
        ];

        return $results;
    }

    /**
     * Diagnostics information.
     */
    public function getDiagnostics(): array
    {
        $mysqlVer = 'Unknown';
        try {
            $mysqlVer = $this->conn->query("SELECT VERSION()")->fetchColumn() ?: 'Unknown';
        } catch (_) {}

        $requiredExts = ['pdo', 'pdo_mysql', 'openssl', 'curl', 'gd', 'mbstring', 'zlib', 'fileinfo', 'json'];
        $extStatus = [];
        foreach ($requiredExts as $ext) {
            $extStatus[$ext] = extension_loaded($ext);
        }

        return [
            'php_version' => PHP_VERSION,
            'mysql_version' => $mysqlVer,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? php_uname('s'),
            'os' => PHP_OS . ' (' . php_uname('m') . ')',
            'memory_limit' => ini_get('memory_limit'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_execution_time' => ini_get('max_execution_time') . 's',
            'server_timezone' => date_default_timezone_get(),
            'current_time' => date('Y-m-d H:i:s T'),
            'extensions' => $extStatus
        ];
    }

    /**
     * Exports full database dump into SQL format.
     */
    public function exportDatabaseDump(): string
    {
        $dbName = 'agap_db';
        try {
            $dbName = $this->conn->query("SELECT DATABASE()")->fetchColumn() ?: 'agap_db';
        } catch (_) {}

        $tables = $this->conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        $dump = "-- =====================================================\n";
        $dump .= "-- AGAP System Full Database Backup\n";
        $dump .= "-- Database: {$dbName}\n";
        $dump .= "-- Generated: " . date('Y-m-d H:i:s') . " (Asia/Manila)\n";
        $dump .= "-- PHP Version: " . PHP_VERSION . "\n";
        $dump .= "-- =====================================================\n\n";
        $dump .= "SET FOREIGN_KEY_CHECKS=0;\n";
        $dump .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $dump .= "SET time_zone = \"+08:00\";\n\n";

        foreach ($tables as $table) {
            // 1. Table structure
            $createStmt = $this->conn->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            $createSql = $createStmt['Create Table'] ?? '';
            $dump .= "-- -----------------------------------------------------\n";
            $dump .= "-- Structure for table `{$table}`\n";
            $dump .= "-- -----------------------------------------------------\n";
            $dump .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $dump .= $createSql . ";\n\n";

            // 2. Table data
            $rows = $this->conn->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > 0) {
                $dump .= "-- Dumping data for table `{$table}` (" . count($rows) . " rows)\n";
                $columns = array_keys($rows[0]);
                $colNames = implode('`, `', $columns);

                // Batch inserts in chunks of 50
                $chunks = array_chunk($rows, 50);
                foreach ($chunks as $chunk) {
                    $valSets = [];
                    foreach ($chunk as $row) {
                        $escapedVals = [];
                        foreach ($columns as $c) {
                            $v = $row[$c];
                            if ($v === null) {
                                $escapedVals[] = 'NULL';
                            } else {
                                $escapedVals[] = $this->conn->quote((string)$v);
                            }
                        }
                        $valSets[] = "(" . implode(', ', $escapedVals) . ")";
                    }
                    $dump .= "INSERT INTO `{$table}` (`{$colNames}`) VALUES\n" . implode(",\n", $valSets) . ";\n";
                }
                $dump .= "\n";
            }
        }

        $dump .= "SET FOREIGN_KEY_CHECKS=1;\n";
        $dump .= "-- Backup completed successfully.\n";

        return $dump;
    }

    /**
     * Retrieves personal user profile and preferences.
     */
    public function getUserProfile(int $userId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT u.user_id, u.first_name, u.middle_name, u.last_name, u.username, u.email, u.contact_no,
                   u.role_id, r.role_name, u.status, u.notification_audio, u.notification_inbox,
                   u.preferred_language, u.avatar_path, u.created_at
            FROM users u
            INNER JOIN roles r ON r.role_id = u.role_id
            WHERE u.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    /**
     * Updates user profile fields and preferences.
     */
    public function updateUserProfile(int $userId, array $data): array
    {
        $fn = trim((string)($data['first_name'] ?? ''));
        $mn = trim((string)($data['middle_name'] ?? '')) ?: null;
        $ln = trim((string)($data['last_name'] ?? ''));
        $email = trim((string)($data['email'] ?? '')) ?: null;
        $contact = trim((string)($data['contact_no'] ?? '')) ?: null;
        $audio = isset($data['notification_audio']) ? ((int)$data['notification_audio'] ? 1 : 0) : 1;
        $inbox = isset($data['notification_inbox']) ? ((int)$data['notification_inbox'] ? 1 : 0) : 1;
        $lang = in_array(trim((string)($data['preferred_language'] ?? '')), ['en', 'fil'], true) ? trim((string)$data['preferred_language']) : 'fil';

        if ($fn === '' || $ln === '') {
            return ['success' => false, 'message' => 'First name and last name are required.'];
        }

        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email address format.'];
        }

        // Check duplicate email
        if ($email !== null) {
            $dup = $this->conn->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND user_id != ?");
            $dup->execute([$email, $userId]);
            if ($dup->fetchColumn() > 0) {
                return ['success' => false, 'message' => 'Email address is already in use by another account.'];
            }
        }

        try {
            $stmt = $this->conn->prepare("
                UPDATE users
                SET first_name = ?, middle_name = ?, last_name = ?, email = ?, contact_no = ?,
                    notification_audio = ?, notification_inbox = ?, preferred_language = ?, updated_at = CURRENT_TIMESTAMP
                WHERE user_id = ?
            ");
            $stmt->execute([$fn, $mn, $ln, $email, $contact, $audio, $inbox, $lang, $userId]);

            // Update session cache if current user
            if (session_status() === PHP_SESSION_ACTIVE && ($_SESSION['user_id'] ?? 0) === $userId) {
                $_SESSION['preferred_language'] = $lang;
            }

            return ['success' => true, 'message' => 'Profile updated successfully.'];
        } catch (Throwable $e) {
            error_log("updateUserProfile error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Updates user password with mandatory current password verification.
     */
    public function updateUserPassword(int $userId, string $currentPassword, string $newPassword): array
    {
        if (strlen($newPassword) < 8) {
            return ['success' => false, 'message' => 'New password must be at least 8 characters long.'];
        }

        $stmt = $this->conn->prepare("SELECT password_hash FROM users WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $currentHash = $stmt->fetchColumn();

        if (!$currentHash || !password_verify($currentPassword, (string)$currentHash)) {
            return ['success' => false, 'message' => 'Current password entered is incorrect.'];
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $upStmt = $this->conn->prepare("UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?");
        $upStmt->execute([$newHash, $userId]);

        return ['success' => true, 'message' => 'Password has been updated successfully.'];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}
