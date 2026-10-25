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

    /**
     * Resolves the official Punong Barangay / Lupon Chairman from system_settings or default profile.
     */
    public function getAuthorizedCaptainName(?string $explicit = null): string
    {
        $candidate = trim((string) $explicit);
        if ($candidate !== '' &&
            strcasecmp($candidate, 'Admin User') !== 0 &&
            strcasecmp($candidate, 'Punong Barangay') !== 0 &&
            strcasecmp($candidate, 'Tagapangulo ng Lupon') !== 0 &&
            strcasecmp($candidate, 'Punong Barangay / Lupon Chairman') !== 0 &&
            strcasecmp($candidate, 'Punong Barangay / Tagapangulo ng Lupon') !== 0) {
            return $candidate;
        }

        try {
            if (!$this->conn) {
                require_once __DIR__ . '/../config/Database.php';
                $this->conn = (new Database())->connect();
            }
            $pbStmt = $this->conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'captain_name' LIMIT 1");
            $pbStmt->execute();
            $val = trim((string) $pbStmt->fetchColumn());
            if ($val !== '' && strcasecmp($val, 'Admin User') !== 0) {
                return $val;
            }
        } catch (Throwable $e) {
            // ignore and fallback
        }

        return 'HON. AKIKO LYNNE CENTENO';
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
                if ($formCode === 'KP Form 8') {
            $htmlToLoad = $this->kp8Html($data, true);
        } elseif ($formCode === 'KP Form 9') {
            $htmlToLoad = $this->kp9Html($data, true);
        } elseif ($formCode === 'KP Form 10') {
            $htmlToLoad = $this->kp10Html($data, true);
        } elseif ($formCode === 'KP Form 11') {
            $htmlToLoad = $this->kp11Html($data, true);
        } elseif ($formCode === 'KP Form 12') {
            $htmlToLoad = $this->kp12Html($data, true);
        } elseif ($formCode === 'KP Form 13') {
            $htmlToLoad = $this->kp13Html($data, true);
        } elseif ($formCode === 'KP Form 14') {
            $htmlToLoad = $this->kp14Html($data, true);
        } elseif ($formCode === 'KP Form 15') {
            $htmlToLoad = $this->kp15Html($data, true);
        } elseif ($formCode === 'KP Form 16') {
            $htmlToLoad = $this->kp16Html($data, true);
        } elseif ($formCode === 'KP Form 17') {
            $htmlToLoad = $this->kp17Html($data, true);
        } elseif ($formCode === 'KP Form 18') {
            $htmlToLoad = $this->kp18Html($data, true);
        } elseif ($formCode === 'KP Form 19' || $formCode === 'Pormularyo ng KP Blg. 19') {
            $htmlToLoad = $this->kp19Html($data, true);
        } elseif ($formCode === 'KP Form 20' || $formCode === 'Pormularyo ng KP Blg. 20') {
            $htmlToLoad = $this->kp20Html($data, true);
        } elseif ($formCode === 'KP Form 20-A' || $formCode === 'Pormularyo ng KP Blg. 20-A') {
            $htmlToLoad = $this->kp20AHtml($data, true);
        } elseif ($formCode === 'KP Form 20-B' || $formCode === 'Pormularyo ng KP Blg. 20-B') {
            $htmlToLoad = $this->kp20BHtml($data, true);
        } elseif ($formCode === 'KP Form 21' || $formCode === 'Pormularyo ng KP Blg. 21') {
            $htmlToLoad = $this->kp21Html($data, true);
        } elseif ($formCode === 'KP Form 22' || $formCode === 'Pormularyo ng KP Blg. 22') {
            $htmlToLoad = $this->kp22Html($data, true);
        } elseif ($formCode === 'KP Form 23' || $formCode === 'Pormularyo ng KP Blg. 23') {
            $htmlToLoad = $this->kp23Html($data, true);
        } elseif ($formCode === 'KP Form 24' || $formCode === 'Pormularyo ng KP Blg. 24') {
            $htmlToLoad = $this->kp24Html($data, true);
        } elseif ($formCode === 'KP Form 25' || $formCode === 'Pormularyo ng KP Blg. 25') {
            $htmlToLoad = $this->kp25Html($data, true);
        } else {
            $htmlToLoad = $this->noticeHtml($formCode, $data);
        }
        $dompdf->loadHtml($htmlToLoad, 'UTF-8');
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


    
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function partyLines(array $parties): string
    {
        if (empty($parties)) {
            return '___________________________';
        }
        $lines = [];
        foreach ($parties as $p) {
            $name = is_array($p) ? ($p['full_name'] ?? '') : (string) $p;
            if (trim($name) !== '') {
                $lines[] = htmlspecialchars(trim($name), ENT_QUOTES, 'UTF-8');
            }
        }
        return !empty($lines) ? implode('<br>', $lines) : '___________________________';
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
        if (!$ts) return $timeStr;
        return date('g:i A', $ts);
    }

    public function renderNoticeHtml(string $formCode, array $data): string
    {
        return $this->noticeHtml($formCode, $data);
    }

    private function noticeHtml(string $formCode, array $data): string
    {
        if ($formCode === 'KP Form 7') {
            return $this->kp7Html($data);
        } elseif ($formCode === 'KP Form 8') {
            return $this->kp8Html($data);
        } elseif ($formCode === 'KP Form 9') {
            return $this->kp9Html($data);
        } elseif ($formCode === 'KP Form 10') {
            return $this->kp10Html($data);
        } elseif ($formCode === 'KP Form 11') {
            return $this->kp11Html($data);
        } elseif ($formCode === 'KP Form 12') {
            return $this->kp12Html($data);
        } elseif ($formCode === 'KP Form 13') {
            return $this->kp13Html($data);
        } elseif ($formCode === 'KP Form 14') {
            return $this->kp14Html($data);
        } elseif ($formCode === 'KP Form 15') {
            return $this->kp15Html($data);
        } elseif ($formCode === 'KP Form 16') {
            return $this->kp16Html($data);
        } elseif ($formCode === 'KP Form 17') {
            return $this->kp17Html($data);
        } elseif ($formCode === 'KP Form 18') {
            return $this->kp18Html($data);
        } elseif ($formCode === 'KP Form 19' || $formCode === 'Pormularyo ng KP Blg. 19') {
            return $this->kp19Html($data);
        } elseif ($formCode === 'KP Form 20' || $formCode === 'Pormularyo ng KP Blg. 20') {
            return $this->kp20Html($data);
        } elseif ($formCode === 'KP Form 20-A' || $formCode === 'Pormularyo ng KP Blg. 20-A') {
            return $this->kp20AHtml($data);
        } elseif ($formCode === 'KP Form 20-B' || $formCode === 'Pormularyo ng KP Blg. 20-B') {
            return $this->kp20BHtml($data);
        } elseif ($formCode === 'KP Form 21' || $formCode === 'Pormularyo ng KP Blg. 21') {
            return $this->kp21Html($data);
        } elseif ($formCode === 'KP Form 22' || $formCode === 'Pormularyo ng KP Blg. 22') {
            return $this->kp22Html($data);
        } elseif ($formCode === 'KP Form 23' || $formCode === 'Pormularyo ng KP Blg. 23') {
            return $this->kp23Html($data);
        } elseif ($formCode === 'KP Form 24' || $formCode === 'Pormularyo ng KP Blg. 24') {
            return $this->kp24Html($data);
        } elseif ($formCode === 'KP Form 25' || $formCode === 'Pormularyo ng KP Blg. 25') {
            return $this->kp25Html($data);
        }

        $complainants = $this->partyLines($data['complainants'] ?? []);
        $respondents = $this->partyLines($data['respondents'] ?? []);
        $caseNumber = $this->escape((string) ($data['case_number'] ?? ''));
        $title = $this->escape((string) ($data['complaint_title'] ?? ''));
        $officialName = $this->escape($this->getAuthorizedCaptainName($data['official_name'] ?? $data['chairman_name'] ?? $data['captain_name'] ?? null));

        $formTitle = 'Pormularyo ng ' . $formCode;

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>' . $formTitle . '</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11pt; color: #111; line-height: 1.4; }
        .header { text-align: center; font-size: 10pt; line-height: 1.25; margin-bottom: 6mm; }
        .office-name { font-weight: bold; font-size: 11pt; margin-top: 2mm; }
        .form-number { font-size: 10pt; font-weight: bold; margin-bottom: 4mm; }
    </style>
</head>
<body>
    <div class="form-number">' . $formTitle . '</div>
    <div class="header">
        <div>Republika ng Pilipinas</div>
        <div>Lalawigan ng NCR — Lungsod ng Marikina</div>
        <div>Barangay Tumana</div>
        <div class="office-name">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>
    </div>
    <div style="margin-top: 10mm;"><p>Opisyal na dokumento ng Katarungang Pambarangay para sa usapin Blg. <strong>' . $caseNumber . '</strong>.</p></div>
    <div style="margin-top: 20mm; text-align: right;">
        <div style="display: inline-block; text-align: center; min-width: 250px;">
            <div style="border-top: 1px solid #111; padding-top: 2mm; font-weight: bold;">' . $officialName . '</div>
            <div style="font-size: 9.5pt;">Punong Barangay / Tagapangulo ng Lupon</div>
        </div>
    </div>
</body>
</html>';
    }

    public function renderKp9Html(array $data, bool $forPdf = false): string
    {
        return $this->kp9Html($data, $forPdf);
    }

    private function kp9Html(array $data, bool $forPdf = false): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $caseNumber = !empty($data['case_number']) ? $this->escape((string) $data['case_number']) : '__________';
        $title = !empty($data['complaint_title']) ? $this->escape((string) $data['complaint_title']) : '__________';
        $venue = !empty($data['venue']) ? $this->escape((string) $data['venue']) : 'Tanggapan ng Punong Barangay';

        $compList = [];
        if (!empty($data['complainants']) && is_array($data['complainants'])) {
            foreach ($data['complainants'] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') $compList[] = trim($cName);
            }
        }
        $respList = [];
        if (!empty($data['respondents']) && is_array($data['respondents'])) {
            foreach ($data['respondents'] as $r) {
                $rName = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
                if (trim($rName) !== '') $respList[] = trim($rName);
            }
        }
        if (empty($respList) && !empty($data['target_party_name'])) {
            $respList[] = trim((string)$data['target_party_name']);
        }

        $compLines = '';
        foreach ($compList as $c) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($c) . '</div>'; }
        for ($i = 0; $i < max(0, 2 - count($compList)); $i++) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $respLines = '';
        foreach ($respList as $r) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($r) . '</div>'; }
        for ($i = 0; $i < max(0, 2 - count($respList)); $i++) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $targetRespLines = '';
        if (empty($respList)) {
            $targetRespLines = '<div style="border-bottom: 1px solid #111; min-height: 20px; margin-bottom: 6px;">&nbsp;</div>';
        } else {
            foreach ($respList as $r) {
                $targetRespLines .= '<div style="border-bottom: 1px solid #111; min-height: 20px; margin-bottom: 6px; font-weight: bold; font-size: 11pt;">' . $this->escape($r) . '</div>';
            }
        }

        $hearingTs = !empty($data['hearing_date']) ? strtotime($data['hearing_date']) : null;
        if ($hearingTs) {
            $hearingDay = date('j', $hearingTs);
            $hearingMonth = $this->filipinoMonth((int) date('n', $hearingTs));
            $hearingYear = date('Y', $hearingTs);
            $hearingTime = date('g:i A', $hearingTs);
        } else {
            $hearingDay = '___'; $hearingMonth = '________'; $hearingYear = '____'; $hearingTime = '_____';
        }

        $noticeTs = !empty($data['notice_date']) ? strtotime($data['notice_date']) : time();
        $noticeDay = date('j', $noticeTs);
        $noticeMonth = $this->filipinoMonth((int) date('n', $noticeTs));
        $noticeYear = date('Y', $noticeTs);

        $officialName = $this->escape($this->getAuthorizedCaptainName($data['official_name'] ?? $data['captain_name'] ?? null));

        $wrapperStart = $forPdf ? '' : '<div class="preview-outer" style="background: #f1f5f9; padding: 14px 10px; min-height: 100%; display: flex; justify-content: center; box-sizing: border-box;"><div class="preview-sheet" style="background: #fff; width: 100%; max-width: 760px; min-height: 960px; padding: 14mm 16mm; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 4px; box-sizing: border-box;">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 9 - PATAWAG</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 11pt; color: #111827; line-height: 1.4; margin: 0; padding: 0; }
        .form-code { font-size: 11pt; font-weight: bold; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-table td { vertical-align: middle; }
        .header-center { text-align: center; font-size: 10pt; line-height: 1.25; }
        .offices { text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 14px; letter-spacing: 0.3px; line-height: 1.35; }
        @media print {
            html, body { background: #fff !important; width: 100% !important; overflow: visible !important; margin: 0 !important; padding: 0 !important; }
            .preview-outer { background: transparent !important; padding: 0 !important; display: block !important; min-height: 0 !important; }
            .preview-sheet { width: 100% !important; max-width: 100% !important; min-height: 0 !important; padding: 0 !important; box-shadow: none !important; border-radius: 0 !important; }
        }
    </style>
</head>
<body>
' . $wrapperStart . '
    <div class="form-code">Pormularyo ng KP Blg. 9</div>

    <table class="header-table">
        <tr>
            <td style="width: 80px; text-align: left;">
                ' . (!empty($tumanaLogo) ? '<img src="' . $tumanaLogo . '" alt="Barangay Tumana" style="width: 72px; height: 72px; object-fit: contain;">' : '') . '
            </td>
            <td class="header-center">
                <div>Republika ng Pilipinas</div>
                <div>Lalawigan ng NCR</div>
                <div>Lungsod ng Marikina</div>
                <div style="font-weight: bold; font-size: 11pt; margin-top: 1px;">Barangay Tumana</div>
                <div style="font-size: 9pt; margin-top: 2px;">Telephone Nos. 8-238-4208</div>
                <div style="font-size: 8.5pt;">EMAIL ADDRESS: barangaytumana2023@gmail.com</div>
            </td>
            <td style="width: 80px; text-align: right;">
                ' . (!empty($marikinaLogo) ? '<img src="' . $marikinaLogo . '" alt="Lungsod ng Marikina" style="width: 72px; height: 72px; object-fit: contain;">' : '') . '
            </td>
        </tr>
    </table>

    <div class="offices">
        <div>TANGGAPAN NG PUNONG BARANGAY</div>
        <div style="margin-top: 2px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>
    </div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
        <tr>
            <td style="width: 52%; vertical-align: top;">
                ' . $compLines . '
                <div style="text-align: center; font-size: 10pt; margin-bottom: 6px; color: #334155;">(Mga) nagrereklamo</div>
                <div style="text-align: center; font-size: 10pt; margin-bottom: 6px; font-style: italic;">-laban kay/kina-</div>
                ' . $respLines . '
                <div style="text-align: center; font-size: 10pt; color: #334155;">(Mga) inirereklamo</div>
            </td>
            <td style="width: 48%; vertical-align: top; text-align: right; padding-left: 16px;">
                <div>Usapin ng Barangay Blg: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px; font-weight: bold; text-align: center;">' . $caseNumber . '</span></div>
                <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px; font-weight: bold; text-align: center;">' . $title . '</span></div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin: 16px 0;">
        <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">PATAWAG</div>
        <div style="font-size: 11pt; font-weight: bold;">(Summons)</div>
    </div>

    <div style="margin-bottom: 18px; font-size: 11pt;">
        <table style="border-collapse: collapse;">
            <tr>
                <td style="vertical-align: top; padding-right: 12px; font-size: 11pt; white-space: nowrap; padding-top: 1px;">Para kay/Kina:</td>
                <td style="vertical-align: top; width: 320px;">
                    ' . $targetRespLines . '
                    <div style="text-align: center; font-size: 10pt; margin-top: 3px; color: #334155;">(Mga) inirereklamo</div>
                </td>
            </tr>
        </table>
    </div>

    <div style="font-size: 11pt; line-height: 2; text-align: justify; margin-bottom: 22px;">
        <p style="text-indent: 40px; margin: 0 0 16px 0;">
            Ikaw ay ipinatawag upang humarap sa akin nang personal kasama ang iyong mga testigo, sa <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px; font-weight: bold; text-align: center;">' . $venue . '</span> ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 45px; font-weight: bold; text-align: center;">' . $hearingDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 100px; font-weight: bold; text-align: center;">' . $hearingMonth . '</span>, <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 50px; font-weight: bold; text-align: center;">' . $hearingYear . '</span> sa ganap na ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 80px; font-weight: bold; text-align: center;">' . $hearingTime . '</span> upang sagutin ang reklamo laban sa iyo na idinulog sa harap ko, kakabit nito ang kopya, para sa pamamagitan/pag-aayos ng inyong mga sigalot ng nagrereklamo/mga nagrereklamo.
        </p>
        <p style="text-indent: 40px; margin: 0 0 16px 0;">
            Binabalaan ka na ang iyong pagtanggi o sadyang di-pagharap upang sundin ang patawag na ito ay maghahadlang sa iyo na makapaghain ng ganting-sumbong na magmumula sa reklamong ito.
        </p>
        <p style="text-indent: 40px; margin: 0 0 16px 0;">
            Kung sakali at mabigo ka, mahaharap ka sa kaparusahang paglapastangan sa hukuman.
        </p>
        <p style="text-indent: 40px; margin: 0;">
            Ngayong Ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 50px; font-weight: bold; text-align: center;">' . $noticeDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 100px; font-weight: bold; text-align: center;">' . $noticeMonth . '</span>, ' . $noticeYear . '.
        </p>
    </div>

    <div style="margin-top: 35px; text-align: right;">
        <div style="display: inline-block; text-align: center; min-width: 290px;">
            <div style="border-top: 1px solid #111; padding-top: 4px; font-weight: bold; font-size: 11pt;">' . $officialName . '</div>
            <div style="font-size: 10pt; margin-top: 2px;">Punong Barangay / Tagapangulo ng Lupon</div>
        </div>
    </div>
' . $wrapperEnd . '
</body>
</html>';
    }

    public function renderKp10Html(array $data, bool $forPdf = false): string
    {
        return $this->kp10Html($data, $forPdf);
    }

    private function kp10Html(array $data, bool $forPdf = false): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $compList = [];
        if (!empty($data['complainants']) && is_array($data['complainants'])) {
            foreach ($data['complainants'] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') $compList[] = trim($cName);
            }
        }
        $respList = [];
        if (!empty($data['respondents']) && is_array($data['respondents'])) {
            foreach ($data['respondents'] as $r) {
                $rName = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
                if (trim($rName) !== '') $respList[] = trim($rName);
            }
        }

        $compLines = '';
        foreach ($compList as $c) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($c) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($compList)); $i++) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $respLines = '';
        foreach ($respList as $r) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($r) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($respList)); $i++) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $constTs = !empty($data['constitution_date']) ? strtotime($data['constitution_date']) : (!empty($data['hearing_date']) ? strtotime($data['hearing_date']) : null);
        if ($constTs) {
            $constDay = date('j', $constTs);
            $constMonth = $this->filipinoMonth((int) date('n', $constTs));
            $constYear = date('Y', $constTs);
            $constTime = date('g:i A', $constTs);
        } else {
            $constDay = '___'; $constMonth = '________'; $constYear = '2026'; $constTime = '_____';
        }

        $noticeTs = !empty($data['notice_date']) ? strtotime($data['notice_date']) : time();
        $noticeDay = date('j', $noticeTs);
        $noticeMonth = $this->filipinoMonth((int) date('n', $noticeTs));
        $noticeYear = date('Y', $noticeTs);

        $officialName = $this->escape($this->getAuthorizedCaptainName($data['official_name'] ?? $data['captain_name'] ?? null));

        $wrapperStart = $forPdf ? '' : '<div style="background: #f1f5f9; padding: 24px 12px; min-height: 100vh; display: flex; justify-content: center;"><div style="background: #fff; width: 100%; max-width: 760px; min-height: 960px; padding: 14mm 16mm; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 4px; box-sizing: border-box;">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 10 - PAABISO SA PAGBUBUO NG PANGKAT</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 11pt; color: #111827; line-height: 1.4; margin: 0; padding: 0; }
        .form-code { font-size: 11pt; font-weight: bold; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-center { text-align: center; font-size: 10pt; line-height: 1.25; }
    </style>
</head>
<body>
' . $wrapperStart . '
    <div class="form-code">Pormularyo ng KP Blg. 10</div>

    <table class="header-table">
        <tr>
            <td style="width: 80px; text-align: left; vertical-align: top;">
                ' . (!empty($tumanaLogo) ? '<img src="' . $tumanaLogo . '" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
            <td class="header-center" style="vertical-align: top;">
                <div>Republika ng Pilipinas</div>
                <div>Lalawigan ng NCR</div>
                <div>Lungsod ng Marikina</div>
                <div style="font-weight: bold;">Barangay Tumana</div>
                <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
            </td>
            <td style="width: 80px; text-align: right; vertical-align: top;">
                ' . (!empty($marikinaLogo) ? '<img src="' . $marikinaLogo . '" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG PUNONG BARANGAY</div>

    <div style="text-align: center; margin: 16px 0;">
        <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">PAABISO SA PAGBUBUO NG PANGKAT</div>
        <div style="font-size: 11.5pt; font-weight: bold;">(Notice for the Constitution of Pangkat)</div>
    </div>

    <div style="font-size: 11pt; margin-bottom: 18px;">
        <div style="margin-bottom: 8px;">Para kay/kina:</div>
        <table style="width: 100%; border-collapse: collapse; font-size: 10.5pt;">
            <tr>
                <td style="width: 48%; vertical-align: top;">
                    ' . $compLines . '
                    <div style="text-align: center; font-size: 10pt;">(Mga) Nagrereklamo</div>
                </td>
                <td style="width: 4%;"></td>
                <td style="width: 48%; vertical-align: top;">
                    ' . $respLines . '
                    <div style="text-align: center; font-size: 10pt;">Mga) Inirereklamo</div>
                </td>
            </tr>
        </table>
    </div>

    <div style="font-size: 11pt; line-height: 2.1; text-align: justify; margin-bottom: 22px;">
        <p style="text-indent: 40px; margin: 0 0 16px 0;">
            Kayo ay hinihilingang humarap sa akin nang personal sa ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 70px; font-weight: bold; text-align: center;">' . $constDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px; font-weight: bold; text-align: center;">' . $constMonth . '</span> ' . $constYear . ', sa ganap na ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 80px; font-weight: bold; text-align: center;">' . $constTime . '</span> ng umaga/hapon/gabi para sa pagbubuo ng Pangkat ng Tagapagkasundo na siyang mag-aayos ng inyong mga sigalot. Kung sakali at mabigo kayong magkasundo sa kasapian ng Pangkat o humarap sa nasabing petsa para magbuo ng Pangkat, pagpapasyahan ko ito sa pamamagitan ng palabunutan.
        </p>
        <p style="text-indent: 40px; margin: 0;">
            Ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; font-weight: bold; text-align: center;">' . $noticeDay . '</span> araw ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px; font-weight: bold; text-align: center;">' . $noticeMonth . '</span>, ' . $noticeYear . '.
        </p>
    </div>

    <div style="margin-top: 35px; display: flex; justify-content: flex-end;">
        <div style="text-align: center; min-width: 280px;">
            <div style="border-top: 1px solid #111; padding-top: 4px; font-weight: bold; font-size: 11pt;">' . $officialName . '</div>
            <div style="font-size: 10pt;">Punong Barangay</div>
        </div>
    </div>

    <div style="margin-top: 25px; font-size: 10.5pt;">
        <div style="margin-bottom: 16px;">
            Ipinagbibigay-alam ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; font-weight: bold; text-align: center;">' . $noticeDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 100px; font-weight: bold; text-align: center;">' . $noticeMonth . '</span>, ' . $noticeYear . '.
        </div>
        <div>Kay/Kina:</div>
        <table style="width: 100%; border-collapse: collapse; margin-top: 22px;">
            <tr>
                <td style="width: 45%; text-align: center;">
                    <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                    <div>(Mga) Nagrereklamo</div>
                </td>
                <td style="width: 10%;"></td>
                <td style="width: 45%; text-align: center;">
                    <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                    <div>(Mga) Inirereklamo</div>
                </td>
            </tr>
        </table>
    </div>
' . $wrapperEnd . '
</body>
</html>';
    }

    public function renderKp11Html(array $data, bool $forPdf = false): string
    {
        return $this->kp11Html($data, $forPdf);
    }

    private function kp11Html(array $data, bool $forPdf = false): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $caseNumber = !empty($data['case_number']) ? $this->escape((string) $data['case_number']) : '__________';
        $title = !empty($data['complaint_title']) ? $this->escape((string) $data['complaint_title']) : '__________';
        $chosenMember = !empty($data['chosen_member']) ? $this->escape((string) $data['chosen_member']) : (!empty($data['target_party_name']) ? $this->escape((string) $data['target_party_name']) : '________________________');

        $compList = [];
        if (!empty($data['complainants']) && is_array($data['complainants'])) {
            foreach ($data['complainants'] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') $compList[] = trim($cName);
            }
        }
        $respList = [];
        if (!empty($data['respondents']) && is_array($data['respondents'])) {
            foreach ($data['respondents'] as $r) {
                $rName = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
                if (trim($rName) !== '') $respList[] = trim($rName);
            }
        }

        $compLines = '';
        foreach ($compList as $c) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($c) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($compList)); $i++) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $respLines = '';
        foreach ($respList as $r) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($r) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($respList)); $i++) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $noticeTs = !empty($data['notice_date']) ? strtotime($data['notice_date']) : time();
        $noticeDay = date('j', $noticeTs);
        $noticeMonth = $this->filipinoMonth((int) date('n', $noticeTs));
        $noticeYear = date('Y', $noticeTs);
        $dateStr = 'ika-' . $noticeDay . ' ng ' . $noticeMonth . ', ' . $noticeYear;

        $officialName = $this->escape($this->getAuthorizedCaptainName($data['official_name'] ?? $data['captain_name'] ?? null));
        $secretaryName = !empty($data['secretary_name']) ? $this->escape((string) $data['secretary_name']) : 'PAMELA D. CONSULTA';

        $wrapperStart = $forPdf ? '' : '<div style="background: #f1f5f9; padding: 24px 12px; min-height: 100vh; display: flex; justify-content: center;"><div style="background: #fff; width: 100%; max-width: 760px; min-height: 960px; padding: 14mm 16mm; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 4px; box-sizing: border-box;">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 11 - PAABISO SA PINILING KASAPI NG PANGKAT</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 11pt; color: #111827; line-height: 1.4; margin: 0; padding: 0; }
        .form-code { font-size: 11pt; font-weight: bold; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-center { text-align: center; font-size: 10pt; line-height: 1.25; }
    </style>
</head>
<body>
' . $wrapperStart . '
    <div class="form-code">Pormularyo ng KP Blg. 11</div>

    <table class="header-table">
        <tr>
            <td style="width: 80px; text-align: left; vertical-align: top;">
                ' . (!empty($tumanaLogo) ? '<img src="' . $tumanaLogo . '" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
            <td class="header-center" style="vertical-align: top;">
                <div>Republika ng Pilipinas</div>
                <div>Lalawigan ng NCR</div>
                <div>Lungsod ng Marikina</div>
                <div style="font-weight: bold;">Barangay Tumana</div>
                <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
            </td>
            <td style="width: 80px; text-align: right; vertical-align: top;">
                ' . (!empty($marikinaLogo) ? '<img src="' . $marikinaLogo . '" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                ' . $compLines . '
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                ' . $respLines . '
                <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                <div>Usapin ng Barangay Blg. <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px; font-weight: bold; text-align: center;">' . $caseNumber . '</span></div>
                <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px; font-weight: bold; text-align: center;">' . $title . '</span></div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin: 16px 0;">
        <div style="font-size: 13pt; font-weight: bold; letter-spacing: 0.5px;">PAABISO SA PINILING KASAPI NG PANGKAT</div>
        <div style="font-size: 11pt; font-weight: bold;">(Notice to Chosen Pangkat Member)</div>
    </div>

    <div style="text-align: right; margin-bottom: 12px; font-size: 10.5pt;">
        <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px; font-weight: bold; text-align: center;">' . $dateStr . '</span> Petsa
    </div>

    <div style="margin-bottom: 18px; font-size: 11pt;">
        Para kay: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 260px; font-weight: bold;">' . $chosenMember . '</span>
    </div>

    <div style="font-size: 11pt; line-height: 2.1; text-align: justify; margin-bottom: 25px;">
        <p style="text-indent: 40px; margin: 0;">
            Isang paabiso ang ibinibigay sa iyo na ikaw ay napiling kasapi ng Pangkat ng Tagapagkasundo upang mapayapang ayusin ang sigalot sa pagitan ng mga panig sa nakasaad sa usapin sa itaas.
        </p>
    </div>

    <div style="margin-top: 25px; display: flex; justify-content: flex-end;">
        <div style="text-align: center; min-width: 280px;">
            <div style="border-top: 1px solid #111; padding-top: 4px; font-weight: bold; font-size: 11pt;">' . $secretaryName . '</div>
            <div style="font-size: 10pt; margin-bottom: 22px;">Kalihim ng Lupon</div>

            <div style="border-top: 1px solid #111; padding-top: 4px; font-weight: bold; font-size: 11pt;">' . $officialName . '</div>
            <div style="font-size: 10pt;">Punong Barangay</div>
        </div>
    </div>

    <div style="margin-top: 22px; font-size: 10.5pt;">
        <div>Tinanggap ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; font-weight: bold; text-align: center;">' . $noticeDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px; font-weight: bold; text-align: center;">' . $noticeMonth . '</span>, 20<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 40px; font-weight: bold; text-align: center;">' . substr($noticeYear, -2) . '</span>.</div>
        <div style="margin-top: 25px; text-align: right;">
            <div style="display: inline-block; text-align: center; min-width: 200px;">
                <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                <div>Kasapi</div>
            </div>
        </div>
    </div>
' . $wrapperEnd . '
</body>
</html>';
    }

    public function renderKp12Html(array $data, bool $forPdf = false): string
    {
        return $this->kp12Html($data, $forPdf);
    }

    private function kp12Html(array $data, bool $forPdf = false): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $caseNumber = !empty($data['case_number']) ? $this->escape((string) $data['case_number']) : '__________';
        $title = !empty($data['complaint_title']) ? $this->escape((string) $data['complaint_title']) : '__________';
        $venue = !empty($data['venue']) ? $this->escape((string) $data['venue']) : 'Tanggapan ng Lupong Tagapamayapa';

        $compList = [];
        if (!empty($data['complainants']) && is_array($data['complainants'])) {
            foreach ($data['complainants'] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') $compList[] = trim($cName);
            }
        }
        $respList = [];
        if (!empty($data['respondents']) && is_array($data['respondents'])) {
            foreach ($data['respondents'] as $r) {
                $rName = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
                if (trim($rName) !== '') $respList[] = trim($rName);
            }
        }

        $compLines = '';
        foreach ($compList as $c) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($c) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($compList)); $i++) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $respLines = '';
        foreach ($respList as $r) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($r) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($respList)); $i++) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $hearingTs = !empty($data['hearing_date']) ? strtotime($data['hearing_date']) : null;
        if ($hearingTs) {
            $hearingDay = date('j', $hearingTs);
            $hearingMonth = $this->filipinoMonth((int) date('n', $hearingTs));
            $hearingYear = date('Y', $hearingTs);
            $hearingTime = date('g:i A', $hearingTs);
        } else {
            $hearingDay = '___'; $hearingMonth = '________'; $hearingYear = '____'; $hearingTime = '_____';
        }

        $noticeTs = !empty($data['notice_date']) ? strtotime($data['notice_date']) : time();
        $noticeDay = date('j', $noticeTs);
        $noticeMonth = $this->filipinoMonth((int) date('n', $noticeTs));
        $noticeYear = date('Y', $noticeTs);

        $chairmanPangkat = !empty($data['chairman_name']) ? $this->escape((string) $data['chairman_name']) : (!empty($data['pangkat_chairman']) ? $this->escape((string) $data['pangkat_chairman']) : '&nbsp;');

        $wrapperStart = $forPdf ? '' : '<div style="background: #f1f5f9; padding: 24px 12px; min-height: 100vh; display: flex; justify-content: center;"><div style="background: #fff; width: 100%; max-width: 760px; min-height: 960px; padding: 14mm 16mm; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 4px; box-sizing: border-box;">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 12 - PAABISO NG PAGDINIG</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 11pt; color: #111827; line-height: 1.4; margin: 0; padding: 0; }
        .form-code { font-size: 11pt; font-weight: bold; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-center { text-align: center; font-size: 10pt; line-height: 1.25; }
    </style>
</head>
<body>
' . $wrapperStart . '
    <div class="form-code">Pormularyo ng KP Blg. 12</div>

    <table class="header-table">
        <tr>
            <td style="width: 80px; text-align: left; vertical-align: top;">
                ' . (!empty($tumanaLogo) ? '<img src="' . $tumanaLogo . '" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
            <td class="header-center" style="vertical-align: top;">
                <div>Republika ng Pilipinas</div>
                <div>Lalawigan ng NCR</div>
                <div>Lungsod ng Marikina</div>
                <div style="font-weight: bold;">Barangay Tumana</div>
                <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
            </td>
            <td style="width: 80px; text-align: right; vertical-align: top;">
                ' . (!empty($marikinaLogo) ? '<img src="' . $marikinaLogo . '" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                ' . $compLines . '
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                ' . $respLines . '
                <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                <div>Usapin ng Barangay Blg. <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px; font-weight: bold; text-align: center;">' . $caseNumber . '</span></div>
                <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px; font-weight: bold; text-align: center;">' . $title . '</span></div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin: 16px 0;">
        <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">PAABISO NG PAGDINIG</div>
        <div style="font-size: 11.5pt; font-weight: bold;">(Conciliation Proceedings)</div>
    </div>

    <div style="font-size: 11pt; line-height: 2.1; text-align: justify; margin-bottom: 25px;">
        <p style="text-indent: 40px; margin: 0;">
            Ikaw ay hinihilingang humarap sa Pangkat sa <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 150px; font-weight: bold;">' . $venue . '</span> araw ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 90px; font-weight: bold; text-align: center;">' . $hearingDay . ' ng ' . $hearingMonth . '</span>, <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 50px; font-weight: bold; text-align: center;">' . $hearingYear . '</span> sa ganap na ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 70px; font-weight: bold; text-align: center;">' . $hearingTime . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 80px; font-weight: bold; text-align: center;">' . $hearingMonth . '</span> para sa pagdinig ng usaping nakasaad sa itaas.
        </p>
    </div>

    <div style="margin-top: 35px; display: flex; justify-content: flex-end;">
        <div style="text-align: center; min-width: 280px;">
            <div style="border-top: 1px solid #111; padding-top: 4px; font-weight: bold; font-size: 11pt;">' . $chairmanPangkat . '</div>
            <div style="font-size: 10pt;">Tagapangulo ng Pangkat</div>
        </div>
    </div>

    <div style="margin-top: 25px; font-size: 10.5pt;">
        <div style="margin-bottom: 20px;">
            Ipinagbibigay-alam ngayong <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 80px; font-weight: bold; text-align: center;">' . $noticeDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 100px; font-weight: bold; text-align: center;">' . $noticeMonth . '</span>, <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 50px; font-weight: bold; text-align: center;">' . $noticeYear . '</span>.
        </div>

        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 45%; text-align: center;">
                    <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                    <div>(Mga) Nagrereklamo</div>
                </td>
                <td style="width: 10%;"></td>
                <td style="width: 45%; text-align: center;">
                    <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                    <div>(Mga) Inirereklamo</div>
                </td>
            </tr>
        </table>
    </div>
' . $wrapperEnd . '
</body>
</html>';
    }


    public function renderKp8Html(array $data, bool $forPdf = false): string
    {
        return $this->kp8Html($data, $forPdf);
    }

    private function kp8Html(array $data, bool $forPdf = false): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $caseNumber = !empty($data['case_number']) ? $this->escape((string) $data['case_number']) : '__________';
        $title = !empty($data['complaint_title']) ? $this->escape((string) $data['complaint_title']) : '__________';
        $venue = !empty($data['venue']) ? $this->escape((string) $data['venue']) : 'Tanggapan ng Punong Barangay';

        // Complainants list
        $compList = [];
        if (!empty($data['complainants']) && is_array($data['complainants'])) {
            foreach ($data['complainants'] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') {
                    $compList[] = trim($cName);
                }
            }
        }
        if (empty($compList) && !empty($data['target_party_name'])) {
            $compList[] = trim((string)$data['target_party_name']);
        }

        $compLinesHtml = '';
        if (empty($compList)) {
            $compLinesHtml = '
                <div style="border-bottom: 1px solid #111; min-height: 20px; margin-bottom: 6px;">&nbsp;</div>
            ';
        } else {
            foreach ($compList as $idx => $cName) {
                $compLinesHtml .= '<div style="border-bottom: 1px solid #111; min-height: 20px; margin-bottom: 6px; font-weight: bold; font-size: 11pt;">' . $this->escape($cName) . '</div>';
            }
        }

        $hearingTs = !empty($data['hearing_date']) ? strtotime($data['hearing_date']) : null;
        if ($hearingTs) {
            $hearingDateFormatted = 'ika-' . date('j', $hearingTs) . ' ng ' . $this->filipinoMonth((int) date('n', $hearingTs)) . ', ' . date('Y', $hearingTs);
            $hearingTimeFormatted = date('g:i A', $hearingTs);
        } else {
            $hearingDateFormatted = '________________________';
            $hearingTimeFormatted = '_________';
        }

        $noticeTs = !empty($data['notice_date']) ? strtotime($data['notice_date']) : time();
        $noticeDay = date('j', $noticeTs);
        $noticeMonth = $this->filipinoMonth((int) date('n', $noticeTs));
        $noticeYear = date('Y', $noticeTs);

        $officialName = $this->escape($this->getAuthorizedCaptainName($data['official_name'] ?? $data['captain_name'] ?? null));

        $bodyContent = '
            <p style="text-indent: 40px; margin: 0 0 20px 0; text-align: justify; line-height: 2;">
                Ikaw ay hinihilingang humarap sa akin sa <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 180px; font-weight: bold; text-align: center;">' . $venue . '</span>, <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px; font-weight: bold; text-align: center;">' . $hearingDateFormatted . '</span>, sa ganap na ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 90px; font-weight: bold; text-align: center;">' . $hearingTimeFormatted . '</span> para sa pagdinig ng iyong reklamo.
            </p>
            <p style="text-indent: 40px; margin: 0; text-align: justify; line-height: 2;">
                Ngayong Ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; text-align: center; font-weight: bold;">' . $noticeDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px; text-align: center; font-weight: bold;">' . $noticeMonth . '</span>, ' . $noticeYear . '.
            </p>
        ';

        $wrapperStart = $forPdf ? '' : '<div class="preview-outer" style="background: #f1f5f9; padding: 14px 10px; min-height: 100%; display: flex; justify-content: center; box-sizing: border-box;"><div class="preview-sheet" style="background: #fff; width: 100%; max-width: 760px; min-height: 960px; padding: 14mm 16mm; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 4px; box-sizing: border-box;">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 8 - PAABISO NG PAGDINIG</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 11pt; color: #111827; line-height: 1.4; margin: 0; padding: 0; }
        .form-code { font-size: 11pt; font-weight: bold; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-table td { vertical-align: middle; }
        .header-center { text-align: center; font-size: 10pt; line-height: 1.25; }
        .offices { text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 14px; letter-spacing: 0.3px; line-height: 1.35; }
        .case-meta { text-align: right; font-size: 10.5pt; line-height: 1.6; margin-bottom: 18px; }
        .form-title-block { text-align: center; margin-bottom: 20px; }
        .form-title { font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px; }
        .form-sub { font-size: 11pt; font-weight: bold; }
        .addressee-block { margin-bottom: 18px; }
        .sig-block { margin-top: 35px; margin-bottom: 35px; text-align: right; }
        .sig-box { display: inline-block; text-align: center; min-width: 280px; }
        .sig-line { border-top: 1px solid #111; padding-top: 4px; font-weight: bold; font-size: 11pt; }
        .sig-title { font-size: 10pt; margin-top: 2px; }
        .ack-section { margin-top: 25px; font-size: 11pt; }
        .ack-box { display: inline-block; text-align: center; min-width: 250px; }
        @media print {
            html, body { background: #fff !important; width: 100% !important; overflow: visible !important; margin: 0 !important; padding: 0 !important; }
            .preview-outer { background: transparent !important; padding: 0 !important; display: block !important; min-height: 0 !important; }
            .preview-sheet { width: 100% !important; max-width: 100% !important; min-height: 0 !important; padding: 0 !important; box-shadow: none !important; border-radius: 0 !important; }
        }
    </style>
</head>
<body>
' . $wrapperStart . '
    <div class="form-code">Pormularyo ng KP Blg. 8</div>

    <table class="header-table">
        <tr>
            <td style="width: 80px; text-align: left;">
                ' . (!empty($tumanaLogo) ? '<img src="' . $tumanaLogo . '" alt="Barangay Tumana" style="width: 72px; height: 72px; object-fit: contain;">' : '') . '
            </td>
            <td class="header-center">
                <div>Republika ng Pilipinas</div>
                <div>Lalawigan ng NCR</div>
                <div>Lungsod ng Marikina</div>
                <div style="font-weight: bold; font-size: 11pt; margin-top: 1px;">Barangay Tumana</div>
                <div style="font-size: 9pt; margin-top: 2px;">Telephone Nos. 8-238-4208</div>
                <div style="font-size: 8.5pt;">EMAIL ADDRESS: barangaytumana2023@gmail.com</div>
            </td>
            <td style="width: 80px; text-align: right;">
                ' . (!empty($marikinaLogo) ? '<img src="' . $marikinaLogo . '" alt="Lungsod ng Marikina" style="width: 72px; height: 72px; object-fit: contain;">' : '') . '
            </td>
        </tr>
    </table>

    <div class="offices">
        <div>TANGGAPAN NG PUNONG BARANGAY</div>
        <div style="margin-top: 2px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>
    </div>

    <div class="case-meta">
        <div>Usapin ng Barangay Blg: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px; text-align: center; font-weight: bold;">' . $caseNumber . '</span></div>
        <div style="margin-top: 4px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px; text-align: center; font-weight: bold;">' . $title . '</span></div>
    </div>

    <div class="form-title-block">
        <div class="form-title">PAABISO NG PAGDINIG</div>
        <div class="form-sub">(Mediation Proceedings)</div>
    </div>

    <div class="addressee-block">
        <table style="border-collapse: collapse;">
            <tr>
                <td style="vertical-align: top; padding-right: 12px; font-size: 11pt; white-space: nowrap; padding-top: 1px;">
                    Para kay/Kina:
                </td>
                <td style="vertical-align: top; width: 320px;">
                    ' . $compLinesHtml . '
                    <div style="text-align: center; font-size: 10pt; margin-top: 3px; color: #334155;">(Mga) Nagrereklamo</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="body-section">
        ' . $bodyContent . '
    </div>

    <div class="sig-block">
        <div class="sig-box">
            <div class="sig-line">' . $officialName . '</div>
            <div class="sig-title">Tagapangulo ng Lupon</div>
        </div>
    </div>

    <div class="ack-section">
        <div style="margin-bottom: 25px;">
            Ipinagbigay-alam ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; text-align: center; font-weight: bold;">' . $noticeDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px; text-align: center; font-weight: bold;">' . $noticeMonth . '</span>, ' . $noticeYear . '.
        </div>

        <div style="text-align: right;">
            <div class="ack-box">
                <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                <div style="font-size: 10.5pt;">(Mga) Nagrereklamo</div>
            </div>
        </div>
    </div>
' . $wrapperEnd . '
</body>
</html>';
    }


    public function renderKp13Html(array $data, bool $forPdf = false): string
    {
        return $this->kp13Html($data, $forPdf);
    }

    private function kp13Html(array $data, bool $forPdf = false): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $caseNumber = !empty($data['case_number']) ? $this->escape((string) $data['case_number']) : '__________';
        $title = !empty($data['complaint_title']) ? $this->escape((string) $data['complaint_title']) : '__________';
        $witnessNames = !empty($data['witness_names']) ? $this->escape((string) $data['witness_names']) : (!empty($data['target_party_name']) ? $this->escape((string) $data['target_party_name']) : '________________________');

        $compList = [];
        if (!empty($data['complainants']) && is_array($data['complainants'])) {
            foreach ($data['complainants'] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') $compList[] = trim($cName);
            }
        }
        $respList = [];
        if (!empty($data['respondents']) && is_array($data['respondents'])) {
            foreach ($data['respondents'] as $r) {
                $rName = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
                if (trim($rName) !== '') $respList[] = trim($rName);
            }
        }

        $compLines = '';
        foreach ($compList as $c) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($c) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($compList)); $i++) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $respLines = '';
        foreach ($respList as $r) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($r) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($respList)); $i++) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $hearingTs = !empty($data['hearing_date']) ? strtotime($data['hearing_date']) : null;
        if ($hearingTs) {
            $hDay = date('j', $hearingTs);
            $hMonth = $this->filipinoMonth((int) date('n', $hearingTs));
            $hTime = date('g:i A', $hearingTs);
        } else {
            $hDay = '___'; $hMonth = '________'; $hTime = '_____';
        }

        $noticeTs = !empty($data['notice_date']) ? strtotime($data['notice_date']) : time();
        $noticeDay = date('j', $noticeTs);
        $noticeMonth = $this->filipinoMonth((int) date('n', $noticeTs));
        $noticeYear = date('Y', $noticeTs);

        $officialName = $this->escape($this->getAuthorizedCaptainName($data['official_name'] ?? $data['captain_name'] ?? null));

        $wrapperStart = $forPdf ? '' : '<div style="background: #f1f5f9; padding: 24px 12px; min-height: 100vh; display: flex; justify-content: center;"><div style="background: #fff; width: 100%; max-width: 760px; min-height: 960px; padding: 14mm 16mm; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 4px; box-sizing: border-box;">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 13 - UTOS UPANG HUMARAP</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 11pt; color: #111827; line-height: 1.4; margin: 0; padding: 0; }
        .form-code { font-size: 11pt; font-weight: bold; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-center { text-align: center; font-size: 10pt; line-height: 1.25; }
    </style>
</head>
<body>
' . $wrapperStart . '
    <div class="form-code">Pormularyo ng KP Blg. 13</div>

    <table class="header-table">
        <tr>
            <td style="width: 80px; text-align: left; vertical-align: top;">
                ' . (!empty($tumanaLogo) ? '<img src="' . $tumanaLogo . '" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
            <td class="header-center" style="vertical-align: top;">
                <div>Republika ng Pilipinas</div>
                <div>Lalawigan ng NCR</div>
                <div>Lungsod ng Marikina</div>
                <div style="font-weight: bold;">Barangay Tumana</div>
                <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
            </td>
            <td style="width: 80px; text-align: right; vertical-align: top;">
                ' . (!empty($marikinaLogo) ? '<img src="' . $marikinaLogo . '" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                ' . $compLines . '
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                ' . $respLines . '
                <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                <div>Usapin ng Barangay Blg. <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px; font-weight: bold; text-align: center;">' . $caseNumber . '</span></div>
                <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px; font-weight: bold; text-align: center;">' . $title . '</span></div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin: 16px 0;">
        <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">UTOS UPANG HUMARAP</div>
        <div style="font-size: 11.5pt; font-weight: bold;">(Subpoena)</div>
    </div>

    <div style="margin-bottom: 16px; font-size: 11pt;">
        <div>Para kay/kina: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 250px; font-weight: bold;">' . $witnessNames . '</span></div>
        <div style="text-align: center; font-size: 10.5pt; margin-top: 4px; font-weight: bold;">Mga Testigo</div>
    </div>

    <div style="font-size: 11pt; line-height: 2.1; text-align: justify; margin-bottom: 25px;">
        <p style="text-indent: 40px; margin: 0 0 16px 0;">
            Ikaw ay inuutusan na humarap sa akin sa ika - <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; font-weight: bold; text-align: center;">' . $hDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px; font-weight: bold; text-align: center;">' . $hMonth . '</span> sa ganap na ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 80px; font-weight: bold; text-align: center;">' . $hTime . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 100px; font-weight: bold; text-align: center;">' . $hMonth . '</span> upang tumestigo sa pagdinig sa usaping nakasaad sa itaas.
        </p>
        <p style="text-indent: 40px; margin: 0;">
            Ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; font-weight: bold; text-align: center;">' . $noticeDay . '</span> araw ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px; font-weight: bold; text-align: center;">' . $noticeMonth . '</span>, <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 50px; font-weight: bold; text-align: center;">' . $noticeYear . '</span>.
        </p>
    </div>

    <div style="margin-top: 35px; display: flex; justify-content: flex-end;">
        <div style="text-align: center; min-width: 310px;">
            <div style="border-top: 1px solid #111; padding-top: 4px; font-weight: bold; font-size: 11pt;">' . $officialName . '</div>
            <div style="font-size: 10pt;">Punong Barangay/Tagapangulo ng Pangkat</div>
        </div>
    </div>
' . $wrapperEnd . '
</body>
</html>';
    }

    public function renderKp14Html(array $data, bool $forPdf = false): string
    {
        return $this->kp14Html($data, $forPdf);
    }

    private function kp14Html(array $data, bool $forPdf = false): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $caseNumber = !empty($data['case_number']) ? $this->escape((string) $data['case_number']) : '__________';
        $title = !empty($data['complaint_title']) ? $this->escape((string) $data['complaint_title']) : '__________';

        $compList = [];
        if (!empty($data['complainants']) && is_array($data['complainants'])) {
            foreach ($data['complainants'] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') $compList[] = trim($cName);
            }
        }
        $respList = [];
        if (!empty($data['respondents']) && is_array($data['respondents'])) {
            foreach ($data['respondents'] as $r) {
                $rName = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
                if (trim($rName) !== '') $respList[] = trim($rName);
            }
        }

        $compLines = '';
        foreach ($compList as $c) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($c) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($compList)); $i++) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $respLines = '';
        foreach ($respList as $r) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($r) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($respList)); $i++) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $noticeTs = !empty($data['notice_date']) ? strtotime($data['notice_date']) : time();
        $noticeDay = date('j', $noticeTs);
        $noticeMonth = $this->filipinoMonth((int) date('n', $noticeTs));
        $noticeYear = date('Y', $noticeTs);

        $officialName = $this->escape($this->getAuthorizedCaptainName($data['official_name'] ?? $data['captain_name'] ?? null));

        $wrapperStart = $forPdf ? '' : '<div style="background: #f1f5f9; padding: 24px 12px; min-height: 100vh; display: flex; justify-content: center;"><div style="background: #fff; width: 100%; max-width: 760px; min-height: 960px; padding: 14mm 16mm; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 4px; box-sizing: border-box;">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 14 - KASUNDUAN PARA SA PAGHAHATOL</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 11pt; color: #111827; line-height: 1.4; margin: 0; padding: 0; }
        .form-code { font-size: 11pt; font-weight: bold; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-center { text-align: center; font-size: 10pt; line-height: 1.25; }
    </style>
</head>
<body>
' . $wrapperStart . '
    <div class="form-code">Pormularyo ng KP Blg. 14</div>

    <table class="header-table">
        <tr>
            <td style="width: 80px; text-align: left; vertical-align: top;">
                ' . (!empty($tumanaLogo) ? '<img src="' . $tumanaLogo . '" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
            <td class="header-center" style="vertical-align: top;">
                <div>Republika ng Pilipinas</div>
                <div>Lalawigan ng NCR</div>
                <div>Lungsod ng Marikina</div>
                <div style="font-weight: bold;">Barangay Tumana</div>
                <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
            </td>
            <td style="width: 80px; text-align: right; vertical-align: top;">
                ' . (!empty($marikinaLogo) ? '<img src="' . $marikinaLogo . '" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                ' . $compLines . '
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                ' . $respLines . '
                <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                <div>Usapin ng Barangay Blg. <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px; font-weight: bold; text-align: center;">' . $caseNumber . '</span></div>
                <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px; font-weight: bold; text-align: center;">' . $title . '</span></div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin: 16px 0;">
        <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">KASUNDUAN PARA SA PAGHAHATOL</div>
        <div style="font-size: 11.5pt; font-weight: bold;">(Agreement for Arbitration)</div>
    </div>

    <div style="font-size: 11pt; line-height: 2; text-align: justify; margin-bottom: 20px;">
        <p style="text-indent: 40px; margin: 0 0 16px 0;">
            Kami ay nagkasundo na idulog ang aming sigalot sa paghahatol sa Punong Barangay/Pangkat ng Tagapagkasundo (Alisin ang di-angkop) at pagkakaisahan namin na aming susundin ang hatol na maaaring igawad dito. Ginawa namin ang kasunduang ito nang malaya at kusang-loob na mayroong ganap na pang-unawa sa anumang uri at magiging bunga nito.
        </p>
        <p style="text-indent: 40px; margin: 0;">
            Itinala ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; font-weight: bold; text-align: center;">' . $noticeDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px; font-weight: bold; text-align: center;">' . $noticeMonth . '</span>, 20<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 40px; font-weight: bold; text-align: center;">' . substr($noticeYear, -2) . '</span>.
        </p>
    </div>

    <table style="width: 100%; border-collapse: collapse; margin-top: 20px; margin-bottom: 20px;">
        <tr>
            <td style="width: 45%; text-align: center;">
                <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                <div>(Mga) Nagrereklamo</div>
            </td>
            <td style="width: 10%;"></td>
            <td style="width: 45%; text-align: center;">
                <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                <div>(Mga) Inirereklamo</div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin: 18px 0 8px;">PAGPAPATOTOO</div>
    <div style="font-size: 10.5pt; line-height: 1.8; text-align: justify; margin-bottom: 22px;">
        Pinapatunayan ko na ang sinusundang Kasunduan ng Paghahatol ay malaya at kusang-loob na ginawa ng mga panig, matapos na aking maipaliwanag sa kanila ang uri at ang magiging bunga nito ng gayong kasunduan.
    </div>

    <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
        <tr>
            <td style="width: 45%; vertical-align: top;">
                <div style="font-weight: bold; font-size: 11pt;">' . $officialName . '</div>
                <div style="font-size: 10pt;">Punong Barangay</div>
            </td>
            <td style="width: 10%;"></td>
            <td style="width: 45%; vertical-align: top; text-align: center;">
                <div style="border-top: 1px solid #111; padding-top: 4px; font-size: 10pt;">Tagapangulo ng Pangkat</div>
            </td>
        </tr>
    </table>
' . $wrapperEnd . '
</body>
</html>';
    }

    public function renderKp15Html(array $data, bool $forPdf = false): string
    {
        return $this->kp15Html($data, $forPdf);
    }

    private function kp15Html(array $data, bool $forPdf = false): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $caseNumber = !empty($data['case_number']) ? $this->escape((string) $data['case_number']) : '__________';
        $title = !empty($data['complaint_title']) ? $this->escape((string) $data['complaint_title']) : '__________';
        $awardDetails = !empty($data['award_details']) ? $this->escape((string) $data['award_details']) : (!empty($data['agreement_details']) ? $this->escape((string) $data['agreement_details']) : '');

        $compList = [];
        if (!empty($data['complainants']) && is_array($data['complainants'])) {
            foreach ($data['complainants'] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') $compList[] = trim($cName);
            }
        }
        $respList = [];
        if (!empty($data['respondents']) && is_array($data['respondents'])) {
            foreach ($data['respondents'] as $r) {
                $rName = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
                if (trim($rName) !== '') $respList[] = trim($rName);
            }
        }

        $compLines = '';
        foreach ($compList as $c) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($c) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($compList)); $i++) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $respLines = '';
        foreach ($respList as $r) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($r) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($respList)); $i++) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $noticeTs = !empty($data['notice_date']) ? strtotime($data['notice_date']) : time();
        $noticeDay = date('j', $noticeTs);
        $noticeMonth = $this->filipinoMonth((int) date('n', $noticeTs));
        $noticeYear = date('Y', $noticeTs);

        $wrapperStart = $forPdf ? '' : '<div style="background: #f1f5f9; padding: 24px 12px; min-height: 100vh; display: flex; justify-content: center;"><div style="background: #fff; width: 100%; max-width: 760px; min-height: 960px; padding: 14mm 16mm; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 4px; box-sizing: border-box;">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 15 - GAWAD NG PAGHAHATOL</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 11pt; color: #111827; line-height: 1.4; margin: 0; padding: 0; }
        .form-code { font-size: 11pt; font-weight: bold; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-center { text-align: center; font-size: 10pt; line-height: 1.25; }
    </style>
</head>
<body>
' . $wrapperStart . '
    <div class="form-code">Pormularyo ng KP Blg. 15</div>

    <table class="header-table">
        <tr>
            <td style="width: 80px; text-align: left; vertical-align: top;">
                ' . (!empty($tumanaLogo) ? '<img src="' . $tumanaLogo . '" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
            <td class="header-center" style="vertical-align: top;">
                <div>Republika ng Pilipinas</div>
                <div>Lalawigan ng NCR</div>
                <div>Lungsod ng Marikina</div>
                <div style="font-weight: bold;">Barangay Tumana</div>
                <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
            </td>
            <td style="width: 80px; text-align: right; vertical-align: top;">
                ' . (!empty($marikinaLogo) ? '<img src="' . $marikinaLogo . '" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                ' . $compLines . '
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                ' . $respLines . '
                <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                <div>Usapin ng Barangay Blg. <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px; font-weight: bold; text-align: center;">' . $caseNumber . '</span></div>
                <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px; font-weight: bold; text-align: center;">' . $title . '</span></div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin: 16px 0;">
        <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">GAWAD NG PAGHAHATOL</div>
        <div style="font-size: 11.5pt; font-weight: bold;">(Arbitration Award)</div>
    </div>

    <div style="font-size: 11pt; line-height: 2; text-align: justify; margin-bottom: 20px;">
        <p style="text-indent: 40px; margin: 0 0 14px 0;">
            Matapos na marinig ang mga salaysay na ipinahayag at sa maingat na pagsusuri ng ebidensya na iniharap sa usaping ito, ang hatol ay ginawa tulad ng sumusunod:
        </p>
        <div style="border-bottom: 1px solid #111; min-height: 22px; margin-bottom: 10px; font-weight: bold;">' . ($awardDetails ?: '&nbsp;') . '</div>
        <div style="border-bottom: 1px solid #111; min-height: 22px; margin-bottom: 14px;">&nbsp;</div>
        <p style="margin: 0;">
            Ginawa ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; font-weight: bold; text-align: center;">' . $noticeDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px; font-weight: bold; text-align: center;">' . $noticeMonth . '</span>, 20<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 40px; font-weight: bold; text-align: center;">' . substr($noticeYear, -2) . '</span> sa Barangay Tumana, Marikina City.
        </p>
    </div>

    <div style="margin-top: 25px; display: flex; justify-content: flex-end;">
        <div style="text-align: center; min-width: 260px;">
            <div style="border-top: 1px solid #111; padding-top: 4px; font-size: 10pt; margin-bottom: 20px;">Tagapangulo ng Pangkat</div>
            <div style="border-top: 1px solid #111; padding-top: 4px; font-size: 10pt; margin-bottom: 20px;">Kasapi</div>
            <div style="border-top: 1px solid #111; padding-top: 4px; font-size: 10pt;">Kasapi</div>
        </div>
    </div>

    <div style="margin-top: 22px; font-size: 10pt;">
        <div style="font-weight: bold; margin-bottom: 18px;">Pinatotohanan:</div>
        <div style="width: 250px; border-top: 1px solid #111; padding-top: 4px; font-weight: bold;">
            Punong Barangay/Kalihim ng Lupon**
        </div>
        <div style="margin-top: 22px; font-size: 8.5pt; color: #475569; line-height: 1.4;">
            <div>*Lalagdaan ng sinumang gumawa ng gawad ng paghahatol.</div>
            <div>**Lalagdaan ng Punong Barangay kung ang gawad ay ginawa ng Tagapangulo ng Pangkat, at ng Kalihim ng Lupon, kung ginawa ng Punong Barangay.</div>
        </div>
    </div>
' . $wrapperEnd . '
</body>
</html>';
    }


    public function renderKp16Html(array $data, bool $forPdf = false): string
    {
        return $this->kp16Html($data, $forPdf);
    }

    private function kp16Html(array $data, bool $forPdf = false): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $caseNumber = !empty($data['case_number']) ? $this->escape((string) $data['case_number']) : '__________';
        $title = !empty($data['complaint_title']) ? $this->escape((string) $data['complaint_title']) : '__________';
        $agreementDetails = !empty($data['agreement_details']) ? $this->escape((string) $data['agreement_details']) : '';

        $compList = [];
        if (!empty($data['complainants']) && is_array($data['complainants'])) {
            foreach ($data['complainants'] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') $compList[] = trim($cName);
            }
        }
        $respList = [];
        if (!empty($data['respondents']) && is_array($data['respondents'])) {
            foreach ($data['respondents'] as $r) {
                $rName = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
                if (trim($rName) !== '') $respList[] = trim($rName);
            }
        }

        $compLines = '';
        foreach ($compList as $c) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($c) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($compList)); $i++) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $respLines = '';
        foreach ($respList as $r) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($r) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($respList)); $i++) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $noticeTs = !empty($data['settlement_date']) ? strtotime($data['settlement_date']) : (!empty($data['notice_date']) ? strtotime($data['notice_date']) : time());
        $noticeDay = date('j', $noticeTs);
        $noticeMonth = $this->filipinoMonth((int) date('n', $noticeTs));
        $noticeYear = date('Y', $noticeTs);

        $officialName = $this->escape($this->getAuthorizedCaptainName($data['official_name'] ?? $data['captain_name'] ?? null));

        $wrapperStart = $forPdf ? '' : '<div style="background: #f1f5f9; padding: 24px 12px; min-height: 100vh; display: flex; justify-content: center;"><div style="background: #fff; width: 100%; max-width: 760px; min-height: 960px; padding: 14mm 16mm; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 4px; box-sizing: border-box;">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 16 - MATIWASAY NA PAG-AAYOS</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 11pt; color: #111827; line-height: 1.4; margin: 0; padding: 0; }
        .form-code { font-size: 11pt; font-weight: bold; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-center { text-align: center; font-size: 10pt; line-height: 1.25; }
    </style>
</head>
<body>
' . $wrapperStart . '
    <div class="form-code">Pormularyo ng KP Blg. 16</div>

    <table class="header-table">
        <tr>
            <td style="width: 80px; text-align: left; vertical-align: top;">
                ' . (!empty($tumanaLogo) ? '<img src="' . $tumanaLogo . '" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
            <td class="header-center" style="vertical-align: top;">
                <div>Republika ng Pilipinas</div>
                <div>Lalawigan ng NCR</div>
                <div>Lungsod ng Marikina</div>
                <div style="font-weight: bold;">Barangay Tumana</div>
                <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
            </td>
            <td style="width: 80px; text-align: right; vertical-align: top;">
                ' . (!empty($marikinaLogo) ? '<img src="' . $marikinaLogo . '" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                ' . $compLines . '
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                ' . $respLines . '
                <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                <div>Usapin ng Barangay Blg. <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px; font-weight: bold; text-align: center;">' . $caseNumber . '</span></div>
                <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px; font-weight: bold; text-align: center;">' . $title . '</span></div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin: 16px 0;">
        <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">MATIWASAY NA PAG-AAYOS</div>
        <div style="font-size: 11.5pt; font-weight: bold;">(Amicable Settlement)</div>
    </div>

    <div style="font-size: 11pt; line-height: 1.8; margin-bottom: 14px;">
        KAMI, mga nagrereklamo/at inirereklamo sa usaping nakasaad sa itaas, ay nagkasundo na aayusin ang aming sigalot sa mga sumusunod:
    </div>

    <div style="margin-bottom: 16px; font-size: 10.5pt; line-height: 1.7;">
        ' . ($agreementDetails ? '<div style="border: 1px solid #999; padding: 10px 14px; background: #fafafa; margin-bottom: 10px;">' . nl2br($agreementDetails) . '</div>' : '
            <div style="border-bottom: 1px solid #111; min-height: 20px; margin-bottom: 6px;">&nbsp;</div>
            <div style="border-bottom: 1px solid #111; min-height: 20px; margin-bottom: 6px;">&nbsp;</div>
            <div style="border-bottom: 1px solid #111; min-height: 20px; margin-bottom: 6px;">&nbsp;</div>
            <div style="border-bottom: 1px solid #111; min-height: 20px; margin-bottom: 6px;">&nbsp;</div>
        ') . '
    </div>

    <div style="font-size: 10.5pt; line-height: 1.8; margin-bottom: 14px;">
        At magkakaisa na tapat naming susundin ang mga kondisyon sa pag-aayos na nakasaad sa itaas.
    </div>

    <div style="font-size: 10.5pt; margin-bottom: 18px;">
        Itinala ngayong <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; font-weight: bold; text-align: center;">' . $noticeDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px; font-weight: bold; text-align: center;">' . $noticeMonth . '</span>, 20<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 40px; font-weight: bold; text-align: center;">' . substr($noticeYear, -2) . '</span>.
    </div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
        <tr>
            <td style="width: 45%; text-align: center;">
                <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                <div>(Mga) Nagrereklamo</div>
            </td>
            <td style="width: 10%;"></td>
            <td style="width: 45%; text-align: center;">
                <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                <div>(Mga) Inirereklamo</div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-bottom: 6px;">PAGPAPATOTOO</div>
    <div style="font-size: 10pt; line-height: 1.7; text-align: justify; margin-bottom: 22px;">
        PINATUTUNAYAN ko ang sinusundang Matiwasay na Pag-aayos na ginawa ng mga panig ay malaya at kusang-loob, matapos na aking maipaliwanag sa kanila ang uri at magiging bunga ng gayong pag-aayos.
    </div>

    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="width: 45%; vertical-align: top;">
                <div style="border-top: 1px solid #111; width: 220px; margin-bottom: 4px;"></div>
                <div style="font-weight: bold; font-size: 10.5pt;">' . $officialName . '</div>
                <div style="font-size: 9.5pt;">Punong Barangay</div>
            </td>
            <td style="width: 10%;"></td>
            <td style="width: 45%; vertical-align: top; text-align: center;">
                <div style="border-top: 1px solid #111; width: 220px; margin: 0 auto 4px;"></div>
                <div style="font-size: 10pt;">Tagapangulo ng Pangkat</div>
            </td>
        </tr>
    </table>
' . $wrapperEnd . '
</body>
</html>';
    }

    public function renderKp17Html(array $data, bool $forPdf = false): string
    {
        return $this->kp17Html($data, $forPdf);
    }

    private function kp17Html(array $data, bool $forPdf = false): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $caseNumber = !empty($data['case_number']) ? $this->escape((string) $data['case_number']) : '__________';
        $title = !empty($data['complaint_title']) ? $this->escape((string) $data['complaint_title']) : '__________';
        $reason = !empty($data['repudiation_reason']) ? $this->escape((string) $data['repudiation_reason']) : '';

        $compList = [];
        if (!empty($data['complainants']) && is_array($data['complainants'])) {
            foreach ($data['complainants'] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') $compList[] = trim($cName);
            }
        }
        $respList = [];
        if (!empty($data['respondents']) && is_array($data['respondents'])) {
            foreach ($data['respondents'] as $r) {
                $rName = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
                if (trim($rName) !== '') $respList[] = trim($rName);
            }
        }

        $compLines = '';
        foreach ($compList as $c) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($c) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($compList)); $i++) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $respLines = '';
        foreach ($respList as $r) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($r) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($respList)); $i++) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $noticeTs = !empty($data['repudiation_date']) ? strtotime($data['repudiation_date']) : (!empty($data['notice_date']) ? strtotime($data['notice_date']) : time());
        $noticeDay = date('j', $noticeTs);
        $noticeMonth = $this->filipinoMonth((int) date('n', $noticeTs));
        $noticeYear = date('Y', $noticeTs);

        $officialName = $this->escape($this->getAuthorizedCaptainName($data['official_name'] ?? $data['captain_name'] ?? null));

        $wrapperStart = $forPdf ? '' : '<div style="background: #f1f5f9; padding: 24px 12px; min-height: 100vh; display: flex; justify-content: center;"><div style="background: #fff; width: 100%; max-width: 760px; min-height: 960px; padding: 14mm 16mm; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 4px; box-sizing: border-box;">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 17 - PAGTANGGI</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 11pt; color: #111827; line-height: 1.4; margin: 0; padding: 0; }
        .form-code { font-size: 11pt; font-weight: bold; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-center { text-align: center; font-size: 10pt; line-height: 1.25; }
    </style>
</head>
<body>
' . $wrapperStart . '
    <div class="form-code">Pormularyo ng KP Blg. 17</div>

    <table class="header-table">
        <tr>
            <td style="width: 80px; text-align: left; vertical-align: top;">
                ' . (!empty($tumanaLogo) ? '<img src="' . $tumanaLogo . '" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
            <td class="header-center" style="vertical-align: top;">
                <div>Republika ng Pilipinas</div>
                <div>Lalawigan ng NCR</div>
                <div>Lungsod ng Marikina</div>
                <div style="font-weight: bold;">Barangay Tumana</div>
                <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
            </td>
            <td style="width: 80px; text-align: right; vertical-align: top;">
                ' . (!empty($marikinaLogo) ? '<img src="' . $marikinaLogo . '" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                ' . $compLines . '
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                ' . $respLines . '
                <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                <div>Usapin ng Barangay Blg. <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px; font-weight: bold; text-align: center;">' . $caseNumber . '</span></div>
                <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px; font-weight: bold; text-align: center;">' . $title . '</span></div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin: 16px 0;">
        <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">PAGTANGGI</div>
        <div style="font-size: 11.5pt; font-weight: bold;">(Repudiation)</div>
    </div>

    <div style="font-size: 11pt; line-height: 2; text-align: justify; margin-bottom: 14px;">
        Aking/Aming tinatanggihan ang pag-aayos/kasunduan para sa paghahatol sa dahilan na ang aking pagpayag ay pinawalang-bisa ng:
    </div>

    <div style="font-size: 10.5pt; line-height: 1.8; margin-bottom: 16px;">
        <div style="margin-bottom: 6px;">(Lagyan ng tsek kung alin ang angkop)</div>
        <div style="margin-bottom: 8px;">
            ( &nbsp; ) Pandaraya (Isaad ang detalye)<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 450px;">&nbsp;</span>
        </div>
        <div style="margin-bottom: 8px;">
            ( &nbsp; ) Karahasan (Isaad ang detalye)<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 450px;">&nbsp;</span>
        </div>
        <div style="margin-bottom: 8px;">
            ( &nbsp; ) Pananakot (Isaad ang detalye)<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 450px;">&nbsp;</span>
        </div>
    </div>

    <div style="font-size: 10.5pt; margin-bottom: 18px;">
        Ngayong <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; font-weight: bold; text-align: center;">' . $noticeDay . '</span> araw ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px; font-weight: bold; text-align: center;">' . $noticeMonth . '</span>, 20<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 40px; font-weight: bold; text-align: center;">' . substr($noticeYear, -2) . '</span>.
    </div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
        <tr>
            <td style="width: 45%; text-align: center;">
                <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                <div>(Mga) Nagrereklamo</div>
            </td>
            <td style="width: 10%;"></td>
            <td style="width: 45%; text-align: center;">
                <div style="border-top: 1px solid #111; min-height: 2px; margin-bottom: 4px;"></div>
                <div>(Mga) Inirereklamo</div>
            </td>
        </tr>
    </table>

    <div style="font-size: 10pt; line-height: 1.7; margin-bottom: 22px;">
        Tinanggap at pinanumpaan sa harap ko ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 50px; font-weight: bold; text-align: center;">' . $noticeDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 100px; font-weight: bold; text-align: center;">' . $noticeMonth . '</span>, 20<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 40px; font-weight: bold; text-align: center;">' . substr($noticeYear, -2) . '</span> sa Barangay Tumana, Marikina City.
    </div>

    <div style="text-align: right; margin-bottom: 20px;">
        <div style="display: inline-block; text-align: center; min-width: 280px;">
            <div style="border-top: 1px solid #111; padding-top: 4px; font-size: 10pt; margin-bottom: 18px;">Tagapangulo ng Pangkat/Kasapi</div>
            <div style="border-top: 1px solid #111; padding-top: 4px; font-weight: bold; font-size: 10.5pt;">' . $officialName . '</div>
            <div style="font-size: 9.5pt;">Punong Barangay</div>
        </div>
    </div>

    <div style="font-size: 8pt; color: #475569; line-height: 1.35; border-top: 1px dashed #cbd5e1; padding-top: 8px;">
        *Ang pagkabigong tanggihan ang pag-aayos o kasunduan ng paghahatol na nalolooban sa itinakdang sampung (10) araw mula sa petsa ng pag-aayos at limang (5) araw mula sa petsa ng kasunduan ng paghahatol ayon sa pag-kakasunud-sunod ay ipapalagay na tinalikdan na niya ang karapatang tumutol sa nasabing mga dahilan.
    </div>
' . $wrapperEnd . '
</body>
</html>';
    }

    public function renderKp18Html(array $data, bool $forPdf = false): string
    {
        return $this->kp18Html($data, $forPdf);
    }

    private function kp18Html(array $data, bool $forPdf = false): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $caseNumber = !empty($data['case_number']) ? $this->escape((string) $data['case_number']) : '__________';
        $title = !empty($data['complaint_title']) ? $this->escape((string) $data['complaint_title']) : '__________';

        $compList = [];
        if (!empty($data['complainants']) && is_array($data['complainants'])) {
            foreach ($data['complainants'] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') $compList[] = trim($cName);
            }
        }
        $respList = [];
        if (!empty($data['respondents']) && is_array($data['respondents'])) {
            foreach ($data['respondents'] as $r) {
                $rName = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
                if (trim($rName) !== '') $respList[] = trim($rName);
            }
        }
        $targetParty = !empty($data['target_party_name']) ? $this->escape((string) $data['target_party_name']) : (!empty($compList) ? implode(' / ', $compList) : '________________________');

        $compLines = '';
        foreach ($compList as $c) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($c) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($compList)); $i++) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $respLines = '';
        foreach ($respList as $r) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($r) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($respList)); $i++) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $hearingTs = !empty($data['hearing_date']) ? strtotime($data['hearing_date']) : null;
        if ($hearingTs) {
            $hDay = date('j', $hearingTs);
            $hMonth = $this->filipinoMonth((int) date('n', $hearingTs));
            $hYear = date('Y', $hearingTs);
            $hTime = date('g:i A', $hearingTs);
        } else {
            $hDay = '___'; $hMonth = '________'; $hYear = '2026'; $hTime = '_____';
        }

        $missedTs = !empty($data['missed_hearing_date']) ? strtotime($data['missed_hearing_date']) : null;
        $missedDateStr = $missedTs ? 'ika-' . date('j', $missedTs) . ' ng ' . $this->filipinoMonth((int) date('n', $missedTs)) . ', ' . date('Y', $missedTs) : '___________, 2026';

        $noticeTs = !empty($data['notice_date']) ? strtotime($data['notice_date']) : time();
        $noticeDay = date('j', $noticeTs);
        $noticeMonth = $this->filipinoMonth((int) date('n', $noticeTs));
        $noticeYear = date('Y', $noticeTs);

        $officialName = $this->escape($this->getAuthorizedCaptainName($data['official_name'] ?? $data['captain_name'] ?? null));

        $wrapperStart = $forPdf ? '' : '<div style="background: #f1f5f9; padding: 24px 12px; min-height: 100vh; display: flex; justify-content: center;"><div style="background: #fff; width: 100%; max-width: 760px; min-height: 960px; padding: 14mm 16mm; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 4px; box-sizing: border-box;">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 18 - PAABISO NG PAGDINIG</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 11pt; color: #111827; line-height: 1.4; margin: 0; padding: 0; }
        .form-code { font-size: 11pt; font-weight: bold; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-center { text-align: center; font-size: 10pt; line-height: 1.25; }
    </style>
</head>
<body>
' . $wrapperStart . '
    <div class="form-code">Pormularyo ng KP Blg. 18</div>

    <table class="header-table">
        <tr>
            <td style="width: 80px; text-align: left; vertical-align: top;">
                ' . (!empty($tumanaLogo) ? '<img src="' . $tumanaLogo . '" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
            <td class="header-center" style="vertical-align: top;">
                <div>Republika ng Pilipinas</div>
                <div>Lalawigan ng NCR</div>
                <div>Lungsod ng Marikina</div>
                <div style="font-weight: bold;">Barangay Tumana</div>
                <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
            </td>
            <td style="width: 80px; text-align: right; vertical-align: top;">
                ' . (!empty($marikinaLogo) ? '<img src="' . $marikinaLogo . '" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                ' . $compLines . '
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                ' . $respLines . '
                <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                <div>Usapin ng Barangay Blg.: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px; font-weight: bold; text-align: center;">' . $caseNumber . '</span></div>
                <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px; font-weight: bold; text-align: center;">' . $title . '</span></div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin: 16px 0;">
        <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">PAABISO NG PAGDINIG</div>
        <div style="font-size: 11.5pt; font-weight: bold;">(Re: Failure to Appear)</div>
    </div>

    <div style="margin-bottom: 16px; font-size: 11pt;">
        <div>Para kay/kina: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 250px; font-weight: bold;">' . $targetParty . '</span></div>
        <div style="text-align: center; font-size: 10.5pt; margin-top: 4px;">(Mga) nagrereklamo</div>
    </div>

    <div style="font-size: 11pt; line-height: 2; text-align: justify; margin-bottom: 25px;">
        <p style="text-indent: 40px; margin: 0 0 16px 0;">
            Ikaw ay hinihilingan na humarap sa akin/Pangkat <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px; font-weight: bold; text-align: center;">' . ($data['venue'] ?? 'Tanggapan ng Punong Barangay') . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 140px; font-weight: bold; text-align: center;">ika-' . $hDay . ' ng ' . $hMonth . ', ' . $hYear . '</span> sa ganap na ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 80px; font-weight: bold; text-align: center;">' . $hTime . '</span> upang ipaliwanag kung bakit nabigo kang humarap para sa pamamagitan/pagkakasundo na itinakda noong <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px; font-weight: bold; text-align: center;">' . $missedDateStr . '</span> at kung bakit ang iyong reklamo ay hindi dapat ipawalang-saysay, ang katibayan upang humadlang sa paghahain ng sakdal sa hukuman/tanggapan ng pamahalaan ay di-dapat ipalabas, at ang paglilitis ng paglapastangan sa hukuman ay di-dapat ihain sa hukuman dahil sa pagkabigo o pagtangging humarap sa Punong Barangay/Pangkat ng Tagapagkasundo.
        </p>
        <p style="text-indent: 40px; margin: 0;">
            Ipinagbibigay-alam ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; font-weight: bold; text-align: center;">' . $noticeDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px; font-weight: bold; text-align: center;">' . $noticeMonth . '</span>, ' . $noticeYear . '.
        </p>
    </div>

    <div style="margin-top: 35px;">
        <div style="border-top: 1px solid #111; width: 260px; margin-bottom: 4px;"></div>
        <div style="font-weight: bold; font-size: 11pt;">' . $officialName . '</div>
        <div style="font-size: 10pt;">Punong Barangay/Tagapangulo ng Pangkat</div>
    </div>
' . $wrapperEnd . '
</body>
</html>';
    }


    public function renderKp19Html(array $data, bool $forPdf = false): string
    {
        return $this->kp19Html($data, $forPdf);
    }

    private function kp19Html(array $data, bool $forPdf = false): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $caseNumber = !empty($data['case_number']) ? $this->escape((string) $data['case_number']) : '__________';
        $title = !empty($data['complaint_title']) ? $this->escape((string) $data['complaint_title']) : '__________';

        $compList = [];
        if (!empty($data['complainants']) && is_array($data['complainants'])) {
            foreach ($data['complainants'] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') $compList[] = trim($cName);
            }
        }
        $respList = [];
        if (!empty($data['respondents']) && is_array($data['respondents'])) {
            foreach ($data['respondents'] as $r) {
                $rName = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
                if (trim($rName) !== '') $respList[] = trim($rName);
            }
        }
        $targetParty = !empty($data['target_party_name']) ? $this->escape((string) $data['target_party_name']) : (!empty($respList) ? implode(' / ', $respList) : '________________________');

        $compLines = '';
        foreach ($compList as $c) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($c) . '</div>'; }
        for ($i = 0; $i < max(0, 2 - count($compList)); $i++) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $respLines = '';
        foreach ($respList as $r) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($r) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($respList)); $i++) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $hearingTs = !empty($data['hearing_date']) ? strtotime($data['hearing_date']) : null;
        if ($hearingTs) {
            $hDayOfWeek = $this->filipinoDayOfWeek((int) date('w', $hearingTs));
            $hDay = date('j', $hearingTs);
            $hMonth = $this->filipinoMonth((int) date('n', $hearingTs));
            $hYear = date('Y', $hearingTs);
            $hTime = date('g:i', $hearingTs);
            $hAmPm = $this->filipinoTime((int) date('H', $hearingTs));
        } else {
            $hDayOfWeek = '________'; $hDay = '___'; $hMonth = '________'; $hYear = '2026'; $hTime = '_____'; $hAmPm = 'umaga/hapon';
        }

        $missedTs = !empty($data['missed_hearing_date']) ? strtotime($data['missed_hearing_date']) : null;
        $missedDateStr = $missedTs ? 'ika-' . date('j', $missedTs) . ' ng ' . $this->filipinoMonth((int) date('n', $missedTs)) . ', ' . date('Y', $missedTs) : '___________, 2025';

        $noticeTs = !empty($data['notice_date']) ? strtotime($data['notice_date']) : time();
        $noticeDay = date('j', $noticeTs);
        $noticeMonth = $this->filipinoMonth((int) date('n', $noticeTs));
        $noticeYear = date('Y', $noticeTs);

        $officialName = $this->escape($this->getAuthorizedCaptainName($data['official_name'] ?? $data['captain_name'] ?? null));

        $wrapperStart = $forPdf ? '' : '<div style="background: #f1f5f9; padding: 24px 12px; min-height: 100vh; display: flex; justify-content: center;"><div style="background: #fff; width: 100%; max-width: 760px; min-height: 960px; padding: 14mm 16mm; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 4px; box-sizing: border-box;">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 19 - PAABISO NG PAGDINIG</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 11pt; color: #111827; line-height: 1.4; margin: 0; padding: 0; }
        .form-code { font-size: 11pt; font-weight: bold; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-center { text-align: center; font-size: 10pt; line-height: 1.25; }
    </style>
</head>
<body>
' . $wrapperStart . '
    <div class="form-code">Pormularyo ng KP Blg. 19</div>

    <table class="header-table">
        <tr>
            <td style="width: 80px; text-align: left; vertical-align: top;">
                ' . (!empty($tumanaLogo) ? '<img src="' . $tumanaLogo . '" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
            <td class="header-center" style="vertical-align: top;">
                <div>Republika ng Pilipinas</div>
                <div>Lalawigan ng NCR</div>
                <div>Lungsod ng Marikina</div>
                <div style="font-weight: bold;">Barangay Tumana</div>
                <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
            </td>
            <td style="width: 80px; text-align: right; vertical-align: top;">
                ' . (!empty($marikinaLogo) ? '<img src="' . $marikinaLogo . '" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 6px; margin-bottom: 2px;">TANGAPAN NG PUNONG BARANGAY</div>
    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-bottom: 16px;">TANGGAPAN NG LUPON TAGAPAMAYAPA</div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                ' . $compLines . '
                <div style="font-size: 10pt; margin-bottom: 4px;">Barangay Tumana, Marikina City</div>
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                ' . $respLines . '
                <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                <div>Usapin ng Barangay Blg.: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px; font-weight: bold; text-align: center;">' . $caseNumber . '</span></div>
                <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px; font-weight: bold; text-align: center;">' . $title . '</span></div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin: 16px 0;">
        <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">PAABISO NG PAGDINIG</div>
        <div style="font-size: 11.5pt; font-weight: bold;">(Re: Failure to Appear)</div>
    </div>

    <div style="margin-bottom: 16px; font-size: 11pt;">
        <div>Para kay: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 250px; font-weight: bold;">' . $targetParty . '</span></div>
        <div style="text-align: center; font-size: 10.5pt; margin-top: 4px;">(Mga) Inerereklamo</div>
    </div>

    <div style="font-size: 11pt; line-height: 2; text-align: justify; margin-bottom: 25px;">
        <p style="text-indent: 40px; margin: 0 0 16px 0;">
            Ikaw ay hinihilingan na humarap sa akin/Pangkat sa <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 100px; font-weight: bold; text-align: center;">' . $hDayOfWeek . '</span> ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; font-weight: bold; text-align: center;">' . $hDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px; font-weight: bold; text-align: center;">' . $hMonth . '</span>, <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 70px; font-weight: bold; text-align: center;">' . $hYear . '</span> sa ganap na ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 70px; font-weight: bold; text-align: center;">' . $hTime . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 90px; font-weight: bold; text-align: center;">' . $hAmPm . '</span> upang ipaliwanag kung bakit nabigo kang humarap para sa pamamagitan/pagkakasundo na itinakda noong, 2025 at kung bakit ang iyong reklamo ay hindi dapat ipawalang-saysay, ang katibayan upang humadlang sa paghahain ng sakdal sa hukuman/tanggapan ng pamahalaan ay di-dapat ipalabas, at ang paglilitis ng paglapastangan sa hukuman ay di-dapat ihain sa hukuman dahil sa pagkabigo o pagtangging humarap sa Punong Barangay/Pangkat ng Tagapagkasundo.
        </p>
        <p style="text-indent: 40px; margin: 0;">
            Ipinagbibigay-alam ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; font-weight: bold; text-align: center;">' . $noticeDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 110px; font-weight: bold; text-align: center;">' . $noticeMonth . '</span>, ' . $noticeYear . '.
        </p>
    </div>

    <table style="width: 100%; border-collapse: collapse; margin-top: 35px;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                <div style="border-top: 1px solid #111; width: 220px; margin-bottom: 4px;"></div>
                <div style="font-size: 10pt;">Inerereklamo</div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right;">
                <div style="display: inline-block; text-align: center;">
                    <div style="border-top: 1px solid #111; width: 260px; margin-bottom: 4px;"></div>
                    <div style="font-weight: bold; font-size: 11pt;">' . $officialName . '</div>
                    <div style="font-size: 10pt;">Punong Barangay</div>
                </div>
            </td>
        </tr>
    </table>
' . $wrapperEnd . '
</body>
</html>';
    }

    public function renderKp20Html(array $data, bool $forPdf = false): string
    {
        return $this->kp20Html($data, $forPdf);
    }

    private function kp20Html(array $data, bool $forPdf = false): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $caseNumber = !empty($data['case_number']) ? $this->escape((string) $data['case_number']) : '__________';
        $title = !empty($data['complaint_title']) ? $this->escape((string) $data['complaint_title']) : '__________';
        $repudiatingParty = !empty($data['repudiating_party']) ? $this->escape((string) $data['repudiating_party']) : (!empty($data['target_party_name']) ? $this->escape((string) $data['target_party_name']) : '______________________________');
        $grounds = !empty($data['repudiation_grounds']) ? $this->escape((string) $data['repudiation_grounds']) : (!empty($data['grounds']) ? $this->escape((string) $data['grounds']) : '');

        $compList = [];
        if (!empty($data['complainants']) && is_array($data['complainants'])) {
            foreach ($data['complainants'] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') $compList[] = trim($cName);
            }
        }
        $respList = [];
        if (!empty($data['respondents']) && is_array($data['respondents'])) {
            foreach ($data['respondents'] as $r) {
                $rName = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
                if (trim($rName) !== '') $respList[] = trim($rName);
            }
        }

        $compLines = '';
        foreach ($compList as $c) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($c) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($compList)); $i++) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $respLines = '';
        foreach ($respList as $r) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($r) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($respList)); $i++) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $noticeTs = !empty($data['notice_date']) ? strtotime($data['notice_date']) : (!empty($data['hearing_date']) ? strtotime($data['hearing_date']) : time());
        $noticeDay = date('j', $noticeTs);
        $noticeMonth = $this->filipinoMonth((int) date('n', $noticeTs));
        $noticeYear = date('Y', $noticeTs);

        $wrapperStart = $forPdf ? '' : '<div style="background: #f1f5f9; padding: 24px 12px; min-height: 100vh; display: flex; justify-content: center;"><div style="background: #fff; width: 100%; max-width: 760px; min-height: 960px; padding: 14mm 16mm; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 4px; box-sizing: border-box;">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 20 - KATIBAYAN NA MAKAPAGHAIN NG SAKDAL</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 11pt; color: #111827; line-height: 1.4; margin: 0; padding: 0; }
        .form-code { font-size: 11pt; font-weight: bold; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-center { text-align: center; font-size: 10pt; line-height: 1.25; }
    </style>
</head>
<body>
' . $wrapperStart . '
    <div class="form-code">Pormularyo ng KP Blg. 20</div>

    <table class="header-table">
        <tr>
            <td style="width: 80px; text-align: left; vertical-align: top;">
                ' . (!empty($tumanaLogo) ? '<img src="' . $tumanaLogo . '" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
            <td class="header-center" style="vertical-align: top;">
                <div>Republika ng Pilipinas</div>
                <div>Lalawigan ng NCR</div>
                <div>Lungsod ng Marikina</div>
                <div style="font-weight: bold;">Barangay Tumana</div>
                <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
            </td>
            <td style="width: 80px; text-align: right; vertical-align: top;">
                ' . (!empty($marikinaLogo) ? '<img src="' . $marikinaLogo . '" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                ' . $compLines . '
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                ' . $respLines . '
                <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                <div>Usapin ng Barangay Blg.: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px; font-weight: bold; text-align: center;">' . $caseNumber . '</span></div>
                <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px; font-weight: bold; text-align: center;">' . $title . '</span></div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin: 18px 0;">
        <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">KATIBAYAN NA MAKAPAGHAIN NG SAKDAL</div>
        <div style="font-size: 11pt; font-weight: bold;">(Certification to File Action)</div>
    </div>

    <div style="font-size: 11pt; line-height: 1.8; margin-bottom: 20px;">
        <div style="font-weight: bold; margin-bottom: 8px;">ITO AY NAGPAPATUNAY NA:</div>
        <ol style="margin: 0; padding-left: 24px; text-align: justify;">
            <li style="margin-bottom: 10px;">Nagkaroon ng personal na paghaharap sa pagitan ng mga panig sa harap ng Punong Barangay/Pangkat ng Tagapagkasundo;</li>
            <li style="margin-bottom: 10px;">Na nagkaroon ng pag-aayos;</li>
            <li style="margin-bottom: 10px;">Na ang pag-aayos ay tinanggihan sa isang sinumpaang salaysay sa harap ng Punong Barangay sa pamamagitan ni <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 250px; font-weight: bold; text-align: center;">' . $repudiatingParty . '</span> sa dahilan ng ' . ($grounds !== '' ? '<span style="border-bottom: 1px solid #111; font-weight: bold;">' . $grounds . '</span>' : '<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 250px;">&nbsp;</span><div style="border-bottom: 1px solid #111; min-height: 18px; margin-top: 8px;">&nbsp;</div><div style="border-bottom: 1px solid #111; min-height: 18px; margin-top: 8px;">&nbsp;</div>') . ';</li>
            <li style="margin-bottom: 10px;">Dahil dito, ang nasabing sigalot ay maaari nang ihain sa hukuman/tanggapan ng pamahalaan.</li>
        </ol>
    </div>

    <div style="font-size: 11pt; margin-top: 25px; margin-bottom: 35px; text-indent: 40px;">
        Ngayong ika- <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; font-weight: bold; text-align: center;">' . $noticeDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px; font-weight: bold; text-align: center;">' . $noticeMonth . '</span>, ' . $noticeYear . '.
    </div>

    <div style="text-align: right; margin-top: 40px;">
        <div style="display: inline-block; text-align: center;">
            <div style="border-top: 1px solid #111; width: 240px; margin-bottom: 4px;"></div>
            <div style="font-size: 10.5pt;">Tagapangulo ng Pangkat</div>
        </div>
    </div>
' . $wrapperEnd . '
</body>
</html>';
    }

    public function renderKp20AHtml(array $data, bool $forPdf = false): string
    {
        return $this->kp20AHtml($data, $forPdf);
    }

    private function kp20AHtml(array $data, bool $forPdf = false): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $caseNumber = !empty($data['case_number']) ? $this->escape((string) $data['case_number']) : '__________';
        $title = !empty($data['complaint_title']) ? $this->escape((string) $data['complaint_title']) : '__________';

        $compList = [];
        if (!empty($data['complainants']) && is_array($data['complainants'])) {
            foreach ($data['complainants'] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') $compList[] = trim($cName);
            }
        }
        $respList = [];
        if (!empty($data['respondents']) && is_array($data['respondents'])) {
            foreach ($data['respondents'] as $r) {
                $rName = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
                if (trim($rName) !== '') $respList[] = trim($rName);
            }
        }

        $compLines = '';
        foreach ($compList as $c) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($c) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($compList)); $i++) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $respLines = '';
        foreach ($respList as $r) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($r) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($respList)); $i++) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $noticeTs = !empty($data['notice_date']) ? strtotime($data['notice_date']) : (!empty($data['hearing_date']) ? strtotime($data['hearing_date']) : time());
        $noticeDay = date('j', $noticeTs);
        $noticeMonth = $this->filipinoMonth((int) date('n', $noticeTs));
        $noticeYear = date('Y', $noticeTs);

        $wrapperStart = $forPdf ? '' : '<div style="background: #f1f5f9; padding: 24px 12px; min-height: 100vh; display: flex; justify-content: center;"><div style="background: #fff; width: 100%; max-width: 760px; min-height: 960px; padding: 14mm 16mm; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 4px; box-sizing: border-box;">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 20-A - KATIBAYAN NA MAKAPAGHAIN NG SAKDAL</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 11pt; color: #111827; line-height: 1.4; margin: 0; padding: 0; }
        .form-code { font-size: 11pt; font-weight: bold; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-center { text-align: center; font-size: 10pt; line-height: 1.25; }
    </style>
</head>
<body>
' . $wrapperStart . '
    <div class="form-code">Pormularyo ng KP Blg. 20-A</div>

    <table class="header-table">
        <tr>
            <td style="width: 80px; text-align: left; vertical-align: top;">
                ' . (!empty($tumanaLogo) ? '<img src="' . $tumanaLogo . '" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
            <td class="header-center" style="vertical-align: top;">
                <div>Republika ng Pilipinas</div>
                <div>Lalawigan ng NCR</div>
                <div>Lungsod ng Marikina</div>
                <div style="font-weight: bold;">Barangay Tumana</div>
                <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
            </td>
            <td style="width: 80px; text-align: right; vertical-align: top;">
                ' . (!empty($marikinaLogo) ? '<img src="' . $marikinaLogo . '" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                ' . $compLines . '
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                ' . $respLines . '
                <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                <div>Usapin ng Barangay Blg.: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px; font-weight: bold; text-align: center;">' . $caseNumber . '</span></div>
                <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px; font-weight: bold; text-align: center;">' . $title . '</span></div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin: 18px 0;">
        <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">KATIBAYAN NA MAKAPAGHAIN NG SAKDAL</div>
        <div style="font-size: 11pt; font-weight: bold;">(Certification to File Action)</div>
    </div>

    <div style="font-size: 11pt; line-height: 1.8; margin-bottom: 20px;">
        <div style="font-weight: bold; margin-bottom: 8px;">ITO AY NAGPAPATUNAY NA:</div>
        <ol style="margin: 0; padding-left: 24px; text-align: justify;">
            <li style="margin-bottom: 10px;">Nagkaroon ng personal na paghaharap sa pagitan ng mga panig sa harap ng Punong Barangay subalit ang pamamagitan ay nabigo;</li>
            <li style="margin-bottom: 10px;">Ang Pangkat ng Tagapagkasundo ay binuo subalit ang personal na pag-uusap sa harap ng Pangkat ay di nagbunga ng pag-aayos;</li>
            <li style="margin-bottom: 10px;">Dahil dito, ang nasabing reklamo para sa sigalot ay maaari nang idulog sa hukuman/tanggapan ng pamahalaan.</li>
        </ol>
    </div>

    <div style="font-size: 11pt; margin-top: 25px; margin-bottom: 35px; text-indent: 40px;">
        Ngayong ika- <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; font-weight: bold; text-align: center;">' . $noticeDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px; font-weight: bold; text-align: center;">' . $noticeMonth . '</span>, ' . $noticeYear . '.
    </div>

    <div style="text-align: right; margin-top: 40px;">
        <div style="display: inline-block; text-align: center;">
            <div style="border-top: 1px solid #111; width: 240px; margin-bottom: 4px;"></div>
            <div style="font-size: 10.5pt;">Tagapangulo ng Pangkat</div>
        </div>
    </div>
' . $wrapperEnd . '
</body>
</html>';
    }


    public function renderKp20BHtml(array $data, bool $forPdf = false): string
    {
        return $this->kp20BHtml($data, $forPdf);
    }

    private function kp20BHtml(array $data, bool $forPdf = false): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $caseNumber = !empty($data['case_number']) ? $this->escape((string) $data['case_number']) : '__________';
        $title = !empty($data['complaint_title']) ? $this->escape((string) $data['complaint_title']) : '__________';

        $compList = [];
        if (!empty($data['complainants']) && is_array($data['complainants'])) {
            foreach ($data['complainants'] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') $compList[] = trim($cName);
            }
        }
        $respList = [];
        if (!empty($data['respondents']) && is_array($data['respondents'])) {
            foreach ($data['respondents'] as $r) {
                $rName = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
                if (trim($rName) !== '') $respList[] = trim($rName);
            }
        }

        $compLines = '';
        foreach ($compList as $c) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($c) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($compList)); $i++) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $respLines = '';
        foreach ($respList as $r) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($r) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($respList)); $i++) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $noticeTs = !empty($data['notice_date']) ? strtotime($data['notice_date']) : (!empty($data['hearing_date']) ? strtotime($data['hearing_date']) : time());
        $noticeDay = date('j', $noticeTs);
        $noticeMonth = $this->filipinoMonth((int) date('n', $noticeTs));
        $noticeYear = date('Y', $noticeTs);

        $wrapperStart = $forPdf ? '' : '<div style="background: #f1f5f9; padding: 24px 12px; min-height: 100vh; display: flex; justify-content: center;"><div style="background: #fff; width: 100%; max-width: 760px; min-height: 960px; padding: 14mm 16mm; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 4px; box-sizing: border-box;">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 20-B - KATIBAYAN NA MAKAPAGHAIN NG SAKDAL</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 11pt; color: #111827; line-height: 1.4; margin: 0; padding: 0; }
        .form-code { font-size: 11pt; font-weight: bold; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-center { text-align: center; font-size: 10pt; line-height: 1.25; }
    </style>
</head>
<body>
' . $wrapperStart . '
    <div class="form-code">Pormularyo ng KP Blg. 20-B</div>

    <table class="header-table">
        <tr>
            <td style="width: 80px; text-align: left; vertical-align: top;">
                ' . (!empty($tumanaLogo) ? '<img src="' . $tumanaLogo . '" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
            <td class="header-center" style="vertical-align: top;">
                <div>Republika ng Pilipinas</div>
                <div>Lalawigan ng NCR</div>
                <div>Lungsod ng Marikina</div>
                <div style="font-weight: bold;">Barangay Tumana</div>
                <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
            </td>
            <td style="width: 80px; text-align: right; vertical-align: top;">
                ' . (!empty($marikinaLogo) ? '<img src="' . $marikinaLogo . '" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                ' . $compLines . '
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                ' . $respLines . '
                <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                <div>Usapin ng Barangay Blg.: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px; font-weight: bold; text-align: center;">' . $caseNumber . '</span></div>
                <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px; font-weight: bold; text-align: center;">' . $title . '</span></div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin: 18px 0;">
        <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">KATIBAYAN NA MAKAPAGHAIN NG SAKDAL</div>
        <div style="font-size: 11pt; font-weight: bold;">(Certification to File Action)</div>
    </div>

    <div style="font-size: 11pt; line-height: 1.8; margin-bottom: 20px;">
        <div style="font-weight: bold; margin-bottom: 8px;">ITO AY NAGPAPATUNAY NA:</div>
        <ol style="margin: 0; padding-left: 24px; text-align: justify;">
            <li style="margin-bottom: 10px;">Nagkaroon ng personal na paghaharap sa pagitan ng mga panig sa harap ng Punong Barangay, subalit ang pamamagitan ay nabigo;</li>
            <li style="margin-bottom: 10px;">Ang Punong Barangay ay nagtakda ng pulong ng mga panig para magbuo ng Pangkat;</li>
            <li style="margin-bottom: 10px;">Sinadya ng inirereklamo o tumangging humarap sa Pangkat para sa hakbang ng pagkakasundo ng walang makatwirang dahilan; at</li>
            <li style="margin-bottom: 10px;">Dahil dito, ang nasabing reklamo para sa sigalot ay maaari nang idulog sa hukuman/tanggapan ng pamahalaan.</li>
        </ol>
    </div>

    <div style="font-size: 11pt; margin-top: 25px; margin-bottom: 35px; text-indent: 40px;">
        Ngayong ika- <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; font-weight: bold; text-align: center;">' . $noticeDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px; font-weight: bold; text-align: center;">' . $noticeMonth . '</span>, ' . $noticeYear . '.
    </div>

    <div style="text-align: right; margin-top: 40px;">
        <div style="display: inline-block; text-align: center;">
            <div style="border-top: 1px solid #111; width: 240px; margin-bottom: 4px;"></div>
            <div style="font-size: 10.5pt;">Tagapangulo ng Pangkat</div>
        </div>
    </div>
' . $wrapperEnd . '
</body>
</html>';
    }

    public function renderKp21Html(array $data, bool $forPdf = false): string
    {
        return $this->kp21Html($data, $forPdf);
    }

    private function kp21Html(array $data, bool $forPdf = false): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $caseNumber = !empty($data['case_number']) ? $this->escape((string) $data['case_number']) : '__________';
        $title = !empty($data['complaint_title']) ? $this->escape((string) $data['complaint_title']) : '__________';

        $compList = [];
        if (!empty($data['complainants']) && is_array($data['complainants'])) {
            foreach ($data['complainants'] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') $compList[] = trim($cName);
            }
        }
        $respList = [];
        if (!empty($data['respondents']) && is_array($data['respondents'])) {
            foreach ($data['respondents'] as $r) {
                $rName = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
                if (trim($rName) !== '') $respList[] = trim($rName);
            }
        }
        $complainantNames = !empty($compList) ? implode(' at ', array_map([$this, 'escape'], $compList)) : '______________________________';

        $compLines = '';
        foreach ($compList as $c) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($c) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($compList)); $i++) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $respLines = '';
        foreach ($respList as $r) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($r) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($respList)); $i++) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $orderDate = !empty($data['order_date']) ? $this->escape((string) $data['order_date']) : '________________________';

        $noticeTs = !empty($data['notice_date']) ? strtotime($data['notice_date']) : (!empty($data['hearing_date']) ? strtotime($data['hearing_date']) : time());
        $noticeDay = date('j', $noticeTs);
        $noticeMonth = $this->filipinoMonth((int) date('n', $noticeTs));
        $noticeYear = date('Y', $noticeTs);

        $officialName = $this->escape($this->getAuthorizedCaptainName($data['official_name'] ?? $data['captain_name'] ?? null));

        $wrapperStart = $forPdf ? '' : '<div style="background: #f1f5f9; padding: 24px 12px; min-height: 100vh; display: flex; justify-content: center;"><div style="background: #fff; width: 100%; max-width: 760px; min-height: 960px; padding: 14mm 16mm; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 4px; box-sizing: border-box;">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 21 - KATIBAYAN UPANG HADLANGAN NA MAKAPAGSAKDAL</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 11pt; color: #111827; line-height: 1.4; margin: 0; padding: 0; }
        .form-code { font-size: 11pt; font-weight: bold; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-center { text-align: center; font-size: 10pt; line-height: 1.25; }
    </style>
</head>
<body>
' . $wrapperStart . '
    <div class="form-code">Pormularyo ng KP Blg. 21</div>

    <table class="header-table">
        <tr>
            <td style="width: 80px; text-align: left; vertical-align: top;">
                ' . (!empty($tumanaLogo) ? '<img src="' . $tumanaLogo . '" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
            <td class="header-center" style="vertical-align: top;">
                <div>Republika ng Pilipinas</div>
                <div>Lalawigan ng NCR</div>
                <div>Lungsod ng Marikina</div>
                <div style="font-weight: bold;">Barangay Tumana</div>
                <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
            </td>
            <td style="width: 80px; text-align: right; vertical-align: top;">
                ' . (!empty($marikinaLogo) ? '<img src="' . $marikinaLogo . '" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                ' . $compLines . '
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                ' . $respLines . '
                <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                <div>Usapin ng Barangay Blg.: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px; font-weight: bold; text-align: center;">' . $caseNumber . '</span></div>
                <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px; font-weight: bold; text-align: center;">' . $title . '</span></div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin: 18px 0;">
        <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">KATIBAYAN UPANG HADLANGAN NA MAKAPAGSAKDAL</div>
        <div style="font-size: 11pt; font-weight: bold;">(Certification to Bar Action)</div>
    </div>

    <div style="font-size: 11pt; line-height: 2; text-align: justify; margin-bottom: 25px;">
        <p style="text-indent: 40px; margin: 0 0 16px 0;">
            ITO AY NAGPAPATUNAY NA ang usaping nakasaad sa itaas ay ipinawalang-saysay sa Kautusan na may petsang <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 150px; font-weight: bold; text-align: center;">' . $orderDate . '</span> para sa mga nagrereklamong si <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 200px; font-weight: bold; text-align: center;">' . $complainantNames . '</span>, dahil sa sinadya o kusang di-pagharap sa pagdinig sa harap ng Punong Barangay/Pangkat ng Tagapagkasundo at dahil dito, ang nagrereklamo/mga nagrereklamo ay hinahadlangang makapaghain ng sakdal sa hukuman/tanggapan ng pamahalaan.
        </p>
        <p style="text-indent: 40px; margin: 0;">
            Ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; font-weight: bold; text-align: center;">' . $noticeDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px; font-weight: bold; text-align: center;">' . $noticeMonth . '</span>, ' . $noticeYear . '.
        </p>
    </div>

    <table style="width: 100%; border-collapse: collapse; margin-top: 30px;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                <div style="border-top: 1px solid #111; width: 220px; margin-bottom: 4px;"></div>
                <div style="font-size: 10pt; margin-bottom: 35px;">Tagapangulo ng Pangkat</div>

                <div style="border-top: 1px solid #111; width: 260px; margin-bottom: 4px;"></div>
                <div style="font-weight: bold; font-size: 11pt;">' . $officialName . '</div>
                <div style="font-size: 10pt;">Punong Barangay</div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right;">
                <div style="display: inline-block; text-align: center;">
                    <div style="border-top: 1px solid #111; width: 240px; margin-bottom: 4px;"></div>
                    <div style="font-size: 10pt;">Kalihim ng Lupon/Pangkat</div>
                </div>
            </td>
        </tr>
    </table>
' . $wrapperEnd . '
</body>
</html>';
    }

    public function renderKp22Html(array $data, bool $forPdf = false): string
    {
        return $this->kp22Html($data, $forPdf);
    }

    private function kp22Html(array $data, bool $forPdf = false): string
    {
        $officialName = $this->escape($this->getAuthorizedCaptainName($data['official_name'] ?? $data['captain_name'] ?? null));
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $caseNumber = !empty($data['case_number']) ? $this->escape((string) $data['case_number']) : '__________';
        $title = !empty($data['complaint_title']) ? $this->escape((string) $data['complaint_title']) : '__________';

        $compList = [];
        if (!empty($data['complainants']) && is_array($data['complainants'])) {
            foreach ($data['complainants'] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') $compList[] = trim($cName);
            }
        }
        $respList = [];
        if (!empty($data['respondents']) && is_array($data['respondents'])) {
            foreach ($data['respondents'] as $r) {
                $rName = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
                if (trim($rName) !== '') $respList[] = trim($rName);
            }
        }
        $respondentNames = !empty($respList) ? implode(' at ', array_map([$this, 'escape'], $respList)) : '______________________________';

        $compLines = '';
        foreach ($compList as $c) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($c) . '</div>'; }
        for ($i = 0; $i < max(0, 2 - count($compList)); $i++) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $respLines = '';
        foreach ($respList as $r) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($r) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($respList)); $i++) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $noticeTs = !empty($data['notice_date']) ? strtotime($data['notice_date']) : (!empty($data['hearing_date']) ? strtotime($data['hearing_date']) : time());
        $noticeDay = date('j', $noticeTs);
        $noticeMonth = $this->filipinoMonth((int) date('n', $noticeTs));
        $noticeYear = date('Y', $noticeTs);

        $wrapperStart = $forPdf ? '' : '<div style="background: #f1f5f9; padding: 24px 12px; min-height: 100vh; display: flex; justify-content: center;"><div style="background: #fff; width: 100%; max-width: 760px; min-height: 960px; padding: 14mm 16mm; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 4px; box-sizing: border-box;">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 22 - KATIBAYAN UPANG HADLANGAN NA MAKAPAGSAKDAL</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 11pt; color: #111827; line-height: 1.4; margin: 0; padding: 0; }
        .form-code { font-size: 11pt; font-weight: bold; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-center { text-align: center; font-size: 10pt; line-height: 1.25; }
    </style>
</head>
<body>
' . $wrapperStart . '
    <div class="form-code">Pormularyo ng KP Blg. 22</div>

    <table class="header-table">
        <tr>
            <td style="width: 80px; text-align: left; vertical-align: top;">
                ' . (!empty($tumanaLogo) ? '<img src="' . $tumanaLogo . '" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
            <td class="header-center" style="vertical-align: top;">
                <div>Republika ng Pilipinas</div>
                <div>Lalawigan ng NCR</div>
                <div>Lungsod ng Marikina</div>
                <div style="font-weight: bold;">Barangay Tumana</div>
                <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
            </td>
            <td style="width: 80px; text-align: right; vertical-align: top;">
                ' . (!empty($marikinaLogo) ? '<img src="' . $marikinaLogo . '" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                ' . $compLines . '
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                ' . $respLines . '
                <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                <div>Usapin ng Barangay Blg.: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px; font-weight: bold; text-align: center;">' . $caseNumber . '</span></div>
                <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px; font-weight: bold; text-align: center;">' . $title . '</span></div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin: 18px 0;">
        <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">KATIBAYAN UPANG HADLANGAN NA MAKAPAGSAKDAL</div>
        <div style="font-size: 11pt; font-weight: bold;">(Certification to Bar Counterclaim)</div>
    </div>

    <div style="font-size: 11pt; line-height: 2; text-align: justify; margin-bottom: 25px;">
        <p style="text-indent: 40px; margin: 0 0 16px 0;">
            ITO AY NAGPAPATUNAY NA matapos ang mga nauunang paabiso at pagdinig, ang inirereklamo/mga inirereklamo na sina <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 200px; font-weight: bold; text-align: center;">' . $respondentNames . '</span>(pangalan), ay napag-alamang sinadya o tumangging humarap nang walang makatwirang dahilan sa harap ng Punong Barangay/Pangkat Tagapagkasundo at dahil dito ang inirereklamo/mga inirereklamo ay hinahadlangang maghain ng kanilang ganting-sumbong (kung meron) na nagmula sa sigalot sa hukuman/tanggapan ng pamahalaan.
        </p>
        <p style="text-indent: 40px; margin: 0;">
            Ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; font-weight: bold; text-align: center;">' . $noticeDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px; font-weight: bold; text-align: center;">' . $noticeMonth . '</span>, ' . $noticeYear . '.
        </p>
    </div>

    <table style="width: 100%; border-collapse: collapse; margin-top: 25px;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                <div style="border-top: 1px solid #111; width: 220px; margin-bottom: 4px;"></div>
                <div style="font-size: 10pt; margin-bottom: 35px;">Tagapangulo ng Pangkat</div>

                <div style="border-top: 1px solid #111; width: 260px; margin-bottom: 4px;"></div>
                <div style="font-weight: bold; font-size: 11pt;">' . $officialName . '</div>
                <div style="font-size: 10pt;">Tagapangulo ng Lupon/Tagapangulo ng Pangkat</div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right;">
                <div style="display: inline-block; text-align: center;">
                    <div style="border-top: 1px solid #111; width: 240px; margin-bottom: 4px;"></div>
                    <div style="font-size: 10pt;">Kalihim ng Lupon/Pangkat</div>
                </div>
            </td>
        </tr>
    </table>

    <div style="font-size: 9.5pt; font-style: italic; color: #374151; margin-top: 25px; line-height: 1.4;">
        <strong>Mahalaga:</strong> Kung ang Kalihim ng Lupon ang gumawa ng katibayan, ang Tagapangulo ng Lupon ang magpapatunay. Kung ang Kalihim ng Pangkat ang gumawa ng katibayan, ang Tagapangulo ng Pangkat ang magpapatunay.
    </div>
' . $wrapperEnd . '
</body>
</html>';
    }


    public function renderKp23Html(array $data, bool $forPdf = false): string
    {
        return $this->kp23Html($data, $forPdf);
    }

    private function kp23Html(array $data, bool $forPdf = false): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $caseNumber = !empty($data['case_number']) ? $this->escape((string) $data['case_number']) : '__________';
        $title = !empty($data['complaint_title']) ? $this->escape((string) $data['complaint_title']) : '__________';

        $compList = [];
        if (!empty($data['complainants']) && is_array($data['complainants'])) {
            foreach ($data['complainants'] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') $compList[] = trim($cName);
            }
        }
        $respList = [];
        if (!empty($data['respondents']) && is_array($data['respondents'])) {
            foreach ($data['respondents'] as $r) {
                $rName = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
                if (trim($rName) !== '') $respList[] = trim($rName);
            }
        }
        $targetParty = !empty($data['moving_party_name']) ? $this->escape((string) $data['moving_party_name']) : (!empty($data['target_party_name']) ? $this->escape((string) $data['target_party_name']) : (!empty($respList) ? implode(' / ', $respList) : 'Inirereklamo'));

        $compLines = '';
        foreach ($compList as $c) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($c) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($compList)); $i++) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $respLines = '';
        foreach ($respList as $r) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($r) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($respList)); $i++) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $settlementTs = !empty($data['settlement_date']) ? strtotime($data['settlement_date']) : (!empty($data['hearing_date']) ? strtotime($data['hearing_date']) : time());
        $settlementDay = date('j', $settlementTs);
        $settlementMonth = $this->filipinoMonth((int) date('n', $settlementTs));
        $settlementYear = date('Y', $settlementTs);
        $settlementDateStr = "ika-{$settlementDay} ng {$settlementMonth}, {$settlementYear}";

        $noticeTs = !empty($data['notice_date']) ? strtotime($data['notice_date']) : time();
        $noticeDateFormatted = date('j', $noticeTs) . ' ' . $this->filipinoMonth((int) date('n', $noticeTs)) . ' ' . date('Y', $noticeTs);

        $wrapperStart = $forPdf ? '' : '<div style="background: #f1f5f9; padding: 24px 12px; min-height: 100vh; display: flex; justify-content: center;"><div style="background: #fff; width: 100%; max-width: 760px; min-height: 960px; padding: 14mm 16mm; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 4px; box-sizing: border-box;">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 23 - MOSYON SA PAGPAPATUPAD</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 11pt; color: #111827; line-height: 1.4; margin: 0; padding: 0; }
        .form-code { font-size: 11pt; font-weight: bold; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-center { text-align: center; font-size: 10pt; line-height: 1.25; }
    </style>
</head>
<body>
' . $wrapperStart . '
    <div class="form-code">Pormularyo ng KP Blg. 23</div>

    <table class="header-table">
        <tr>
            <td style="width: 80px; text-align: left; vertical-align: top;">
                ' . (!empty($tumanaLogo) ? '<img src="' . $tumanaLogo . '" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
            <td class="header-center" style="vertical-align: top;">
                <div>Republika ng pilipinas</div>
                <div>Lalawigan ng NCR</div>
                <div>Lungsod ng Marikina</div>
                <div style="font-weight: bold;">Barangay Tumana</div>
                <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
            </td>
            <td style="width: 80px; text-align: right; vertical-align: top;">
                ' . (!empty($marikinaLogo) ? '<img src="' . $marikinaLogo . '" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                ' . $compLines . '
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                ' . $respLines . '
                <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                <div>Usapin ng Barangay Blg.: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px; font-weight: bold; text-align: center;">' . $caseNumber . '</span></div>
                <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px; font-weight: bold; text-align: center;">' . $title . '</span></div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin: 18px 0;">
        <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">MOSYON SA PAGPAPATUPAD</div>
        <div style="font-size: 11pt; font-weight: bold;">(Motion for Execution)</div>
    </div>

    <div style="font-size: 11pt; line-height: 1.8; margin-bottom: 25px;">
        <ol style="margin: 0; padding-left: 24px; text-align: justify;">
            <li style="margin-bottom: 12px;">Na noong <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px; font-weight: bold; text-align: center;">' . $settlementDateStr . '</span> ang mga panig sa usaping ito ay lumagda sa isang matiwasay na pag-aayos/tumanggap ng gawad ng paghahatol ng Tagapangulo ng Lupon/Pangkat ng Tagapagkasundo;</li>
            <li style="margin-bottom: 12px;">Ang sampung (10) araw na taning mula sa petsang nakasaad sa itaas ay natapos nang walang anumang sinumpaang salaysay ng pagtanggi sa pag-aayos ang inihain ng sinumang panig sa harap ng Tagapangulo ng Lupon, isang petisyon na nagpapawalang-saysay ng gawad ng paghahatol sa hukuman; at</li>
            <li style="margin-bottom: 12px;">Ang matiwasay na pag-aayos/gawad ng paghahatol ay pinal at maaari nang ipatupad.</li>
        </ol>
        <p style="text-indent: 40px; margin: 18px 0 0 0; text-align: justify;">
            DAHIL DITO, ang Nagrereklamo/Inirereklamo ay humiling na ang katumbas na kasulatan sa pagpapatupad sa usaping ito ay ipalabas na ng Tagapangulo ng Lupon.
        </p>
    </div>

    <table style="width: 100%; border-collapse: collapse; margin-top: 50px;">
        <tr>
            <td style="width: 45%; vertical-align: top;">
                <div style="border-top: 1px solid #111; width: 180px; margin-bottom: 4px; font-weight: bold; padding-top: 4px;">' . $noticeDateFormatted . '</div>
                <div style="font-size: 10pt;">Petsa</div>
            </td>
            <td style="width: 55%; vertical-align: top; text-align: right;">
                <div style="display: inline-block; text-align: center;">
                    <div style="border-top: 1px solid #111; width: 240px; margin-bottom: 4px; font-weight: bold; padding-top: 4px;">' . $targetParty . '</div>
                    <div style="font-size: 10pt;">Inirereklamo</div>
                </div>
            </td>
        </tr>
    </table>
' . $wrapperEnd . '
</body>
</html>';
    }

    /**
     * KP Form 24 — Notice of Hearing on a Motion for Execution.
     */
    public function renderKp24Html(array $data, bool $forPdf = false): string
    {
        return $this->kp24Html($data, $forPdf);
    }

    private function kp24Html(array $data, bool $forPdf = false): string
    {
        $caseNumber = $this->escape((string) ($data['case_number'] ?? '__________'));
        $title = $this->escape((string) ($data['complaint_title'] ?? '__________'));
        $movingParty = $this->escape((string) ($data['moving_party_name'] ?? $data['target_party_name'] ?? '______________________________'));
        $opposingParty = $this->escape((string) ($data['opposing_party_name'] ?? '______________________________'));
        $hearingDate = $this->escape((string) ($data['hearing_date'] ?? '________________'));
        $hearingTime = $this->escape((string) ($data['hearing_time'] ?? '________________'));
        $venue = $this->escape((string) ($data['venue'] ?? 'Tanggapan ng Lupong Tagapamayapa'));
        $noticeDate = $this->escape((string) ($data['notice_date'] ?? $data['date_today'] ?? date('F j, Y')));
        $officialName = $this->escape($this->getAuthorizedCaptainName($data['official_name'] ?? $data['captain_name'] ?? null));
        $wrapperStart = $forPdf ? '' : '<div style="background:#f1f5f9;padding:24px 12px;min-height:100vh;display:flex;justify-content:center"><div style="background:#fff;width:100%;max-width:760px;min-height:960px;padding:14mm 16mm;box-shadow:0 4px 15px rgba(0,0,0,.08);box-sizing:border-box">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        return '<!DOCTYPE html>
<html lang="fil"><head><meta charset="UTF-8"><title>Pormularyo ng KP Blg. 24 - PAABISO NG PAGDINIG</title>
<style>@page{size:A4 portrait;margin:15mm 18mm}*{box-sizing:border-box}body{font-family:Arial,"DejaVu Sans",sans-serif;font-size:11pt;color:#111827;line-height:1.5;margin:0}.center{text-align:center}.line{display:inline-block;border-bottom:1px solid #111;min-width:180px;font-weight:bold;text-align:center}</style>
</head><body>' . $wrapperStart . '
<div style="font-weight:bold;font-size:11pt">Pormularyo ng KP Blg. 24</div>
<div class="center" style="margin-top:14px"><div>Republika ng Pilipinas</div><div>Lungsod ng Marikina</div><div style="font-weight:bold">Barangay Tumana</div><div style="font-weight:bold;margin-top:8px">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div></div>
<table style="width:100%;border-collapse:collapse;margin-top:28px"><tr><td style="width:55%;vertical-align:top"><strong>' . $movingParty . '</strong><br><span style="font-size:10pt">Naghain ng Mosyon para sa Pagpapatupad</span><div class="center" style="margin:10px 0">- laban kay -</div><strong>' . $opposingParty . '</strong><br><span style="font-size:10pt">Kabilang Panig</span></td><td style="vertical-align:top;text-align:right">Usaping Barangay Blg.<br><span class="line">' . $caseNumber . '</span><br><br>Para sa:<br><span class="line">' . $title . '</span></td></tr></table>
<div class="center" style="margin:34px 0 24px"><div style="font-weight:bold;font-size:14pt">PAABISO NG PAGDINIG</div><div style="font-weight:bold">(Re: Mosyon sa Pagpapatupad)</div></div>
<p>PARA KAY: <strong>' . $opposingParty . '</strong></p>
<p style="text-align:justify">IPINAGBIBIGAY-ALAM na ang Mosyon para sa Pagpapatupad sa usaping ito ay diringgin sa <strong>' . $hearingDate . '</strong>, sa ganap na <strong>' . $hearingTime . '</strong>, sa <strong>' . $venue . '</strong>. Ikaw/kayo ay hinihiling na humarap upang maipahayag ang anumang pagtutol o paliwanag ukol sa nasabing mosyon.</p>
<p style="text-align:justify">Kung hindi makakaharap nang walang sapat na dahilan, ang pagdinig ay maaaring ituloy at ang mosyon ay pagpasiyahan batay sa mga rekord at ebidensiyang nasa harap ng Lupon/Pangkat.</p>
<p style="margin-top:36px">Ipinagkaloob ngayong <strong>' . $noticeDate . '</strong> sa Barangay Tumana, Lungsod ng Marikina.</p>
<div style="text-align:right;margin-top:64px"><div style="display:inline-block;text-align:center;min-width:250px;border-top:1px solid #111;padding-top:4px;font-weight:bold">' . $officialName . '</div><br><span style="font-size:10pt">Punong Barangay / Tagapangulo ng Lupon</span></div>
' . $wrapperEnd . '</body></html>';
    }

    public function renderKp25Html(array $data, bool $forPdf = false): string
    {
        return $this->kp25Html($data, $forPdf);
    }

    private function kp25Html(array $data, bool $forPdf = false): string
    {
        $tumanaPath = __DIR__ . '/../../frontend/assets/images/tumana_logo.jpg';
        $marikinaPath = __DIR__ . '/../../frontend/assets/images/marikina_logo.jpg';
        $tumanaLogo = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';
        $marikinaLogo = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';

        $caseNumber = !empty($data['case_number']) ? $this->escape((string) $data['case_number']) : '__________';
        $title = !empty($data['complaint_title']) ? $this->escape((string) $data['complaint_title']) : '__________';

        $compList = [];
        if (!empty($data['complainants']) && is_array($data['complainants'])) {
            foreach ($data['complainants'] as $c) {
                $cName = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
                if (trim($cName) !== '') $compList[] = trim($cName);
            }
        }
        $respList = [];
        if (!empty($data['respondents']) && is_array($data['respondents'])) {
            foreach ($data['respondents'] as $r) {
                $rName = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
                if (trim($rName) !== '') $respList[] = trim($rName);
            }
        }

        $defeatedParty = !empty($data['defeated_party_name']) ? $this->escape((string) $data['defeated_party_name']) : (!empty($respList) ? implode(' / ', $respList) : '______________________________');
        $amountWords = !empty($data['execution_amount_words']) ? $this->escape((string) $data['execution_amount_words']) : (!empty($data['amount_words']) ? $this->escape((string) $data['amount_words']) : '________________________');
        $amountNumbers = !empty($data['execution_amount_numbers']) ? $this->escape((string) $data['execution_amount_numbers']) : (!empty($data['amount_numeric']) ? $this->escape((string) $data['amount_numeric']) : '__________');
        $agreementDetails = !empty($data['agreement_details']) ? $this->escape((string) $data['agreement_details']) : (!empty($data['terms']) ? $this->escape((string) $data['terms']) : '');

        $compLines = '';
        foreach ($compList as $c) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($c) . '</div>'; }
        for ($i = 0; $i < max(0, 2 - count($compList)); $i++) { $compLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $respLines = '';
        foreach ($respList as $r) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px; font-weight: bold;">' . $this->escape($r) . '</div>'; }
        for ($i = 0; $i < max(0, 3 - count($respList)); $i++) { $respLines .= '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 5px;">&nbsp;</div>'; }

        $settlementTs = !empty($data['settlement_date']) ? strtotime($data['settlement_date']) : (!empty($data['hearing_date']) ? strtotime($data['hearing_date']) : time());
        $settlementDay = date('j', $settlementTs);
        $settlementMonth = $this->filipinoMonth((int) date('n', $settlementTs));
        $settlementYear = date('Y', $settlementTs);
        $settlementDateStr = "ika-{$settlementDay} ng {$settlementMonth}, {$settlementYear}";

        $noticeTs = !empty($data['notice_date']) ? strtotime($data['notice_date']) : time();
        $noticeDay = date('j', $noticeTs);
        $noticeMonth = $this->filipinoMonth((int) date('n', $noticeTs));
        $noticeYear = date('Y', $noticeTs);

        $officialName = $this->escape($this->getAuthorizedCaptainName($data['official_name'] ?? $data['captain_name'] ?? null));

        $wrapperStart = $forPdf ? '' : '<div style="background: #f1f5f9; padding: 24px 12px; min-height: 100vh; display: flex; justify-content: center;"><div style="background: #fff; width: 100%; max-width: 760px; min-height: 960px; padding: 14mm 16mm; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 4px; box-sizing: border-box;">';
        $wrapperEnd = $forPdf ? '' : '</div></div>';

        $termsBlock = '';
        if ($agreementDetails !== '') {
            $termsBlock = '<div style="border-bottom: 1px solid #111; font-weight: bold; padding: 4px 0; margin-bottom: 14px;">' . $agreementDetails . '</div>';
        } else {
            $termsBlock = '<div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 6px;">&nbsp;</div>
                <div style="border-bottom: 1px solid #111; min-height: 18px; margin-bottom: 14px;">&nbsp;</div>';
        }

        return '<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 25 - PAABISO PARA SA PAGPAPATUPAD</title>
    <style>
        @page { size: A4 portrait; margin: 15mm 18mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 11pt; color: #111827; line-height: 1.4; margin: 0; padding: 0; }
        .form-code { font-size: 11pt; font-weight: bold; margin-bottom: 6px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-center { text-align: center; font-size: 10pt; line-height: 1.25; }
    </style>
</head>
<body>
' . $wrapperStart . '
    <div class="form-code">Pormularyo ng KP Blg. 25</div>

    <table class="header-table">
        <tr>
            <td style="width: 80px; text-align: left; vertical-align: top;">
                ' . (!empty($tumanaLogo) ? '<img src="' . $tumanaLogo . '" alt="Barangay Tumana" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
            <td class="header-center" style="vertical-align: top;">
                <div>Republika ng Pilipinas</div>
                <div>Lalawigan ng NCR</div>
                <div>Lungsod ng Marikina</div>
                <div style="font-weight: bold;">Barangay Tumana</div>
                <div style="font-size: 9.5pt;">Telephone Nos. 8-238-4208</div>
                <div style="font-size: 9.5pt;">EMAILADDRESS:barangaytumana2023@gmail.com</div>
            </td>
            <td style="width: 80px; text-align: right; vertical-align: top;">
                ' . (!empty($marikinaLogo) ? '<img src="' . $marikinaLogo . '" alt="Lungsod ng Marikina" style="width: 76px; height: 76px; object-fit: contain;">' : '') . '
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 11pt; margin-top: 10px; margin-bottom: 16px;">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                ' . $compLines . '
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">(Mga) Nagrereklamo</div>
                <div style="text-align: center; font-size: 10pt; margin-bottom: 8px;">-laban kay/kina-</div>
                ' . $respLines . '
                <div style="text-align: center; font-size: 10pt;">(Mga) Inirereklamo</div>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right; padding-left: 20px;">
                <div>Usapin ng Barangay Blg.: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 130px; font-weight: bold; text-align: center;">' . $caseNumber . '</span></div>
                <div style="margin-top: 6px;">Para sa: <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 160px; font-weight: bold; text-align: center;">' . $title . '</span></div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin: 18px 0;">
        <div style="font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5px;">PAABISO PARA SA PAGPAPATUPAD</div>
        <div style="font-size: 11pt; font-weight: bold;">(Notice for Execution)</div>
    </div>

    <div style="font-size: 11pt; line-height: 1.8; text-align: justify; margin-bottom: 25px;">
        <p style="text-indent: 40px; margin: 0 0 14px 0;">
            SAPAGKAT, noong <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 150px; font-weight: bold; text-align: center;">' . $settlementDateStr . '</span> (petsa), isang matiwasay na pag-aayos ang nilagdaan ng mga panig sa usaping nakasaad sa itaas (o isang gawad ng paghahatol ang iginawad ng Punong Barangay/Pangkat ng Tagapagkasundo);
        </p>
        <p style="text-indent: 40px; margin: 0 0 10px 0;">
            SAPAGKAT, ang bahagi ng mga probisyon at mga kondisyon ng hatol ay mababasa tulad ng sumusunod:
        </p>
        ' . $termsBlock . '

        <p style="margin: 0 0 14px 0;">
            Ang nasabing pag-aayos/hatol ay pinal at maaari nang ipatupad;
        </p>

        <p style="text-indent: 40px; margin: 0 0 14px 0;">
            SAPAGKAT, ang natalong panig na si <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 200px; font-weight: bold; text-align: center;">' . $defeatedParty . '</span>(pangalan) ay hindi tumupad nang kusang-loob sa nasabing matiwasay na pag-aayos/gawad ng paghahatol sa loob ng limang (5) araw mula sa petsa ng pagdinig ng mosyon ng pagpapatupad;
        </p>

        <p style="text-indent: 40px; margin: 0 0 14px 0;">
            DAHIL DITO, sa ngalan ng Lupong Tagapamayapa at sa pamamagitan ng mga kapangyarihang ibinigay sa akin ng Lupon sa pamamagitan ng Batas at mga Patakaran ng Katarungang Pambarangay aking gagawin upang maisakatuparan mula sa mga kalakal at ari-ariang personal ni <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 200px; font-weight: bold; text-align: center;">' . $defeatedParty . '</span>(pangalan ng natalong panig) ang halagang <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 180px; font-weight: bold; text-align: center;">' . $amountWords . '</span>(₱<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 90px; font-weight: bold; text-align: center;">' . $amountNumbers . '</span>) (isaad ang halaga ng pag-aayos o hatol) na pinagkasunduan sa nasabing matiwasay na pag-aayos (o inihatol sa nasabing gawad ng paghahatol), maliban kung kusang-loob na tinupad ang nasabing pag-aayos o hatol ay ginawa sa sandaling matanggap ito.
        </p>

        <p style="text-indent: 40px; margin: 0;">
            Nilagdaan ngayong ika-<span style="display: inline-block; border-bottom: 1px solid #111; min-width: 60px; font-weight: bold; text-align: center;">' . $noticeDay . '</span> ng <span style="display: inline-block; border-bottom: 1px solid #111; min-width: 120px; font-weight: bold; text-align: center;">' . $noticeMonth . '</span>, ' . $noticeYear . '.
        </p>
    </div>

    <div style="text-align: right; margin-top: 40px;">
        <div style="display: inline-block; text-align: center;">
            <div style="border-top: 1px solid #111; width: 280px; margin-bottom: 4px;"></div>
            <div style="font-weight: bold; font-size: 11pt;">' . $officialName . '</div>
            <div style="font-size: 10pt;">Punong Barangay</div>
        </div>
    </div>
' . $wrapperEnd . '
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

        $captainName = $this->escape($this->getAuthorizedCaptainName($data['punong_barangay'] ?? $data['captain_name'] ?? $data['official_name'] ?? null));

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
        body { font-family: Arial, "DejaVu Sans", Helvetica, sans-serif; font-size: 10pt; color: #111111; line-height: 1.45; background: #ffffff; margin: 0; padding: 0; }
        .page-sheet { width: 100%; margin: 0; padding: 0; background: #ffffff; }
        ' : '
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            background: #475569;
            font-family: Arial, "DejaVu Sans", Helvetica, sans-serif;
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
