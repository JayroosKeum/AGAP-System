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
}