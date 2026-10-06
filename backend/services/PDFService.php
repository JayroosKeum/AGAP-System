<?php

use Dompdf\Dompdf;
use Dompdf\Options;

class PDFService
{
    public function __construct()
    {
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

    public function generateKp10(
        array|int $dataOrCaseId,
        string|int $pathOrUserId = '',
        array $options = []
    ): mixed {
        if (is_int($dataOrCaseId)) {
            return $this->generateKp10ForCase($dataOrCaseId, (int) $pathOrUserId, $options);
        }
        $this->generateNotice('KP Form 10', $dataOrCaseId, (string) $pathOrUserId);
        return true;
    }

    public function generateKp10ForCase(int $caseId, int $userId, array $options = []): array
    {
        require_once __DIR__ . '/../config/database.php';
        $db = (new Database())->connect();

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
}