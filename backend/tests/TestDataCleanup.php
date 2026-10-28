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

                    // Unlink FKs from hearings to generated_documents
                    try {
                        $this->db->prepare('UPDATE hearings SET kp12_document_id = NULL WHERE case_id = ?')->execute([$caseId]);
                    } catch (Throwable) {}

                    // Delete hearing children
                    $hStmt = $this->db->prepare('SELECT hearing_id FROM hearings WHERE case_id = ?');
                    $hStmt->execute([$caseId]);
                    $hIds = array_map('intval', $hStmt->fetchAll(PDO::FETCH_COLUMN));
                    foreach ($hIds as $hId) {
                        foreach (['hearing_party_services', 'hearing_attendance', 'hearing_minutes_documents', 'hearing_minutes', 'hearing_explanations', 'hearing_legal_actions', 'hearing_nonappearances', 'show_cause_evaluations'] as $hTable) {
                            try {
                                $this->db->prepare("DELETE FROM {$hTable} WHERE hearing_id = ?")->execute([$hId]);
                            } catch (Throwable) {}
                        }
                    }

                    // Delete case-level children
                    foreach (['summon_deliveries', 'proof_of_service', 'arbitration_records', 'settlement_executions', 'settlement_installments', 'settlements', 'cfa_records', 'case_transmittals'] as $cTable) {
                        try {
                            $this->db->prepare("DELETE FROM {$cTable} WHERE case_id = ?")->execute([$caseId]);
                        } catch (Throwable) {}
                    }

                    // Pangkat members and groups
                    try {
                        $pgStmt = $this->db->prepare('SELECT pangkat_id FROM pangkat_groups WHERE case_id = ?');
                        $pgStmt->execute([$caseId]);
                        $pgIds = array_map('intval', $pgStmt->fetchAll(PDO::FETCH_COLUMN));
                        foreach ($pgIds as $pgId) {
                            $this->db->prepare('DELETE FROM pangkat_members WHERE pangkat_id = ?')->execute([$pgId]);
                            $this->db->prepare('DELETE FROM pangkat_challenges WHERE pangkat_id = ?')->execute([$pgId]);
                            $this->db->prepare('DELETE FROM pangkat_extensions WHERE pangkat_id = ?')->execute([$pgId]);
                        }
                        $this->db->prepare('DELETE FROM pangkat_groups WHERE case_id = ?')->execute([$caseId]);
                    } catch (Throwable) {}

                    foreach (['generated_documents', 'case_history', 'case_deadlines', 'case_assignments', 'case_stages', 'stage_transitions'] as $table) {
                        try {
                            $this->db->prepare("DELETE FROM {$table} WHERE case_id = ?")->execute([$caseId]);
                        } catch (Throwable) {}
                    }

                    $this->db->prepare('DELETE FROM hearings WHERE case_id = ?')->execute([$caseId]);
                    $this->db->prepare('DELETE FROM cases WHERE case_id = ?')->execute([$caseId]);
                }

                foreach ($files as $file) {
                    $this->deleteManagedFile((string) $file);
                }

                foreach (['paanyaya_minutes', 'paanyaya_sessions', 'paanyaya_officer_returns', 'complaint_paanyaya', 'complaint_parties', 'incident_locations', 'complaint_attachments'] as $table) {
                    try {
                        $this->db->prepare("DELETE FROM {$table} WHERE complaint_id = ?")->execute([$complaintId]);
                    } catch (Throwable) {}
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
