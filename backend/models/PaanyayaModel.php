<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Paanyaya.php';

class PaanyayaModel
{
    private PDO $conn;
    private Paanyaya $legacyModel;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
        $this->legacyModel = new Paanyaya($this->conn);
    }

    /**
     * Fetch complete workspace and preview data for a complaint's Paanyaya.
     */
    public function getPaanyayaData(int $complaintId): ?array
    {
        $stmt = $this->conn->prepare('
            SELECT c.*,
                   cat.category_name,
                   (SELECT COUNT(*) FROM cases WHERE complaint_id = c.complaint_id) AS has_case,
                   (SELECT case_id FROM cases WHERE complaint_id = c.complaint_id LIMIT 1) AS case_id,
                   (SELECT case_number FROM cases WHERE complaint_id = c.complaint_id LIMIT 1) AS case_number,
                   (SELECT case_status FROM cases WHERE complaint_id = c.complaint_id LIMIT 1) AS case_status
            FROM complaints c
            LEFT JOIN complaint_categories cat ON c.category_id = cat.category_id
            WHERE c.complaint_id = ?
            LIMIT 1
        ');
        $stmt->execute([$complaintId]);
        $complaint = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$complaint) {
            return null;
        }

        // Fetch Parties with Resident info
        $partyStmt = $this->conn->prepare("
            SELECT cp.party_id, cp.resident_id, cp.party_type,
                   TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, r.suffix)) AS full_name,
                   r.contact_no AS contact_number, r.address, r.email
            FROM complaint_parties cp
            LEFT JOIN residents r ON r.resident_id = cp.resident_id
            WHERE cp.complaint_id = ?
            ORDER BY cp.party_id ASC
        ");
        $partyStmt->execute([$complaintId]);
        $parties = $partyStmt->fetchAll(PDO::FETCH_ASSOC);

        $complainants = [];
        $respondents = [];
        foreach ($parties as $p) {
            if (strcasecmp($p['party_type'], 'Complainant') === 0) {
                $complainants[] = $p;
            } else {
                $respondents[] = $p;
            }
        }

        // Fetch stages
        $stageStmt = $this->conn->prepare('
            SELECT * FROM paanyaya_stages
            WHERE complaint_id = ?
            ORDER BY id ASC
        ');
        $stageStmt->execute([$complaintId]);
        $stages = $stageStmt->fetchAll(PDO::FETCH_ASSOC);

        $stage1 = null;
        $stage2 = null;
        foreach ($stages as $s) {
            if ($s['stage_type'] === 'Paanyaya 1') {
                $stage1 = $s;
            } elseif ($s['stage_type'] === 'Paanyaya 2') {
                $stage2 = $s;
            }
        }

        // Fetch sessions
        $sessStmt = $this->conn->prepare('
            SELECT * FROM paanyaya_sessions
            WHERE complaint_id = ?
            ORDER BY attempt_number ASC, session_id ASC
        ');
        $sessStmt->execute([$complaintId]);
        $sessions = $sessStmt->fetchAll(PDO::FETCH_ASSOC);

        $latestSession = !empty($sessions) ? end($sessions) : null;

        // Current active stage type
        $currentStage = 'Paanyaya 1';
        if ($stage2 && in_array($stage2['status'], ['In Progress', 'Completed', 'Cancelled'], true)) {
            $currentStage = 'Paanyaya 2';
        } elseif ($stage1 && in_array($stage1['status'], ['Completed'], true)) {
            $currentStage = 'Paanyaya 2';
        }

        $complainantNames = array_column($complainants, 'full_name');
        $respondentNames = array_column($respondents, 'full_name');
        $complainantNameStr = !empty($complainantNames) ? implode(', ', $complainantNames) : 'NAGPAANYAYA';
        $respondentNameStr = !empty($respondentNames) ? implode(', ', $respondentNames) : 'INANYAYAHAN';

        // Extract dates/times from latest session or stage or defaults
        $activeStageData = ($currentStage === 'Paanyaya 2' && $stage2) ? $stage2 : $stage1;
        $invitationDate = $activeStageData['invitation_date'] ?? ($latestSession['scheduled_date'] ?? date('Y-m-d', strtotime('+3 weekdays')));
        $invitationTime = $activeStageData['invitation_time'] ?? ($latestSession['scheduled_time'] ?? '09:00:00');
        $hasESignature = isset($activeStageData['has_e_signature']) ? (bool)$activeStageData['has_e_signature'] : true;
        $salaysay = $latestSession['salaysay'] ?? ($complaint['narrative'] ?? 'Naisin nilang magkausap at magkasundo nang maayos ukol sa kanilang hidwaan.');

        // Deconstruct date components
        $ts = strtotime($invitationDate);
        $petsa = date('j', $ts);
        $buwan = $this->filipinoMonth((int)date('n', $ts));
        $taon = date('Y', $ts);
        $araw = $this->filipinoDayOfWeek((int)date('w', $ts));
        $oras = date('h:i A', strtotime($invitationTime));

        return [
            'complaint' => $complaint,
            'complainants' => $complainants,
            'respondents' => $respondents,
            'complainant_name' => $complainantNameStr,
            'respondent_name' => $respondentNameStr,
            'tanggapan' => 'Barangay Tumana',
            'current_stage' => $currentStage,
            'stage1' => $stage1,
            'stage2' => $stage2,
            'sessions' => $sessions,
            'latest_session' => $latestSession,
            'invitation_date' => $invitationDate,
            'invitation_time' => $invitationTime,
            'petsa' => $petsa,
            'buwan' => $buwan,
            'taon' => $taon,
            'araw' => $araw,
            'oras' => $oras,
            'salaysay' => $salaysay,
            'has_e_signature' => $hasESignature,
            'venue' => 'Tanggapan ng Punong Barangay, Barangay Tumana',
            'captain_name' => 'HON. AKIKO LYNNE CENTENO',
            'is_dismissed' => str_contains(strtolower($complaint['status']), 'dismissed'),
            'is_settled' => str_contains(strtolower($complaint['status']), 'settled'),
            'is_escalated' => !empty($complaint['case_id']) || str_contains(strtolower($complaint['status']), 'mediation')
        ];
    }

    /**
     * Save/Update Paanyaya streamlined fields.
     */
    public function savePaanyaya(int $complaintId, array $data, int $userId): array
    {
        $stageType = in_array(($data['stage_type'] ?? ''), ['Paanyaya 1', 'Paanyaya 2'], true)
            ? $data['stage_type']
            : 'Paanyaya 1';

        $invitationDate = trim($data['invitation_date'] ?? '');
        $invitationTime = trim($data['invitation_time'] ?? '');

        // If date pieces were provided instead
        if (empty($invitationDate) && !empty($data['petsa']) && !empty($data['buwan']) && !empty($data['taon'])) {
            $monthNum = $this->parseFilipinoMonthToNumber((string)$data['buwan']);
            $dayNum = (int)$data['petsa'];
            $yearNum = (int)$data['taon'];
            $invitationDate = sprintf('%04d-%02d-%02d', $yearNum, $monthNum, $dayNum);
        }

        if (empty($invitationTime) && !empty($data['oras'])) {
            $invitationTime = date('H:i:s', strtotime((string)$data['oras']));
        }

        // Validate past date
        if (!empty($invitationDate)) {
            $scheduledTs = strtotime($invitationDate . ' ' . ($invitationTime ?: '00:00:00'));
            if ($scheduledTs < strtotime(date('Y-m-d 00:00:00'))) {
                throw new InvalidArgumentException('Hindi maaaring magtakda ng nakaraang petsa (Past dates are not allowed).');
            }
        }

        $salaysay = trim($data['salaysay'] ?? '');
        $hasESignature = !empty($data['has_e_signature']) ? 1 : 0;
        $venue = 'Tanggapan ng Punong Barangay, Barangay Tumana';
        $attemptNumber = ($stageType === 'Paanyaya 2') ? 2 : 1;

        $this->conn->beginTransaction();
        try {
            // 1. Get or Create paanyaya_stages row
            $findStage = $this->conn->prepare('
                SELECT id FROM paanyaya_stages
                WHERE complaint_id = ? AND stage_type = ?
                LIMIT 1
            ');
            $findStage->execute([$complaintId, $stageType]);
            $stageId = $findStage->fetchColumn();

            if ($stageId) {
                $updStage = $this->conn->prepare('
                    UPDATE paanyaya_stages
                    SET invitation_date = ?,
                        invitation_time = ?,
                        venue = ?,
                        has_e_signature = ?,
                        status = ?,
                        issued_at = COALESCE(issued_at, NOW()),
                        issued_by = COALESCE(issued_by, ?),
                        updated_at = NOW()
                    WHERE id = ?
                ');
                $updStage->execute([
                    $invitationDate,
                    $invitationTime,
                    $venue,
                    $hasESignature,
                    'In Progress',
                    $userId,
                    $stageId
                ]);
            } else {
                $insStage = $this->conn->prepare('
                    INSERT INTO paanyaya_stages (
                        complaint_id, stage_type, invitee_name, status,
                        issued_at, issued_by, invitation_date, invitation_time,
                        venue, has_e_signature, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?,
                        NOW(), ?, ?, ?,
                        ?, ?, NOW(), NOW()
                    )
                ');
                $invitee = trim($data['respondent_name'] ?? 'Inanyayahan');
                $insStage->execute([
                    $complaintId,
                    $stageType,
                    $invitee,
                    'In Progress',
                    $userId,
                    $invitationDate,
                    $invitationTime,
                    $venue,
                    $hasESignature
                ]);
                $stageId = (int)$this->conn->lastInsertId();
            }

            // 2. Record / Update Session
            $sessCheck = $this->conn->prepare('
                SELECT session_id FROM paanyaya_sessions
                WHERE complaint_id = ? AND attempt_number = ?
                ORDER BY session_id DESC LIMIT 1
            ');
            $sessCheck->execute([$complaintId, $attemptNumber]);
            $sessionId = $sessCheck->fetchColumn();

            if ($sessionId) {
                $updSess = $this->conn->prepare('
                    UPDATE paanyaya_sessions
                    SET scheduled_date = ?,
                        scheduled_time = ?,
                        venue = ?,
                        has_e_signature = ?,
                        salaysay = ?,
                        updated_at = NOW()
                    WHERE session_id = ?
                ');
                $updSess->execute([
                    $invitationDate,
                    $invitationTime,
                    $venue,
                    $hasESignature,
                    $salaysay,
                    $sessionId
                ]);
            } else {
                $insSess = $this->conn->prepare('
                    INSERT INTO paanyaya_sessions (
                        complaint_id, invitee_name, attempt_number,
                        scheduled_date, scheduled_time, venue,
                        has_e_signature, salaysay, scheduled_by,
                        queue_status, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, NOW(), NOW()
                    )
                ');
                $invitee = trim($data['respondent_name'] ?? 'Inanyayahan');
                $insSess->execute([
                    $complaintId,
                    $invitee,
                    $attemptNumber,
                    $invitationDate,
                    $invitationTime,
                    $venue,
                    $hasESignature,
                    $salaysay,
                    $userId,
                    'Waiting'
                ]);
                $sessionId = (int)$this->conn->lastInsertId();
            }

            // 3. Keep complaint status updated
            $updComp = $this->conn->prepare('
                UPDATE complaints
                SET status = ?,
                    paanyaya_attempt_count = GREATEST(COALESCE(paanyaya_attempt_count, 0), ?),
                    updated_at = NOW()
                WHERE complaint_id = ?
            ');
            $updComp->execute(['Pre-Docketed (Paanyaya)', $attemptNumber, $complaintId]);

            // 4. Update or insert in paanyayas table for dashboard counters
            $pnyCheck = $this->conn->prepare('SELECT paanyaya_id FROM paanyayas WHERE complaint_id = ? LIMIT 1');
            $pnyCheck->execute([$complaintId]);
            $pnyId = $pnyCheck->fetchColumn();

            $complainantName = trim($data['complainant_name'] ?? 'Nagpaanyaya');
            $respondentName = trim($data['respondent_name'] ?? 'Inanyayahan');

            if ($pnyId) {
                $pnyUpd = $this->conn->prepare('
                    UPDATE paanyayas
                    SET complainant_name = ?,
                        invitee_name = ?,
                        scheduled_date = ?,
                        scheduled_time = ?,
                        venue = ?,
                        status = ?,
                        updated_at = NOW()
                    WHERE paanyaya_id = ?
                ');
                $pnyUpd->execute([
                    $complainantName,
                    $respondentName,
                    $invitationDate,
                    $invitationTime,
                    $venue,
                    'Scheduled',
                    $pnyId
                ]);
            } else {
                $pnyIns = $this->conn->prepare('
                    INSERT INTO paanyayas (
                        complaint_id, complainant_name, invitee_name,
                        scheduled_date, scheduled_time, status,
                        venue, scheduled_by, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, NOW(), NOW()
                    )
                ');
                $pnyIns->execute([
                    $complaintId,
                    $complainantName,
                    $respondentName,
                    $invitationDate,
                    $invitationTime,
                    'Scheduled',
                    $venue,
                    $userId
                ]);
            }

            $this->conn->commit();

            return [
                'success' => true,
                'message' => "Matagumpay na na-save ang $stageType.",
                'stage_type' => $stageType,
                'stage_id' => $stageId,
                'session_id' => $sessionId
            ];
        } catch (Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    /**
     * Attendance & Progression Engine implementing the exact outcome tree:
     *
     * 1st Paanyaya (Both Attended):
     *   - Settled Privately ("Kasulatan sa Pribadong Kasunduan")
     *   - No Agreement -> Issue 2nd Paanyaya
     *
     * 1st Paanyaya (One Absent):
     *   - Complainant absent -> Dismissed
     *   - Respondent absent -> Issue 2nd Paanyaya
     *
     * 2nd Paanyaya (Both Attended):
     *   - Settled Privately ("Kasulatan sa Pribadong Kasunduan")
     *   - No Agreement -> Escalate to Formal Mediation
     *
     * 2nd Paanyaya (One Absent):
     *   - Complainant absent -> Dismissed
     *   - Respondent absent -> Escalate to Formal Mediation
     */
    public function recordAttendanceAndProgression(int $complaintId, array $payload, int $userId): array
    {
        $stageType = in_array(($payload['stage_type'] ?? ''), ['Paanyaya 1', 'Paanyaya 2'], true)
            ? $payload['stage_type']
            : 'Paanyaya 1';

        $complainantAttended = ($payload['complainant_attended'] ?? 'Absent') === 'Present' ? 'Present' : 'Absent';
        $respondentAttended = ($payload['respondent_attended'] ?? 'Absent') === 'Present' ? 'Present' : 'Absent';
        $agreementStatus = trim((string)($payload['agreement_status'] ?? 'no_agreement')); // 'settled_privately' or 'no_agreement'

        $this->conn->beginTransaction();
        try {
            $outcome = '';
            $newComplaintStatus = '';
            $progressionAction = ''; // 'settled', 'issue_p2', 'dismissed', 'escalate_mediation'
            $remarks = '';

            if ($stageType === 'Paanyaya 1') {
                if ($complainantAttended === 'Present' && $respondentAttended === 'Present') {
                    // Both Attended
                    if ($agreementStatus === 'settled_privately') {
                        $outcome = 'Settled';
                        $newComplaintStatus = 'Settled (Pre-Docketed)';
                        $progressionAction = 'settled';
                        $remarks = '1st Paanyaya: Nagkasundo nang Pribado (Kasulatan sa Pribadong Kasunduan).';
                    } else {
                        // No Agreement -> Issue 2nd Paanyaya
                        $outcome = 'Continued_Dialogue';
                        $newComplaintStatus = 'Pre-Docketed (Paanyaya 2 Authorized)';
                        $progressionAction = 'issue_p2';
                        $remarks = '1st Paanyaya: Walang Kasunduan (No Agreement). Awtorisado para sa Ikalawang Paanyaya.';
                    }
                } elseif ($complainantAttended === 'Absent') {
                    // Complainant absent -> Dismissed
                    $outcome = 'Dismissed';
                    $newComplaintStatus = 'Dismissed (Complainant Absent)';
                    $progressionAction = 'dismissed';
                    $remarks = '1st Paanyaya: Hindi sumipot ang Nagpaanyaya (Complainant Absent). Dismissed.';
                } else {
                    // Respondent absent -> Issue 2nd Paanyaya
                    $outcome = 'Absent_Retry';
                    $newComplaintStatus = 'Pre-Docketed (Paanyaya 2 Authorized)';
                    $progressionAction = 'issue_p2';
                    $remarks = '1st Paanyaya: Hindi sumipot ang Inanyayahan (Respondent Absent). Awtorisado para sa Ikalawang Paanyaya.';
                }
            } else {
                // Stage is Paanyaya 2
                if ($complainantAttended === 'Present' && $respondentAttended === 'Present') {
                    // Both Attended
                    if ($agreementStatus === 'settled_privately') {
                        $outcome = 'Settled';
                        $newComplaintStatus = 'Settled (Pre-Docketed)';
                        $progressionAction = 'settled';
                        $remarks = '2nd Paanyaya: Nagkasundo nang Pribado (Kasulatan sa Pribadong Kasunduan).';
                    } else {
                        // No Agreement -> Escalate to Formal Mediation
                        $outcome = 'Impasse_Escalate';
                        $newComplaintStatus = 'Docketed (Mediation)';
                        $progressionAction = 'escalate_mediation';
                        $remarks = '2nd Paanyaya: Walang Kasunduan (No Agreement). Inilipat sa Pormal na Mediation.';
                    }
                } elseif ($complainantAttended === 'Absent') {
                    // Complainant absent -> Dismissed
                    $outcome = 'Dismissed';
                    $newComplaintStatus = 'Dismissed (Complainant Absent)';
                    $progressionAction = 'dismissed';
                    $remarks = '2nd Paanyaya: Hindi sumipot ang Nagpaanyaya (Complainant Absent). Dismissed.';
                } else {
                    // Respondent absent -> Escalate to Formal Mediation
                    $outcome = 'Max_Failed_Escalate';
                    $newComplaintStatus = 'Docketed (Mediation)';
                    $progressionAction = 'escalate_mediation';
                    $remarks = '2nd Paanyaya: Hindi sumipot ang Inanyayahan (Respondent Absent). Inilipat sa Pormal na Mediation para sa Pormal na Summons.';
                }
            }

            // 1. Update or complete stage
            $stageUpd = $this->conn->prepare('
                UPDATE paanyaya_stages
                SET status = ?,
                    review_status = ?,
                    review_notes = ?,
                    reviewed_by = ?,
                    reviewed_at = NOW(),
                    updated_at = NOW()
                WHERE complaint_id = ? AND stage_type = ?
            ');
            $stageStatus = ($progressionAction === 'dismissed' || $progressionAction === 'settled') ? 'Completed' : 'Completed';
            $reviewStatus = ($progressionAction === 'issue_p2') ? 'Approved for Paanyaya 2'
                : (($progressionAction === 'escalate_mediation') ? 'Approved for Summons' : 'Case Closed');

            $stageUpd->execute([
                $stageStatus,
                $reviewStatus,
                $remarks,
                $userId,
                $complaintId,
                $stageType
            ]);

            // 2. Update session attendance & outcome
            $attemptNum = ($stageType === 'Paanyaya 2') ? 2 : 1;
            $sessUpd = $this->conn->prepare('
                UPDATE paanyaya_sessions
                SET complainant_attended = ?,
                    respondent_attended = ?,
                    session_outcome = ?,
                    outcome_notes = ?,
                    session_remarks = ?,
                    resolved_at = CASE WHEN ? = "Settled" THEN NOW() ELSE resolved_at END,
                    escalated_at = CASE WHEN ? IN ("Impasse_Escalate", "Max_Failed_Escalate") THEN NOW() ELSE escalated_at END,
                    queue_status = "Concluded",
                    updated_at = NOW()
                WHERE complaint_id = ? AND attempt_number = ?
            ');
            $sessUpd->execute([
                $complainantAttended,
                $respondentAttended,
                $outcome,
                $remarks,
                $remarks,
                $outcome,
                $outcome,
                $complaintId,
                $attemptNum
            ]);

            // 3. Update complaint
            $compUpd = $this->conn->prepare('
                UPDATE complaints
                SET status = ?,
                    resolved_without_docketing = CASE WHEN ? = "Settled" THEN 1 ELSE resolved_without_docketing END,
                    paanyaya_1_outcome = CASE WHEN ? = "Paanyaya 1" THEN ? ELSE paanyaya_1_outcome END,
                    updated_at = NOW()
                WHERE complaint_id = ?
            ');
            $compUpd->execute([
                $newComplaintStatus,
                $outcome,
                $stageType,
                $outcome,
                $complaintId
            ]);

            // 4. Update paanyayas table status
            $pnyStatus = ($outcome === 'Settled') ? 'Resolved' : (($outcome === 'Dismissed') ? 'Cancelled' : 'Completed');
            $pnyUpd = $this->conn->prepare('
                UPDATE paanyayas
                SET status = ?, updated_at = NOW()
                WHERE complaint_id = ?
            ');
            $pnyUpd->execute([$pnyStatus, $complaintId]);

            // 5. If Escalating to Formal Mediation, perform docketing!
            $docketResult = null;
            if ($progressionAction === 'escalate_mediation') {
                $docketResult = $this->legacyModel->escalateToFormalDocketing(
                    $complaintId,
                    $userId,
                    "Inilipat mula sa Paanyaya: $remarks"
                );
            }

            // 6. If Progression is Issue 2nd Paanyaya, ensure Stage 2 record exists and is ready
            if ($progressionAction === 'issue_p2') {
                $checkP2 = $this->conn->prepare('
                    SELECT id FROM paanyaya_stages
                    WHERE complaint_id = ? AND stage_type = "Paanyaya 2"
                    LIMIT 1
                ');
                $checkP2->execute([$complaintId]);
                if (!$checkP2->fetchColumn()) {
                    $insP2 = $this->conn->prepare('
                        INSERT INTO paanyaya_stages (
                            complaint_id, stage_type, invitee_name, status,
                            review_status, created_at, updated_at
                        ) VALUES (
                            ?, "Paanyaya 2",
                            (SELECT COALESCE(TRIM(CONCAT_WS(" ", r.first_name, r.middle_name, r.last_name, r.suffix)), "Inanyayahan") FROM complaint_parties cp LEFT JOIN residents r ON r.resident_id = cp.resident_id WHERE cp.complaint_id = ? AND cp.party_type = "Respondent" LIMIT 1),
                            "In Progress", "Approved for Paanyaya 2", NOW(), NOW()
                        )
                    ');
                    $insP2->execute([$complaintId, $complaintId]);
                }
            }

            $this->conn->commit();

            return [
                'success' => true,
                'message' => $remarks,
                'progression_action' => $progressionAction,
                'new_status' => $newComplaintStatus,
                'outcome' => $outcome,
                'docket_result' => $docketResult
            ];
        } catch (Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    public function filipinoMonth(int $m): string
    {
        $months = [
            1 => 'Enero', 2 => 'Pebrero', 3 => 'Marso', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Hunyo', 7 => 'Hulyo', 8 => 'Agosto',
            9 => 'Setyembre', 10 => 'Oktubre', 11 => 'Nobyembre', 12 => 'Disyembre'
        ];
        return $months[$m] ?? date('F', mktime(0, 0, 0, $m, 1));
    }

    public function parseFilipinoMonthToNumber(string $monthName): int
    {
        $m = strtolower(trim($monthName));
        $map = [
            'enero' => 1, 'january' => 1, 'jan' => 1,
            'pebrero' => 2, 'february' => 2, 'feb' => 2,
            'marso' => 3, 'march' => 3, 'mar' => 3,
            'abril' => 4, 'april' => 4, 'apr' => 4,
            'mayo' => 5, 'may' => 5,
            'hunyo' => 6, 'june' => 6, 'jun' => 6,
            'hulyo' => 7, 'july' => 7, 'jul' => 7,
            'agosto' => 8, 'august' => 8, 'aug' => 8,
            'setyembre' => 9, 'september' => 9, 'sep' => 9, 'sept' => 9,
            'oktubre' => 10, 'october' => 10, 'oct' => 10,
            'nobyembre' => 11, 'november' => 11, 'nov' => 11,
            'disyembre' => 12, 'december' => 12, 'dec' => 12
        ];
        return $map[$m] ?? (int)date('n');
    }

    public function filipinoDayOfWeek(int $w): string
    {
        $days = [
            0 => 'Linggo', 1 => 'Lunes', 2 => 'Martes', 3 => 'Miyerkules',
            4 => 'Huwebes', 5 => 'Biyernes', 6 => 'Sabado'
        ];
        return $days[$w] ?? 'Lunes';
    }
}
