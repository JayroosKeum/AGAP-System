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
        $explanationDate = !empty($data['explanation_date']) ? date('F j, Y g:i A', strtotime($data['explanation_date'])) : date('F j, Y g:i A', strtotime('+3 days 09:00:00'));
        $noticeDate = !empty($data['notice_date']) ? date('F j, Y', strtotime($data['notice_date'])) : date('F j, Y');

        $formTitle = 'Pormularyo ng KP Blg. 19';
        $subTitle = 'PAUNAWA SA PAGDINIG (Para sa Ipinagsusumbong)';
        $body = '';

        if ($formCode === 'KP Form 18') {
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

    ' . (in_array($formCode, ['KP Form 18', 'KP Form 19'], true) ? '
    <div class="officers-return">
        <h3>Katunayan ng Paglilingkod (Officer’s Return)</h3>
        <p style="margin: 2mm 0; line-height: 1.4;">
            Pinatutunayan ko na ang Paunawang ito ay personal / maayos na pinagsilbihan kay <strong>' . $targetParty . '</strong>
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