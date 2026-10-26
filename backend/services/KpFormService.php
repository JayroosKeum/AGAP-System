<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/PDFService.php';

class KpFormService
{
    private PDO $conn;
    private PDFService $pdfService;

    public function __construct(?PDO $conn = null, ?PDFService $pdfService = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
        $this->pdfService = $pdfService ?? new PDFService($this->conn);
    }

    /**
     * Retrieve all relevant case, complaint, party, hearing, pangkat, settlement,
     * arbitration, and show-cause records needed to populate official KP forms.
     */
    public function getCaseData(int $caseId, ?int $hearingId = null): array
    {
        // 1. Fetch Case & Complaint
        $caseStmt = $this->conn->prepare("
            SELECT c.*, co.complaint_number, co.complaint_title, co.complaint_details,
                   co.status AS complaint_status, co.created_at AS complaint_created_at
            FROM cases c
            INNER JOIN complaints co ON co.complaint_id = c.complaint_id
            WHERE c.case_id = ?
        ");
        $caseStmt->execute([$caseId]);
        $case = $caseStmt->fetch(PDO::FETCH_ASSOC);

        if (!$case) {
            throw new InvalidArgumentException("Case #{$caseId} not found.");
        }

        $complaintId = (int) $case['complaint_id'];

        // 2. Fetch Complaint Parties
        $partiesStmt = $this->conn->prepare("
            SELECT cp.party_type, r.resident_id,
                   TRIM(CONCAT_WS(' ', r.first_name, NULLIF(r.middle_name, ''), r.last_name)) AS full_name,
                   r.first_name, r.last_name, r.middle_name, r.address, r.purok, r.contact_no
            FROM complaint_parties cp
            INNER JOIN residents r ON r.resident_id = cp.resident_id
            WHERE cp.complaint_id = ?
            ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent', 'Witness'), r.last_name, r.first_name
        ");
        $partiesStmt->execute([$complaintId]);
        $allParties = $partiesStmt->fetchAll(PDO::FETCH_ASSOC);

        $complainants = [];
        $respondents = [];
        $witnesses = [];

        foreach ($allParties as $p) {
            if ($p['party_type'] === 'Complainant') {
                $complainants[] = $p;
            } elseif ($p['party_type'] === 'Respondent') {
                $respondents[] = $p;
            } elseif ($p['party_type'] === 'Witness') {
                $witnesses[] = $p;
            }
        }

        // 3. Authorized Officials from system_settings
        $captainStmt = $this->conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'captain_name' LIMIT 1");
        $captainStmt->execute();
        $capVal = trim((string) $captainStmt->fetchColumn());
        $captainName = (!empty($capVal) && strcasecmp($capVal, 'Admin User') !== 0 && strcasecmp($capVal, 'Punong Barangay / Lupon Chairman') !== 0)
            ? $capVal
            : 'HON. AKIKO LYNNE CENTENO';

        $secStmt = $this->conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'secretary_name' LIMIT 1");
        $secStmt->execute();
        $secVal = trim((string) $secStmt->fetchColumn());
        $secretaryName = (!empty($secVal) && strcasecmp($secVal, 'Admin User') !== 0)
            ? $secVal
            : 'MARILOU P. CRUZ';

        // 4. Pangkat Group and Members
        $pangkatStmt = $this->conn->prepare("
            SELECT pg.*,
                   (SELECT TRIM(CONCAT_WS(' ', u.first_name, NULLIF(u.middle_name, ''), u.last_name))
                    FROM pangkat_members pm JOIN users u ON u.user_id = pm.member_id
                    WHERE pm.pangkat_id = pg.pangkat_id AND (pm.position = 'Chairman' OR pm.position = 'Chairperson') LIMIT 1) AS chairman_name,
                   (SELECT TRIM(CONCAT_WS(' ', u.first_name, NULLIF(u.middle_name, ''), u.last_name))
                    FROM pangkat_members pm JOIN users u ON u.user_id = pm.member_id
                    WHERE pm.pangkat_id = pg.pangkat_id AND pm.position = 'Secretary' LIMIT 1) AS secretary_name,
                   (SELECT TRIM(CONCAT_WS(' ', u.first_name, NULLIF(u.middle_name, ''), u.last_name))
                    FROM pangkat_members pm JOIN users u ON u.user_id = pm.member_id
                    WHERE pm.pangkat_id = pg.pangkat_id AND pm.position = 'Member' LIMIT 1) AS member_name
            FROM pangkat_groups pg
            WHERE pg.case_id = ?
            ORDER BY pg.pangkat_id DESC LIMIT 1
        ");
        $pangkatStmt->execute([$caseId]);
        $pangkat = $pangkatStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Fetch individual roster members for chosen list
        $pangkatMembers = [];
        if (!empty($pangkat['pangkat_id'])) {
            $memStmt = $this->conn->prepare("
                SELECT pm.*, TRIM(CONCAT_WS(' ', u.first_name, NULLIF(u.middle_name, ''), u.last_name)) AS full_name
                FROM pangkat_members pm
                INNER JOIN users u ON u.user_id = pm.member_id
                WHERE pm.pangkat_id = ?
                ORDER BY FIELD(pm.position, 'Chairman', 'Chairperson', 'Secretary', 'Member')
            ");
            $memStmt->execute([(int) $pangkat['pangkat_id']]);
            $pangkatMembers = $memStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 5. Hearing Details (target or latest)
        $hearing = null;
        if ($hearingId) {
            $hStmt = $this->conn->prepare("SELECT * FROM hearings WHERE hearing_id = ? AND case_id = ?");
            $hStmt->execute([$hearingId, $caseId]);
            $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$hearing) {
            $hStmt = $this->conn->prepare("SELECT * FROM hearings WHERE case_id = ? ORDER BY hearing_date DESC, hearing_id DESC LIMIT 1");
            $hStmt->execute([$caseId]);
            $hearing = $hStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        // Also fetch latest conciliation hearing specifically (for KP Form 12)
        $concHearingStmt = $this->conn->prepare("
            SELECT * FROM hearings 
            WHERE case_id = ? AND hearing_type = 'Conciliation' 
            ORDER BY hearing_date DESC, hearing_id DESC LIMIT 1
        ");
        $concHearingStmt->execute([$caseId]);
        $concHearing = $concHearingStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // 6. Hearing Minutes & Settlement Progress
        $minutesStmt = $this->conn->prepare("
            SELECT hm.*, h.hearing_type, h.hearing_date, h.venue
            FROM hearing_minutes hm
            INNER JOIN hearings h ON h.hearing_id = hm.hearing_id
            WHERE h.case_id = ?
            ORDER BY hm.updated_at DESC, hm.minutes_id DESC LIMIT 1
        ");
        $minutesStmt->execute([$caseId]);
        $minutes = $minutesStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // 7. Settlement Record (KP Form 16 & 17)
        $settleStmt = $this->conn->prepare("SELECT * FROM settlements WHERE case_id = ? ORDER BY settlement_id DESC LIMIT 1");
        $settleStmt->execute([$caseId]);
        $settlement = $settleStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // 8. Arbitration Record (KP Form 14 & 15)
        $arbStmt = $this->conn->prepare("SELECT * FROM arbitration_records WHERE case_id = ? ORDER BY arbitration_id DESC LIMIT 1");
        $arbStmt->execute([$caseId]);
        $arbitration = $arbStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // 9. CFA Record (KP Form 20, 20-A, 20-B)
        $cfaStmt = $this->conn->prepare("SELECT * FROM cfa_records WHERE case_id = ? ORDER BY cfa_id DESC LIMIT 1");
        $cfaStmt->execute([$caseId]);
        $cfa = $cfaStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // 10. Show Cause Evaluations (KP Form 18, 19, 20-A, 20-B, 21, 22)
        $scStmt = $this->conn->prepare("SELECT * FROM show_cause_evaluations WHERE case_id = ? ORDER BY evaluation_id DESC");
        $scStmt->execute([$caseId]);
        $showCauseList = $scStmt->fetchAll(PDO::FETCH_ASSOC);

        $complainantSc = null;
        $respondentSc = null;
        foreach ($showCauseList as $sc) {
            if ($sc['party_type'] === 'Complainant' && !$complainantSc) {
                $complainantSc = $sc;
            } elseif ($sc['party_type'] === 'Respondent' && !$respondentSc) {
                $respondentSc = $sc;
            }
        }

        return [
            'case' => $case,
            'complainants' => $complainants,
            'respondents' => $respondents,
            'witnesses' => $witnesses,
            'captain_name' => $captainName,
            'secretary_name' => $secretaryName,
            'pangkat' => $pangkat,
            'pangkat_members' => $pangkatMembers,
            'hearing' => $hearing,
            'conc_hearing' => $concHearing,
            'minutes' => $minutes,
            'settlement' => $settlement,
            'arbitration' => $arbitration,
            'cfa' => $cfa,
            'complainant_sc' => $complainantSc,
            'respondent_sc' => $respondentSc
        ];
    }

    /**
     * Build the structured $data array tailored for a specific KP Form.
     */
    public function getFormData(string $formCode, int $caseId, ?int $hearingId = null): array
    {
        $d = $this->getCaseData($caseId, $hearingId);

        $case = $d['case'];
        $complainants = $d['complainants'];
        $respondents = $d['respondents'];
        $captainName = $d['captain_name'];
        $secretaryName = $d['secretary_name'];
        $pangkat = $d['pangkat'];
        $pangkatMembers = $d['pangkat_members'];
        $hearing = $d['hearing'];
        $concHearing = $d['conc_hearing'];
        $minutes = $d['minutes'];
        $settlement = $d['settlement'];
        $arbitration = $d['arbitration'];
        $cfa = $d['cfa'];

        $primaryComplainant = !empty($complainants[0]['full_name']) ? $complainants[0]['full_name'] : 'Complainant';
        $primaryRespondent = !empty($respondents[0]['full_name']) ? $respondents[0]['full_name'] : 'Respondent';

        // Pangkat official names
        $pangkatChairName = $pangkat['chairman_name'] ?? '';
        $pangkatSecName = $pangkat['secretary_name'] ?? '';
        $pangkatMemberName = $pangkat['member_name'] ?? '';

        if (empty($pangkatChairName) && !empty($pangkatMembers)) {
            foreach ($pangkatMembers as $pm) {
                $pos = strtolower($pm['position'] ?? '');
                if (str_contains($pos, 'chair')) $pangkatChairName = $pm['full_name'];
                elseif (str_contains($pos, 'sec')) $pangkatSecName = $pm['full_name'];
                elseif (str_contains($pos, 'mem')) $pangkatMemberName = $pm['full_name'];
            }
        }

        // Hearing schedule
        $hearingDate = !empty($hearing['hearing_date']) ? $hearing['hearing_date'] : date('Y-m-d H:i:s', strtotime('+2 days 09:00'));
        $venue = !empty($hearing['venue']) ? $hearing['venue'] : 'Lupon Court Room, Barangay Tumana';

        // Common baseline data for all forms
        $formData = [
            'case_id' => $caseId,
            'case_number' => $case['case_number'],
            'complaint_id' => $case['complaint_id'],
            'complaint_number' => $case['complaint_number'],
            'complaint_title' => $case['complaint_title'],
            'complaint_details' => $case['complaint_details'],
            'complainants' => $complainants,
            'respondents' => $respondents,
            'target_party_name' => $primaryRespondent,
            'captain_name' => $captainName,
            'official_name' => $captainName,
            'secretary_name' => $secretaryName,
            'venue' => $venue,
            'hearing_date' => $hearingDate,
            'notice_date' => date('Y-m-d')
        ];

        // Specific mappings based on KP form code:
        if (str_contains($formCode, '10')) {
            // KP Form 10: Paabiso sa Pagbubuo ng Pangkat
            $formData['constitution_date'] = !empty($pangkat['formation_date']) ? $pangkat['formation_date'] : $hearingDate;
            $formData['selection_method'] = $pangkat['selection_method'] ?? 'Party Agreement';
            $formData['selection_notes'] = $pangkat['selection_notes'] ?? '';
            $formData['convening_deadline'] = $pangkat['convening_deadline'] ?? date('Y-m-d', strtotime('+3 days'));
        } elseif (str_contains($formCode, '11')) {
            // KP Form 11: Patalastas sa Piniling Miyembro ng Pangkat
            $chosenList = [];
            if (!empty($pangkatChairName)) $chosenList[] = "{$pangkatChairName} (Pangkat Chairman)";
            if (!empty($pangkatSecName)) $chosenList[] = "{$pangkatSecName} (Pangkat Secretary)";
            if (!empty($pangkatMemberName)) $chosenList[] = "{$pangkatMemberName} (Pangkat Member)";
            $chosenMemberStr = !empty($chosenList) ? implode(', ', $chosenList) : 'Mga Piniling Miyembro ng Pangkat';

            $formData['chosen_member'] = $chosenMemberStr;
            $formData['target_party_name'] = $chosenMemberStr;
            $formData['hearing_date'] = !empty($concHearing['hearing_date']) ? $concHearing['hearing_date'] : $hearingDate;
        } elseif (str_contains($formCode, '12')) {
            // KP Form 12: Paabiso ng Pagdinig (Pangkat Conciliation)
            $formData['hearing_date'] = !empty($concHearing['hearing_date']) ? $concHearing['hearing_date'] : $hearingDate;
            $formData['venue'] = !empty($concHearing['venue']) ? $concHearing['venue'] : 'Lupon Court Room, Barangay Tumana';
            $formData['pangkat_chairman'] = $pangkatChairName ?: 'Pangkat Chairman';
            $formData['pangkat_secretary'] = $pangkatSecName ?: 'Pangkat Secretary';
            $formData['pangkat_member'] = $pangkatMemberName ?: 'Pangkat Member';
        } elseif (str_contains($formCode, '14')) {
            // KP Form 14: Kasunduan sa Pag-aarbitro (Agreement for Arbitration)
            $agreementDate = !empty($arbitration['agreement_date']) ? $arbitration['agreement_date'] : date('Y-m-d');
            $formData['agreement_date'] = $agreementDate;
            $formData['notice_date'] = $agreementDate;
            $formData['agreement_details'] = $arbitration['resolution_notes'] ?? 'Ang mga panig ay kusang-loob na nagkasundo na isumite ang kanilang alitan sa Punong Barangay / Pangkat para sa pinal at nagtatapos na arbitrasyon alinsunod sa Seksiyon 413 ng Batas Republika Blg. 7160.';
        } elseif (str_contains($formCode, '15')) {
            // KP Form 15: Gawad ng Arbitrasyon (Arbitration Award)
            $formData['award_date'] = !empty($arbitration['award_date']) ? $arbitration['award_date'] : date('Y-m-d');
            $formData['award_details'] = $arbitration['award_terms'] ?? $arbitration['resolution_notes'] ?? 'Matapos ang masusing pagdinig at pagsusuri sa lahat ng ebidensya at pahayag ng magkabilang panig, iginagawad ng Lupon ang sumusunod na hatol...';
            $formData['award_terms'] = $formData['award_details'];
            $formData['pangkat_chairman'] = $pangkatChairName ?: $captainName;
        } elseif (str_contains($formCode, '16')) {
            // KP Form 16: Kasunduang Pag-aayos (Amicable Settlement)
            $agreementTerms = $settlement['agreement_details']
                ?? $minutes['agreements_action_items']
                ?? $minutes['settlement_discussion_notes']
                ?? 'Ang mga panig ay nagkasundo nang buong laya at mapayapa na tapusin ang kanilang alitan sa ilalim ng mga napag-usapang kondisyon.';

            $formData['agreement_details'] = $agreementTerms;
            $formData['settlement_date'] = !empty($settlement['settlement_date']) ? $settlement['settlement_date'] : date('Y-m-d');
            $formData['presiding_role'] = !empty($pangkatChairName) ? 'Pangkat Chairman' : 'Punong Barangay';
            $formData['official_name'] = !empty($pangkatChairName) ? $pangkatChairName : $captainName;
        } elseif (str_contains($formCode, '17')) {
            // KP Form 17: Pagtatwa (Repudiation of Settlement or Arbitration Agreement)
            $repudiatingName = !empty($settlement['repudiating_party']) ? $settlement['repudiating_party'] : $primaryRespondent;
            $formData['repudiating_party'] = $repudiatingName;
            $formData['repudiating_party_name'] = $repudiatingName;
            $formData['target_party_name'] = $repudiatingName;
            $formData['repudiation_date'] = !empty($settlement['repudiation_date']) ? $settlement['repudiation_date'] : date('Y-m-d');
            $formData['repudiation_reason'] = !empty($settlement['repudiation_reason'])
                ? $settlement['repudiation_reason']
                : 'Pagtatwa ng kasunduan batay sa pandaraya, karahasan, o pananakot (vitiated consent under RA 7160 Sec. 418).';
            $formData['repudiation_grounds'] = $formData['repudiation_reason'];
            $formData['grounds'] = $formData['repudiation_reason'];
        } elseif (str_contains($formCode, '20-A') || str_contains($formCode, '20A')) {
            // KP Form 20-A: Certificate to Bar Action (Complainant absence/failure)
            $formData['target_party_name'] = $primaryComplainant;
            $formData['complainant_name'] = $primaryComplainant;
            $formData['absence_reason'] = $d['complainant_sc']['justification_notes'] ?? 'Hindi pagdalo ng may-sumbong sa itinakdang pagdinig nang walang makatwirang dahilan.';
            $formData['issuance_date'] = !empty($cfa['issuance_date']) ? $cfa['issuance_date'] : date('Y-m-d');
        } elseif (str_contains($formCode, '20-B') || str_contains($formCode, '20B')) {
            // KP Form 20-B: Certificate to Bar Counterclaim (Respondent absence/failure)
            $formData['target_party_name'] = $primaryRespondent;
            $formData['respondent_name'] = $primaryRespondent;
            $formData['absence_reason'] = $d['respondent_sc']['justification_notes'] ?? 'Hindi pagdalo ng ipinagsusumbong sa itinakdang pagdinig nang walang makatwirang dahilan matapos mapagsilbihan ng patawag.';
            $formData['issuance_date'] = !empty($cfa['issuance_date']) ? $cfa['issuance_date'] : date('Y-m-d');
        } elseif (str_contains($formCode, '20')) {
            // KP Form 20: Certificate to File Action (CFA - Impasse / Failure to Settle)
            $formData['cfa_reason'] = !empty($cfa['reasons_notes'])
                ? $cfa['reasons_notes']
                : 'Nabigong magkasundo ang magkabilang panig sa harap ng Lupong Tagapamayapa / Pangkat ng Tagapagkasundo matapos ang mga itinakdang pagdinig alinsunod sa batas.';
            $formData['pangkat_secretary_name'] = $pangkatSecName ?: $secretaryName;
            $formData['pangkat_chairman_name'] = $pangkatChairName ?: 'Pangkat Chairman';
            $formData['captain_name'] = $captainName;
            $formData['issuance_date'] = !empty($cfa['issuance_date']) ? $cfa['issuance_date'] : date('Y-m-d');
        } elseif (str_contains($formCode, '18')) {
            $formData['target_party_name'] = $primaryComplainant;
        } elseif (str_contains($formCode, '19')) {
            $formData['target_party_name'] = $primaryRespondent;
        }

        return $formData;
    }

    /**
     * Render the official HTML for browser viewing, iframe embedding, or printing.
     */
    public function renderHtml(string $formCode, int $caseId, ?int $hearingId = null, bool $forPdf = false): string
    {
        $data = $this->getFormData($formCode, $caseId, $hearingId);
        return $this->pdfService->getFormHtml($formCode, $data, $forPdf);
    }

    /**
     * Generate PDF and stream or save to disk.
     */
    public function generatePdf(string $formCode, int $caseId, ?int $hearingId = null, ?string $outputPath = null): string
    {
        $data = $this->getFormData($formCode, $caseId, $hearingId);
        $tempPath = $outputPath ?: sys_get_temp_dir() . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $formCode) . '_' . $caseId . '_' . time() . '.pdf';
        $this->pdfService->generateNotice($formCode, $data, $tempPath);
        return $tempPath;
    }

    /**
     * Determine dynamic list of official KP forms eligible/unlocked for this case
     * based on stage, recorded outcomes, repudiation, and show-cause evaluations.
     */
    public function getEligibleForms(int $caseId): array
    {
        $d = $this->getCaseData($caseId);
        $case = $d['case'];
        $caseStatus = $case['case_status'] ?? '';
        $stage = $case['current_stage'] ?? '';
        $settlement = $d['settlement'];
        $arbitration = $d['arbitration'];
        $cfa = $d['cfa'];
        $minutes = $d['minutes'];
        $lastOutcome = $minutes['session_outcome'] ?? '';

        $forms = [];

        // Always available baseline forms
        $forms[] = [
            'code' => 'KP Form 7',
            'title' => 'Pormularyo ng KP Blg. 7 (Reklamo / Formal Complaint)',
            'category' => 'Complaint',
            'status' => 'Issued',
            'description' => 'Original signed formal complaint filed before the Lupon.'
        ];

        // KP Form 8 & 9 (Mediation Summons & Hearing Notices)
        $forms[] = [
            'code' => 'KP Form 8',
            'title' => 'Pormularyo ng KP Blg. 8 (Paabiso ng Pagdinig - May-sumbong)',
            'category' => 'Mediation',
            'status' => 'Issued',
            'description' => 'Notice of mediation hearing served to the complainant.'
        ];
        $forms[] = [
            'code' => 'KP Form 9',
            'title' => 'Pormularyo ng KP Blg. 9 (Patawag / Summons - Ipinagsusumbong)',
            'category' => 'Mediation',
            'status' => 'Issued',
            'description' => 'Formal summons served to the respondent with Officer\'s Return tracking.'
        ];

        // Check if elevated to Pangkat
        $isElevatedToPangkat = ($stage === 'Conciliation' || $caseStatus === 'Conciliation' ||
                                $lastOutcome === 'Elevate to Pangkat' || !empty($d['pangkat']));

        if ($isElevatedToPangkat) {
            $forms[] = [
                'code' => 'KP Form 10',
                'title' => 'Pormularyo ng KP Blg. 10 (Paabiso sa Pagbubuo ng Pangkat)',
                'category' => 'Conciliation',
                'status' => 'Active',
                'description' => 'Notice summoning parties to appear and agree upon 3 Lupon members for Pangkat.'
            ];
            $forms[] = [
                'code' => 'KP Form 11',
                'title' => 'Pormularyo ng KP Blg. 11 (Patalastas sa Piniling Miyembro ng Pangkat)',
                'category' => 'Conciliation',
                'status' => 'Active',
                'description' => 'Official notice of appointment served to chosen Pangkat members.'
            ];
            $forms[] = [
                'code' => 'KP Form 12',
                'title' => 'Pormularyo ng KP Blg. 12 (Paabiso ng Pagdinig sa Harap ng Pangkat)',
                'category' => 'Conciliation',
                'status' => 'Active',
                'description' => 'Notice of first Pangkat conciliation hearing served to both parties with Officer\'s Return.'
            ];
        }

        // Check Amicable Settlement (KP Form 16)
        $isSettled = ($caseStatus === 'Settled' || $lastOutcome === 'Settled' || !empty($settlement));
        if ($isSettled) {
            $forms[] = [
                'code' => 'KP Form 16',
                'title' => 'Pormularyo ng KP Blg. 16 (Kasunduang Pag-aayos / Amicable Settlement)',
                'category' => 'Settlement',
                'status' => 'Ready',
                'description' => 'Binding settlement agreement entered into freely by the parties.'
            ];
        }

        // Check Voluntary Arbitration (KP Form 14 & 15)
        $isArbitration = ($stage === 'Arbitration' || $caseStatus === 'Arbitration' ||
                          $lastOutcome === 'Arbitration Award' || $lastOutcome === 'Arbitration' || !empty($arbitration));
        if ($isArbitration) {
            $forms[] = [
                'code' => 'KP Form 14',
                'title' => 'Pormularyo ng KP Blg. 14 (Kasunduan sa Pag-aarbitro / Agreement for Arbitration)',
                'category' => 'Arbitration',
                'status' => 'Ready',
                'description' => 'Written agreement submitting the dispute to binding arbitration under RA 7160 Sec. 413.'
            ];
            $forms[] = [
                'code' => 'KP Form 15',
                'title' => 'Pormularyo ng KP Blg. 15 (Gawad ng Arbitrasyon / Arbitration Award)',
                'category' => 'Arbitration',
                'status' => (!empty($arbitration['award_date']) || !empty($arbitration['award_terms'])) ? 'Award Rendered' : 'Ready',
                'description' => 'Formal arbitration award rendered by the Lupon Presider / Pangkat Chairman.'
            ];
        }

        // Check Repudiation (KP Form 17)
        $isRepudiated = ($lastOutcome === 'Repudiated' ||
                         ($settlement['repudiation_status'] ?? '') === 'Repudiated' ||
                         !empty($settlement['repudiation_date']));
        if ($isRepudiated) {
            $forms[] = [
                'code' => 'KP Form 17',
                'title' => 'Pormularyo ng KP Blg. 17 (Pagtatwa ng Kasunduan / Repudiation)',
                'category' => 'Repudiation',
                'status' => 'Repudiated',
                'description' => 'Sworn statement repudiating settlement on grounds of vitiated consent (fraud/violence/intimidation).'
            ];
        }

        // Check CFA (KP Form 20, 20-A, 20-B)
        $hasCfa = (!empty($cfa) || $lastOutcome === 'Pending CFA' || $caseStatus === 'CFA Issued' ||
                   $caseStatus === 'Dismissed' || $isRepudiated);

        if ($hasCfa) {
            $cfaType = $cfa['cfa_type'] ?? 'KP Form 20';
            if (str_contains($cfaType, '20-A') || ($d['complainant_sc'] && empty($d['complainant_sc']['is_justified']))) {
                $forms[] = [
                    'code' => 'KP Form 20-A',
                    'title' => 'Pormularyo ng KP Blg. 20-A (Katibayan Upang Hadlangan ang Paghahain ng Aksyon)',
                    'category' => 'CFA',
                    'status' => 'Barred',
                    'description' => 'Certificate to Bar Action issued due to willful failure of complainant to appear.'
                ];
            }
            if (str_contains($cfaType, '20-B') || ($d['respondent_sc'] && empty($d['respondent_sc']['is_justified']))) {
                $forms[] = [
                    'code' => 'KP Form 20-B',
                    'title' => 'Pormularyo ng KP Blg. 20-B (Katibayan Upang Hadlangan ang Ganting-Sakdal)',
                    'category' => 'CFA',
                    'status' => 'Barred Counterclaim',
                    'description' => 'Certificate to Bar Counterclaim issued due to willful failure of respondent to appear.'
                ];
            }
            $forms[] = [
                'code' => 'KP Form 20',
                'title' => 'Pormularyo ng KP Blg. 20 (Katibayan Upang Makadulog sa Hukuman / CFA)',
                'category' => 'CFA',
                'status' => 'Ready for Court Filing',
                'description' => 'Official Certificate to File Action endorsed by Punong Barangay authorizing court filing.'
            ];
        }

        return $forms;
    }
}
