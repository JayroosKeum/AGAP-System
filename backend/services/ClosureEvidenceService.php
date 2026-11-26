<?php

declare(strict_types=1);

/** Saved supporting records owned by the complaint; arbitrary text is never evidence. */
final class ClosureEvidenceService
{
    public function __construct(private PDO $db) {}

    /** @return array<int, array{reference: string, label: string, file_path: ?string}> */
    public function forComplaint(int $complaintId): array
    {
        $query = $this->db->prepare("SELECT complaint_id FROM complaints WHERE complaint_id = ? AND deleted_at IS NULL");
        $query->execute([$complaintId]);
        if (!$query->fetchColumn()) return [];

        $sources = [
            ["SELECT CONCAT('attachment:', attachment_id) AS reference, CONCAT('Attachment: ', file_name) AS label, file_path
              FROM complaint_attachments WHERE complaint_id = ?", true],
            ["SELECT CONCAT('document:', gd.document_id) AS reference, CONCAT(dt.template_name, ' #', gd.document_id) AS label, gd.file_path
              FROM generated_documents gd JOIN document_templates dt ON dt.template_id = gd.template_id
              JOIN cases cs ON cs.case_id = gd.case_id WHERE cs.complaint_id = ? AND cs.deleted_at IS NULL", true],
            ["SELECT CONCAT('hearing-minutes:', hm.minute_id) AS reference, CONCAT('Finalized ', h.hearing_type, ' minutes #', hm.minute_id) AS label, NULL AS file_path
              FROM hearing_minutes hm JOIN hearings h ON h.hearing_id = hm.hearing_id JOIN cases cs ON cs.case_id = hm.case_id
              WHERE cs.complaint_id = ? AND cs.deleted_at IS NULL AND hm.status = 'Finalized' AND hm.finalized_at IS NOT NULL", false],
            ["SELECT CONCAT('paanyaya-minutes:', minutes_id) AS reference, CONCAT(stage_type, ' recorded minutes #', minutes_id) AS label, NULL AS file_path
              FROM paanyaya_minutes WHERE complaint_id = ? AND TRIM(discussion_summary) <> ''", false],
            ["SELECT CONCAT('settlement:', s.settlement_id) AS reference, CONCAT('Signed settlement #', s.settlement_id) AS label, NULL AS file_path
              FROM settlements s JOIN cases cs ON cs.case_id = s.case_id
              WHERE cs.complaint_id = ? AND cs.deleted_at IS NULL AND s.settlement_status = 'Signed and Attested'", false],
            ["SELECT CONCAT('cfa:', cf.cfa_id) AS reference, CONCAT('Issued CFA #', cf.cfa_id) AS label, NULL AS file_path
              FROM cfa_records cf JOIN cases cs ON cs.case_id = cf.case_id WHERE cs.complaint_id = ? AND cs.deleted_at IS NULL", false],
        ];
        $records = [];
        foreach ($sources as [$sql, $requiresFile]) {
            if ($this->db->inTransaction()) $sql .= ' FOR SHARE';
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$complaintId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($requiresFile && !$this->hasStoredFile((string) ($row['file_path'] ?? ''))) continue;
                $records[] = $row;
            }
        }
        return $records;
    }

    public function resolve(int $complaintId, ?string $reference): ?string
    {
        $reference = trim((string) $reference);
        foreach ($this->forComplaint($complaintId) as $record) {
            // Preserve existing callers that pass an exact, saved path belonging to this complaint.
            if ($reference === $record['reference'] || ($reference !== '' && $reference === ($record['file_path'] ?? null))) {
                return $record['reference'];
            }
        }
        return null;
    }

    private function hasStoredFile(string $relativePath): bool
    {
        $storage = realpath(dirname(__DIR__, 2) . '/storage');
        $file = realpath(dirname(__DIR__, 2) . '/' . ltrim($relativePath, '/\\'));
        return $storage !== false && $file !== false && str_starts_with($file, $storage . DIRECTORY_SEPARATOR) && is_file($file);
    }
}
