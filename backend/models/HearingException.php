<?php

require_once __DIR__ . '/../config/database.php';

class HearingException
{
    private PDO $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    public function recordUnjustifiedNonAppearance(int $hearingId, int $residentId, string $remarks, int $userId): array
    {
        $party = $this->conn->prepare(
            "SELECT h.case_id, c.case_status, cp.party_type
             FROM hearings h
             INNER JOIN cases c ON c.case_id = h.case_id
             INNER JOIN complaint_parties cp ON cp.complaint_id = c.complaint_id
             WHERE h.hearing_id = ? AND cp.resident_id = ? AND cp.party_type IN ('Complainant', 'Respondent')"
        );
        $party->execute([$hearingId, $residentId]);
        $record = $party->fetch(PDO::FETCH_ASSOC);
        if (!$record) return ['success' => false, 'message' => 'Choose a complainant or respondent assigned to this hearing case.'];

        try {
            $this->conn->beginTransaction();
            $attendance = $this->conn->prepare(
                "INSERT INTO hearing_attendance (hearing_id, resident_id, attendance_status, remarks)
                 VALUES (?, ?, 'Absent', ?)
                 ON DUPLICATE KEY UPDATE attendance_status = 'Absent', remarks = VALUES(remarks), recorded_at = CURRENT_TIMESTAMP"
            );
            $attendance->execute([$hearingId, $residentId, $remarks ?: 'Unjustified non-appearance.']);
            $entry = $this->conn->prepare(
                "INSERT INTO hearing_nonappearances (hearing_id, resident_id, party_type, remarks, recorded_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE remarks = VALUES(remarks), resolution = 'Pending', recorded_by = VALUES(recorded_by), recorded_at = CURRENT_TIMESTAMP, resolved_by = NULL, resolved_at = NULL"
            );
            $entry->execute([$hearingId, $residentId, $record['party_type'], $remarks ?: null, $userId]);
            $history = $this->conn->prepare('INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, ?, ?, ?)');
            $history->execute([$record['case_id'], $record['case_status'], ucfirst(strtolower($record['party_type'])) . ' marked with unjustified non-appearance for hearing #' . $hearingId . '.', $userId]);
            $this->conn->commit();
            return ['success' => true, 'case_id' => (int) $record['case_id'], 'message' => 'Unjustified non-appearance recorded.'];
        } catch (Throwable $exception) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $exception;
        }
    }

    public function pendingForHearing(int $hearingId): ?array
    {
        $stmt = $this->conn->prepare("SELECT hn.*, TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS resident_name FROM hearing_nonappearances hn INNER JOIN residents r ON r.resident_id = hn.resident_id WHERE hn.hearing_id = ? AND hn.resolution = 'Pending' ORDER BY hn.nonappearance_id DESC LIMIT 1");
        $stmt->execute([$hearingId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function partiesForHearing(int $hearingId): array
    {
        $stmt = $this->conn->prepare("SELECT cp.resident_id, cp.party_type, TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS resident_name FROM hearings h INNER JOIN cases c ON c.case_id = h.case_id INNER JOIN complaint_parties cp ON cp.complaint_id = c.complaint_id INNER JOIN residents r ON r.resident_id = cp.resident_id WHERE h.hearing_id = ? AND cp.party_type IN ('Complainant', 'Respondent') ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent'), r.last_name, r.first_name");
        $stmt->execute([$hearingId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function resolveForHearing(int $hearingId, string $resolution, int $userId): void
    {
        $stmt = $this->conn->prepare("UPDATE hearing_nonappearances SET resolution = ?, resolved_by = ?, resolved_at = NOW() WHERE hearing_id = ? AND resolution = 'Pending'");
        $stmt->execute([$resolution, $userId, $hearingId]);
    }
}
