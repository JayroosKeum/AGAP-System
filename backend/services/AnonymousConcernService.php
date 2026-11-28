<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ComplaintRoutingService.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/IntakeNoticeFormatter.php';

/**
 * Anonymous general concern intake and CSPRNG hashed-token tracking (A5).
 * Intentionally separated from formal named Katarungang Pambarangay complaints:
 * no complainant identity, contact details, user_id, or IP address are stored.
 */
final class AnonymousConcernService
{
    public const MAX_FAILED_ATTEMPTS = 5;
    public const LOCKOUT_MINUTES = 15;

    private PDO $conn;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
    }

    /**
     * Submits an anonymous concern, strips attachment metadata where applicable,
     * and returns the one-time plaintext follow-up token (only its SHA-256 hash is stored).
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $attachment
     */
    public function submit(array $input, ?array $attachment = null): array
    {
        $title = trim((string) ($input['concern_title'] ?? $input['title'] ?? ''));
        $narrative = trim((string) ($input['narrative'] ?? $input['description'] ?? ''));
        $categoryHint = trim((string) ($input['category_hint'] ?? $input['category'] ?? '')) ?: null;
        $barangayArea = trim((string) ($input['barangay_area'] ?? $input['purok'] ?? '')) ?: null;

        if ($title === '' || mb_strlen($title) > 255) {
            return ['success' => false, 'message' => 'Enter a concise concern title (up to 255 characters).'];
        }
        if ($narrative === '' || mb_strlen($narrative) < 10) {
            return ['success' => false, 'message' => 'Describe your concern in at least 10 characters without including personal identity if you wish to remain anonymous.'];
        }

        $savedAttachmentPath = null;
        if ($attachment !== null && isset($attachment['error']) && (int) $attachment['error'] === UPLOAD_ERR_OK) {
            $attachResult = $this->storeSanitizedAttachment($attachment);
            if (!$attachResult['success']) {
                return $attachResult;
            }
            $savedAttachmentPath = $attachResult['path'];
        }

        $routing = ComplaintRoutingService::evaluate([
            'complaint_title' => $title,
            'description' => $narrative,
            'category' => (string) ($categoryHint ?? ''),
            'case_type' => 'Civil',
        ]);

        // Generate a high-entropy 192-bit random secret token; store only its SHA-256 hash.
        $rawToken = 'AGAP-ANON-' . bin2hex(random_bytes(20));
        $tokenHash = hash('sha256', $rawToken);

        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO anonymous_concerns
                 (reference_code, token_hash, category_hint, concern_title, narrative, barangay_area,
                  routing_route, assigned_queue, routing_rule_version, status, public_milestone_note, attachment_path)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'Submitted\', ?, ?)'
            );
            $tempRef = 'ANON-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $stmt->execute([
                $tempRef,
                $tokenHash,
                $categoryHint !== null ? mb_substr($categoryHint, 0, 120) : null,
                mb_substr($title, 0, 255),
                $narrative,
                $barangayArea !== null ? mb_substr($barangayArea, 0, 120) : null,
                $routing['route'],
                $routing['assigned_queue'],
                $routing['rule_version'],
                'Concern received anonymously and queued for barangay review.',
                $savedAttachmentPath,
            ]);

            $concernId = (int) $this->conn->lastInsertId();
            $referenceCode = sprintf('ANON-%s-%06d', date('Y'), $concernId);
            $this->conn->prepare('UPDATE anonymous_concerns SET reference_code = ? WHERE concern_id = ?')
                ->execute([$referenceCode, $concernId]);

            // Log system audit event strictly without storing submitter IP or PII
            (new AuditService($this->conn))->logStrict(
                null,
                'Submitted Anonymous Concern',
                'AnonymousConcerns',
                $concernId,
                [
                    'ip_address' => null,
                    'after' => [
                        'reference_code' => $referenceCode,
                        'routing_route' => $routing['route'],
                        'assigned_queue' => $routing['assigned_queue'],
                        'status' => 'Submitted',
                    ],
                ]
            );

            $this->conn->commit();

            $kpNotice = IntakeNoticeFormatter::formatInsufficientInfoNotice([
                'pagkakakilanlan at beripikadong tirahan ng nagsusumbong',
                'pangalan at tirahan ng inirereklamo sa Barangay Tumana',
            ]);

            return [
                'success' => true,
                'message' => 'Anonymous concern submitted. Save your follow-up token now; it cannot be recovered later.',
                'concern_id' => $concernId,
                'reference_code' => $referenceCode,
                'follow_up_token' => $rawToken,
                'routing_route' => $routing['route'],
                'assigned_queue' => $routing['assigned_queue'],
                'formal_kp_notice' => 'Anonymous submissions are handled as general barangay concerns. Formal Katarungang Pambarangay mediation/summons requires named complainant/respondent intake.',
                'kp_insufficient_info_notice' => $kpNotice,
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            if ($savedAttachmentPath !== null) {
                $abs = dirname(__DIR__, 2) . '/' . $savedAttachmentPath;
                if (is_file($abs)) {
                    @unlink($abs);
                }
            }
            error_log('Anonymous concern submit error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to submit anonymous concern right now.'];
        }
    }

    /**
     * Looks up safe milestone status using reference_code + high-entropy token.
     * Enforces per-record attempt throttling and never discloses PII.
     */
    public function track(string $referenceCode, string $followUpToken): array
    {
        $ref = strtoupper(trim($referenceCode));
        $token = trim($followUpToken);
        if ($ref === '' || $token === '') {
            return ['success' => false, 'http_code' => 422, 'message' => 'Reference code and follow-up token are required.'];
        }

        $stmt = $this->conn->prepare(
            'SELECT concern_id, reference_code, token_hash, routing_route, assigned_queue, status,
                    public_milestone_note, failed_lookup_attempts, locked_until, created_at, updated_at
             FROM anonymous_concerns
             WHERE UPPER(reference_code) = ?
             LIMIT 1'
        );
        $stmt->execute([$ref]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ['success' => false, 'http_code' => 404, 'message' => 'Invalid reference code or follow-up token.'];
        }

        if (!empty($row['locked_until']) && strtotime((string) $row['locked_until']) > time()) {
            return ['success' => false, 'http_code' => 429, 'message' => 'Too many invalid token attempts. Please try again later.'];
        }

        $candidateHash = hash('sha256', $token);
        if (!hash_equals((string) $row['token_hash'], $candidateHash)) {
            $attempts = ((int) $row['failed_lookup_attempts']) + 1;
            $lockedUntil = $attempts >= self::MAX_FAILED_ATTEMPTS
                ? date('Y-m-d H:i:s', time() + (self::LOCKOUT_MINUTES * 60))
                : null;
            $this->conn->prepare(
                'UPDATE anonymous_concerns SET failed_lookup_attempts = ?, locked_until = ? WHERE concern_id = ?'
            )->execute([$attempts, $lockedUntil, (int) $row['concern_id']]);

            return ['success' => false, 'http_code' => $lockedUntil !== null ? 429 : 404, 'message' => 'Invalid reference code or follow-up token.'];
        }

        if ((int) $row['failed_lookup_attempts'] > 0 || $row['locked_until'] !== null) {
            $this->conn->prepare('UPDATE anonymous_concerns SET failed_lookup_attempts = 0, locked_until = NULL WHERE concern_id = ?')
                ->execute([(int) $row['concern_id']]);
        }

        return [
            'success' => true,
            'http_code' => 200,
            'record' => [
                'reference_code' => $row['reference_code'],
                'status' => $row['status'],
                'routing_route' => $row['routing_route'],
                'assigned_queue' => $row['assigned_queue'],
                'milestone_note' => $row['public_milestone_note'] ?: 'Concern is queued for barangay review.',
                'submitted_date' => substr((string) $row['created_at'], 0, 10),
                'last_updated' => (string) $row['updated_at'],
                'formal_referral_guidance' => 'If formal KP mediation or summons is required, visit Barangay Tumana or file through the Resident Portal with party details.',
            ],
        ];
    }

    public function listForStaff(): array
    {
        $stmt = $this->conn->query(
            'SELECT concern_id, reference_code, category_hint, concern_title, narrative, barangay_area,
                    routing_route, assigned_queue, status, public_milestone_note, created_at, updated_at
             FROM anonymous_concerns
             ORDER BY created_at DESC'
        );
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function updateMilestone(int $concernId, string $status, string $milestoneNote, int $actorUserId): array
    {
        $allowed = ['Submitted', 'Under Review', 'Referred to Formal KP', 'Referred Externally', 'Action Taken', 'Closed'];
        if ($concernId <= 0 || !in_array($status, $allowed, true)) {
            return ['success' => false, 'message' => 'Provide a valid concern ID and milestone status.'];
        }
        $cleanNote = trim($milestoneNote);
        if ($cleanNote === '' || mb_strlen($cleanNote) > 500) {
            return ['success' => false, 'message' => 'Provide a safe public milestone note (1–500 characters, without PII).'];
        }
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $cleanNote) || preg_match('/(?:\+?63|0)9\d{2}[\s-]?\d{3}[\s-]?\d{4}/', $cleanNote)) {
            return ['success' => false, 'message' => 'Public milestone notes must not contain email addresses or phone numbers.'];
        }

        $this->conn->beginTransaction();
        try {
            $beforeStmt = $this->conn->prepare('SELECT concern_id, status, public_milestone_note FROM anonymous_concerns WHERE concern_id = ? FOR UPDATE');
            $beforeStmt->execute([$concernId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Anonymous concern not found.'];
            }

            $this->conn->prepare('UPDATE anonymous_concerns SET status = ?, public_milestone_note = ? WHERE concern_id = ?')
                ->execute([$status, $cleanNote, $concernId]);

            (new AuditService($this->conn))->logStrict(
                $actorUserId,
                'Updated Anonymous Concern Milestone',
                'AnonymousConcerns',
                $concernId,
                [
                    'before' => $before,
                    'after' => ['status' => $status, 'public_milestone_note' => $cleanNote],
                ]
            );

            $this->conn->commit();
            return ['success' => true, 'message' => 'Anonymous concern milestone updated.'];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Anonymous concern milestone update error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to update milestone.'];
        }
    }

    /**
     * Stores an attachment under storage/uploads/anonymous-concerns with a randomized name
     * and strips EXIF/identifying image metadata via GD re-encoding when applicable.
     *
     * @param array<string, mixed> $file
     * @return array{success: bool, path?: string, message?: string}
     */
    private function storeSanitizedAttachment(array $file): array
    {
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmpPath === '' || !is_uploaded_file($tmpPath) && !is_file($tmpPath)) {
            return ['success' => false, 'message' => 'Invalid uploaded file.'];
        }
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            return ['success' => false, 'message' => 'Attachment must be 5 MB or smaller.'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmpPath);
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];
        if (!isset($allowed[$mime])) {
            return ['success' => false, 'message' => 'Only JPEG or PNG images are accepted for anonymous uploads so EXIF metadata can be stripped automatically.'];
        }

        $ext = $allowed[$mime];
        $relDir = 'storage/uploads/anonymous-concerns/' . date('Y');
        $absDir = dirname(__DIR__, 2) . '/' . $relDir;
        if (!is_dir($absDir) && !mkdir($absDir, 0750, true) && !is_dir($absDir)) {
            return ['success' => false, 'message' => 'Anonymous upload storage is unavailable.'];
        }

        $randomName = 'anon-' . bin2hex(random_bytes(12)) . '.' . $ext;
        $relPath = $relDir . '/' . $randomName;
        $absPath = $absDir . '/' . $randomName;

        // Re-encode JPEG/PNG through GD to strip EXIF GPS/camera/author metadata; fail closed if GD is unavailable.
        if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg') && function_exists('imagejpeg')) {
            $img = @imagecreatefromjpeg($tmpPath);
            if ($img !== false) {
                $ok = imagejpeg($img, $absPath, 88);
                imagedestroy($img);
                if ($ok) {
                    return ['success' => true, 'path' => $relPath];
                }
            }
        } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng') && function_exists('imagepng')) {
            $img = @imagecreatefrompng($tmpPath);
            if ($img !== false) {
                imagesavealpha($img, true);
                $ok = imagepng($img, $absPath, 6);
                imagedestroy($img);
                if ($ok) {
                    return ['success' => true, 'path' => $relPath];
                }
            }
        }

        return ['success' => false, 'message' => 'Unable to sanitize and re-encode uploaded image metadata.'];
    }
}
