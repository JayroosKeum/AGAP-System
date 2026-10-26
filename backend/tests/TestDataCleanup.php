<?php

declare(strict_types=1);

/**
 * Removes only complaint records created by a CLI test, even when the test
 * aborts early. This prevents failed PDF/database assertions from polluting
 * a developer's local data set.
 */
final class TestDataCleanup
{
    /** @var int[] */
    private array $complaintIds = [];

    public function __construct(private PDO $db)
    {
        register_shutdown_function([$this, 'cleanup']);
    }

    public function trackComplaint(int $complaintId): void
    {
        if ($complaintId > 0 && !in_array($complaintId, $this->complaintIds, true)) {
            $this->complaintIds[] = $complaintId;
        }
    }

    public function cleanup(): void
    {
        foreach ($this->complaintIds as $complaintId) {
            try {
                $files = [];
                $paanyayaFiles = $this->db->prepare('SELECT pdf_path FROM paanyaya_sessions WHERE complaint_id = ? AND pdf_path IS NOT NULL');
                $paanyayaFiles->execute([$complaintId]);
                $files = array_merge($files, $paanyayaFiles->fetchAll(PDO::FETCH_COLUMN));

                $caseStmt = $this->db->prepare('SELECT case_id FROM cases WHERE complaint_id = ?');
                $caseStmt->execute([$complaintId]);
                $caseIds = array_map('intval', $caseStmt->fetchAll(PDO::FETCH_COLUMN));

                foreach ($caseIds as $caseId) {
                    $docFiles = $this->db->prepare('SELECT file_path FROM generated_documents WHERE case_id = ? AND file_path IS NOT NULL');
                    $docFiles->execute([$caseId]);
                    $files = array_merge($files, $docFiles->fetchAll(PDO::FETCH_COLUMN));
                    // Delete child rows explicitly where older local schemas do
                    // not have cascading foreign keys.
                    foreach (['summon_deliveries', 'generated_documents', 'case_history', 'case_deadlines', 'case_assignments', 'case_stages', 'stage_transitions'] as $table) {
                        try {
                            $this->db->prepare("DELETE FROM {$table} WHERE case_id = ?")->execute([$caseId]);
                        } catch (Throwable) {
                            // Optional tables vary between local schema versions.
                        }
                    }
                    $this->db->prepare('DELETE FROM hearings WHERE case_id = ?')->execute([$caseId]);
                    $this->db->prepare('DELETE FROM cases WHERE case_id = ?')->execute([$caseId]);
                }

                foreach ($files as $file) {
                    $this->deleteManagedFile((string) $file);
                }

                foreach (['paanyaya_minutes', 'paanyaya_sessions', 'complaint_paanyaya', 'complaint_parties'] as $table) {
                    try {
                        $this->db->prepare("DELETE FROM {$table} WHERE complaint_id = ?")->execute([$complaintId]);
                    } catch (Throwable) {
                        // Optional tables vary between local schema versions.
                    }
                }
                $this->db->prepare('DELETE FROM complaints WHERE complaint_id = ?')->execute([$complaintId]);
            } catch (Throwable $e) {
                fwrite(STDERR, "Test cleanup warning for complaint {$complaintId}: {$e->getMessage()}\n");
            }
        }
    }

    private function deleteManagedFile(string $relativePath): void
    {
        $root = realpath(__DIR__ . '/../..');
        if ($root === false || $relativePath === '') {
            return;
        }
        $candidate = realpath($root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relativePath, '/\\')));
        if ($candidate !== false && str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) && is_file($candidate)) {
            @unlink($candidate);
        }
    }
}
