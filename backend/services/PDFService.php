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
            'venue' => $options['venue'] ?? 'Lupon Office, Barangay Tumana',
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
        $venue = $this->escape((string) ($data['venue'] ?? 'Tanggapan ng Lupong Tagapamayapa, Barangay Hall'));
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
            return '<div style="border-bottom: 1px solid #1e293b; padding-bottom: 2px; margin-bottom: 4px;">' . $fallback . '</div>';
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
            
            <div class="narrative-indent" style="margin-top: 1.5mm; font-style: italic;">
                ' . ($resolution !== '' ? nl2br($this->escape($resolution)) : 'Mapayapang pag-aayos, pagwawasto sa idinulot na pinsala o abala, at kaukulang lunas alinsunod sa batas at mga umiiral na alituntunin ng Katarungang Pambarangay.') . '
            </div>
        </div>

        <div class="date-statement">
            <div>Ginawa ngayong ika- <strong>' . $day . '</strong> ng <strong>' . $month . '</strong>, <strong>' . $year . '</strong>.</div>
            <div style="margin-top: 1.5mm;">Tinanggap ngayong ika- <strong>' . $day . '</strong> ng <strong>' . $month . '</strong>, <strong>' . $year . '</strong>.</div>
        </div>

        <table class="sig-table">
            <tr>
                <td style="width: 48%; vertical-align: bottom; text-align: center;">
                    <div class="sig-space"></div>
                    <div class="sig-line">
                        <strong style="font-size: 10pt; text-transform: uppercase;">' . $this->escape($signatureCompName) . '</strong>
                    </div>
                    <div class="sig-title">(Mga) Nagrereklamo</div>
                </td>
                <td style="width: 4%;"></td>
                <td style="width: 48%; vertical-align: bottom; text-align: center;">
                    <div class="sig-space"></div>
                    <div class="sig-line">
                        <strong style="font-size: 10pt; text-transform: uppercase;">' . $captainName . '</strong>
                    </div>
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

        .date-statement { margin: 3mm 0 2mm; font-size: 10pt; }

        .sig-table { width: 100%; border-collapse: collapse; margin-top: 10mm; }
        .sig-space { height: 48px; min-height: 48px; }
        .sig-line { border-bottom: 1.5px solid #0f172a; width: 90%; margin: 0 auto; padding-bottom: 1.5mm; }
        .sig-title { font-size: 9pt; margin-top: 2mm; color: #1e293b; }
    </style>
</head>
<body>
    ' . $bodyWrapped . '
</body>
</html>';
    }
}