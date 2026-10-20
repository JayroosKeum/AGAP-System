<?php

require_once __DIR__ . '/../config/database.php';

class ProofOfService
{
    private PDO $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    public function caseExists(int $caseId): bool
    {
        $stmt = $this->conn->prepare("SELECT 1 FROM cases WHERE case_id = ? AND case_status <> 'Archived'");
        $stmt->execute([$caseId]);
        return (bool) $stmt->fetchColumn();
    }

    public function create(int $caseId, int $documentId, int $servedBy, string $servedDate, ?string $remarks, ?string $imagePath, string $serviceResult = 'Served'): array
    {
        if (!$this->caseExists($caseId)) {
            return ['success' => false, 'message' => 'Active case not found.'];
        }
        $allowedResults = ['Served', 'Not Served', 'Refused', 'Respondent Not Found', 'Address Problem', 'Other'];
        if (!in_array($serviceResult, $allowedResults, true)) {
            $serviceResult = 'Served';
        }
        $document = $this->conn->prepare('SELECT document_id, template_id FROM generated_documents WHERE document_id = ? AND case_id = ?');
        $document->execute([$documentId, $caseId]);
        if (!$document->fetch()) return ['success' => false, 'message' => 'Select a generated document for this case.'];
        $duplicate = $this->conn->prepare('SELECT proof_id FROM proof_of_service WHERE document_id = ? AND served_by = ? AND served_date = ? LIMIT 1');
        $duplicate->execute([$documentId, $servedBy, $servedDate]);
        if ($duplicate->fetchColumn()) {
            return ['success' => false, 'message' => 'This service entry has already been recorded.'];
        }
        $stmt = $this->conn->prepare('INSERT INTO proof_of_service (case_id, document_id, service_result, served_by, served_date, remarks, image_path) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$caseId, $documentId, $serviceResult, $servedBy, $servedDate, $remarks, $imagePath]);
        $proofId = (int) $this->conn->lastInsertId();

        // Update document service status: 'Served' if Served, else 'Service Failed'
        $newDocStatus = ($serviceResult === 'Served') ? 'Served' : 'Service Failed';
        $updateDoc = $this->conn->prepare('UPDATE generated_documents SET service_status = ? WHERE document_id = ? AND case_id = ?');
        $updateDoc->execute([$newDocStatus, $documentId, $caseId]);

        // Keep complete case history of every service attempt
        $historyStmt = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) SELECT case_id, case_status, ?, ? FROM cases WHERE case_id = ?");
        $historyNote = sprintf('Summons service attempt: %s.%s', $serviceResult, $remarks ? ' Remarks: ' . $remarks : '');
        $historyStmt->execute([$historyNote, $servedBy, $caseId]);

        // Statutory mediation timer pause/resume for summon service status
        $caseStmt = $this->conn->prepare('SELECT case_id, case_status, is_paused, pause_reason, mediation_start_date FROM cases WHERE case_id = ?');
        $caseStmt->execute([$caseId]);
        $targetCase = $caseStmt->fetch(PDO::FETCH_ASSOC);

        if ($targetCase && $targetCase['case_status'] === 'Mediation') {
            require_once __DIR__ . '/../services/MediationDeadlineService.php';
            $deadlineService = new MediationDeadlineService($this->conn);

            if ($newDocStatus === 'Service Failed' && empty($targetCase['is_paused'])) {
                $deadlineService->pauseMediation(
                    $caseId,
                    'Summon Unserved',
                    sprintf('Summons marked Service Failed (%s).%s', $serviceResult, $remarks ? ' ' . $remarks : ''),
                    $servedBy
                );
            } elseif ($newDocStatus === 'Served' && !empty($targetCase['is_paused']) && $targetCase['pause_reason'] === 'Summon Unserved') {
                $deadlineService->resumeMediation($caseId, $servedBy);
            }
        }

        return ['success' => true, 'proof_id' => $proofId, 'service_result' => $serviceResult, 'document_status' => $newDocStatus];
    }

    public function getByCase(int $caseId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT p.*, CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name) AS served_by_name, dt.template_name
             FROM proof_of_service p LEFT JOIN users u ON u.user_id = p.served_by
             LEFT JOIN generated_documents gd ON gd.document_id = p.document_id
             LEFT JOIN document_templates dt ON dt.template_id = gd.template_id
             WHERE p.case_id = ? ORDER BY p.served_date DESC, p.proof_id DESC"
        );
        $stmt->execute([$caseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $proofId): array|false
    {
        $stmt = $this->conn->prepare('SELECT proof_id, image_path FROM proof_of_service WHERE proof_id = ?');
        $stmt->execute([$proofId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAvailableCases(): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                c.case_id,
                c.case_number,
                c.case_status,
                co.complaint_id,
                co.complaint_number,
                co.complaint_title,
                h.hearing_id,
                h.hearing_date,
                h.hearing_type,
                h.status AS hearing_status,
                (
                    SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ')
                    FROM complaint_parties cp
                    INNER JOIN residents r ON r.resident_id = cp.resident_id
                    WHERE cp.complaint_id = c.complaint_id AND cp.party_type = 'Complainant'
                ) AS complainant_names,
                (
                    SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ')
                    FROM complaint_parties cp
                    INNER JOIN residents r ON r.resident_id = cp.resident_id
                    WHERE cp.complaint_id = c.complaint_id AND cp.party_type = 'Respondent'
                ) AS respondent_names,
                COALESCE(
                    (
                        SELECT CASE
                            WHEN sd.delivery_status IN ('Served', 'Served Personal', 'Served Substituted', 'Served Refused') THEN 'Served'
                            WHEN (SELECT h_sub.hearing_type FROM hearings h_sub WHERE h_sub.case_id = c.case_id ORDER BY h_sub.hearing_date ASC, h_sub.hearing_id ASC LIMIT 1) = 'Mediation' THEN 'Served'
                            ELSE sd.delivery_status
                        END
                        FROM summon_deliveries sd
                        WHERE sd.case_id = c.case_id AND sd.party_type = 'Complainant'
                        ORDER BY sd.delivery_id DESC LIMIT 1
                    ),
                    'Served'
                ) AS complainant_service_status,
                (
                    SELECT sd.delivery_status
                    FROM summon_deliveries sd
                    WHERE sd.case_id = c.case_id AND sd.party_type = 'Respondent'
                    ORDER BY sd.attempt_number DESC, sd.delivery_id DESC LIMIT 1
                ) AS respondent_service_status,
                (
                    SELECT COUNT(*)
                    FROM summon_deliveries sd
                    WHERE sd.case_id = c.case_id AND sd.party_type = 'Respondent' AND sd.delivery_status = 'Unserved'
                ) AS respondent_failed_attempts,
                (
                    SELECT COUNT(*)
                    FROM summon_deliveries sd
                    WHERE sd.case_id = c.case_id AND sd.party_type = 'Respondent' AND sd.delivery_status IN ('Served', 'Served Personal', 'Served Substituted', 'Served Refused')
                ) AS respondent_served_attempts,
                (
                    SELECT COUNT(*)
                    FROM summon_deliveries sd
                    WHERE sd.case_id = c.case_id AND sd.party_type = 'Respondent'
                ) AS respondent_total_attempts,
                (
                    SELECT MAX(sd.served_at)
                    FROM summon_deliveries sd
                    WHERE sd.case_id = c.case_id AND DATE(sd.served_at) = CURRENT_DATE()
                ) AS served_today_date
            FROM cases c
            INNER JOIN complaints co ON co.complaint_id = c.complaint_id
            LEFT JOIN (
                SELECT h1.*
                FROM hearings h1
                INNER JOIN (
                    SELECT case_id, MIN(hearing_date) AS min_date
                    FROM hearings
                    WHERE status = 'Scheduled'
                    GROUP BY case_id
                ) h2 ON h1.case_id = h2.case_id AND h1.hearing_date = h2.min_date
            ) h ON h.case_id = c.case_id
            WHERE c.case_status NOT IN ('Settled', 'Dismissed', 'Archived', 'DISMISSED_BARRED')
            ORDER BY 
                CASE WHEN h.hearing_date IS NOT NULL THEN 0 ELSE 1 END,
                h.hearing_date ASC,
                c.case_id DESC
        ");
                $stmt->execute();
        $formalCases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Also query pending delivery for Paanyaya preliminary invitation stages
        $paaStmt = $this->conn->prepare("
            SELECT 
                COALESCE(c.case_id, co.complaint_id) AS case_id,
                COALESCE(c.case_number, co.complaint_number) AS case_number,
                COALESCE(c.case_status, co.status) AS case_status,
                co.complaint_id,
                co.complaint_number,
                co.complaint_title,
                ps.id AS hearing_id,
                CASE 
                    WHEN ps.invitation_date IS NOT NULL AND ps.invitation_time IS NOT NULL 
                    THEN CONCAT(ps.invitation_date, ' ', ps.invitation_time)
                    WHEN ps.invitation_date IS NOT NULL 
                    THEN CONCAT(ps.invitation_date, ' 14:00:00')
                    ELSE co.created_at
                END AS hearing_date,
                ps.stage_type AS hearing_type,
                ps.status AS hearing_status,
                (
                    SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ')
                    FROM complaint_parties cp
                    INNER JOIN residents r ON r.resident_id = cp.resident_id
                    WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Complainant'
                ) AS complainant_names,
                (
                    SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ')
                    FROM complaint_parties cp
                    INNER JOIN residents r ON r.resident_id = cp.resident_id
                    WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Respondent'
                ) AS respondent_names,
                'Served' AS complainant_service_status,
                COALESCE(
                    (
                        SELECT CASE 
                            WHEN por.delivery_outcome = 'Successfully delivered' THEN 'Served'
                            ELSE CONCAT('Attempt ', por.attempt_number, ' (Unserved)')
                        END
                        FROM paanyaya_officer_returns por
                        WHERE por.paanyaya_stage_id = ps.id
                        ORDER BY por.attempt_number DESC, por.return_id DESC LIMIT 1
                    ),
                    'Pending'
                ) AS respondent_service_status,
                (
                    SELECT COUNT(*)
                    FROM paanyaya_officer_returns por
                    WHERE por.paanyaya_stage_id = ps.id AND por.delivery_outcome != 'Successfully delivered'
                ) AS respondent_failed_attempts,
                (
                    SELECT COUNT(*)
                    FROM paanyaya_officer_returns por
                    WHERE por.paanyaya_stage_id = ps.id AND por.delivery_outcome = 'Successfully delivered'
                ) AS respondent_served_attempts,
                (
                    SELECT COUNT(*)
                    FROM paanyaya_officer_returns por
                    WHERE por.paanyaya_stage_id = ps.id
                ) AS respondent_total_attempts,
                (
                    SELECT MAX(por.service_datetime)
                    FROM paanyaya_officer_returns por
                    WHERE por.paanyaya_stage_id = ps.id AND DATE(por.service_datetime) = CURRENT_DATE()
                ) AS served_today_date,
                1 AS is_paanyaya,
                ps.stage_type AS paanyaya_stage_type,
                ps.status AS paanyaya_stage_status
            FROM paanyaya_stages ps
            INNER JOIN complaints co ON co.complaint_id = ps.complaint_id
            LEFT JOIN cases c ON c.complaint_id = co.complaint_id
            WHERE ps.status IN ('In Progress', 'Attempts Exhausted – For Review', 'Not Started', 'Successfully Delivered')
              AND NOT EXISTS (
                  SELECT 1 FROM hearings h WHERE h.case_id = c.case_id AND h.status = 'Scheduled'
              )
              AND (
                  (ps.stage_type = 'Paanyaya 2' AND ps.status != 'Not Started')
                  OR (ps.stage_type = 'Paanyaya 1' AND NOT EXISTS (
                      SELECT 1 FROM paanyaya_stages ps2 
                      WHERE ps2.complaint_id = ps.complaint_id 
                        AND ps2.stage_type = 'Paanyaya 2' 
                        AND ps2.status != 'Not Started'
                  ))
              )
            ORDER BY ps.updated_at DESC
        ");
        $paaStmt->execute();
        $paaCases = $paaStmt->fetchAll(PDO::FETCH_ASSOC);

        // Combined list of active cases for Proof of Service
        return array_merge($paaCases, $formalCases);
    }

    public function getCaseSummary(int $caseId): array|false
    {
        $stmt = $this->conn->prepare(
            "SELECT c.case_id, c.case_number, c.case_status, c.docket_date,
                    co.complaint_id, co.complaint_number, co.complaint_title, co.status AS complaint_status
             FROM cases c
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             WHERE c.case_id = ?"
        );
        $stmt->execute([$caseId]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$case) {
            return false;
        }

        $partiesStmt = $this->conn->prepare(
            "SELECT cp.party_type, TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name
             FROM complaint_parties cp
             INNER JOIN residents r ON r.resident_id = cp.resident_id
             WHERE cp.complaint_id = ?
             ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent', 'Witness'), r.last_name, r.first_name"
        );
        $partiesStmt->execute([(int) $case['complaint_id']]);
        $parties = $partiesStmt->fetchAll(PDO::FETCH_ASSOC);

        $complainants = [];
        $respondents = [];
        foreach ($parties as $p) {
            if ($p['party_type'] === 'Complainant') {
                $complainants[] = $p['full_name'];
            } elseif ($p['party_type'] === 'Respondent') {
                $respondents[] = $p['full_name'];
            }
        }
        $case['complainants'] = $complainants;
        $case['respondents'] = $respondents;

        return $case;
    }

    public function getSummonsServers(): array
    {
        $stmt = $this->conn->prepare(
            "SELECT u.user_id, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS full_name, r.role_name
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.status = 'Active' AND r.role_name IN ('Summons Server', 'Lupon Clerk', 'Administrator')
             ORDER BY FIELD(r.role_name, 'Summons Server', 'Lupon Clerk', 'Administrator'), u.last_name, u.first_name"
        );
                $stmt->execute();
        $formalCases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Also query pending delivery for Paanyaya preliminary invitation stages
        $paaStmt = $this->conn->prepare("
            SELECT 
                COALESCE(c.case_id, co.complaint_id) AS case_id,
                COALESCE(c.case_number, co.complaint_number) AS case_number,
                COALESCE(c.case_status, co.status) AS case_status,
                co.complaint_id,
                co.complaint_number,
                co.complaint_title,
                ps.id AS hearing_id,
                CASE 
                    WHEN ps.invitation_date IS NOT NULL AND ps.invitation_time IS NOT NULL 
                    THEN CONCAT(ps.invitation_date, ' ', ps.invitation_time)
                    WHEN ps.invitation_date IS NOT NULL 
                    THEN CONCAT(ps.invitation_date, ' 14:00:00')
                    ELSE co.created_at
                END AS hearing_date,
                ps.stage_type AS hearing_type,
                ps.status AS hearing_status,
                (
                    SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ')
                    FROM complaint_parties cp
                    INNER JOIN residents r ON r.resident_id = cp.resident_id
                    WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Complainant'
                ) AS complainant_names,
                (
                    SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ')
                    FROM complaint_parties cp
                    INNER JOIN residents r ON r.resident_id = cp.resident_id
                    WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Respondent'
                ) AS respondent_names,
                'Served' AS complainant_service_status,
                COALESCE(
                    (
                        SELECT CASE 
                            WHEN por.delivery_outcome = 'Successfully delivered' THEN 'Served'
                            ELSE CONCAT('Attempt ', por.attempt_number, ' (Unserved)')
                        END
                        FROM paanyaya_officer_returns por
                        WHERE por.paanyaya_stage_id = ps.id
                        ORDER BY por.attempt_number DESC, por.return_id DESC LIMIT 1
                    ),
                    'Pending'
                ) AS respondent_service_status,
                (
                    SELECT COUNT(*)
                    FROM paanyaya_officer_returns por
                    WHERE por.paanyaya_stage_id = ps.id AND por.delivery_outcome != 'Successfully delivered'
                ) AS respondent_failed_attempts,
                (
                    SELECT COUNT(*)
                    FROM paanyaya_officer_returns por
                    WHERE por.paanyaya_stage_id = ps.id AND por.delivery_outcome = 'Successfully delivered'
                ) AS respondent_served_attempts,
                (
                    SELECT COUNT(*)
                    FROM paanyaya_officer_returns por
                    WHERE por.paanyaya_stage_id = ps.id
                ) AS respondent_total_attempts,
                (
                    SELECT MAX(por.service_datetime)
                    FROM paanyaya_officer_returns por
                    WHERE por.paanyaya_stage_id = ps.id AND DATE(por.service_datetime) = CURRENT_DATE()
                ) AS served_today_date,
                1 AS is_paanyaya,
                ps.stage_type AS paanyaya_stage_type,
                ps.status AS paanyaya_stage_status
            FROM paanyaya_stages ps
            INNER JOIN complaints co ON co.complaint_id = ps.complaint_id
            LEFT JOIN cases c ON c.complaint_id = co.complaint_id
            WHERE ps.status IN ('In Progress', 'Attempts Exhausted – For Review', 'Not Started', 'Successfully Delivered')
              AND NOT EXISTS (
                  SELECT 1 FROM hearings h WHERE h.case_id = c.case_id AND h.status = 'Scheduled'
              )
              AND (
                  (ps.stage_type = 'Paanyaya 2' AND ps.status != 'Not Started')
                  OR (ps.stage_type = 'Paanyaya 1' AND NOT EXISTS (
                      SELECT 1 FROM paanyaya_stages ps2 
                      WHERE ps2.complaint_id = ps.complaint_id 
                        AND ps2.stage_type = 'Paanyaya 2' 
                        AND ps2.status != 'Not Started'
                  ))
              )
            ORDER BY ps.updated_at DESC
        ");
        $paaStmt->execute();
        $paaCases = $paaStmt->fetchAll(PDO::FETCH_ASSOC);

        // Combined list of active cases for Proof of Service
        return array_merge($paaCases, $formalCases);
    }

    public function getDefaultLuponClerk(): array|false
    {
        $stmt = $this->conn->prepare("SELECT u.user_id, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS full_name FROM users u INNER JOIN roles r ON r.role_id = u.role_id WHERE u.status = 'Active' AND r.role_name = 'Lupon Clerk' ORDER BY u.user_id ASC LIMIT 1");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getSummonsNotices(int $caseId): array
    {
        $stmt = $this->conn->prepare("SELECT gd.document_id, gd.case_id, gd.file_path, gd.generated_at, gd.service_status, dt.template_name, COUNT(p.proof_id) AS attempt_count, MAX(p.served_date) AS last_attempt_at FROM generated_documents gd INNER JOIN document_templates dt ON dt.template_id = gd.template_id LEFT JOIN proof_of_service p ON p.document_id = gd.document_id WHERE gd.case_id = ? AND (dt.template_name = 'KP Form 9' OR dt.template_name LIKE '%Summon%') GROUP BY gd.document_id, gd.case_id, gd.file_path, gd.generated_at, gd.service_status, dt.template_name ORDER BY gd.generated_at DESC, gd.document_id DESC");
        $stmt->execute([$caseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
