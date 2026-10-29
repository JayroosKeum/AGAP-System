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

            SELECT c.*, co.complaint_number, co.complaint_title, co.narrative AS complaint_details,

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

            ORDER BY hm.updated_at DESC, hm.minute_id DESC LIMIT 1

        ");

        $minutesStmt->execute([$caseId]);

        $minutes = $minutesStmt->fetch(PDO::FETCH_ASSOC) ?: [];



        // 7. Settlement Record (KP Form 16 & 17)

        $settleStmt = $this->conn->prepare("
            SELECT s.*,
                   TRIM(CONCAT_WS(' ', rep.first_name, NULLIF(rep.middle_name, ''), rep.last_name)) AS repudiating_party_name
            FROM settlements s
            LEFT JOIN residents rep ON rep.resident_id = s.repudiated_by
            WHERE s.case_id = ?
            ORDER BY s.settlement_id DESC LIMIT 1
        ");

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

        $code = trim((string) ($formCode ?? ''));
        $formCode = $code;

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

        $caseStatus = $case['case_status'] ?? '';

        $lastOutcome = $minutes['session_outcome'] ?? '';



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

            // Dates of arbitration hearings held
            $hStmt = $this->conn->prepare("SELECT hearing_date FROM hearings WHERE case_id = ? AND hearing_type = 'Arbitration' AND status != 'Cancelled' ORDER BY hearing_date ASC");
            $hStmt->execute([$caseId]);
            $hDates = $hStmt->fetchAll(PDO::FETCH_COLUMN);
            $formData['arbitration_hearing_dates'] = $hDates;

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

            $repudiatingName = !empty($settlement['repudiating_party_name'])
                ? $settlement['repudiating_party_name']
                : (!empty($settlement['repudiating_party']) ? $settlement['repudiating_party'] : $primaryRespondent);

            $formData['repudiating_party'] = $repudiatingName;

            $formData['repudiating_party_name'] = $repudiatingName;

            $formData['target_party_name'] = $repudiatingName;

            $formData['repudiation_date'] = !empty($settlement['repudiation_date']) ? $settlement['repudiation_date'] : date('Y-m-d');

            $formData['repudiation_reason'] = !empty($settlement['repudiation_reason'])

                ? $settlement['repudiation_reason']

                : 'Pagtatwa ng kasunduan batay sa pandaraya, karahasan, o pananakot (vitiated consent under RA 7160 Sec. 418).';

            $formData['repudiation_grounds'] = $formData['repudiation_reason'];

            $formData['grounds'] = $formData['repudiation_reason'];

            $formData['official_name'] = !empty($captainName) ? $captainName : 'HON. AKIKO LYNNE CENTENO';

            $formData['captain_name'] = $formData['official_name'];

            $formData['pangkat_chairman_name'] = $pangkatChairName ?: 'Tagapangulo ng Pangkat';

        } elseif (str_contains($formCode, '23')) {
            // KP Form 23: Mosyon sa Pagpapatupad (Motion for Execution)
            $execStmt = $this->conn->prepare("SELECT * FROM settlement_executions WHERE case_id = ? ORDER BY execution_id DESC LIMIT 1");
            $execStmt->execute([$caseId]);
            $exec = $execStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $formData['motion_date'] = !empty($exec['motion_date']) ? $exec['motion_date'] : date('Y-m-d');
            $formData['obligation_violated'] = $exec['obligation_violated'] ?? ($settlement['agreement_details'] ?? 'Hindi pagtupad sa mga napagkasunduang obligasyon sa takdang panahon.');
            $formData['amount_or_requirement'] = $exec['amount_or_requirement'] ?? (!empty($settlement['total_amount']) ? ('Halagang PHP ' . number_format((float)$settlement['total_amount'], 2)) : 'Pagtupad sa napagkasunduang obligasyon');
            $formData['moving_party_name'] = !empty($formData['complainants'][0]['full_name']) ? $formData['complainants'][0]['full_name'] : 'May-Sumbong';
            $formData['opposing_party_name'] = !empty($formData['respondents'][0]['full_name']) ? $formData['respondents'][0]['full_name'] : 'Ipinagsusumbong';
            $formData['target_party_name'] = $formData['opposing_party_name'];
            $formData['settlement_date'] = !empty($settlement['settlement_date']) ? $settlement['settlement_date'] : date('Y-m-d');
            $formData['execution_amount_words'] = $formData['amount_or_requirement'];
            $formData['execution_amount_numbers'] = !empty($settlement['total_amount']) ? number_format((float)$settlement['total_amount'], 2) : '0.00';
            $formData['agreement_details'] = $formData['obligation_violated'];
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

            // KP Form 20: Certificate to File Action (CFA - Impasse / Repudiation / Failure to Settle)

            $isRepudiated = ($lastOutcome === 'Repudiated' || ($settlement['repudiation_status'] ?? '') === 'Repudiated' || !empty($settlement['repudiation_date']));
            $repudiatingName = !empty($settlement['repudiating_party_name'])
                ? $settlement['repudiating_party_name']
                : (!empty($settlement['repudiating_party']) ? $settlement['repudiating_party'] : $primaryRespondent);

            $formData['is_repudiated'] = $isRepudiated;
            $formData['repudiating_party'] = $repudiatingName;
            $formData['repudiating_party_name'] = $repudiatingName;
            $formData['repudiation_grounds'] = !empty($settlement['repudiation_reason'])
                ? $settlement['repudiation_reason']
                : 'Pandaraya, Karahasan, o Pananakot (vitiated consent under RA 7160 Sec. 416)';
            $formData['grounds'] = $formData['repudiation_grounds'];

            if (!empty($cfa['reasons_notes'])) {
                $formData['cfa_reason'] = $cfa['reasons_notes'];
            } elseif ($isRepudiated) {
                $formData['cfa_reason'] = "Nagkaroon ng pag-aayos ngunit ito ay tinanggihan sa isang sinumpaang salaysay sa harap ng Punong Barangay ni {$repudiatingName} sa dahilang {$formData['repudiation_grounds']}.";
            } else {
                $formData['cfa_reason'] = 'Nabigong magkasundo ang magkabilang panig sa harap ng Lupong Tagapamayapa / Pangkat ng Tagapagkasundo matapos ang mga itinakdang pagdinig alinsunod sa batas.';
            }

            $formData['pangkat_secretary_name'] = $pangkatSecName ?: $secretaryName;
            $formData['secretary_name'] = $secretaryName;
            $formData['pangkat_chairman_name'] = $pangkatChairName ?: 'Tagapangulo ng Pangkat';
            $formData['captain_name'] = $captainName;
            $formData['official_name'] = $captainName;
            $formData['issuance_date'] = !empty($cfa['issuance_date']) ? $cfa['issuance_date'] : (!empty($settlement['repudiation_date']) ? date('Y-m-d', strtotime($settlement['repudiation_date'])) : date('Y-m-d'));

        } elseif (str_contains($formCode, '18')) {

            $formData['target_party_name'] = $primaryComplainant;

        } elseif (str_contains($formCode, '19')) {

            $formData['target_party_name'] = $primaryRespondent;

        } elseif (str_contains($formCode, '7')) {

            // KP Form 7: Sumbong (Complaint)
            $formData['complaint_details'] = $case['complaint_details'] ?? ($case['narrative'] ?? '');
            $formData['narrative'] = $formData['complaint_details'];
            $formData['relief_sought'] = $case['relief_sought'] ?? 'Pagsasaayos at makatarungang lunas sa idinulog na sigalot alinsunod sa batas.';

        } elseif (str_contains($formCode, '8')) {

            // KP Form 8: Paunawa ng Pagdinig (Mediation Notice to Complainant)
            $formData['complainant_name'] = $primaryComplainant;
            $formData['target_party_name'] = $primaryComplainant;

        } elseif (str_contains($formCode, '9')) {

            // KP Form 9: Patawag (Summons to Respondent)
            $formData['respondent_name'] = $primaryRespondent;
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

        $caseStatus = $case['case_status'] ?? '';

        $lastOutcome = $minutes['session_outcome'] ?? '';

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



        // An agreement unlocks Form 14. Form 15 is available only after the

        // arbitrator has actually rendered an award.

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

            if ($lastOutcome === 'Arbitration Award' || !empty($arbitration['award_date']) || !empty($arbitration['award_terms'])) {

                $forms[] = [

                    'code' => 'KP Form 15',

                    'title' => 'Pormularyo ng KP Blg. 15 (Gawad ng Arbitrasyon / Arbitration Award)',

                    'category' => 'Arbitration',

                    'status' => 'Award Rendered',

                    'description' => 'Formal arbitration award rendered by the Lupon Presider / Pangkat Chairman.'

                ];

            }

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



        // Check Motion for Execution (KP Form 23)
        if ($isSettled && !$isRepudiated) {
            $forms[] = [
                'code' => 'KP Form 23',
                'title' => 'Pormularyo ng KP Blg. 23 (Mosyon sa Pagpapatupad / Motion for Execution)',
                'category' => 'Execution',
                'status' => 'Available',
                'description' => 'Motion for execution of final and executory amicable settlement.'
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



        // Check Subpoena (KP Form 13)

        if (!empty($d['witnesses']) || $stage === 'Conciliation' || $stage === 'Mediation') {

            $forms[] = [

                'code' => 'KP Form 13',

                'title' => 'Pormularyo ng KP Blg. 13 (Subpoena / Paanyaya sa Saksi)',

                'category' => 'Hearing',

                'status' => 'Available',

                'description' => 'Subpoena directed to witnesses requiring attendance at scheduled proceedings.'

            ];

        }



        // Check Show Cause / Failure to Appear (KP Forms 18 & 19)

        if ($d['complainant_sc'] || !empty($minutes['complainant_attendance_status']) && $minutes['complainant_attendance_status'] === 'Absent') {

            $forms[] = [

                'code' => 'KP Form 18',

                'title' => 'Pormularyo ng KP Blg. 18 (Paabiso ng Pagdinig - May-sumbong na Hindi Dumalo)',

                'category' => 'Show Cause',

                'status' => 'Active',

                'description' => 'Notice to complainant to explain willful failure to appear at scheduled hearing.'

            ];

        }

        if ($d['respondent_sc'] || !empty($minutes['respondent_attendance_status']) && $minutes['respondent_attendance_status'] === 'Absent') {

            $forms[] = [

                'code' => 'KP Form 19',

                'title' => 'Pormularyo ng KP Blg. 19 (Paabiso ng Pagdinig - Ipinagsusumbong na Hindi Dumalo)',

                'category' => 'Show Cause',

                'status' => 'Active',

                'description' => 'Notice to respondent to explain willful failure to appear at scheduled hearing.'

            ];

        }



        return $forms;

    }



    /**

     * Verify prerequisites before an official KP Form can be generated/issued.

     */

    public function checkPrerequisites(string $formCode, int $caseId, ?int $hearingId = null): array

    {

        $d = $this->getCaseData($caseId, $hearingId);

        $case = $d['case'];

        $caseStatus = $case['case_status'] ?? '';

        $stage = $case['current_stage'] ?? '';

        $settlement = $d['settlement'];

        $arbitration = $d['arbitration'];

        $cfa = $d['cfa'];

        $caseStatus = $case['case_status'] ?? '';

        $lastOutcome = $minutes['session_outcome'] ?? '';

        $minutes = $d['minutes'];

        $lastOutcome = $minutes['session_outcome'] ?? '';

        $pangkat = $d['pangkat'];

        $pangkatMembers = $d['pangkat_members'];



        $code = trim((string) ($formCode ?? ''));
        $formCode = $code;



        // KP Form 10: Notice to Constitute Pangkat

        if (str_contains($code, '10')) {

            $isElevated = ($stage === 'Conciliation' || $caseStatus === 'Conciliation' ||

                           $lastOutcome === 'Elevate to Pangkat' || !empty($pangkat));

            if (!$isElevated) {

                return [

                    'eligible' => false,

                    'reason' => 'KP Form 10 requires the case to be elevated to Pangkat Conciliation following conclusion of mediation.',

                    'prerequisite' => 'Mediation Impasse or Maximum 2 Mediation Sessions'

                ];

            }

            return ['eligible' => true, 'reason' => 'Case is elevated to Pangkat Conciliation.', 'prerequisite' => 'Met'];

        }



        // KP Form 11: Notice to Chosen Pangkat Members

        if (str_contains($code, '11')) {

            if (empty($pangkat) || empty($pangkatMembers) || count($pangkatMembers) < 3) {

                return [

                    'eligible' => false,

                    'reason' => 'KP Form 11 requires three (3) distinct Lupon members to be chosen/appointed for the Pangkat panel.',

                    'prerequisite' => 'Pangkat Panel Selection'

                ];

            }

            return ['eligible' => true, 'reason' => 'Three Pangkat members are duly constituted.', 'prerequisite' => 'Met'];

        }



        // KP Form 12: Notice of Hearing (Conciliation)

        if (str_contains($code, '12')) {

            if (empty($pangkat)) {

                return [

                    'eligible' => false,

                    'reason' => 'KP Form 12 requires Pangkat constitution before conciliation hearing notices can be issued.',

                    'prerequisite' => 'Pangkat Constitution'

                ];

            }

            if (empty($d['complainants']) || empty($d['respondents'])) {

                return [

                    'eligible' => false,

                    'reason' => 'KP Form 12 requires at least one Complainant and one Respondent to be served.',

                    'prerequisite' => 'Party Registration'

                ];

            }

            return ['eligible' => true, 'reason' => 'Pangkat is constituted and parties are registered.', 'prerequisite' => 'Met'];

        }



        // KP Form 13: Subpoena

        if (str_contains($code, '13')) {

            return ['eligible' => true, 'reason' => 'Subpoena available for summoning witnesses or parties.', 'prerequisite' => 'Met'];

        }



        // KP Form 14: Agreement for Arbitration

        if (str_contains($code, '14')) {

            $isArb = ($stage === 'Arbitration' || $caseStatus === 'Arbitration' ||

                      $lastOutcome === 'Arbitration' || $lastOutcome === 'Arbitration Award' || !empty($arbitration));

            if (!$isArb) {

                return [

                    'eligible' => false,

                    'reason' => 'KP Form 14 requires parties to mutually agree to submit their dispute to binding arbitration under RA 7160 Sec. 413.',

                    'prerequisite' => 'Mutual Consent for Binding Arbitration'

                ];

            }

            return ['eligible' => true, 'reason' => 'Parties have submitted to binding arbitration.', 'prerequisite' => 'Met'];

        }



        // KP Form 15: Arbitration Award

        if (str_contains($code, '15')) {

            $hasAward = (!empty($arbitration['award_date']) || !empty($arbitration['award_terms']) || $lastOutcome === 'Arbitration Award');

            if (!$hasAward) {

                return [

                    'eligible' => false,

                    'reason' => 'KP Form 15 requires an arbitration award to be formally rendered following arbitration proceedings.',

                    'prerequisite' => 'Rendered Arbitration Award'

                ];

            }

            return ['eligible' => true, 'reason' => 'Arbitration award rendered.', 'prerequisite' => 'Met'];

        }



        // KP Form 16: Amicable Settlement

        if (str_contains($code, '16')) {

            $isSettled = ($caseStatus === 'Settled' || $lastOutcome === 'Settled' || !empty($settlement));

            if (!$isSettled) {

                return [

                    'eligible' => false,

                    'reason' => 'KP Form 16 requires an amicable settlement agreement to be reached and recorded.',

                    'prerequisite' => 'Amicable Settlement Reached'

                ];

            }

            return ['eligible' => true, 'reason' => 'Amicable settlement reached.', 'prerequisite' => 'Met'];

        }



        // KP Form 17: Repudiation

        if (str_contains($code, '17')) {

            $isRepudiated = ($lastOutcome === 'Repudiated' ||

                             ($settlement['repudiation_status'] ?? '') === 'Repudiated' ||

                             !empty($settlement['repudiation_date']) ||

                             !empty($arbitration) ||

                             $stage === 'Arbitration' ||

                             $caseStatus === 'Arbitration');

            if (!$isRepudiated) {

                return [

                    'eligible' => false,

                    'reason' => 'KP Form 17 requires a prior settlement or arbitration agreement to be repudiated on grounds of vitiated consent.',

                    'prerequisite' => 'Repudiation of Settlement'

                ];

            }

            return ['eligible' => true, 'reason' => 'Settlement repudiated by party.', 'prerequisite' => 'Met'];

        }



        // KP Form 18: Failure to Appear - Complainant

        if (str_contains($code, '18')) {

            $isCompAbsent = false;

            if (!empty($hearingId)) {

                $haStmt = $this->conn->prepare("

                    SELECT COUNT(*) FROM hearing_attendance ha

                    INNER JOIN complaint_parties cp ON cp.resident_id = ha.resident_id AND cp.complaint_id = ?

                    WHERE ha.hearing_id = ? AND cp.party_type = 'Complainant' AND ha.attendance_status = 'Absent'

                ");

                $haStmt->execute([(int) $case['complaint_id'], $hearingId]);

                $isCompAbsent = ((int) $haStmt->fetchColumn() > 0);

            } elseif ($d['complainant_sc']) {

                $isCompAbsent = true;

            }

            if (!$isCompAbsent) {

                return [

                    'eligible' => false,

                    'reason' => 'KP Form 18 requires Complainant to have been recorded absent in the scheduled hearing.',

                    'prerequisite' => 'Complainant Absence'

                ];

            }

            return ['eligible' => true, 'reason' => 'Complainant absence recorded.', 'prerequisite' => 'Met'];

        }



        // KP Form 19: Failure to Appear - Respondent

        if (str_contains($code, '19')) {

            $isRespAbsent = false;

            if (!empty($hearingId)) {

                $haStmt = $this->conn->prepare("

                    SELECT COUNT(*) FROM hearing_attendance ha

                    INNER JOIN complaint_parties cp ON cp.resident_id = ha.resident_id AND cp.complaint_id = ?

                    WHERE ha.hearing_id = ? AND cp.party_type = 'Respondent' AND ha.attendance_status = 'Absent'

                ");

                $haStmt->execute([(int) $case['complaint_id'], $hearingId]);

                $isRespAbsent = ((int) $haStmt->fetchColumn() > 0);

            } elseif ($d['respondent_sc']) {

                $isRespAbsent = true;

            }

            if (!$isRespAbsent) {

                return [

                    'eligible' => false,

                    'reason' => 'KP Form 19 requires Respondent to have been recorded absent after notice was served.',

                    'prerequisite' => 'Respondent Absence'

                ];

            }

            return ['eligible' => true, 'reason' => 'Respondent absence recorded.', 'prerequisite' => 'Met'];

        }



        // KP Form 20-A: Certificate to Bar Action

        if (str_contains($code, '20-A') || str_contains($code, '20A')) {

            $hasBarredComp = ($d['complainant_sc'] && empty($d['complainant_sc']['is_justified'])) ||

                             (($cfa['cfa_type'] ?? '') === 'KP Form 20-A');

            if (!$hasBarredComp) {

                return [

                    'eligible' => false,

                    'reason' => 'KP Form 20-A requires willful non-appearance of Complainant without justifiable reason.',

                    'prerequisite' => 'Unjustified Non-Appearance (Complainant)'

                ];

            }

            return ['eligible' => true, 'reason' => 'Complainant unjustified non-appearance established.', 'prerequisite' => 'Met'];

        }



        // KP Form 20-B: Certificate to Bar Counterclaim

        if (str_contains($code, '20-B') || str_contains($code, '20B')) {

            $hasBarredResp = ($d['respondent_sc'] && empty($d['respondent_sc']['is_justified'])) ||

                             (($cfa['cfa_type'] ?? '') === 'KP Form 20-B');

            if (!$hasBarredResp) {

                return [

                    'eligible' => false,

                    'reason' => 'KP Form 20-B requires willful non-appearance of Respondent without justifiable reason.',

                    'prerequisite' => 'Unjustified Non-Appearance (Respondent)'

                ];

            }

            return ['eligible' => true, 'reason' => 'Respondent unjustified non-appearance established.', 'prerequisite' => 'Met'];

        }



        // KP Form 23: Motion for Execution
        if (str_contains($code, '23')) {
            $isSettled = ($caseStatus === 'Settled' || $lastOutcome === 'Settled' || !empty($settlement));
            $isRepudiated = ($lastOutcome === 'Repudiated' || ($settlement['repudiation_status'] ?? '') === 'Repudiated');
            if ($isRepudiated) {
                return [
                    'eligible' => false,
                    'reason' => 'KP Form 23 cannot be issued for a repudiated settlement.',
                    'prerequisite' => 'Unrepudiated Settlement'
                ];
            }
            if (!$isSettled) {
                return [
                    'eligible' => false,
                    'reason' => 'KP Form 23 requires an amicable settlement agreement to be entered.',
                    'prerequisite' => 'Amicable Settlement'
                ];
            }
            return ['eligible' => true, 'reason' => 'Settlement is eligible for execution upon expiration of repudiation period.', 'prerequisite' => 'Met'];
        }

        // KP Form 20: Certificate to File Action

        if (str_contains($code, '20')) {

            $hasCfaElig = (!empty($cfa) || $lastOutcome === 'Pending CFA' || $caseStatus === 'CFA Issued' ||
                           $caseStatus === 'For CFA' || $caseStatus === 'Repudiated' ||
                           $caseStatus === 'Dismissed' || !empty($settlement['repudiation_date']) ||
                           ($settlement['repudiation_status'] ?? '') === 'Repudiated');

            if (!$hasCfaElig) {

                return [

                    'eligible' => false,

                    'reason' => 'KP Form 20 requires conciliation failure/impasse, repudiated settlement, or breach of settlement.',

                    'prerequisite' => 'Conciliation Impasse or Repudiation or Settlement Breach'

                ];

            }

            return ['eligible' => true, 'reason' => 'Grounds for Certificate to File Action established.', 'prerequisite' => 'Met'];

        }



        return ['eligible' => true, 'reason' => 'Form is eligible.', 'prerequisite' => 'Met'];

    }



    /**

     * Backend logic to generate and persist KP Forms (KP Forms 10, 11, 12, etc.).

     * Ensures every generated document persists in storage (database/file storage)

     * and creates an associated Delivery Record (tracking delivery date, recipient,

     * serving officer, and status).

     */

    public function generateAndPersistForm(string $formCode, int $caseId, int $userId, array $options = []): array

    {

        $code = trim((string) ($formCode ?? ''));
        $formCode = $code;

        $hearingId = !empty($options['hearing_id']) ? (int) $options['hearing_id'] : null;



        // Check prerequisites unless explicitly bypassed

        if (empty($options['bypass_prerequisites'])) {

            $prereq = $this->checkPrerequisites($code, $caseId, $hearingId);

            if (!$prereq['eligible']) {

                return [

                    'success' => false,

                    'message' => $prereq['reason'],

                    'prerequisite' => $prereq['prerequisite'] ?? 'Prerequisites not met'

                ];

            }

        }



        $caseData = $this->getCaseData($caseId, $hearingId);

        $case = $caseData['case'];

        $caseNumber = $case['case_number'];



        // 1. Ensure template exists in document_templates

        $descMap = [

            'KP Form 10' => 'Paabiso sa Pagbubuo ng Pangkat (Notice to Constitute the Pangkat)',

            'KP Form 11' => 'Patalastas sa Piniling Miyembro ng Pangkat (Notice to Chosen Pangkat Members)',

            'KP Form 12' => 'Paabiso ng Pagdinig sa Harap ng Pangkat (Notice of Hearing for Conciliation)',

            'KP Form 13' => 'Subpoena (Paanyaya sa mga Saksi)',

            'KP Form 14' => 'Kasunduan sa Pag-aarbitro (Agreement for Arbitration)',

            'KP Form 15' => 'Gawad ng Arbitrasyon (Arbitration Award)',

            'KP Form 16' => 'Kasunduang Pag-aayos (Amicable Settlement)',

            'KP Form 17' => 'Pagtatwa ng Kasunduan (Repudiation of Settlement)',

            'KP Form 18' => 'Paabiso ng Pagdinig para sa May-Sumbong (Failure to Appear - Complainant)',

            'KP Form 19' => 'Paabiso ng Pagdinig para sa Ipinagsusumbong (Failure to Appear - Respondent)',

            'KP Form 20' => 'Katibayan Upang Makadulog sa Hukuman (Certificate to File Action)',

            'KP Form 20-A' => 'Katibayan Upang Hadlangan ang Paghahain ng Aksyon (Certificate to Bar Action)',

            'KP Form 20-B' => 'Katibayan Upang Hadlangan ang Ganting-Sakdal (Certificate to Bar Counterclaim)',

        ];

        $desc = $descMap[$code] ?? "{$code} Official Document";



        $tplStmt = $this->conn->prepare("

            INSERT INTO document_templates (template_name, description)

            VALUES (?, ?)

            ON DUPLICATE KEY UPDATE description = VALUES(description), template_id = LAST_INSERT_ID(template_id)

        ");

        $tplStmt->execute([$code, $desc]);

        $templateId = (int) $this->conn->lastInsertId();

        if ($templateId === 0) {

            $getTpl = $this->conn->prepare("SELECT template_id FROM document_templates WHERE template_name = ? LIMIT 1");

            $getTpl->execute([$code]);

            $templateId = (int) $getTpl->fetchColumn();

        }



        // 2. Prepare file storage

        $safeCase = preg_replace('/[^a-zA-Z0-9_-]/', '_', $caseNumber);

        $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $code);

        $relativeDir = 'storage/documents/kp-forms/' . $caseId;

        $absoluteDir = dirname(__DIR__, 2) . '/' . $relativeDir;

        if (!is_dir($absoluteDir)) {

            mkdir($absoluteDir, 0777, true);

        }



        $timestamp = date('Ymd-His');

        $fileName = sprintf('%s-Case-%s-%s.pdf', $safeCode, $safeCase, $timestamp);

        $relativePath = $relativeDir . '/' . $fileName;

        $absolutePath = dirname(__DIR__, 2) . '/' . $relativePath;



        // 3. Generate PDF file

        try {

            $this->generatePdf($code, $caseId, $hearingId, $absolutePath);

        } catch (Throwable $e) {

            file_put_contents($absolutePath, "%PDF-1.4 {$code} for Case {$caseNumber}");

        }



        // 4. Persist in generated_documents

        $serviceStatus = $options['service_status'] ?? 'For Service';

        $docStmt = $this->conn->prepare("

            INSERT INTO generated_documents (case_id, template_id, generated_by, file_path, service_status, generated_at)

            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)

        ");

        $docStmt->execute([$caseId, $templateId, $userId, $relativePath, $serviceStatus]);

        $documentId = (int) $this->conn->lastInsertId();



        // 5. Create Associated Delivery Records

        $servingOfficer = !empty($options['serving_officer']) ? (int) $options['serving_officer'] : $userId;

        $deliveryDate = !empty($options['delivery_date']) ? $options['delivery_date'] : null;

        $deliveryStatus = $options['delivery_status'] ?? 'Pending';

        $createdDeliveries = [];



        $insDeliv = $this->conn->prepare("

            INSERT INTO summon_deliveries (

                hearing_id, case_id, party_type, resident_id, form_type,

                delivery_status, service_method, attempted_at, served_at,

                served_by, served_by_user_id, recipient_name, document_id,

                created_by, remarks

            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)

        ");



        if (str_contains($code, '10')) {

            // KP Form 10: delivered to Complainants and Respondents

            $parties = array_merge($caseData['complainants'], $caseData['respondents']);

            foreach ($parties as $p) {

                $pType = $p['party_type'] ?? 'Respondent';

                $rId = (int) ($p['resident_id'] ?? 0) ?: null;

                $rName = $p['full_name'] ?? $pType;

                $remarks = $options['remarks'] ?? 'KP Form 10 — Notice to Constitute Pangkat Tagapagkasundo';



                $insDeliv->execute([

                    $hearingId,

                    $caseId,

                    $pType,

                    $rId,

                    'KP Form 10',

                    $deliveryStatus,

                    $options['service_method'] ?? 'Personal Notice',

                    $deliveryDate,

                    ($deliveryStatus === 'Served') ? ($deliveryDate ?: date('Y-m-d H:i:s')) : null,

                    $servingOfficer,

                    $servingOfficer,

                    $rName,

                    $documentId,

                    $userId,

                    $remarks

                ]);

                $createdDeliveries[] = [

                    'delivery_id' => (int) $this->conn->lastInsertId(),

                    'recipient' => $rName,

                    'party_type' => $pType,

                    'status' => $deliveryStatus,

                    'form_type' => 'KP Form 10'

                ];

            }

        } elseif (str_contains($code, '11')) {

            // KP Form 11: delivered to chosen Pangkat members

            $members = $caseData['pangkat_members'] ?? [];

            if (empty($members) && !empty($caseData['pangkat'])) {

                $p = $caseData['pangkat'];

                if (!empty($p['chairman_name'])) $members[] = ['full_name' => $p['chairman_name'], 'position' => 'Chairman', 'member_id' => null];

                if (!empty($p['secretary_name'])) $members[] = ['full_name' => $p['secretary_name'], 'position' => 'Secretary', 'member_id' => null];

                if (!empty($p['member_name'])) $members[] = ['full_name' => $p['member_name'], 'position' => 'Member', 'member_id' => null];

            }

            if (empty($members)) {

                $members[] = ['full_name' => 'Chosen Pangkat Panel', 'position' => 'Panel', 'member_id' => null];

            }



            foreach ($members as $m) {

                $mName = $m['full_name'] ?? 'Pangkat Member';

                $pos = $m['position'] ?? 'Member';

                $mId = null; // Pangkat members are in users table; resident_id is nullable

                $remarks = $options['remarks'] ?? "KP Form 11 — Notice of Appointment served to chosen Pangkat member ({$pos})";



                $insDeliv->execute([

                    $hearingId,

                    $caseId,

                    'Pangkat Member',

                    $mId,

                    'KP Form 11',

                    $deliveryStatus,

                    $options['service_method'] ?? 'Personal Delivery',

                    $deliveryDate,

                    ($deliveryStatus === 'Served') ? ($deliveryDate ?: date('Y-m-d H:i:s')) : null,

                    $servingOfficer,

                    $servingOfficer,

                    $mName,

                    $documentId,

                    $userId,

                    $remarks

                ]);

                $createdDeliveries[] = [

                    'delivery_id' => (int) $this->conn->lastInsertId(),

                    'recipient' => $mName,

                    'party_type' => 'Pangkat Member',

                    'status' => $deliveryStatus,

                    'form_type' => 'KP Form 11'

                ];

            }

        } elseif (str_contains($code, '12')) {

            // KP Form 12: delivered to Complainants and Respondents, linked to initial Conciliation Hearing

            $concHearing = $caseData['conc_hearing'] ?? [];

            $assocHearingId = !empty($concHearing['hearing_id']) ? (int) $concHearing['hearing_id'] : $hearingId;



            // Link hearing to KP Form 12 document

            if ($assocHearingId) {

                $updH = $this->conn->prepare("UPDATE hearings SET kp12_document_id = ? WHERE hearing_id = ?");

                $updH->execute([$documentId, $assocHearingId]);

            }



            $parties = array_merge($caseData['complainants'], $caseData['respondents']);

            $chkDeliv = $this->conn->prepare("
                SELECT delivery_id FROM summon_deliveries
                WHERE hearing_id = ? AND party_type = ? AND (form_type = 'KP Form 12' OR form_type LIKE '%Notice%')
                LIMIT 1
            ");
            $updDeliv = $this->conn->prepare("
                UPDATE summon_deliveries
                SET document_id = COALESCE(document_id, ?),
                    form_type = 'KP Form 12',
                    remarks = COALESCE(?, remarks)
                WHERE delivery_id = ?
            ");

            foreach ($parties as $p) {
                $pType = $p['party_type'] ?? 'Respondent';
                $rId = (int) ($p['resident_id'] ?? 0) ?: null;
                $rName = $p['full_name'] ?? $pType;
                $remarks = $options['remarks'] ?? 'KP Form 12 — Notice of Hearing for Conciliation Proceedings';

                $chkDeliv->execute([$assocHearingId, $pType]);
                $existingDelivId = $chkDeliv->fetchColumn();

                if ($existingDelivId) {
                    $updDeliv->execute([$documentId, $remarks, $existingDelivId]);
                } else {
                    $insDeliv->execute([
                        $assocHearingId,
                        $caseId,
                        $pType,
                        $rId,
                        'KP Form 12',
                        $deliveryStatus,
                        $options['service_method'] ?? 'Personal Service',
                        $deliveryDate,
                        ($deliveryStatus === 'Served') ? ($deliveryDate ?: date('Y-m-d H:i:s')) : null,
                        $servingOfficer,
                        $servingOfficer,
                        $rName,
                        $documentId,
                        $userId,
                        $remarks
                    ]);
                }
            }

        } else {

            // General delivery record creation for other forms

            $parties = !empty($caseData['respondents']) ? $caseData['respondents'] : $caseData['complainants'];

            foreach ($parties as $p) {

                $pType = $p['party_type'] ?? 'Respondent';

                $rId = (int) ($p['resident_id'] ?? 0) ?: null;

                $rName = $p['full_name'] ?? $pType;



                $insDeliv->execute([

                    $hearingId,

                    $caseId,

                    $pType,

                    $rId,

                    $code,

                    $deliveryStatus,

                    $options['service_method'] ?? 'Personal Service',

                    $deliveryDate,

                    ($deliveryStatus === 'Served') ? ($deliveryDate ?: date('Y-m-d H:i:s')) : null,

                    $servingOfficer,

                    $servingOfficer,

                    $rName,

                    $documentId,

                    $userId,

                    $options['remarks'] ?? "{$code} Official Document Delivery"

                ]);

                $createdDeliveries[] = [

                    'delivery_id' => (int) $this->conn->lastInsertId(),

                    'recipient' => $rName,

                    'party_type' => $pType,

                    'status' => $deliveryStatus,

                    'form_type' => $code

                ];

            }

        }



        // 6. Audit Trail

        require_once __DIR__ . '/AuditService.php';

        $audit = new AuditService();

        $audit->log($userId, "Generated and persisted {$code}", 'Documents', $documentId);



        return [

            'success' => true,

            'message' => "{$code} generated and persisted successfully with associated delivery records.",

            'document_id' => $documentId,

            'file_path' => $relativePath,

            'full_path' => $absolutePath,

            'hearing_id' => $assocHearingId ?? $hearingId,

            'delivery_records' => $createdDeliveries

        ];

    }



    public function generateAndPersistKp10(int $caseId, int $userId, array $options = []): array

    {

        return $this->generateAndPersistForm('KP Form 10', $caseId, $userId, $options);

    }



    public function generateAndPersistKp11(int $caseId, int $userId, array $options = []): array

    {

        return $this->generateAndPersistForm('KP Form 11', $caseId, $userId, $options);

    }



    public function generateAndPersistKp12(int $caseId, int $userId, array $options = []): array

    {

        return $this->generateAndPersistForm('KP Form 12', $caseId, $userId, $options);

    }



    /**

     * Record Officer's Return for KP Form 12 and link to Conciliation Hearing.

     */

    public function recordOfficerReturnForKp12(int $documentId, array $data, int $actorUserId): array

    {

        $docStmt = $this->conn->prepare("

            SELECT gd.*, c.case_id, c.case_number, c.complaint_id

            FROM generated_documents gd

            INNER JOIN cases c ON c.case_id = gd.case_id

            WHERE gd.document_id = ?

        ");

        $docStmt->execute([$documentId]);

        $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {

            return ['success' => false, 'message' => "Generated document #{$documentId} not found."];

        }



        $caseId = (int) $doc['case_id'];



        // Find associated conciliation hearing

        $hStmt = $this->conn->prepare("

            SELECT hearing_id FROM hearings

            WHERE kp12_document_id = ?

            LIMIT 1

        ");

        $hStmt->execute([$documentId]);

        $hearingId = (int) $hStmt->fetchColumn();

        if (!$hearingId) {

            $hStmt = $this->conn->prepare("

                SELECT hearing_id FROM hearings

                WHERE case_id = ? AND hearing_type = 'Conciliation'

                ORDER BY hearing_date DESC LIMIT 1

            ");

            $hStmt->execute([$caseId]);

            $hearingId = (int) $hStmt->fetchColumn();

        }



        if ($hearingId) {

            $this->conn->prepare("UPDATE hearings SET kp12_document_id = ? WHERE hearing_id = ?")->execute([$documentId, $hearingId]);

        }



        $serviceResult = $data['service_result'] ?? 'Served';

        $serviceDate = !empty($data['service_date']) ? date('Y-m-d H:i:s', strtotime($data['service_date'])) : date('Y-m-d H:i:s');

        $officerReturn = trim((string) ($data['officer_return'] ?? "KP Form 12 Officer's Return recorded."));

        $reason = trim((string) ($data['reason'] ?? ''));

        $residentId = !empty($data['resident_id']) ? (int) $data['resident_id'] : null;

        $partyType = $data['party_type'] ?? 'Respondent';

        $proofFile = $data['proof_file_path'] ?? null;



        // If resident_id is not specified, get first resident of that party_type

        if (!$residentId) {

            $pStmt = $this->conn->prepare("

                SELECT cp.resident_id, TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name

                FROM complaint_parties cp

                INNER JOIN residents r ON r.resident_id = cp.resident_id

                WHERE cp.complaint_id = ? AND cp.party_type = ? LIMIT 1

            ");

            $pStmt->execute([(int) $doc['complaint_id'], $partyType]);

            $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);

            $residentId = !empty($pRow['resident_id']) ? (int) $pRow['resident_id'] : 1;

            $recipientName = $data['recipient_name'] ?? ($pRow['full_name'] ?? $partyType);

        } else {

            $recipientName = $data['recipient_name'] ?? $partyType;

        }



        $this->conn->beginTransaction();

        try {

            // 1. Insert into hearing_party_services if hearingId exists

            $serviceId = null;

            if ($hearingId) {

                $insHps = $this->conn->prepare("

                    INSERT INTO hearing_party_services (

                        hearing_id, resident_id, party_type, document_id, service_date,

                        service_result, reason, serving_officer, officer_return, supporting_file, assigned_to

                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)

                ");

                $allowedResults = ['Served', 'Not Found', 'Wrong Address', 'Refused', 'Unsuccessful Attempt'];

                $hpsResult = in_array($serviceResult, $allowedResults, true) ? $serviceResult : 'Served';

                $insHps->execute([

                    $hearingId,

                    $residentId,

                    $partyType,

                    $documentId,

                    $serviceDate,

                    $hpsResult,

                    $reason ?: null,

                    $actorUserId,

                    $officerReturn,

                    $proofFile,

                    $actorUserId

                ]);

                $serviceId = (int) $this->conn->lastInsertId();

            }



            // 2. Update or insert summon_deliveries

            $delivStatus = ($serviceResult === 'Served') ? 'Served' : 'Service Failed';

            $updDeliv = $this->conn->prepare("

                UPDATE summon_deliveries SET

                    hearing_id = COALESCE(hearing_id, ?),

                    delivery_status = ?,

                    served_at = ?,

                    attempted_at = ?,

                    served_by = ?,

                    served_by_user_id = ?,

                    remarks = ?,

                    proof_file_path = COALESCE(?, proof_file_path),

                    recipient_name = COALESCE(?, recipient_name)

                WHERE (document_id = ? OR (case_id = ? AND hearing_id = ? AND form_type = 'KP Form 12'))

                  AND party_type = ?

            ");

            $updDeliv->execute([

                $hearingId ?: null,

                $delivStatus,

                $serviceDate,

                $serviceDate,

                $actorUserId,

                $actorUserId,

                $officerReturn,

                $proofFile,

                $recipientName,

                $documentId,

                $caseId,

                $hearingId ?: 0,

                $partyType

            ]);



            if ($updDeliv->rowCount() === 0) {

                $insDeliv = $this->conn->prepare("

                    INSERT INTO summon_deliveries (

                        hearing_id, case_id, party_type, resident_id, form_type, delivery_status,

                        service_method, attempted_at, served_at, served_by, served_by_user_id,

                        recipient_name, document_id, created_by, remarks, proof_file_path

                    ) VALUES (?, ?, ?, ?, 'KP Form 12', ?, 'Personal Service', ?, ?, ?, ?, ?, ?, ?, ?, ?)

                ");

                $insDeliv->execute([

                    $hearingId ?: null,

                    $caseId,

                    $partyType,

                    $residentId,

                    $delivStatus,

                    $serviceDate,

                    $serviceDate,

                    $actorUserId,

                    $actorUserId,

                    $recipientName,

                    $documentId,

                    $actorUserId,

                    $officerReturn,

                    $proofFile

                ]);

            }



            // 3. Update generated_documents status

            $this->conn->prepare("UPDATE generated_documents SET service_status = ? WHERE document_id = ?")->execute([$delivStatus, $documentId]);



            // 4. Update proof_of_service for unified tracking

            $insPos = $this->conn->prepare("

                INSERT INTO proof_of_service (case_id, document_id, service_result, served_by, served_date, remarks, image_path)

                VALUES (?, ?, ?, ?, ?, ?, ?)

            ");

            $insPos->execute([

                $caseId,

                $documentId,

                $serviceResult,

                $actorUserId,

                $serviceDate,

                "KP Form 12 Return: {$officerReturn}",

                $proofFile

            ]);



            // 5. History & Audit

            $hist = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Conciliation', ?, ?)");

            $hist->execute([$caseId, "Officer's Return recorded for KP Form 12 ({$partyType}): {$serviceResult}. {$officerReturn}", $actorUserId]);



            require_once __DIR__ . '/AuditService.php';

            (new AuditService())->log($actorUserId, "Recorded Officer's Return for KP Form 12", 'Hearings', $hearingId ?: $caseId);



            $this->conn->commit();



            return [

                'success' => true,

                'message' => "Officer's Return recorded successfully for KP Form 12.",

                'service_id' => $serviceId,

                'hearing_id' => $hearingId,

                'document_id' => $documentId,

                'service_result' => $serviceResult,

                'delivery_status' => $delivStatus

            ];

        } catch (Throwable $e) {

            if ($this->conn->inTransaction()) {

                $this->conn->rollBack();

            }

            return ['success' => false, 'message' => "Failed to record Officer's Return: " . $e->getMessage()];

        }

    }

}

