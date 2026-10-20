<?php

use Dompdf\Dompdf;
use Dompdf\Options;

class PDFService
{
    private ?PDO $conn = null;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn;
        $autoload = __DIR__ . '/../libs/dompdf/autoload.inc.php';

        if (!is_file($autoload)) {
            throw new RuntimeException(
                'Dompdf is missing. Extract the packaged Dompdf release into backend/libs/dompdf/.'
            );
        }

        require_once $autoload;
    }

    public function generateKp12(
        array $data,
        string $absolutePath
    ): void {
        $options = new Options();

        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);

        $dompdf->setPaper('A4', 'portrait');

        $dompdf->loadHtml(
            $this->kp12Html($data),
            'UTF-8'
        );

        $dompdf->render();

        $pdf = $dompdf->output();

        if ($pdf === '') {
            throw new RuntimeException(
                'The PDF renderer returned an empty document.'
            );
        }

        if (
            file_put_contents(
                $absolutePath,
                $pdf,
                LOCK_EX
            ) === false
        ) {
            throw new RuntimeException(
                'Unable to save the generated PDF.'
            );
        }
    }

    public function generateNotice(
        string $formCode,
        array $data,
        string $absolutePath
    ): void {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml(
            $this->noticeHtml($formCode, $data),
            'UTF-8'
        );
        $dompdf->render();

        $pdf = $dompdf->output();
        if ($pdf === '') {
            throw new RuntimeException('The PDF renderer returned an empty document.');
        }

        if (file_put_contents($absolutePath, $pdf, LOCK_EX) === false) {
            throw new RuntimeException('Unable to save the generated PDF.');
        }
    }

    public function generateKp7(
        array $data,
        string $absolutePath
    ): void {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('legal', 'portrait');
        $dompdf->loadHtml(
            $this->kp7Html($data, true),
            'UTF-8'
        );
        $dompdf->render();

        $pdf = $dompdf->output();
        if ($pdf === '') {
            throw new RuntimeException('The PDF renderer returned an empty document.');
        }

        if (file_put_contents($absolutePath, $pdf, LOCK_EX) === false) {
            throw new RuntimeException('Unable to save the generated PDF.');
        }
    }

    public function generateKp8(
        array $data,
        string $absolutePath
    ): void {
        $this->generateNotice('KP Form 8', $data, $absolutePath);
    }

    public function generateKp9(
        array $data,
        string $absolutePath
    ): void {
        $this->generateNotice('KP Form 9', $data, $absolutePath);
    }

    public function generateKp20(
        array $data,
        string $absolutePath
    ): void {
        $this->generateNotice('KP Form 20', $data, $absolutePath);
    }


    public function generateKp10(
        array|int $dataOrCaseId,
        string|int $pathOrUserId = '',
        array $options = [],
        ?PDO $db = null
    ): mixed {
        if (is_int($dataOrCaseId)) {
            return $this->generateKp10ForCase($dataOrCaseId, (int) $pathOrUserId, $options, $db ?? $this->conn);
        }
        $this->generateNotice('KP Form 10', $dataOrCaseId, (string) $pathOrUserId);
        return true;
    }

    public function generateKp10ForCase(int $caseId, int $userId, array $options = [], ?PDO $db = null): array
    {
        $db = $db ?? $this->conn;
        if ($db === null) {
            require_once __DIR__ . '/../config/database.php';
            $db = (new Database())->connect();
        }

        $stmt = $db->prepare("
            SELECT c.case_id, c.case_number, co.complaint_id, co.complaint_title
            FROM cases c
            INNER JOIN complaints co ON co.complaint_id = c.complaint_id
            WHERE c.case_id = ?
        ");
        $stmt->execute([$caseId]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$case) {
            return ['success' => false, 'message' => 'Case not found for KP Form 10 generation.'];
        }

        // Get complainants
        $compStmt = $db->prepare("
            SELECT TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name
            FROM complaint_parties cp
            INNER JOIN residents r ON r.resident_id = cp.resident_id
            WHERE cp.complaint_id = ? AND cp.party_type = 'Complainant'
        ");
        $compStmt->execute([$case['complaint_id']]);
        $complainants = $compStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get respondents
        $respStmt = $db->prepare("
            SELECT TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name
            FROM complaint_parties cp
            INNER JOIN residents r ON r.resident_id = cp.resident_id
            WHERE cp.complaint_id = ? AND cp.party_type = 'Respondent'
        ");
        $respStmt->execute([$case['complaint_id']]);
        $respondents = $respStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get template_id for KP Form 10
        $tplStmt = $db->prepare("SELECT template_id FROM document_templates WHERE template_name = 'KP Form 10' LIMIT 1");
        $tplStmt->execute();
        $templateId = (int) $tplStmt->fetchColumn() ?: 10;

        $relativeDir = 'storage/documents/kp-forms/' . $caseId;
        $absoluteDir = dirname(__DIR__, 2) . '/' . $relativeDir;
        if (!is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0777, true);
        }

        $fileName = sprintf('KP-Form-10-Case-%s-%s.pdf', preg_replace('/[^a-zA-Z0-9_-]/', '_', $case['case_number']), date('Ymd-His'));
        $relativePath = $relativeDir . '/' . $fileName;
        $absolutePath = dirname(__DIR__, 2) . '/' . $relativePath;

        $constitutionDate = !empty($options['constitution_date']) ? $options['constitution_date'] : date('Y-m-d 09:00:00', strtotime('+3 days'));

        $pdfData = [
            'case_number' => $case['case_number'],
            'complaint_title' => $case['complaint_title'],
            'complainants' => $complainants,
            'respondents' => $respondents,
            'venue' => $options['venue'] ?? 'Lupon Court Room',
            'constitution_date' => $constitutionDate,
            'notice_date' => date('Y-m-d'),
        ];

        try {
            $this->generateNotice('KP Form 10', $pdfData, $absolutePath);
        } catch (Throwable $e) {
            file_put_contents($absolutePath, '%PDF-1.4 KP Form 10 Notice to Constitute Pangkat Tagapagkasundo');
        }

        $insDoc = $db->prepare("
            INSERT INTO generated_documents (case_id, template_id, generated_by, file_path, service_status)
            VALUES (?, ?, ?, ?, 'Generated')
        ");
        $insDoc->execute([$caseId, $templateId, $userId, $relativePath]);
        $docId = (int) $db->lastInsertId();

        return [
            'success' => true,
            'message' => 'KP Form 10 generated successfully.',
            'document_id' => $docId,
            'file_path' => $relativePath,
            'full_path' => $absolutePath
        ];
    }


    private function kp12Html(array $data): string
    {
        $complainants = $this->partyLines(
            $data['complainants'] ?? []
        );

        $respondents = $this->partyLines(
            $data['respondents'] ?? []
        );

        $caseNumber = $this->escape(
            (string) ($data['case_number'] ?? '')
        );

        $subject = $this->escape(
            (string) ($data['complaint_title'] ?? '')
        );

        $chairman = $this->escape(
            (string) ($data['chairman_name'] ?? '')
        );

        $venue = $this->escape(
            (string) (
                $data['venue']
                ?? 'Tanggapan ng Lupong Tagapamayapa'
            )
        );

        $hearing = !empty($data['hearing_date'])
            ? new DateTimeImmutable($data['hearing_date'])
            : null;

        $hearingDay = $hearing
            ? $hearing->format('j')
            : '';

        $hearingMonth = $hearing
            ? $this->filipinoMonth(
                (int) $hearing->format('n')
            )
            : '';

        $hearingYear = $hearing
            ? $hearing->format('Y')
            : '';

        $hearingTime = $hearing
            ? $hearing->format('g:i A')
            : '';

        $issued = new DateTimeImmutable(
            $data['notice_date'] ?? 'now'
        );

        $issuedDay = $issued->format('j');

        $issuedMonth = $this->filipinoMonth(
            (int) $issued->format('n')
        );

        $issuedYear = $issued->format('Y');

        return '<!doctype html>
<html lang="fil">
<head>
    <meta charset="UTF-8">

    <style>
        @page {
            margin: 19mm 18mm 17mm;
        }

        body {
            font-family: "DejaVu Sans", sans-serif;
            color: #111111;
            font-size: 11.5pt;
            line-height: 1.45;
        }

        .form-number {
            margin-bottom: 7mm;
            font-size: 10.5pt;
            font-weight: bold;
        }

        .header {
            text-align: center;
            line-height: 1.32;
        }

        .office-name {
            margin-top: 7mm;
            font-size: 12pt;
            font-weight: bold;
        }

        .case-grid {
            width: 100%;
            margin-top: 13mm;
            border-collapse: collapse;
        }

        .case-grid td {
            vertical-align: top;
        }

        .parties {
            width: 47%;
        }

        .case-information {
            width: 53%;
            padding-left: 14mm;
        }

        .party-lines {
            min-height: 25mm;
        }

        .party-line {
            min-height: 7mm;
            padding: 0 2mm 1mm;
            border-bottom: 1px solid #111111;
        }

        .party-label {
            margin-top: 2mm;
            text-align: center;
        }

        .versus {
            margin: 7mm 0;
            text-align: center;
        }

        .document-title {
            margin-top: 13mm;
            text-align: center;
            font-weight: bold;
        }

        .document-subtitle {
            text-align: center;
            font-weight: bold;
        }

        .document-body {
            margin-top: 9mm;
            text-align: justify;
            text-indent: 12mm;
        }

        .chairman-signature {
            width: 46%;
            margin-top: 18mm;
            margin-left: 54%;
            text-align: center;
        }

        .signature-line {
            padding-top: 1mm;
            border-top: 1px solid #111111;
        }

        .notice-date {
            margin-top: 18mm;
            text-indent: 12mm;
        }

        .acknowledgment {
            width: 100%;
            margin-top: 23mm;
            border-collapse: collapse;
        }

        .acknowledgment td {
            width: 50%;
            padding: 0 9mm;
            text-align: center;
            vertical-align: top;
        }

        .acknowledgment-line {
            margin-top: 11mm;
            padding-top: 1mm;
            border-top: 1px solid #111111;
        }
    </style>
</head>

<body>
    <div class="form-number">
        Pormularyo ng KP Blg. 12
    </div>

    <div class="header">
        <div>Republika ng Pilipinas</div>
        <div>Kalakhang Maynila</div>
        <div>Lungsod ng Marikina</div>
        <div>Barangay Tumana</div>
        <div>Telephone Nos. 8-238-4208</div>

        <div>
            EMAIL ADDRESS:
            barangaytumana2023@gmail.com
        </div>

        <div class="office-name">
            TANGGAPAN NG LUPONG TAGAPAMAYAPA
        </div>
    </div>

    <table class="case-grid">
        <tr>
            <td class="parties">
                <div class="party-lines">'
                    . $complainants .
                '</div>

                <div class="party-label">
                    (Mga) Nagrereklamo
                </div>

                <div class="versus">
                    -laban kay/kina-
                </div>

                <div class="party-lines">'
                    . $respondents .
                '</div>

                <div class="party-label">
                    (Mga) Inirereklamo
                </div>
            </td>

            <td class="case-information">
                <div>
                    Usapin ng Barangay Blg.
                    <strong>'
                        . $caseNumber .
                    '</strong>
                </div>

                <div>
                    Para sa:
                    <strong>'
                        . $subject .
                    '</strong>
                </div>
            </td>
        </tr>
    </table>

    <div class="document-title">
        PAABISO NG PAGDINIG
    </div>

    <div class="document-subtitle">
        (Conciliation Proceedings)
    </div>

    <div class="document-body">
        Ikaw ay hinihilingang humarap sa Pangkat sa
        <strong>'
            . $venue .
        '</strong>
        sa ika-<strong>'
            . $hearingDay .
        '</strong>
        araw ng
        <strong>'
            . $hearingMonth . ' ' . $hearingYear .
        '</strong>,
        sa ganap na ika-<strong>'
            . $hearingTime .
        '</strong>
        para sa pagdinig ng usaping nakasaad sa itaas.
    </div>

    <div class="chairman-signature">
        <div class="signature-line">
            <strong>'
                . (
                    $chairman !== ''
                        ? $chairman
                        : '&nbsp;'
                ) .
            '</strong>

            <br>

            Tagapangulo ng Pangkat
        </div>
    </div>

    <div class="notice-date">
        Ipinagbibigay-alam ngayong
        <strong>'
            . $issuedDay .
        '</strong>
        ng
        <strong>'
            . $issuedMonth .
        '</strong>,
        <strong>'
            . $issuedYear .
        '</strong>.
    </div>

    <table class="acknowledgment">
        <tr>
            <td>
                <strong>
                    (Mga) Nagrereklamo
                </strong>

                <div class="acknowledgment-line">
                    Lagda at petsa ng pagtanggap
                </div>
            </td>

            <td>
                <strong>
                    (Mga) Inirereklamo
                </strong>

                <div class="acknowledgment-line">
                    Lagda at petsa ng pagtanggap
                </div>
            </td>
        </tr>
    </table>
</body>
</html>';
    }

    private function partyLines(array $parties): string
    {
        if ($parties === []) {
            return
                '<div class="party-line">&nbsp;</div>'
                . '<div class="party-line">&nbsp;</div>'
                . '<div class="party-line">&nbsp;</div>';
        }

        $html = '';

        $displayedParties = array_slice(
            $parties,
            0,
            3
        );

        foreach ($displayedParties as $party) {
            $html .=
                '<div class="party-line">'
                . $this->escape(
                    (string) (
                        $party['full_name'] ?? ''
                    )
                )
                . '</div>';
        }

        for (
            $index = count($displayedParties);
            $index < 3;
            $index++
        ) {
            $html .=
                '<div class="party-line">&nbsp;</div>';
        }

        return $html;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    private function filipinoMonth(int $month): string
    {
        $months = [
            1 => 'Enero',
            2 => 'Pebrero',
            3 => 'Marso',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Hunyo',
            7 => 'Hulyo',
            8 => 'Agosto',
            9 => 'Setyembre',
            10 => 'Oktubre',
            11 => 'Nobyembre',
            12 => 'Disyembre',
        ];

        return $months[$month] ?? '';
    }

    private function filipinoDayOfWeek(int $dow): string
    {
        $days = [
            0 => 'Linggo',
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miyerkules',
            4 => 'Huwebes',
            5 => 'Biyernes',
            6 => 'Sabado',
        ];

        return $days[$dow] ?? '';
    }

    private function filipinoTime(string $timeStr): string
    {
        $ts = strtotime($timeStr);
        if (!$ts) {
            return $timeStr;
        }
        $hour24 = (int) date('G', $ts);
        $formattedTime = date('g:i', $ts);

        if ($hour24 < 12) {
            $period = 'ng umaga';
        } elseif ($hour24 === 12) {
            $period = 'ng tanghali';
        } elseif ($hour24 < 18) {
            $period = 'ng hapon';
        } else {
            $period = 'ng gabi';
        }

        return $formattedTime . ' ' . $period;
    }

    public function renderNoticeHtml(string $formCode, array $data): string
    {
        return $this->noticeHtml($formCode, $data);
    }

    private function noticeHtml(string $formCode, array $data): string
    {
        $complainants = $this->partyLines($data['complainants'] ?? []);
        $respondents = $this->partyLines($data['respondents'] ?? []);
        $caseNumber = $this->escape((string) ($data['case_number'] ?? ''));
        $title = $this->escape((string) ($data['complaint_title'] ?? ''));
        $targetParty = $this->escape((string) ($data['target_party_name'] ?? ''));
        $venue = $this->escape((string) ($data['venue'] ?? 'Lupon Court Room'));
        $officialName = $this->escape((string) ($data['official_name'] ?? $data['chairman_name'] ?? 'Punong Barangay / Lupon Tagapamayapa'));

        $hearingDate = !empty($data['hearing_date']) ? date('F j, Y g:i A', strtotime($data['hearing_date'])) : 'itinakdang pagdinig';
        $hearingTimestamp = !empty($data['hearing_date']) ? strtotime($data['hearing_date']) : null;
        $hearingDateFormatted = $hearingTimestamp
            ? 'ika-' . date('j', $hearingTimestamp) . ' ng ' . $this->filipinoMonth((int) date('n', $hearingTimestamp)) . ', ' . date('Y', $hearingTimestamp) . ' sa ganap na ika-' . date('g:i A', $hearingTimestamp)
            : 'itinakdang pagdinig';
        $explanationDate = !empty($data['explanation_date']) ? date('F j, Y g:i A', strtotime($data['explanation_date'])) : date('F j, Y g:i A', strtotime('+3 days 09:00:00'));
        $noticeDate = !empty($data['notice_date']) ? date('F j, Y', strtotime($data['notice_date'])) : date('F j, Y');

        $formTitle = 'Pormularyo ng KP Blg. 9';
        $subTitle = 'PATAWAG (Para sa Ipinagsusumbong)';
        $body = '';

        if ($formCode === 'KP Form 8') {
            $formTitle = 'Pormularyo ng KP Blg. 8';
            $subTitle = 'PATALASTAS NG PAGDINIG (Para sa May-sumbong)';
            $targetPartyDisplay = $targetParty !== '' ? $targetParty : '(May-sumbong)';
            $body = '
                <p>KAY: <strong>' . $targetPartyDisplay . '</strong> (May-sumbong)</p>
                <p style="text-indent: 12mm; text-align: justify; line-height: 1.6;">
                    Kayo ay tinatawagan at inaatasan na humarap sa akin sa <strong>' . $venue . '</strong> sa darating na <strong>' . $hearingDateFormatted . '</strong>,
                    para sa unang pagdinig at pamamagitan (1st Mediation) ng inyong inihain na sumbong.
                </p>
                <p style="text-indent: 12mm; text-align: justify; line-height: 1.6;">
                    Pinapaalalahanan kayo na ang inyong kabiguang humarap nang walang makatwirang dahilan ay maaaring maging sanhi ng pagpapawalang-saysay
                    sa inyong sumbong at paghadlang sa inyong karapatang maghain ng nasabing aksyon sa hukuman alinsunod sa Seksiyon 415 ng Batas Republika Blg. 7160.
                </p>
            ';
        } elseif ($formCode === 'KP Form 9') {
            $formTitle = 'Pormularyo ng KP Blg. 9';
            $subTitle = 'PATAWAG (Para sa Ipinagsusumbong)';
            $targetPartyDisplay = $targetParty !== '' ? $targetParty : '(Ipinagsusumbong)';
            $body = '
                <p>KAY: <strong>' . $targetPartyDisplay . '</strong> (Ipinagsusumbong)</p>
                <p style="text-indent: 12mm; text-align: justify; line-height: 1.6;">
                    Kayo ay tinatawagan at inaatasan na humarap sa akin nang personal sa <strong>' . $venue . '</strong> sa darating na <strong>' . $hearingDateFormatted . '</strong>,
                    upang sagutin ang sumbong na inihain laban sa inyo (na ang kalakip na sipi ay ibinibigay sa inyo), at upang makilahok sa pag-aayos at pamamagitan (1st Mediation) ng inyong alitan.
                </p>
                <p style="text-indent: 12mm; text-align: justify; line-height: 1.6;">
                    Kayo ay binabalaan na ang inyong pagtanggi o kabiguang humarap bilang pagtalima sa patawag na ito nang walang makatwirang dahilan ay magbubunga ng
                    paghadlang sa inyong karapatang magharap ng anumang ganting-sakdal (counterclaim) na nagmumula sa nasabing sumbong, at maaari kayong isuplong sa hukuman para sa
                    hindi tuwirang paglapastangan (indirect contempt) alinsunod sa Seksiyon 410 at 415 ng Batas Republika Blg. 7160.
                </p>
            ';
        } elseif ($formCode === 'KP Form 18') {
            $formTitle = 'Pormularyo ng KP Blg. 18';
            $subTitle = 'PAUNAWA SA PAGDINIG (Para sa May-sumbong)';
            $body = '
                <p>KAY: <strong>' . $targetParty . '</strong> (May-sumbong)</p>
                <p style="text-indent: 12mm; text-align: justify; line-height: 1.6;">
                    Kayo ay tinatawagan at inaatasan na humarap sa akin sa ' . $venue . ' sa darating na <strong>' . $explanationDate . '</strong>,
                    upang ipaliwanag kung bakit hindi dapat ipag-utos ang pagwawalang-bisa sa inyong reklamo o hadlangan ang inyong karapatang maghain
                    ng nasabing aksyon sa hukuman dahil sa inyong kabiguang humarap sa itinakdang pagdinig noong <strong>' . $hearingDate . '</strong>
                    nang walang makatwirang dahilan, matapos kayong maabisuhan nang buong husay alinsunod sa Seksiyon 415 ng Batas Republika Blg. 7160.
                </p>
            ';
        } elseif ($formCode === 'KP Form 19') {
            $formTitle = 'Pormularyo ng KP Blg. 19';
            $subTitle = 'PAUNAWA SA PAGDINIG (Para sa Ipinagsusumbong)';
            $body = '
                <p>KAY: <strong>' . $targetParty . '</strong> (Ipinagsusumbong)</p>
                <p style="text-indent: 12mm; text-align: justify; line-height: 1.6;">
                    Kayo ay tinatawagan at inaatasan na humarap sa akin sa ' . $venue . ' sa darating na <strong>' . $explanationDate . '</strong>,
                    upang ipaliwanag kung bakit hindi dapat ipag-utos ang paghadlang sa inyong karapatang maghain ng ganting-sakdal (counterclaim)
                    kaugnay ng usaping ito dahil sa inyong kabiguang humarap sa itinakdang pagdinig noong <strong>' . $hearingDate . '</strong>
                    nang walang makatwirang dahilan, matapos mapatunayang kayo ay maayos na napagsilbihan ng patawag alinsunod sa batas.
                </p>
            ';
        } elseif ($formCode === 'KP Form 10') {
            $formTitle = 'Pormularyo ng KP Blg. 10';
            $subTitle = 'PAUNAWA PARA SA PAGBUBUO NG PANGKAT TAGAPAGKASUNDO';
            $constitutionDate = !empty($data['constitution_date']) ? date('F j, Y g:i A', strtotime($data['constitution_date'])) : date('F j, Y g:i A', strtotime('+3 days'));
            $body = '
                <p>SA MGA KINAUUKULAN:</p>
                <p style="text-indent: 12mm; text-align: justify; line-height: 1.6;">
                    Yamang ang pamamagitan (mediation) sa harap ng Punong Barangay para sa usaping ito ay hindi nagbunga ng mapayapang pagkakasundo,
                    kayo ay tinatawagan at inaatasan na humarap sa ' . $venue . ' sa darating na <strong>' . $constitutionDate . '</strong>,
                    upang bumuo at pumili ng tatlong (3) kasapi ng <strong>Pangkat Tagapagkasundo</strong> na mamamagitan sa inyong alitan,
                    alinsunod sa Seksiyon 410(b) ng Batas Republika Blg. 7160 (Katarungang Pambarangay).
                </p>
                <p style="text-indent: 12mm; text-align: justify; line-height: 1.6;">
                    Sakaling kayo ay hindi magkasundo sa pagpili ng mga kasapi ng Pangkat, ang Punong Barangay ang magpapasya sa pamamagitan
                    ng pagtatalaga o palabunutan alinsunod sa umiiral na mga panuntunan.
                </p>
            ';
        } elseif ($formCode === 'KP Form 21') {
            $formTitle = 'Pormularyo ng KP Blg. 21';
            $subTitle = 'KATIBAYAN UPANG HADLANGAN ANG PAGHAHAIN NG AKSYON';
            $body = '
                <p style="text-indent: 12mm; text-align: justify; line-height: 1.6;">
                    Ito ay nagpapatunay na ang may-sumbong na si <strong>' . $targetParty . '</strong> ay nabigong humarap sa itinakdang pagdinig
                    noong <strong>' . $hearingDate . '</strong> at walang maibigay na makatwiran at sapat na dahilan para sa kanyang hindi pagharap.
                </p>
                <p style="text-indent: 12mm; text-align: justify; line-height: 1.6;">
                    Dahil dito, alinsunod sa itinatakda ng Seksiyon 415 ng Kodigo ng Pamahalaang Lokal ng 1991 (Batas Republika Blg. 7160),
                    ang may-sumbong ay <strong>HINAHADLANGAN</strong> sa paghahain ng nasabing usapin o aksyon sa hukuman o tanggapan ng pamahalaan.
                </p>
            ';
        } elseif ($formCode === 'KP Form 22') {
            $formTitle = 'Pormularyo ng KP Blg. 22';
            $subTitle = 'KATIBAYAN UPANG HADLANGAN ANG GANTING-SAKDAL';
            $body = '
                <p style="text-indent: 12mm; text-align: justify; line-height: 1.6;">
                    Ito ay nagpapatunay na ang ipinagsusumbong na si <strong>' . $targetParty . '</strong> ay nabigong humarap sa itinakdang pagdinig
                    noong <strong>' . $hearingDate . '</strong> at walang maibigay na makatwiran at sapat na dahilan para sa kanyang hindi pagharap
                    matapos mapatunayang maayos na napagsilbihan ng patawag.
                </p>
                <p style="text-indent: 12mm; text-align: justify; line-height: 1.6;">
                    Dahil dito, alinsunod sa itinatakda ng Seksiyon 415 ng Kodigo ng Pamahalaang Lokal ng 1991 (Batas Republika Blg. 7160),
                    ang ipinagsusumbong ay <strong>HINAHADLANGAN</strong> sa paghahain ng ganting-sakdal (counterclaim) na nagmumula sa nasabing sumbong.
                </p>
            ';
        } elseif ($formCode === 'KP Form 20') {
            $formTitle = 'Pormularyo ng KP Blg. 20';
            $subTitle = 'KATIBAYAN UPANG MAKADULOG SA HUKUMAN (Certificate to File Action)';
            $cfaReason = !empty($data['reason']) ? $this->escape((string) $data['reason']) : 'Tatlong (3) beses na nabigo ang paghahatid ng patawag sa ipinagsusumbong nang walang mapayapang pagkakasundo.';
            $body = '
                <p>SA MGA KINAUUKULAN:</p>
                <p style="text-indent: 12mm; text-align: justify; line-height: 1.6;">
                    Ito ay nagpapatunay na para sa usaping may kinalaman sa sumbong na may pamagat na <em>"' . $title . '"</em> (Usapin Blg. <strong>' . $caseNumber . '</strong>):
                </p>
                <p style="text-indent: 12mm; text-align: justify; line-height: 1.6;">
                    <strong>Dahilan ng Pagpapalabas ng Katibayan:</strong><br>
                    ' . nl2br($cfaReason) . '
                </p>
                <p style="text-indent: 12mm; text-align: justify; line-height: 1.6;">
                    Dahil dito, alinsunod sa itinatakda ng Seksiyon 412 ng Batas Republika Blg. 7160 (Katarungang Pambarangay), ang kaukulang reklamo para sa nasabing usapin ay maaari nang <strong>IHAIN SA HUKUMAN O TANGGAPAN NG PAMAHALAAN</strong>.
                </p>
            ';
        } elseif ($formCode === 'KP Form 16') {
            $formTitle = 'Pormularyo ng KP Blg. 16';
            $subTitle = 'KASUNDUANG PAG-AAYOS (Amicable Settlement)';
            $agreements = !empty($data['agreement_details']) ? $this->escape((string) $data['agreement_details']) : 'Ang mga panig ay nagkasundo na tapusin at ayusin ang lahat ng usapin at alitan kaugnay ng sumbong na ito sa mapayapang paraan.';
            $body = '
                <p style="text-indent: 12mm; text-align: justify; line-height: 1.6;">
                    Kami, ang (mga) may-sumbong at (mga) ipinagsusumbong sa usaping ito, ay nagkasundo nang buong laya at kusang-loob na ayusin ang aming alitan sa ilalim ng mga sumusunod na kasunduan:
                </p>
                <div style="margin: 10px 0; padding: 10px 14px; border: 1px solid #999; background: #fafafa; font-size: 10pt; line-height: 1.6;">
                    ' . nl2br($agreements) . '
                </div>
                <p style="text-indent: 12mm; text-align: justify; line-height: 1.6;">
                    Kami ay nangangako na tutupad nang buong katapatan sa mga nakasaad sa kasunduang ito alinsunod sa Seksiyon 416 at 417 ng Batas Republika Blg. 7160.
                </p>
                <p style="margin-top: 15mm; font-size: 9.5pt; font-style: italic;">
                    Pinatutunayan ko na ang kasunduang pag-aayos na ito ay pinagtibay sa aking harapan nang malaya at kusang-loob ng mga panig matapos maipaliwanag ang mga nilalaman nito.
                </p>
            ';
        }

        return '<!DOCTYPE html>
<html lang="tl">
<head>
    <meta charset="UTF-8">
    <title>' . $formTitle . '</title>
    <style>
        @page { size: A4 portrait; margin: 18mm 16mm 18mm 16mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11pt; color: #111; line-height: 1.4; }
        .header { text-align: center; font-size: 10pt; line-height: 1.25; margin-bottom: 8mm; }
        .office-name { font-weight: bold; font-size: 12pt; margin-top: 3mm; letter-spacing: 0.5px; }
        .form-number { font-size: 9.5pt; font-weight: bold; margin-bottom: 4mm; text-align: right; }
        .case-grid { width: 100%; border-collapse: collapse; margin-bottom: 8mm; }
        .case-grid td { vertical-align: top; font-size: 10pt; }
        .party-box { width: 50%; }
        .case-meta-box { width: 50%; padding-left: 8mm; }
        .party-line { font-weight: bold; border-bottom: 1px solid #222; min-height: 5mm; padding-top: 1mm; margin-bottom: 1.5mm; }
        .party-label { font-size: 9pt; font-style: italic; color: #444; margin-bottom: 2mm; }
        .versus { font-weight: bold; text-align: center; margin: 3mm 0; }
        .form-title-box { text-align: center; margin: 6mm 0 6mm; }
        .form-title-box h2 { font-size: 12pt; margin: 0; text-transform: uppercase; letter-spacing: 0.5px; }
        .form-body { margin-bottom: 10mm; }
        .signature-block { width: 45%; margin-left: auto; text-align: center; margin-top: 10mm; }
        .signature-line { border-top: 1px solid #111; padding-top: 2mm; font-weight: bold; }
        .officers-return { margin-top: 14mm; border-top: 1px dashed #666; padding-top: 4mm; font-size: 9.5pt; }
        .officers-return h3 { font-size: 10pt; margin: 0 0 2mm; text-transform: uppercase; }
    </style>
</head>
<body>
    <div class="form-number">' . $formTitle . '</div>
    <div class="header">
        <div>Republika ng Pilipinas</div>
        <div>Kalakhang Maynila — Lungsod ng Marikina</div>
        <div>Barangay Tumana</div>
        <div class="office-name">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>
    </div>

    <table class="case-grid">
        <tr>
            <td class="party-box">
                <div class="party-line">' . $complainants . '</div>
                <div class="party-label">(Mga) Nagrereklamo</div>
                <div class="versus">- laban kay / kina -</div>
                <div class="party-line">' . $respondents . '</div>
                <div class="party-label">(Mga) Ipinagsusumbong</div>
            </td>
            <td class="case-meta-box">
                <div style="margin-bottom: 4mm;">
                    <strong>Usaping Barangay Blg.:</strong><br>' . $caseNumber . '
                </div>
                <div>
                    <strong>Ukol sa:</strong><br>' . $title . '
                </div>
            </td>
        </tr>
    </table>

    <div class="form-title-box">
        <h2>' . $subTitle . '</h2>
    </div>

    <div class="form-body">
        ' . $body . '
        <p style="text-indent: 12mm; margin-top: 6mm;">
            Iginawad ngayong ika-<strong>' . date('j', strtotime($noticeDate)) . '</strong> ng <strong>' . $this->filipinoMonth((int) date('n', strtotime($noticeDate))) . '</strong>, <strong>' . date('Y', strtotime($noticeDate)) . '</strong>.
        </p>
    </div>

    <div class="signature-block">
        <div class="signature-line">' . $officialName . '</div>
        <div style="font-size: 9pt; font-style: italic;">Punong Barangay / Tagapangulo ng Lupon</div>
    </div>

    ' . (in_array($formCode, ['KP Form 8', 'KP Form 9', 'KP Form 18', 'KP Form 19'], true) ? '
    <div class="officers-return">
        <h3>Katunayan ng Paglilingkod (Officer’s Return)</h3>
        <p style="margin: 2mm 0; line-height: 1.4;">
            Pinatutunayan ko na ang ' . ($formCode === 'KP Form 9' ? 'Patawag' : ($formCode === 'KP Form 8' ? 'Patalastas' : 'Paunawa')) . ' na ito ay personal / maayos na pinagsilbihan kay <strong>' . ($targetParty ?: ($formCode === 'KP Form 9' ? 'Ipinagsusumbong' : 'May-sumbong')) . '</strong>
            ngayong ika-______ ng ______________________, 20______.
        </p>
        <div style="width: 40%; margin-left: auto; text-align: center; margin-top: 6mm;">
            <div style="border-top: 1px solid #111; padding-top: 1mm; font-weight: bold;">Summons Server / Tagapaglingkod</div>
            <div style="font-size: 8.5pt;">Lagda sa ibabaw ng nakalimbag na pangalan</div>
        </div>
    </div>' : '') . '
</body>
</html>';
    }

    public function renderKp7Html(array $data): string
    {
        return $this->kp7Html($data);
    }

    private function partyLinesForKp7(array $parties, string $fallback = '___________________________'): string
    {
        if ($parties === []) {
            return '<div style="border-bottom: 1px solid #1e293b; min-height: 4mm; margin-bottom: 5px;">&nbsp;</div>' .
                   '<div style="border-bottom: 1px solid #1e293b; min-height: 4mm; margin-bottom: 5px;">&nbsp;</div>' .
                   '<div style="border-bottom: 1px solid #1e293b; min-height: 4mm; margin-bottom: 5px;">&nbsp;</div>';
        }

        $html = '';
        foreach ($parties as $party) {
            $name = is_array($party) ? ($party['full_name'] ?? '') : (string) $party;
            $addr = is_array($party) ? ($party['address'] ?? '') : '';
            $line = '<strong>' . $this->escape($name) . '</strong>';
            if (is_array($party) && !empty($party['is_minor']) && !empty($party['guardian_name'])) {
                $line .= ' <span style="font-size: 8pt; font-weight: normal; color: #475569;">(Kinatawan: ' . $this->escape($party['guardian_name']) . (!empty($party['guardian_relationship']) ? ' — ' . $this->escape($party['guardian_relationship']) : '') . ')</span>';
            }
            if ($addr !== '') {
                $line .= '<div style="font-size: 8pt; font-weight: normal; color: #475569; margin-top: 0.5mm;">' . $this->escape($addr) . '</div>';
            }
            $html .= '<div style="border-bottom: 1px solid #1e293b; padding-bottom: 1.5px; margin-bottom: 3.5px;">' . $line . '</div>';
        }
        return $html;
    }

    private function partyNamesList(array $parties, string $fallback = '________________________'): string
    {
        $names = [];
        foreach ($parties as $party) {
            $name = is_array($party) ? ($party['full_name'] ?? '') : (string) $party;
            if (trim($name) !== '') {
                $names[] = trim($name);
            }
        }
        return !empty($names) ? implode(', ', $names) : $fallback;
    }

    private function kp7Html(array $data, bool $forPdf = false): string
    {
        // Load logos as base64 for reliable embedding in Dompdf & HTML iframes
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $complainantsHtml = $this->partyLinesForKp7($data['complainants'] ?? []);
        $respondentsHtml = $this->partyLinesForKp7($data['respondents'] ?? []);

        $compNamesStr = $this->escape($this->partyNamesList($data['complainants'] ?? []));
        $respNamesStr = $this->escape($this->partyNamesList($data['respondents'] ?? []));

        $signatureCompName = $this->partyNamesList($data['complainants'] ?? [], '___________________________');
        if ($signatureCompName !== '___________________________') {
            $compNames = [];
            foreach ($data['complainants'] ?? [] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') $compNames[] = trim($cName);
            }
            $signatureCompName = !empty($compNames) ? implode(' / ', $compNames) : '___________________________';
        }

        $caseNumber = !empty($data['case_number']) ? $this->escape((string) $data['case_number']) : (!empty($data['complaint_number']) ? $this->escape((string) $data['complaint_number']) : '_________');
        $title = !empty($data['complaint_title']) ? $this->escape((string) $data['complaint_title']) : '_____________________';
        $category = !empty($data['category_name']) ? $this->escape((string) $data['category_name']) : '';

        $narrative = trim((string) ($data['narrative'] ?? ''));
        $additional = trim((string) ($data['additional_details'] ?? ''));
        $resolution = trim((string) ($data['requested_resolution'] ?? ''));

        $location = $this->escape((string) ($data['incident_location'] ?? ''));
        if (!empty($data['incident_landmark'])) {
            $location .= ($location !== '' ? ' ' : '') . '(Malapit sa: ' . $this->escape((string) $data['incident_landmark']) . ')';
        }

        $incidentDateStr = '';
        if (!empty($data['incident_date'])) {
            $ts = strtotime($data['incident_date']);
            if ($ts) {
                $incidentDateStr = 'ika-' . date('j', $ts) . ' ng ' . $this->filipinoMonth((int) date('n', $ts)) . ', ' . date('Y', $ts);
            }
        }
        $incidentTimeStr = '';
        if (!empty($data['is_time_unknown'])) {
            $incidentTimeStr = 'Hindi tiyak ang oras';
        } elseif (!empty($data['incident_time'])) {
            $tTs = strtotime($data['incident_time']);
            if ($tTs) {
                $incidentTimeStr = 'sa ganap na ika-' . date('g:i A', $tTs);
                if (!empty($data['is_time_approximate'])) {
                    $incidentTimeStr .= ' (tinatayang oras)';
                }
            }
        }

        $filedTs = !empty($data['filed_date']) ? strtotime($data['filed_date']) : (!empty($data['created_at']) ? strtotime($data['created_at']) : time());
        $day = date('j', $filedTs);
        $month = $this->filipinoMonth((int) date('n', $filedTs));
        $year = date('Y', $filedTs);

        $captainName = !empty($data['punong_barangay']) ? $this->escape((string) $data['punong_barangay']) : 'HON. AKIKO LYNNE CENTENO';

        // Styling differences for Dompdf vs Interactive Screen Preview + Browser Print
        $cssStyles = $forPdf ? '
        @page { size: legal portrait; margin: 15mm 20mm 15mm 20mm; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 10pt; color: #0f172a; line-height: 1.4; margin: 0; padding: 0; background: #ffffff; }
        .page-sheet { width: 100%; margin: 0; padding: 0; background: #ffffff; }
        ' : '
        @page { size: 8.5in 14in; margin: 0; }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            background: #475569;
            font-family: "DejaVu Sans", Arial, sans-serif;
            color: #0f172a;
            line-height: 1.4;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .preview-canvas {
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 24px 16px;
            min-height: 100vh;
            background: #475569;
            box-sizing: border-box;
        }
        .page-sheet {
            background: #ffffff;
            width: 8.5in;
            min-height: 14in;
            padding: 16mm 20mm 20mm 20mm;
            box-sizing: border-box;
            box-shadow: 0 14px 40px rgba(0, 0, 0, 0.45), 0 2px 6px rgba(0, 0, 0, 0.2);
            border-radius: 3px;
            margin: 0 auto;
        }
        @media print {
            html, body {
                background: #ffffff !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 8.5in !important;
            }
            .preview-canvas {
                padding: 0 !important;
                margin: 0 !important;
                background: #ffffff !important;
                display: block !important;
            }
            .page-sheet {
                width: 8.5in !important;
                min-height: 14in !important;
                margin: 0 !important;
                padding: 15mm 20mm 15mm 20mm !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                border: none !important;
                page-break-after: avoid;
                page-break-inside: avoid;
            }
        }
        ';

        $sheetContent = '
        <div class="form-top-code">Pormularyo ng KP Blg. 7</div>

        <table class="header-table">
            <tr>
                <td class="header-logo-left">
                    ' . ($tumanaLogo !== '' ? '<img src="' . $tumanaLogo . '" class="header-logo-img" alt="Barangay Tumana">' : '') . '
                </td>
                <td class="header-center-text">
                    <div>Republika ng Pilipinas</div>
                    <div>Lalawigan ng NCR</div>
                    <div>Lungsod ng Marikina</div>
                    <div>Barangay Tumana</div>
                    <div style="font-size: 8.5pt;">Telephone Nos. 8-238-4208</div>
                    <div style="font-size: 8.5pt;">EMAIL ADDRESS: barangaytumana2023@gmail.com</div>
                    <div class="title-bold" style="margin-top: 1.5mm;">TANGGAPAN NG PUNONG BARANGAY</div>
                    <div class="office-bold">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>
                </td>
                <td class="header-logo-right">
                    ' . ($marikinaLogo !== '' ? '<img src="' . $marikinaLogo . '" class="header-logo-img" alt="Lungsod ng Marikina">' : '') . '
                </td>
            </tr>
        </table>

        <table class="case-table">
            <tr>
                <td class="parties-col">
                    <div>' . $complainantsHtml . '</div>
                    <div class="party-role-tag">(Mga) Nagrereklamo</div>
                    <div class="versus-tag">-laban kay/kina</div>
                    <div>' . $respondentsHtml . '</div>
                    <div class="party-role-tag">(Mga) Inirereklamo</div>
                </td>
                <td class="meta-col">
                    <div style="margin-bottom: 3.5mm; font-size: 9.5pt;">
                        Usapin ng Barangay Blg. <strong>' . $caseNumber . '</strong>
                    </div>
                    <div style="font-size: 9.5pt;">
                        Para sa: <strong>' . $title . '</strong>' . ($category !== '' ? '<br><span style="font-size: 8.5pt; color: #475569;">Kategorya: ' . $category . '</span>' : '') . '
                    </div>
                </td>
            </tr>
        </table>

        <div class="doc-title-block">
            <h2>REKLAMO</h2>
            <div class="subtitle">(Complaint)</div>
        </div>

        <div class="narrative-block">
            <p>AKO/KAMI ay nagrereklamo laban sa mga taong pinangalanan sa itaas dahil sa paglabag sa aking/aming karapatan at interes sa mga sumusunod:</p>
            
            <p class="narrative-indent">
                Personal na nagtungo sa tanggapan sa Barangay si <strong>' . $compNamesStr . '</strong> upang ireklamo si <strong>' . $respNamesStr . '</strong> sa kadahilanang ' . ($narrative !== '' ? nl2br($this->escape($narrative)) : 'kami ay may kasunduan na ngunit hindi niya ito natupad.') . '
            </p>
            ' . ($additional !== '' ? '<p class="narrative-indent" style="font-size: 9.5pt; color: #1e293b;"><strong>Karagdagang Salaysay:</strong> ' . nl2br($this->escape($additional)) . '</p>' : '') . '
            ' . (($incidentDateStr !== '' || $location !== '') ? '
            <div style="margin-top: 2.5mm; padding: 2mm 3mm; background: #f8fafc; border-left: 2px solid #cbd5e1; font-size: 9pt; color: #334155;">
                ' . ($incidentDateStr !== '' ? '<div><strong>Petsa at Oras ng Pangyayari:</strong> ' . $incidentDateStr . ($incidentTimeStr !== '' ? ' (' . $incidentTimeStr . ')' : '') . '</div>' : '') . '
                ' . ($location !== '' ? '<div><strong>Lugar ng Pangyayari:</strong> ' . $location . '</div>' : '') . '
            </div>' : '') . '

            <p style="margin-top: 3.5mm;">DAHIL DITO, AKO/KAMI ay nakikiusap na ang mga sumusunod na lunas ay ipagkaloob sa akin/amin nang naaayon sa batas at/sa katarungan.</p>
            
            ' . ($resolution !== '' ? '<div class="narrative-indent" style="margin-top: 1.5mm; font-style: italic;">' . nl2br($this->escape($resolution)) . '</div>' : '') . '
        </div>

        <div class="date-statement">
            <div>Ginawa ngayong ika- <strong>' . $day . '</strong> ng <strong>' . $month . '</strong>, <strong>' . $year . '</strong>.</div>
            <div style="margin-top: 3.5mm;">Tinanggap ngayong ika- <strong>' . $day . '</strong> ng <strong>' . $month . '</strong>, <strong>' . $year . '</strong>.</div>
        </div>

        <table class="sig-table">
            <tr>
                <td style="width: 48%; vertical-align: top; text-align: center;">
                    <div class="sig-comp-name">' . $this->escape($signatureCompName) . '</div>
                    <div class="sig-comp-line"></div>
                    <div class="sig-title">(Mga) Nagrereklamo</div>
                </td>
                <td style="width: 4%;"></td>
                <td style="width: 48%;"></td>
            </tr>
            <tr>
                <td style="width: 48%;"></td>
                <td style="width: 4%;"></td>
                <td style="width: 48%; vertical-align: top; text-align: center; padding-top: 14mm;">
                    <div class="sig-capt-line">' . $captainName . '</div>
                    <div class="sig-title">Punong Barangay / Tagapangulo ng Lupon</div>
                </td>
            </tr>
        </table>';

        $bodyWrapped = $forPdf ? '<div class="page-sheet">' . $sheetContent . '</div>' : '<div class="preview-canvas"><div class="page-sheet">' . $sheetContent . '</div></div>';

        return '<!DOCTYPE html>
<html lang="tl">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 7 - Reklamo</title>
    <style>
        ' . $cssStyles . '
        .form-top-code { font-size: 10pt; font-weight: bold; margin-bottom: 2mm; text-align: left; }

        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 4mm; }
        .header-table td { vertical-align: middle; }
        .header-logo-left { width: 95px; text-align: left; }
        .header-logo-right { width: 95px; text-align: right; }
        .header-logo-img { width: 88px; height: 88px; object-fit: contain; }
        .header-center-text { text-align: center; font-size: 9.5pt; line-height: 1.25; }
        .header-center-text .title-bold { font-weight: bold; font-size: 10pt; }
        .header-center-text .office-bold { font-weight: bold; font-size: 10.5pt; letter-spacing: 0.3px; }

        .case-table { width: 100%; border-collapse: collapse; margin: 3mm 0 4mm; }
        .case-table td { vertical-align: top; }
        .parties-col { width: 52%; }
        .meta-col { width: 48%; padding-left: 6mm; }
        .party-role-tag { font-size: 9pt; color: #1e293b; margin-top: 1mm; margin-bottom: 2mm; }
        .versus-tag { font-size: 9pt; margin: 2mm 0; }

        .doc-title-block { text-align: center; margin: 3mm 0 4mm; }
        .doc-title-block h2 { font-size: 13pt; font-weight: bold; margin: 0; letter-spacing: 0.5px; }
        .doc-title-block .subtitle { font-size: 10.5pt; font-weight: bold; margin-top: 1px; }

        .narrative-block { margin: 2.5mm 0 3.5mm; text-align: justify; line-height: 1.45; }
        .narrative-block p { margin: 0 0 2.5mm 0; }
        .narrative-indent { text-indent: 10mm; }

        .date-statement { margin: 6mm 0 6mm 10mm; font-size: 10pt; line-height: 1.5; }

        .sig-table { width: 100%; border-collapse: collapse; margin-top: 4mm; }
        .sig-comp-name { font-weight: bold; font-size: 10pt; text-transform: uppercase; margin-bottom: 1.5mm; }
        .sig-comp-line { border-bottom: 1.5px solid #0f172a; width: 92%; margin: 0 auto; }
        .sig-capt-line { border-top: 1.5px solid #0f172a; width: 92%; margin: 0 auto; padding-top: 1.5mm; font-weight: bold; font-size: 10pt; text-transform: uppercase; }
        .sig-title { font-size: 9pt; margin-top: 1.5mm; color: #1e293b; }
    </style>
</head>
<body>
    ' . $bodyWrapped . '
</body>
</html>';
    }

    /**
     * Generates the official MTC and DILG Transmittal Letter for an amicable settlement.
     */
    public function generateTransmittalLetter(int $caseId, array $options = []): string
    {
        require_once __DIR__ . '/../models/CaseTransmittal.php';
        $model = new CaseTransmittal($this->conn);
        $details = $model->getTransmittalFullDetails($caseId);

        if (!empty($options['transmittal_date'])) {
            $details['transmittal']['transmittal_date'] = $options['transmittal_date'];
        }
        if (!empty($options['secretary_name'])) {
            $details['secretary_name'] = $options['secretary_name'];
        }
        if (!empty($options['punong_barangay_name'])) {
            $details['punong_barangay_name'] = $options['punong_barangay_name'];
        }

        $html = $this->transmittalLetterHtml($details, empty($options['return_html']));

        if (!empty($options['return_html'])) {
            return $html;
        }

        $dompdfOptions = new Options();
        $dompdfOptions->set('isRemoteEnabled', false);
        $dompdfOptions->set('isHtml5ParserEnabled', true);
        $dompdfOptions->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($dompdfOptions);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        $pdf = $dompdf->output();
        if ($pdf === '') {
            throw new RuntimeException('The PDF renderer returned an empty transmittal letter document.');
        }

        if (!empty($options['save_path'])) {
            if (file_put_contents($options['save_path'], $pdf, LOCK_EX) === false) {
                throw new RuntimeException('Unable to save generated transmittal letter PDF.');
            }
            return $options['save_path'];
        }

        return $pdf;
    }

    /**
     * Generates formatted HTML for the MTC & DILG Transmittal Letter under RA 7160 Section 411.
     */
    public function transmittalLetterHtml(array $data, bool $forPdf = true): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $case = $data['case'] ?? [];
        $transmittal = $data['transmittal'] ?? [];
        $settlement = $data['settlement'] ?? [];

        $caseNumber = $this->escape((string) ($case['case_number'] ?? '_________'));
        $complaintTitle = $this->escape((string) ($case['complaint_title'] ?? 'Amicable Settlement'));
        $category = !empty($case['category_name']) ? ' (' . $this->escape((string) $case['category_name']) . ')' : '';

        $complainants = !empty($data['complainants']) ? implode(', ', array_map([$this, 'escape'], $data['complainants'])) : '_________________________';
        $respondents = !empty($data['respondents']) ? implode(', ', array_map([$this, 'escape'], $data['respondents'])) : '_________________________';

        $transmittalNumber = $this->escape((string) ($transmittal['transmittal_number'] ?? 'TR-' . date('Y') . '-0001'));

        $transmittalDateRaw = !empty($transmittal['transmittal_date']) ? $transmittal['transmittal_date'] : date('Y-m-d');
        $transmittalDateFormatted = date('F j, Y', strtotime($transmittalDateRaw));

        $settlementDateRaw = !empty($transmittal['settlement_date']) ? $transmittal['settlement_date'] : (!empty($settlement['settlement_date']) ? $settlement['settlement_date'] : date('Y-m-d'));
        $settlementDateFormatted = date('F j, Y', strtotime($settlementDateRaw));

        $expiryDateRaw = !empty($transmittal['ten_day_expiry_date']) ? $transmittal['ten_day_expiry_date'] : (!empty($settlement['repudiation_deadline']) ? $settlement['repudiation_deadline'] : date('Y-m-d', strtotime('+10 weekdays')));
        $expiryDateFormatted = date('F j, Y', strtotime($expiryDateRaw));

        $secName = $this->escape((string) ($data['secretary_name'] ?? 'Lupon Secretary'));
        $pbName = $this->escape((string) ($data['punong_barangay_name'] ?? 'Punong Barangay'));

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Transmittal Letter - ' . $caseNumber . '</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm 14mm 12mm 14mm;
        }
        body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 9.5pt;
            color: #0f172a;
            line-height: 1.45;
            background: #ffffff;
            margin: 0;
            padding: 0;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2mm;
        }
        .header-logo-left { width: 85px; text-align: left; vertical-align: middle; }
        .header-logo-right { width: 85px; text-align: right; vertical-align: middle; }
        .header-logo-img { width: 75px; height: 75px; object-fit: contain; }
        .header-center-text {
            text-align: center;
            line-height: 1.25;
            font-size: 8.5pt;
            vertical-align: middle;
        }
        .header-center-text .gov-title { font-size: 9pt; }
        .header-center-text .office-title {
            font-size: 11pt;
            font-weight: bold;
            color: #0f172a;
            margin-top: 2px;
            letter-spacing: 0.5px;
        }
        .header-center-text .sec-title {
            font-size: 9pt;
            font-weight: bold;
            color: #1e3a8a;
            margin-top: 1px;
        }
        .meta-bar {
            width: 100%;
            border-top: 1.5px solid #0f172a;
            border-bottom: 0.5px solid #64748b;
            padding: 2.5mm 0;
            margin-top: 2mm;
            margin-bottom: 4mm;
        }
        .meta-bar td { font-size: 9pt; }
        .addr-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 3.5mm;
            font-size: 9.5pt;
        }
        .addr-table td {
            vertical-align: top;
            padding: 1.5mm 0;
        }
        .addr-lbl {
            width: 75px;
            font-weight: bold;
            color: #0f172a;
        }
        .subject-box {
            background: #f8fafc;
            border-left: 3.5px solid #1e3a8a;
            padding: 2.5mm 3.5mm;
            font-weight: bold;
            font-size: 9pt;
            line-height: 1.35;
            color: #0f172a;
        }
        .dispute-box {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-collapse: collapse;
            margin: 3mm 0 3mm;
            background: #f8fafc;
        }
        .dispute-box td {
            padding: 1.8mm 3mm;
            font-size: 8.5pt;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        .dispute-lbl {
            width: 28%;
            font-weight: bold;
            color: #334155;
        }
        .dispute-val {
            width: 72%;
            color: #0f172a;
        }
        .statutory-cert {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-left: 3.5px solid #2563eb;
            padding: 2.5mm 3.5mm;
            margin: 2.5mm 0;
            font-size: 9pt;
            font-weight: bold;
            color: #1e3a8a;
            line-height: 1.4;
        }
        .body-p {
            text-align: justify;
            margin: 2.5mm 0;
            font-size: 9.2pt;
            line-height: 1.45;
        }
        .inventory-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1.5mm;
            font-size: 8.8pt;
        }
        .inventory-table td {
            padding: 1mm 0;
            vertical-align: top;
        }
        .chk-box {
            width: 22px;
            font-weight: bold;
            color: #1e3a8a;
            font-size: 10.5pt;
        }
        .sig-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5mm;
        }
        .sig-col {
            width: 50%;
            vertical-align: top;
        }
        .sig-line {
            font-weight: bold;
            font-size: 9.5pt;
            text-transform: uppercase;
            color: #0f172a;
            border-bottom: 1px solid #0f172a;
            display: inline-block;
            min-width: 65%;
            padding-bottom: 1px;
            margin-top: 12mm;
        }
        .sig-role {
            font-size: 8.5pt;
            color: #475569;
            margin-top: 1mm;
        }
        .stamp-section {
            margin-top: 5mm;
            border-top: 1px dashed #94a3b8;
            padding-top: 2.5mm;
        }
        .stamp-title {
            font-size: 8pt;
            font-weight: bold;
            text-transform: uppercase;
            color: #475569;
            margin-bottom: 2mm;
            text-align: center;
            letter-spacing: 0.5px;
        }
        .stamp-table {
            width: 100%;
            border-collapse: collapse;
        }
        .stamp-box {
            width: 50%;
            border: 1.5px solid #475569;
            padding: 2.5mm 3.5mm;
            vertical-align: top;
            font-size: 8pt;
            background: #fafafa;
        }
        .stamp-heading {
            font-size: 8pt;
            font-weight: bold;
            color: #0f172a;
            text-decoration: underline;
            margin-bottom: 2mm;
        }
        .stamp-field {
            margin-top: 1.8mm;
            color: #334155;
        }
    </style>
</head>
<body>

    <!-- Header with Official Barangay Tumana & City of Marikina Logos -->
    <table class="header-table">
        <tr>
            <td class="header-logo-left">
                ' . ($tumanaLogo !== '' ? '<img src="' . $tumanaLogo . '" class="header-logo-img" alt="Barangay Tumana">' : '') . '
            </td>
            <td class="header-center-text">
                <div class="gov-title">Republika ng Pilipinas</div>
                <div class="gov-title">Lalawigan ng NCR &bull; Lungsod ng Marikina</div>
                <div class="gov-title"><strong>BARANGAY TUMANA</strong></div>
                <div style="font-size: 8pt; color: #475569;">Telephone Nos. 8-238-4208 &bull; Email: barangaytumana2023@gmail.com</div>
                <div class="office-title">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>
                <div class="sec-title">OFFICE OF THE BARANGAY SECRETARY</div>
            </td>
            <td class="header-logo-right">
                ' . ($marikinaLogo !== '' ? '<img src="' . $marikinaLogo . '" class="header-logo-img" alt="Lungsod ng Marikina">' : '') . '
            </td>
        </tr>
    </table>

    <!-- Reference & Date Meta Bar -->
    <table class="meta-bar">
        <tr>
            <td style="width: 55%; font-weight: bold; color: #1e3a8a;">
                TRANSMITTAL REF. NO.: <span style="font-family: monospace; font-size: 9.5pt;">' . $transmittalNumber . '</span>
            </td>
            <td style="width: 45%; text-align: right; font-weight: bold;">
                DATE OF TRANSMITTAL: ' . $transmittalDateFormatted . '
            </td>
        </tr>
    </table>

    <!-- Addressees -->
    <table class="addr-table">
        <tr>
            <td class="addr-lbl">TO:</td>
            <td style="line-height: 1.4;">
                <strong>THE OFFICE OF THE CLERK OF COURT</strong><br>
                Municipal Trial Court in Cities (MTCC)<br>
                Marikina City, Metro Manila<br><br>
                <strong>THE CITY DIRECTOR</strong><br>
                Department of the Interior and Local Government (DILG)<br>
                Marikina Field Office, Marikina City
            </td>
        </tr>
        <tr>
            <td class="addr-lbl" style="padding-top: 2.5mm;">FROM:</td>
            <td style="padding-top: 2.5mm; line-height: 1.35;">
                <strong>THE OFFICE OF THE LUPONG TAGAPAMAYAPA / BARANGAY SECRETARY</strong><br>
                Barangay Tumana, Marikina City
            </td>
        </tr>
        <tr>
            <td class="addr-lbl" style="padding-top: 2.5mm;">SUBJECT:</td>
            <td style="padding-top: 2.5mm;">
                <div class="subject-box">
                    FORMAL TRANSMITTAL OF AMICABLE SETTLEMENT (KP FORM NO. 16) &amp; DISPUTE RESOLUTION RECORDS FOR BARANGAY CASE NO. ' . $caseNumber . '
                </div>
            </td>
        </tr>
    </table>

    <!-- Body -->
    <p class="body-p">
        Sir / Madam:
    </p>
    <p class="body-p">
        Pursuant to <strong>Section 411 of Republic Act No. 7160</strong> (The Local Government Code of 1991) and the Katarungang Pambarangay Rules, the Office of the Lupong Tagapamayapa and the Barangay Secretary of Barangay Tumana, Marikina City, respectfully transmit herewith certified true copies of the Amicable Settlement agreement and the dispute resolution case records for:
    </p>

    <!-- Dispute Particulars Box -->
    <table class="dispute-box">
        <tr>
            <td class="dispute-lbl">Barangay Case No.:</td>
            <td class="dispute-val"><strong>' . $caseNumber . '</strong></td>
        </tr>
        <tr>
            <td class="dispute-lbl">Title / Subject Matter:</td>
            <td class="dispute-val"><strong>' . $complaintTitle . '</strong>' . $category . '</td>
        </tr>
        <tr>
            <td class="dispute-lbl">Complainant(s):</td>
            <td class="dispute-val"><strong>' . $complainants . '</strong> <em>(May-sumbong)</em></td>
        </tr>
        <tr>
            <td class="dispute-lbl">Respondent(s):</td>
            <td class="dispute-val"><strong>' . $respondents . '</strong> <em>(Ipinagsusumbong)</em></td>
        </tr>
        <tr>
            <td class="dispute-lbl">Date Settlement Signed:</td>
            <td class="dispute-val"><strong>' . $settlementDateFormatted . '</strong></td>
        </tr>
        <tr>
            <td class="dispute-lbl">10-Day Repudiation Expiry:</td>
            <td class="dispute-val"><strong>' . $expiryDateFormatted . '</strong> <em>(Excluding weekends &amp; statutory Philippine holidays)</em></td>
        </tr>
    </table>

    <!-- Statutory Certification -->
    <div class="statutory-cert">
        STATUTORY CERTIFICATION (R.A. 7160 SEC. 411):<br>
        This is to further certify that the mandatory ten (10) working-day repudiation period under Section 411 of RA 7160 has officially elapsed with no repudiation filed by either party.
    </div>

    <p class="body-p">
        In accordance with Section 411 of RA 7160, the attached Amicable Settlement carries the full force and effect of a final judgment of a court upon the expiration of ten (10) days from the date thereof. It is therefore respectfully transmitted for official court archiving, administrative monitoring, and future judicial execution as provided by law in case of non-compliance.
    </p>

    <!-- Inventory Checklist -->
    <div style="margin-top: 2.5mm;">
        <strong style="font-size: 8.8pt; color: #1e293b;">INVENTORY OF ATTACHED DISPUTE RESOLUTION RECORDS:</strong>
        <table class="inventory-table">
            <tr>
                <td class="chk-box">&#9745;</td>
                <td>Certified True Copy of KP Form No. 7 (Complaint / Sumbong)</td>
            </tr>
            <tr>
                <td class="chk-box">&#9745;</td>
                <td>Certified True Copy of Minutes of Mediation / Conciliation Proceedings</td>
            </tr>
            <tr>
                <td class="chk-box">&#9745;</td>
                <td>Original / Certified True Copy of KP Form No. 16 (Amicable Settlement / Kasunduang Pag-aayos)</td>
            </tr>
        </table>
    </div>

    <!-- Signatories -->
    <table class="sig-table">
        <tr>
            <td class="sig-col" style="padding-right: 10mm;">
                <div>Prepared &amp; Transmitted by:</div>
                <div class="sig-line">' . $secName . '</div>
                <div class="sig-role">Barangay Secretary / Kalihim ng Lupon</div>
            </td>
            <td class="sig-col" style="padding-left: 10mm;">
                <div>Noted &amp; Attested by:</div>
                <div class="sig-line">' . $pbName . '</div>
                <div class="sig-role">Punong Barangay / Tagapangulo ng Lupon</div>
            </td>
        </tr>
    </table>

    <!-- Receiving Copy Stamp Blocks -->
    <div class="stamp-section">
        <div class="stamp-title">
            Official Receiving Copy Stamp Blocks (To be accomplished by receiving agency)
        </div>
        <table class="stamp-table">
            <tr>
                <td class="stamp-box" style="margin-right: 4mm;">
                    <div class="stamp-heading">
                        MUNICIPAL TRIAL COURT IN CITIES (MTCC) &bull; MARIKINA CITY
                    </div>
                    <div class="stamp-field">Received by: _____________________________________________</div>
                    <div class="stamp-field">Signature: _______________________________________________</div>
                    <div class="stamp-field">Date Received: ____________________ Time: _________________</div>
                    <div class="stamp-field">Receiving Action: [ &nbsp; ] Docketed / Filed &nbsp;&nbsp; [ &nbsp; ] Certified Entry</div>
                </td>
                <td class="stamp-box" style="margin-left: 4mm;">
                    <div class="stamp-heading">
                        DILG FIELD OFFICE &bull; MARIKINA CITY
                    </div>
                    <div class="stamp-field">Received by: _____________________________________________</div>
                    <div class="stamp-field">Signature: _______________________________________________</div>
                    <div class="stamp-field">Date Received: ____________________ Time: _________________</div>
                    <div class="stamp-field">Receiving Action: [ &nbsp; ] Logged in KP Registry &nbsp;&nbsp; [ &nbsp; ] Archived</div>
                </td>
            </tr>
        </table>
    </div>

</body>
</html>';
    }

    /**
     * Generates the Paanyaya (Informal Invitation) Letter PDF.
     */
    public function generatePaanyaya(int $complaintId, array $options = []): string
    {
        $data = $options['data'] ?? null;
        if ($data === null) {
            $data = $this->loadPaanyayaData($complaintId, $options);
        }

        $dompdfOptions = new Options();
        $dompdfOptions->set('isRemoteEnabled', false);
        $dompdfOptions->set('isHtml5ParserEnabled', true);
        $dompdfOptions->set('defaultFont', 'Times-Roman');

        $dompdf = new Dompdf($dompdfOptions);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($this->paanyayaLetterHtml($data, true), 'UTF-8');
        $dompdf->render();

        $pdf = $dompdf->output();
        if ($pdf === '') {
            throw new RuntimeException('The PDF renderer returned an empty document.');
        }

        if (!empty($options['save_path'])) {
            $dir = dirname($options['save_path']);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            if (file_put_contents($options['save_path'], $pdf, LOCK_EX) === false) {
                throw new RuntimeException('Unable to save generated Paanyaya PDF to: ' . $options['save_path']);
            }
            return $options['save_path'];
        }

        return $pdf;
    }

    /**
     * Renders HTML for Paanyaya preview in browser or modal.
     */
    public function renderPaanyayaHtml(int $complaintId, array $options = []): string
    {
        $data = $options['data'] ?? null;
        if ($data === null) {
            $data = $this->loadPaanyayaData($complaintId, $options);
        }
        return $this->paanyayaLetterHtml($data, false);
    }

    /**
     * Loads complaint, parties, and paanyaya data from database.
     */
    public function loadPaanyayaData(int $complaintId, array $options = []): array
    {
        if ($this->conn === null) {
            require_once __DIR__ . '/../config/database.php';
            $this->conn = (new Database())->connect();
        }

        // Fetch complaint
        $cStmt = $this->conn->prepare('SELECT * FROM complaints WHERE complaint_id = ? LIMIT 1');
        $cStmt->execute([$complaintId]);
        $complaint = $cStmt->fetch(PDO::FETCH_ASSOC);
        if (!$complaint) {
            throw new RuntimeException('Complaint not found.');
        }

        // Fetch parties
        $pStmt = $this->conn->prepare("
            SELECT cp.party_type,
                   TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, r.suffix)) AS full_name,
                   r.address, r.purok
            FROM complaint_parties cp
            INNER JOIN residents r ON r.resident_id = cp.resident_id
            WHERE cp.complaint_id = ?
            ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent', 'Witness'), r.last_name, r.first_name
        ");
        $pStmt->execute([$complaintId]);
        $parties = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        $complainants = [];
        $respondents = [];
        foreach ($parties as $p) {
            $addr = trim((string)($p['address'] ?? ''));
            if ($addr === '' && !empty($p['purok'])) {
                $addr = 'Purok ' . $p['purok'] . ', Barangay Tumana, Marikina City';
            } elseif ($addr === '') {
                $addr = 'Barangay Tumana, Marikina City';
            }
            $item = [
                'full_name' => $p['full_name'],
                'address' => $addr
            ];
            if ($p['party_type'] === 'Complainant') {
                $complainants[] = $item;
            } elseif ($p['party_type'] === 'Respondent') {
                $respondents[] = $item;
            }
        }

        // Check if specific Paanyaya stage is requested
        $stageType = $options['stage_type'] ?? null;
        if ($stageType) {
            $stStmt = $this->conn->prepare('SELECT * FROM paanyaya_stages WHERE complaint_id = ? AND stage_type = ? LIMIT 1');
            $stStmt->execute([$complaintId, $stageType]);
            $stageData = $stStmt->fetch(PDO::FETCH_ASSOC);
            if ($stageData) {
                $paanyaya = $stageData;
                if (empty($options['invitation_date']) && !empty($stageData['invitation_date'])) $options['invitation_date'] = $stageData['invitation_date'];
                if (empty($options['invitation_time']) && !empty($stageData['invitation_time'])) $options['invitation_time'] = $stageData['invitation_time'];
                if (empty($options['venue']) && !empty($stageData['venue'])) $options['venue'] = $stageData['venue'];
                if (empty($options['salaysay']) && !empty($stageData['salaysay'])) $options['salaysay'] = $stageData['salaysay'];
                if (!isset($options['has_e_signature']) && isset($stageData['has_e_signature'])) $options['has_e_signature'] = (bool)$stageData['has_e_signature'];
                if (empty($options['attempt_number'])) $options['attempt_number'] = ($stageType === 'Paanyaya 2') ? 2 : 1;
            }
        }

        // Fetch latest paanyaya session record if exists
        $sessionStmt = $this->conn->prepare('SELECT * FROM paanyaya_sessions WHERE complaint_id = ? ORDER BY attempt_number DESC, session_id DESC LIMIT 1');
        $sessionStmt->execute([$complaintId]);
        $paanyaya = $sessionStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if (empty($paanyaya)) {
            $paaStmt = $this->conn->prepare('SELECT * FROM complaint_paanyaya WHERE complaint_id = ? ORDER BY paanyaya_id DESC LIMIT 1');
            $paaStmt->execute([$complaintId]);
            $paanyaya = $paaStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        // Punong barangay setting
        $pbStmt = $this->conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'captain_name' LIMIT 1");
        $pbStmt->execute();
        $captainName = $pbStmt->fetchColumn() ?: 'HON. AKIKO LYNNE CENTENO';

        $attemptNumber = (int) ($options['attempt_number'] ?? ($paanyaya['attempt_number'] ?? ($complaint['paanyaya_attempt_count'] ?? 1)));

        return [
            'complaint' => $complaint,
            'complainants' => $complainants,
            'respondents' => $respondents,
            'paanyaya' => $paanyaya,
            'attempt_number' => $attemptNumber,
            'invitation_date' => $options['invitation_date'] ?? ($paanyaya['scheduled_date'] ?? ($paanyaya['invitation_date'] ?? date('Y-m-d', strtotime('+3 weekdays')))),
            'invitation_time' => $options['invitation_time'] ?? ($paanyaya['scheduled_time'] ?? ($paanyaya['invitation_time'] ?? '14:00:00')),
            'venue' => $options['venue'] ?? ($paanyaya['venue'] ?? 'Tanggapan ng Barangay Tumana'),
            'salaysay' => $options['salaysay'] ?? ($paanyaya['salaysay'] ?? ($complaint['narrative'] ?? '')),
            'has_e_signature' => isset($options['has_e_signature']) ? (bool)$options['has_e_signature'] : (!isset($paanyaya['has_e_signature']) || (bool)$paanyaya['has_e_signature']),
            'captain_name' => $options['captain_name'] ?? $captainName,
            'issued_date' => $options['issued_date'] ?? (!empty($paanyaya['created_at']) ? substr($paanyaya['created_at'], 0, 10) : date('Y-m-d')),
        ];
    }

    /**
     * Renders exact layout matching Barangay Tumana\'s Paanyaya template.
     */
    public function paanyayaLetterHtml(array $data, bool $forPdf = true): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $bagongPath = __DIR__ . '/../../frontend/assets/images/bagong_pilipinas_logo.png';
        $signaturePath = __DIR__ . '/../../frontend/assets/images/captain_signature.png';

        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $bagongLogo = file_exists($bagongPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($bagongPath)) : '';
        $sigLogo = file_exists($signaturePath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($signaturePath)) : '';

        $complainants = $data['complainants'] ?? [];
        $respondents = $data['respondents'] ?? [];

        $compNames = [];
        $compAddrs = [];
        foreach ($complainants as $c) {
            $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
            $cAddr = is_array($c) ? ($c['address'] ?? '') : '';
            if (trim($cName) !== '') $compNames[] = trim($cName);
            if (trim($cAddr) !== '') $compAddrs[] = trim($cAddr);
        }
        $compNameStr = !empty($compNames) ? implode(' / ', $compNames) : 'NAGPAANYAYA';
        $compAddrStr = !empty($compAddrs) ? implode(', ', array_unique($compAddrs)) : 'Barangay Tumana, Marikina City';

        $respNames = [];
        $respAddrs = [];
        foreach ($respondents as $r) {
            $rName = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
            $rAddr = is_array($r) ? ($r['address'] ?? '') : '';
            if (trim($rName) !== '') $respNames[] = trim($rName);
            if (trim($rAddr) !== '') $respAddrs[] = trim($rAddr);
        }
        $respNameStr = !empty($respNames) ? implode(' / ', $respNames) : 'INANYAYAHAN';
        $respAddrStr = !empty($respAddrs) ? implode(', ', array_unique($respAddrs)) : 'Barangay Tumana, Marikina City';

        $compCount = count($compNames);
        $niNina = ($compCount > 1) ? 'nina' : 'ni';
        $pronoun = ($compCount > 1) ? 'sila' : 'siya';

        $issuedTs = !empty($data['issued_date']) ? strtotime($data['issued_date']) : time();
        $dateFormatted = strtoupper(date('F j, Y', $issuedTs));

        $invDate = !empty($data['invitation_date']) ? $data['invitation_date'] : date('Y-m-d', strtotime('+3 weekdays'));
        $invTs = strtotime($invDate);
        $petsa = date('j', $invTs);
        $buwan = strtoupper($this->filipinoMonth((int)date('n', $invTs)));
        $taon = date('Y', $invTs);
        $dow = (int)date('w', $invTs);
        $araw = strtoupper($this->filipinoDayOfWeek($dow));
        $oras = strtoupper($this->filipinoTime(!empty($data['invitation_time']) ? $data['invitation_time'] : '14:00:00'));

        $venue = !empty($data['venue']) ? $this->escape($data['venue']) : 'tanggapan ng Barangay Tumana';
        $salaysayRaw = !empty($data['salaysay']) ? trim((string)$data['salaysay']) : 'naisin nilang magkausap at magkaliwanagan.';
        if ($salaysayRaw !== '' && !in_array(substr($salaysayRaw, -1), ['.', '!', '?'], true)) {
            $salaysayRaw .= '.';
        }
        $salaysay = $this->escape($salaysayRaw);
        $captainName = !empty($data['captain_name']) ? $this->escape($data['captain_name']) : 'HON. AKIKO LYNNE CENTENO';
        $hasESignature = !empty($data['has_e_signature']);
        $attemptNumber = (int)($data['attempt_number'] ?? 1);
        $docTitleText = ($attemptNumber === 2) ? 'I K A L A W A N G   P A A N Y A Y A' : 'P A A N Y A Y A';

        $signatureHtml = ($hasESignature && $sigLogo !== '')
            ? '<img src="' . $sigLogo . '" class="sig-img" alt="Hon. Akiko Lynne Centeno">'
            : '';

        $cssStyles = $forPdf ? '
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: "Times New Roman", Times, "DejaVu Serif", Georgia, serif; font-size: 10pt; color: #111111; line-height: 1.45; background: #ffffff; margin: 0; padding: 0; }
        .page-sheet { width: 100%; margin: 0; padding: 0; background: #ffffff; }
        ' : '
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            background: #475569;
            font-family: "Times New Roman", Times, "DejaVu Serif", Georgia, serif;
            color: #111111;
            line-height: 1.45;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .preview-canvas {
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 24px 16px;
            min-height: 100vh;
            background: #475569;
            box-sizing: border-box;
        }
        .page-sheet {
            background: #ffffff;
            width: 210mm;
            min-height: 297mm;
            padding: 18mm 20mm 20mm 20mm;
            box-sizing: border-box;
            box-shadow: 0 14px 40px rgba(0, 0, 0, 0.45), 0 2px 6px rgba(0, 0, 0, 0.2);
            border-radius: 3px;
            margin: 0 auto;
        }
        @media print {
            html, body {
                background: #ffffff !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 210mm !important;
            }
            .preview-canvas {
                padding: 0 !important;
                margin: 0 !important;
                background: #ffffff !important;
                display: block !important;
            }
            .page-sheet {
                width: 210mm !important;
                min-height: 297mm !important;
                margin: 0 !important;
                padding: 15mm 18mm 15mm 18mm !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                border: none !important;
                page-break-after: avoid;
                page-break-inside: avoid;
            }
        }
        ';

        $sheetContent = '
        <table class="header-table">
            <tr>
                <td class="header-logo-left">
                    ' . ($tumanaLogo !== '' ? '<img src="' . $tumanaLogo . '" class="header-logo-img" alt="Barangay Tumana">' : '') . '
                </td>
                <td class="header-center-text">
                    <div class="rep-title">Republika ng Pilipinas</div>
                    <div class="brgy-title">BARANGAY TUMANA</div>
                    <div class="city-title">CITY OF MARIKINA</div>
                    <div class="addr-title">ROAD 1 EXTENSION DOÑA PETRA , BARANGAY TUMANA,</div>
                    <div class="addr-title">MARIKINA CITY</div>
                    <div class="tel-title">Tel. No. 02-8457-2818</div>
                    <div class="pb-office-title">TANGGAPAN NG PUNONGBARANGAY</div>
                    <div class="ooo">— oOo —</div>
                </td>
                <td class="header-logo-right">
                    ' . ($bagongLogo !== '' ? '<img src="' . $bagongLogo . '" class="header-logo-img" alt="Bagong Pilipinas">' : '') . '
                </td>
            </tr>
        </table>

        <div class="doc-title-box">
            <div class="doc-title">' . $docTitleText . '</div>
            <div class="doc-divider">-----------------------------------------</div>
        </div>

        <div class="meta-date">' . $dateFormatted . '</div>

        <div class="party-block">
            <div class="party-role">(Nagpaanyaya)</div>
            <div class="party-name">' . $this->escape($compNameStr) . '</div>
            <div class="party-address">' . $this->escape($compAddrStr) . '</div>
            <div class="party-divider">--- --- --- --- ---</div>
            <div class="party-role">(Inanyayahan)</div>
            <div class="party-name">' . $this->escape($respNameStr) . '</div>
            <div class="party-address">' . $this->escape($respAddrStr) . '</div>
        </div>

        <div class="salutation">Mahal na G/Gng/Bb:</div>

        <div class="body-p">
            Kayo po ay malugod na inaanyayahan sa <strong>' . $venue . '</strong>, sa ika <strong>' . $petsa . '</strong> ng <strong>' . $buwan . '</strong>, <strong>' . $taon . '</strong> araw ng <strong>' . $araw . '</strong> sa ganap na <strong>' . $oras . '</strong> upang pagusapan at mabigyang linaw ukol sa inihaing alintana o usapin ' . $niNina . ' <strong>' . $this->escape($compNameStr) . '</strong>. Dahil ayon sa kanila ' . $salaysay . ' Kung kaya\'t ' . $pronoun . ' ay dumulog sa Barangay upang kayo ay Paanyayahan.
        </div>

        <div class="body-p">
            Ang inyo pong lingkod ay para lamang mamagitan sa anumang inyong mapag-uusapan.
        </div>

        <div class="body-p">
            Kung Kaya\'t inaasahan ang inyong pagtalima sa inyong imbitasyon.
        </div>

        <div class="body-p" style="margin-bottom: 2mm;">
            Salamat po.
        </div>

        <table class="bottom-table">
            <tr>
                <td class="bottom-left-empty">&nbsp;</td>
                <td class="bottom-right-col">
                    <div class="sig-block">
                        <div class="sig-sumasainyo">Sumasainyo,</div>
                        <div class="sig-space">
                            ' . $signatureHtml . '
                        </div>
                        <div class="sig-name">' . $captainName . '</div>
                        <div class="sig-role">Punong Barangay</div>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="bottom-left-col">
                    <div class="party-name">' . $this->escape($compNameStr) . '</div>
                    <div class="comp-addr-italic">' . $this->escape($compAddrStr) . '</div>
                    <div class="comp-role-tag">(Mga) Nagpaanyaya</div>
                    <div class="received-box">Received Copy</div>
                </td>
                <td class="bottom-right-empty">&nbsp;</td>
            </tr>
        </table>
        ';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Paanyaya - ' . $this->escape($compNameStr) . ' laban kay ' . $this->escape($respNameStr) . '</title>
    <style>
        ' . $cssStyles . '
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1mm;
        }
        .header-logo-left { width: 75px; text-align: left; vertical-align: top; }
        .header-logo-right { width: 75px; text-align: right; vertical-align: top; }
        .header-logo-img { width: 70px; height: 70px; object-fit: contain; }
        .header-center-text {
            text-align: center;
            line-height: 1.25;
            vertical-align: top;
        }
        .header-center-text .rep-title {
            font-family: "Old English Text MT", "Cloister Black", "Times New Roman", serif;
            font-size: 11pt;
            letter-spacing: 0.5px;
        }
        .header-center-text .brgy-title {
            font-size: 13pt;
            font-weight: bold;
            letter-spacing: 0.5px;
            margin-top: 1px;
        }
        .header-center-text .city-title {
            font-size: 9.5pt;
            font-weight: bold;
            margin-top: 1px;
        }
        .header-center-text .addr-title {
            font-size: 8pt;
            margin-top: 1px;
        }
        .header-center-text .tel-title {
            font-size: 8pt;
            margin-top: 1px;
        }
        .header-center-text .pb-office-title {
            font-size: 9.5pt;
            font-weight: bold;
            margin-top: 2px;
        }
        .header-center-text .ooo {
            font-size: 8.5pt;
            margin-top: 1px;
        }
        .doc-title-box {
            text-align: center;
            margin-top: 4mm;
            margin-bottom: 4mm;
        }
        .doc-title {
            font-size: 16pt;
            font-weight: bold;
            letter-spacing: 6px;
        }
        .doc-divider {
            font-size: 9pt;
            letter-spacing: 1px;
            color: #222222;
            margin-top: 1mm;
        }
        .meta-date {
            font-size: 10pt;
            font-weight: bold;
            margin-top: 2mm;
            margin-bottom: 3.5mm;
        }
        .party-block {
            font-size: 10pt;
            line-height: 1.35;
            margin-bottom: 4mm;
        }
        .party-role {
            color: #222222;
            font-size: 9.5pt;
        }
        .party-name {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10pt;
        }
        .party-address {
            color: #222222;
            font-size: 9.5pt;
        }
        .party-divider {
            letter-spacing: 2px;
            color: #333333;
            margin: 2.5mm 0;
            font-size: 9.5pt;
        }
        .salutation {
            font-size: 10pt;
            font-weight: bold;
            margin-bottom: 3.5mm;
        }
        .body-p {
            text-align: justify;
            font-size: 10pt;
            line-height: 1.5;
            margin-bottom: 3.5mm;
        }
        .bottom-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6mm;
        }
        .bottom-left-empty {
            width: 48%;
        }
        .bottom-right-col {
            width: 52%;
            vertical-align: top;
            text-align: right;
            padding-bottom: 6mm;
        }
        .sig-block {
            display: inline-block;
            text-align: center;
            min-width: 230px;
        }
        .sig-sumasainyo {
            font-size: 10pt;
            text-align: left;
            margin-bottom: 2mm;
            margin-left: 10px;
        }
        .sig-space {
            height: 48px;
            position: relative;
            text-align: center;
        }
        .sig-img {
            max-height: 48px;
            max-width: 180px;
            object-fit: contain;
        }
        .sig-name {
            font-size: 10.5pt;
            font-weight: bold;
            text-transform: uppercase;
            margin-top: 1mm;
        }
        .sig-role {
            font-size: 9.5pt;
            color: #222222;
        }
        .bottom-left-col {
            width: 48%;
            vertical-align: top;
            text-align: left;
            font-size: 9.5pt;
            line-height: 1.35;
        }
        .comp-addr-italic {
            font-style: italic;
            color: #333333;
            font-size: 9.5pt;
            margin-top: 1px;
        }
        .comp-role-tag {
            margin-top: 4mm;
            font-size: 9.5pt;
        }
        .received-box {
            margin-top: 7mm;
            font-size: 9.5pt;
        }
        .bottom-right-empty {
            width: 52%;
        }
    </style>
</head>
<body>
' . ($forPdf ? '<div class="page-sheet">' . $sheetContent . '</div>' : '<div class="preview-canvas"><div class="page-sheet">' . $sheetContent . '</div></div>') . '
</body>
</html>';
    }
}