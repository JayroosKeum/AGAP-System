<?php
/**
 * AGAP System — Katarungang Pambarangay Document Templates API
 * Manages official KP Form templates (1 to 25), file uploads, previews, and printing.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$roleId = (int) $_SESSION['role_id'];
$userId = (int) $_SESSION['user_id'];
$canUpload = in_array($roleId, [1, 2], true);
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'list'));

require_once dirname(__DIR__, 2) . '/services/DocxTemplateService.php';

$storageDir = DocxTemplateService::getStorageDir();
$registryFile = DocxTemplateService::getRegistryFile();

// Master List of 30 Katarungang Pambarangay & Operational Forms
$masterForms = [
    'patawag' => [
        'code' => 'PATAWAG',
        'title' => 'PATAWAG (Blangkong Paanyaya / Pre-docketing Form)',
        'subtitle' => 'Paanyaya sa Tanggapan ng Punong Barangay Bago ang Pormal na Usapin',
        'category' => 'Pre-Docketing & Case Intake',
        'description' => 'Opisyal na blangkong paanyaya o paunang patawag sa nagrereklamo at inirereklamo para sa maayos na pag-uusap bago pormal na maipasok sa docket ng Lupon.'
    ],
    'case_documentation' => [
        'code' => 'CASE DOCUMENTATION',
        'title' => 'CASE DOCUMENTATION (Blangkong Intake & Case Profile Jacket)',
        'subtitle' => 'Talaan ng Usapin / Case Profile & Intake Documentation Jacket',
        'category' => 'Pre-Docketing & Case Intake',
        'description' => 'Opisyal na blangkong pabalat at talaan ng usapin (Case Jacket) na naglalaman ng buod ng partido, kategorya, milestone checklist, at imbentaryo ng mga dokumento.'
    ],
    'minutes_form' => [
        'code' => 'MINUTES FORM',
        'title' => 'MINUTES FORM (Blangkong Katitikan ng Pagdinig)',
        'subtitle' => 'Blangkong Katitikan ng Pagdinig at Pag-uusap sa Lupon / Pangkat',
        'category' => 'Hearings & Compliance',
        'description' => 'Opisyal na blangkong pormularyo para sa pagtatala ng mga pahayag, attendance, napagkasunduan, at naging kaganapan sa bawat pagdinig o sesyon.'
    ],
    'kp_1' => [
        'code' => 'KP Form No. 1',
        'title' => 'Notice to Constitute the Lupon',
        'subtitle' => 'Paunawa ukol sa Pagbubuo ng Lupon',
        'category' => 'Lupon Organization',
        'description' => 'Official notice posted by the Punong Barangay to announce appointment of Lupon members.'
    ],
    'kp_2' => [
        'code' => 'KP Form No. 2',
        'title' => 'Appointment',
        'subtitle' => 'Pagtatalaga ng Kasapi ng Lupon',
        'category' => 'Lupon Organization',
        'description' => 'Official appointment letter issued to a designated Lupong Tagapamayapa member.'
    ],
    'kp_3' => [
        'code' => 'KP Form No. 3',
        'title' => 'Notice of Appointment',
        'subtitle' => 'Paunawa ng Pagtatalaga',
        'category' => 'Lupon Organization',
        'description' => 'Formal notice served to the appointee regarding their selection to the Lupon.'
    ],
    'kp_4' => [
        'code' => 'KP Form No. 4',
        'title' => 'List of Appointed Lupon Members',
        'subtitle' => 'Talaan ng mga Hinirang na Kasapi ng Lupon',
        'category' => 'Lupon Organization',
        'description' => 'Official roster of constituted Lupong Tagapamayapa members submitted to DILG and court.'
    ],
    'kp_5' => [
        'code' => 'KP Form No. 5',
        'title' => 'Oath of Office',
        'subtitle' => 'Panunumpa sa Katungkulan',
        'category' => 'Lupon Organization',
        'description' => 'Solemn oath taken by appointed Lupon members administered by the Punong Barangay.'
    ],
    'kp_6' => [
        'code' => 'KP Form No. 6',
        'title' => 'Withdrawal of Appointment',
        'subtitle' => 'Pagbawi ng Pagtatalaga',
        'category' => 'Lupon Organization',
        'description' => 'Formal revocation of Lupon membership due to disqualification, transfer, or cause.'
    ],
    'kp_7' => [
        'code' => 'Pormularyo ng KP Blg. 7',
        'title' => 'REKLAMO (Complaint)',
        'subtitle' => 'Pormal na Reklamo / Complaint Form',
        'category' => 'Mediation & Summons',
        'description' => 'Opisyal na pormularyo ng reklamo mula sa Tanggapan ng Punong Barangay / Lupong Tagapamayapa.'
    ],
    'kp_8' => [
        'code' => 'Pormularyo ng KP Blg. 8',
        'title' => 'PAABISO NG PAGDINIG (Mediation Proceedings)',
        'subtitle' => 'Paabiso ng Pagdinig sa May-sumbong ukol sa Pamamagitan',
        'category' => 'Mediation & Summons',
        'description' => 'Opisyal na paabiso ng pagdinig mula sa Tanggapan ng Punong Barangay / Lupong Tagapamayapa para sa (mga) nagrereklamo para sa pagdinig ng reklamo.'
    ],
    'kp_9' => [
        'code' => 'Pormularyo ng KP Blg. 9',
        'title' => 'PATAWAG (Summons)',
        'subtitle' => 'Patawag sa Inirereklamo ukol sa Pamamagitan',
        'category' => 'Mediation & Summons',
        'description' => 'Opisyal na patawag mula sa Punong Barangay para sa inirereklamo upang personal na humarap kasama ang mga testigo.'
    ],
    'kp_10' => [
        'code' => 'Pormularyo ng KP Blg. 10',
        'title' => 'PAABISO SA PAGBUBUO NG PANGKAT (Notice for Constitution of Pangkat)',
        'subtitle' => 'Paabiso sa Pagbubuo ng Pangkat ng Tagapagkasundo',
        'category' => 'Conciliation & Pangkat',
        'description' => 'Opisyal na paabiso mula sa Punong Barangay para sa magkabilang panig upang humarap at bumuo ng 3 kasapi ng Pangkat.'
    ],
    'kp_11' => [
        'code' => 'Pormularyo ng KP Blg. 11',
        'title' => 'PAABISO SA PINILING KASAPI NG PANGKAT (Notice to Chosen Pangkat Member)',
        'subtitle' => 'Paabiso sa Napiling Kasapi ng Pangkat Tagapagkasundo',
        'category' => 'Conciliation & Pangkat',
        'description' => 'Opisyal na paabiso sa napiling kasapi ng Lupon na bumubuo sa Pangkat Tagapagkasundo.'
    ],
    'kp_12' => [
        'code' => 'Pormularyo ng KP Blg. 12',
        'title' => 'PAABISO NG PAGDINIG (Conciliation Proceedings)',
        'subtitle' => 'Paabiso ng Pagdinig sa Harap ng Pangkat Tagapagkasundo',
        'category' => 'Conciliation & Pangkat',
        'description' => 'Opisyal na paabiso ng pagdinig mula sa Tagapangulo ng Pangkat para sa conciliation proceedings.'
    ],
    'kp_13' => [
        'code' => 'Pormularyo ng KP Blg. 13',
        'title' => 'UTOS UPANG HUMARAP (Subpoena)',
        'subtitle' => 'Subpoena / Utos sa Pagsaksi o Pagharap ng mga Testigo',
        'category' => 'Conciliation & Pangkat',
        'description' => 'Opisyal na utos upang humarap at tumestigo sa pagdinig ng usapin sa harap ng Punong Barangay o Pangkat.'
    ],
    'kp_14' => [
        'code' => 'Pormularyo ng KP Blg. 14',
        'title' => 'KASUNDUAN PARA SA PAGHAHATOL (Agreement for Arbitration)',
        'subtitle' => 'Kasunduan sa Paghahatol / Agreement for Arbitration',
        'category' => 'Arbitration & Settlement',
        'description' => 'Opisyal na kasunduan ng magkabilang panig na idulog ang sigalot sa paghahatol ng Punong Barangay o Pangkat.'
    ],
    'kp_15' => [
        'code' => 'Pormularyo ng KP Blg. 15',
        'title' => 'GAWAD NG PAGHAHATOL (Arbitration Award)',
        'subtitle' => 'Gawad ng Paghahatol / Arbitration Award',
        'category' => 'Arbitration & Settlement',
        'description' => 'Opisyal na hatol at gawad ng paghahatol matapos marinig ang mga salaysay at magsuri ng ebidensya sa arbitrasyon.'
    ],
    'kp_16' => [
        'code' => 'Pormularyo ng KP Blg. 16',
        'title' => 'MATIWASAY NA PAG-AAYOS (Amicable Settlement)',
        'subtitle' => 'Matiwasay na Pag-aayos / Amicable Settlement',
        'category' => 'Arbitration & Settlement',
        'description' => 'Opisyal na kasunduan ng mga nagrereklamo at inirereklamo na maayos na tinatapos ang alitan alinsunod sa batas.'
    ],
    'kp_17' => [
        'code' => 'Pormularyo ng KP Blg. 17',
        'title' => 'PAGTANGGI (Repudiation)',
        'subtitle' => 'Pagtanggi sa Kasunduan o Paghahatol / Repudiation',
        'category' => 'Arbitration & Settlement',
        'description' => 'Sinumpaang pagtanggi sa kasunduan ng pag-aayos o paghahatol dahil sa pandaraya, karahasan, o pananakot.'
    ],
    'kp_18' => [
        'code' => 'Pormularyo ng KP Blg. 18',
        'title' => 'PAABISO NG PAGDINIG (Re: Failure to Appear)',
        'subtitle' => 'Paabiso ng Pagdinig ukol sa Kabiguang Humarap ng Nagrereklamo',
        'category' => 'Hearings & Compliance',
        'description' => 'Opisyal na paabiso sa nagrereklamo upang magpaliwanag sa kabiguang humarap sa itinakdang pagdinig.'
    ],
    'kp_19' => [
        'code' => 'Pormularyo ng KP Blg. 19',
        'title' => 'PAABISO NG PAGDINIG (Re: Failure to Appear)',
        'subtitle' => 'Paabiso ng Pagdinig ukol sa Kabiguang Humarap ng Inirereklamo',
        'category' => 'Hearings & Compliance',
        'description' => 'Opisyal na paabiso sa inirereklamo upang magpaliwanag sa kabiguang humarap sa itinakdang pagdinig ukol sa pamamagitan o pagkakasundo.'
    ],
    'kp_20' => [
        'code' => 'Pormularyo ng KP Blg. 20',
        'title' => 'KATIBAYAN NA MAKAPAGHAIN NG SAKDAL (Certification to File Action)',
        'subtitle' => 'Katibayan Dulot ng Pagtanggi sa Kasunduan (Repudiated Settlement)',
        'category' => 'Certifications & Barred Actions',
        'description' => 'Katibayan na makapaghain ng sakdal sa hukuman o tanggapan ng pamahalaan matapos tanggihan ang kasunduan sa pamamagitan ng sinumpaang salaysay.'
    ],
    'kp_20_a' => [
        'code' => 'Pormularyo ng KP Blg. 20-A',
        'title' => 'KATIBAYAN NA MAKAPAGHAIN NG SAKDAL (Certification to File Action)',
        'subtitle' => 'Katibayan Matapos Mabigo ang Pamamagitan at Paghaharap sa Pangkat',
        'category' => 'Certifications & Barred Actions',
        'description' => 'Katibayan na makapaghain ng sakdal sa hukuman matapos mabigo ang pamamagitan sa Punong Barangay at di nagbunga ng pag-aayos sa harap ng Pangkat.'
    ],
    'kp_20_b' => [
        'code' => 'Pormularyo ng KP Blg. 20-B',
        'title' => 'KATIBAYAN NA MAKAPAGHAIN NG SAKDAL (Certification to File Action)',
        'subtitle' => 'Katibayan Dulot ng Kabiguang Humarap ng Inirereklamo sa Pangkat',
        'category' => 'Certifications & Barred Actions',
        'description' => 'Katibayan na makapaghain ng sakdal sa hukuman matapos sinadyang tumanggi ng inirereklamo na humarap sa Pangkat.'
    ],
    'kp_21' => [
        'code' => 'Pormularyo ng KP Blg. 21',
        'title' => 'KATIBAYAN UPANG HADLANGAN NA MAKAPAGSAKDAL (Certification to Bar Action)',
        'subtitle' => 'Katunayan sa Paghadlang sa Pagsasampa ng Usapin ng Nagrereklamo',
        'category' => 'Certifications & Barred Actions',
        'description' => 'Katibayan sa paghadlang sa nagrereklamo na makapagsampa ng sakdal sa hukuman dahil sa sinadyang di-pagharap sa pagdinig.'
    ],
    'kp_22' => [
        'code' => 'Pormularyo ng KP Blg. 22',
        'title' => 'KATIBAYAN UPANG HADLANGAN NA MAKAPAGSAKDAL (Certification to Bar Counterclaim)',
        'subtitle' => 'Katunayan sa Paghadlang sa Ganting-Habla ng Inirereklamo',
        'category' => 'Certifications & Barred Actions',
        'description' => 'Katibayan sa paghadlang sa inirereklamo na maghain ng ganting-sumbong dahil sa sinadyang pagtangging humarap sa pagdinig.'
    ],
    'kp_23' => [
        'code' => 'Pormularyo ng KP Blg. 23',
        'title' => 'MOSYON SA PAGPAPATUPAD (Motion for Execution)',
        'subtitle' => 'Kahilingan sa Pagpapatupad ng Kasunduan o Gawad',
        'category' => 'Execution of Settlement',
        'description' => 'Opisyal na mosyon ng nagrereklamo o inirereklamo para sa pagpapatupad ng pinal na kasunduan o paghahatol matapos lumipas ang 10 araw na taning.'
    ],
    'kp_24' => [
        'code' => 'KP Form No. 24',
        'title' => 'Notice of Hearing (re: Motion for Execution)',
        'subtitle' => 'Paunawa ng Pagdinig ukol sa Kahilingan sa Pagpapatupad',
        'category' => 'Execution of Settlement',
        'description' => 'Notice of hearing to resolve party motion for execution of settlement.'
    ],
    'kp_25' => [
        'code' => 'Pormularyo ng KP Blg. 25',
        'title' => 'PAABISO PARA SA PAGPAPATUPAD (Notice for Execution)',
        'subtitle' => 'Kautusan sa Pagpapatupad ng Kasunduan o Hatol',
        'category' => 'Execution of Settlement',
        'description' => 'Opisyal na paabiso o utos ng pagpapatupad mula sa Punong Barangay para sa pagsasakatuparan ng kasunduan o gawad mula sa mga kalakal at ari-ariang personal ng natalong panig.'
    ],
];

// Helper to load registry
function loadRegistry($file) {
    if (file_exists($file)) {
        $json = @file_get_contents($file);
        $data = json_decode($json, true);
        if (is_array($data)) return $data;
    }
    return [];
}

// Helper to save registry
function saveRegistry($file, $data) {
    @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$registry = loadRegistry($registryFile);

function getActiveBranding(): array {
    $defaults = DocxTemplateService::getSharedBrandingDefaults();
    try {
        require_once dirname(__DIR__, 2) . '/config/Database.php';
        require_once dirname(__DIR__, 2) . '/models/Setting.php';
        $settingModel = new Setting();
        $stored = $settingModel->get('kp_branding_config');
        if ($stored) {
            $parsed = json_decode($stored, true);
            if (is_array($parsed)) {
                $defaults = array_replace_recursive($defaults, $parsed);
            }
        }
    } catch (\Throwable $e) {}

    $defaults['logo_primary_data_uri'] = DocxTemplateService::getLogoDataUri($defaults['logo_primary'] ?? 'tumana_logo.jpg', 'tumana_logo.jpg');
    $defaults['logo_secondary_data_uri'] = DocxTemplateService::getLogoDataUri($defaults['logo_secondary'] ?? 'marikina_logo.jpg', 'marikina_logo.jpg');
    return $defaults;
}

function getActivePaperSettings(): array {
    $defaults = DocxTemplateService::getSharedPaperDefaults();
    try {
        require_once dirname(__DIR__, 2) . '/config/Database.php';
        require_once dirname(__DIR__, 2) . '/models/Setting.php';
        $settingModel = new Setting();
        $stored = $settingModel->get('kp_paper_settings');
        if ($stored) {
            $parsed = json_decode($stored, true);
            if (is_array($parsed)) {
                return array_replace_recursive($defaults, $parsed);
            }
        }
        $all = $settingModel->getAllSettings();
        if (!empty($all['document_paper_size'])) $defaults['paper_size'] = $all['document_paper_size'];
        if (isset($all['document_margin_top'])) $defaults['margin_top'] = (int)$all['document_margin_top'];
        if (isset($all['document_margin_bottom'])) $defaults['margin_bottom'] = (int)$all['document_margin_bottom'];
        if (isset($all['document_margin_left'])) $defaults['margin_left'] = (int)$all['document_margin_left'];
        if (isset($all['document_margin_right'])) $defaults['margin_right'] = (int)$all['document_margin_right'];
    } catch (\Throwable $e) {}
    return $defaults;
}

function getWireframeCategory(string $key, array $meta): string {
    if (in_array($key, ['patawag', 'case_documentation'], true)) {
        return 'Pre-Docketing';
    }
    if (in_array($key, ['kp_10', 'kp_11', 'kp_12', 'kp_13', 'kp_14', 'kp_15', 'kp_16', 'kp_17'], true)) {
        return 'Pangkat';
    }
    if (in_array($key, ['kp_20', 'kp_20_a', 'kp_20_b', 'kp_21', 'kp_22', 'kp_23', 'kp_24', 'kp_25'], true)) {
        return 'Certifications';
    }
    return 'Mediation';
}

// 0. GET/SAVE BRANDING
if ($action === 'get_branding') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'branding' => getActiveBranding()]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action === 'save_branding') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$canUpload) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin permissions required.']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $branding = array_replace_recursive(DocxTemplateService::getSharedBrandingDefaults(), $input['branding'] ?? $input);
    try {
        require_once dirname(__DIR__, 2) . '/config/Database.php';
        require_once dirname(__DIR__, 2) . '/models/Setting.php';
        $settingModel = new Setting();
        $settingModel->set('kp_branding_config', json_encode($branding, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'Centralized KP Letterhead & Branding Profile');
    } catch (\Throwable $e) {}
    echo json_encode(['success' => true, 'message' => 'Branding configuration saved successfully.', 'branding' => $branding]);
    exit;
}

// 0. UPLOAD BRANDING LOGO (PRIMARY OR SECONDARY)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action === 'upload_logo') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$canUpload) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin permissions required to upload logos.']);
        exit;
    }

    $logoType = trim((string)($_POST['logo_type'] ?? 'primary'));
    if (!in_array($logoType, ['primary', 'secondary'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid logo type specified.']);
        exit;
    }

    if (empty($_FILES['logo_file']) || $_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No image file uploaded or upload error occurred.']);
        exit;
    }

    $file = $_FILES['logo_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['png', 'jpg', 'jpeg', 'webp', 'svg'];
    if (!in_array($ext, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file format. Allowed: PNG, JPG, JPEG, WEBP, SVG.']);
        exit;
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Image size cannot exceed 5 MB.']);
        exit;
    }

    $brandingDir = dirname(__DIR__, 2) . '/storage/uploads/branding';
    if (!is_dir($brandingDir)) {
        @mkdir($brandingDir, 0777, true);
    }

    $destName = "seal_{$logoType}_" . date('Ymd_His') . ".{$ext}";
    $destPath = $brandingDir . '/' . $destName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save logo file to storage.']);
        exit;
    }

    $branding = getActiveBranding();
    if ($logoType === 'primary') {
        $branding['logo_primary'] = 'storage/uploads/branding/' . $destName;
    } else {
        $branding['logo_secondary'] = 'storage/uploads/branding/' . $destName;
    }

    try {
        require_once dirname(__DIR__, 2) . '/config/Database.php';
        require_once dirname(__DIR__, 2) . '/models/Setting.php';
        $settingModel = new Setting();
        $settingModel->set('kp_branding_config', json_encode($branding, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'Centralized KP Letterhead & Branding Profile');
    } catch (\Throwable $e) {}

    $logoDataUri = DocxTemplateService::getLogoDataUri($branding['logo_' . $logoType]);

    echo json_encode([
        'success' => true,
        'message' => ($logoType === 'primary' ? 'Barangay Tumana Seal' : 'City of Marikina Seal') . ' updated successfully!',
        'logo_type' => $logoType,
        'logo_path' => $branding['logo_' . $logoType],
        'logo_data_uri' => $logoDataUri,
        'branding' => getActiveBranding()
    ]);
    exit;
}

// 0. RESET BRANDING LOGO TO SYSTEM DEFAULT
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action === 'reset_logo') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$canUpload) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin permissions required.']);
        exit;
    }

    $logoType = trim((string)($_POST['logo_type'] ?? 'primary'));
    $branding = getActiveBranding();
    if ($logoType === 'primary') {
        $branding['logo_primary'] = 'tumana_logo.jpg';
    } else {
        $branding['logo_secondary'] = 'marikina_logo.jpg';
    }

    try {
        require_once dirname(__DIR__, 2) . '/config/Database.php';
        require_once dirname(__DIR__, 2) . '/models/Setting.php';
        $settingModel = new Setting();
        $settingModel->set('kp_branding_config', json_encode($branding, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'Centralized KP Letterhead & Branding Profile');
    } catch (\Throwable $e) {}

    echo json_encode([
        'success' => true,
        'message' => ($logoType === 'primary' ? 'Barangay Tumana Seal' : 'City of Marikina Seal') . ' reset to system default.',
        'logo_type' => $logoType,
        'branding' => getActiveBranding()
    ]);
    exit;
}

// 0. GET/SAVE PRINT & PAPER SETTINGS
if ($action === 'get_paper_settings') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'paper' => getActivePaperSettings()]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action === 'save_paper_settings') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$canUpload) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin permissions required.']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $paper = array_replace_recursive(DocxTemplateService::getSharedPaperDefaults(), $input['paper'] ?? $input);
    try {
        require_once dirname(__DIR__, 2) . '/config/Database.php';
        require_once dirname(__DIR__, 2) . '/models/Setting.php';
        $settingModel = new Setting();
        $settingModel->set('kp_paper_settings', json_encode($paper, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'Centralized KP Paper & Print Profile');
        if (!empty($paper['paper_size'])) $settingModel->set('document_paper_size', (string)$paper['paper_size']);
        if (isset($paper['margin_top'])) $settingModel->set('document_margin_top', (string)$paper['margin_top']);
        if (isset($paper['margin_bottom'])) $settingModel->set('document_margin_bottom', (string)$paper['margin_bottom']);
        if (isset($paper['margin_left'])) $settingModel->set('document_margin_left', (string)$paper['margin_left']);
        if (isset($paper['margin_right'])) $settingModel->set('document_margin_right', (string)$paper['margin_right']);
    } catch (\Throwable $e) {}
    echo json_encode(['success' => true, 'message' => 'Paper and print settings saved successfully.', 'paper' => $paper]);
    exit;
}

// 0. INSPECT DOCX
if ($action === 'inspect') {
    header('Content-Type: application/json; charset=utf-8');
    $formKey = trim((string)($_GET['form_key'] ?? ''));
    $customUpload = $registry[$formKey] ?? null;
    $filePath = '';
    if ($customUpload && !empty($customUpload['file_path'])) {
        $candidate = dirname(__DIR__, 3) . '/' . $customUpload['file_path'];
        if (file_exists($candidate)) {
            $filePath = $candidate;
        }
    }
    if (!$filePath) {
        $masterDocx = DocxTemplateService::getStorageDir() . "/master_{$formKey}.docx";
        if (!file_exists($masterDocx)) {
            DocxTemplateService::generateMasterDocx($formKey, $masterDocx);
        }
        $filePath = $masterDocx;
    }
    $inspection = DocxTemplateService::inspectDocx($filePath);
    echo json_encode(['success' => true, 'inspection' => $inspection, 'template' => $customUpload]);
    exit;
}

// 0. SAVE FORM TEMPLATE CONFIG
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action === 'save_template') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$canUpload) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin permissions required.']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $formKey = trim((string)($input['form_key'] ?? ''));
    if (!isset($masterForms[$formKey])) {
        echo json_encode(['success' => false, 'message' => 'Invalid KP Form key.']);
        exit;
    }
    $current = $registry[$formKey] ?? [
        'code' => $masterForms[$formKey]['code'],
        'title' => $masterForms[$formKey]['title'],
        'file_path' => '',
        'file_name' => "{$formKey}.docx",
        'file_size' => 0,
        'uploaded_at' => date('Y-m-d H:i:s'),
        'uploaded_by' => $userId
    ];
    $current['header_mode'] = $input['header_mode'] ?? ($current['header_mode'] ?? 'shared');
    $current['paper_mode'] = $input['paper_mode'] ?? ($current['paper_mode'] ?? 'shared');
    if (!empty($input['custom_paper'])) {
        $current['custom_paper'] = $input['custom_paper'];
    }
    $current['status'] = $input['status'] ?? ($current['status'] ?? 'published');
    $current['signatory_profile'] = $input['signatory_profile'] ?? ($current['signatory_profile'] ?? 'punong_barangay');
    $current['version'] = ($current['version'] ?? 1) + 1;
    $current['updated_at'] = date('Y-m-d H:i:s');

    $registry[$formKey] = $current;
    saveRegistry($registryFile, $registry);

    echo json_encode(['success' => true, 'message' => 'Template configuration saved successfully.', 'template' => $current]);
    exit;
}

// 0. DOWNLOAD DOCX
if ($action === 'download_docx') {
    $formKey = trim((string)($_GET['form_key'] ?? 'kp_7'));
    if (!isset($masterForms[$formKey])) {
        http_response_code(404);
        echo 'Form not found.';
        exit;
    }
    $branding = getActiveBranding();
    $paper = getActivePaperSettings();
    $templateConfig = $registry[$formKey] ?? [];
    $headerMode = $templateConfig['header_mode'] ?? 'shared';
    $effectivePaper = DocxTemplateService::resolveEffectivePaperSettings($templateConfig, $paper);

    $masterDocx = DocxTemplateService::getStorageDir() . "/master_{$formKey}.docx";
    if (!empty($templateConfig['file_path'])) {
        $candidate = dirname(__DIR__, 3) . '/' . $templateConfig['file_path'];
        if (file_exists($candidate) && str_ends_with(strtolower($candidate), '.docx')) {
            $masterDocx = $candidate;
        }
    }
    if (!file_exists($masterDocx)) {
        DocxTemplateService::generateMasterDocx($formKey, $masterDocx, $headerMode, $branding, $effectivePaper);
    }

    $tmpDownload = DocxTemplateService::getStorageDir() . "/download_{$formKey}_" . session_id() . ".docx";
    $sampleData = [
        'case_number' => 'KP-2026-10-0042',
        'complaint_title' => $masterForms[$formKey]['title'],
        'complainants' => 'Juan Dela Cruz y Santos',
        'respondents' => 'Pedro Garcia at Maria Garcia',
        'respondent_address' => 'Barangay Tumana, Marikina City',
        'hearing_date' => date('F d, Y', strtotime('+3 days')),
        'hearing_time' => '9:30 AM',
        'venue' => 'Tanggapan ng Lupong Tagapamayapa, Barangay Hall, Tumana',
        'docket_date' => date('F d, Y'),
        'date_today' => date('F d, Y'),
    ];

    DocxTemplateService::processAndFillDocx($masterDocx, $tmpDownload, $sampleData, $headerMode, $branding, $effectivePaper);

    $outName = strtoupper($formKey) . '_Master_Template.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $outName . '"');
    header('Content-Length: ' . filesize($tmpDownload));
    readfile($tmpDownload);
    @unlink($tmpDownload);
    exit;
}

// 1. PREVIEW TEMPLATE (HTML or Download or View PDF)
if ($action === 'preview') {
    $formKey = trim((string)($_GET['form_key'] ?? ''));
    if (!isset($masterForms[$formKey])) {
        http_response_code(404);
        echo 'Form not found.';
        exit;
    }

    $formMeta = $masterForms[$formKey];
    $customUpload = $registry[$formKey] ?? [];
    $branding = getActiveBranding();
    $paper = getActivePaperSettings();
    $effectivePaper = DocxTemplateService::resolveEffectivePaperSettings($customUpload, $paper);

    // If custom upload exists and is a PDF or image, serve it directly
    if (!empty($customUpload['file_path'])) {
        $fullPath = dirname(__DIR__, 3) . '/' . $customUpload['file_path'];
        if (file_exists($fullPath)) {
            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'png', 'jpg', 'jpeg'], true)) {
                $mime = mime_content_type($fullPath) ?: 'application/pdf';
                header('Content-Type: ' . $mime);
                header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
                readfile($fullPath);
                exit;
            }
        }
    }

    // Render synchronized visual preview using DocxTemplateService
    header('Content-Type: text/html; charset=utf-8');
    echo DocxTemplateService::renderDocxHtmlPreview($formKey, $customUpload, $branding, $effectivePaper);
    exit;
}

// 2. UPLOAD CUSTOM TEMPLATE
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action === 'upload') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$canUpload) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Administrator or Officer privileges required to upload templates.']);
        exit;
    }

    $formKey = trim((string)($_POST['form_key'] ?? ''));
    if (!isset($masterForms[$formKey])) {
        echo json_encode(['success' => false, 'message' => 'Invalid KP Form code.']);
        exit;
    }

    if (empty($_FILES['template_file']) || $_FILES['template_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or file upload error occurred.']);
        exit;
    }

    $file = $_FILES['template_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg'];

    if (!in_array($ext, $allowedExts, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file format. Allowed: PDF, DOC, DOCX, PNG, JPG.']);
        exit;
    }

    if ($file['size'] > 12 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File size exceeds maximum allowable limit of 12 MB.']);
        exit;
    }

    $filename = "{$formKey}_template_" . date('Ymd_His') . ".{$ext}";
    $destination = $storageDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save template to storage directory.']);
        exit;
    }

    $inspection = null;
    $isDocx = ($ext === 'docx');
    if ($isDocx) {
        $inspection = DocxTemplateService::inspectDocx($destination);
    }

    // Save into registry
    $registry[$formKey] = [
        'code' => $masterForms[$formKey]['code'],
        'title' => $masterForms[$formKey]['title'],
        'file_path' => 'storage/uploads/templates/' . $filename,
        'file_name' => $file['name'],
        'file_size' => $file['size'],
        'is_docx' => $isDocx,
        'header_mode' => ($inspection && ($inspection['is_body_only'] ?? false)) ? 'shared' : 'keep',
        'paper_mode' => 'shared',
        'status' => 'published',
        'version' => 1,
        'inspection' => $inspection,
        'uploaded_at' => date('Y-m-d H:i:s'),
        'uploaded_by' => $userId
    ];
    saveRegistry($registryFile, $registry);

    echo json_encode([
        'success' => true,
        'message' => "Successfully uploaded and inspected template for {$masterForms[$formKey]['code']}.",
        'template' => $registry[$formKey],
        'inspection' => $inspection
    ]);
    exit;
}

// 3. DELETE CUSTOM TEMPLATE (RESTORE PLACEHOLDER)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$canUpload) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Administrator privileges required.']);
        exit;
    }

    $formKey = trim((string)($_POST['form_key'] ?? ''));
    if (isset($registry[$formKey])) {
        $storedPath = dirname(__DIR__, 3) . '/' . $registry[$formKey]['file_path'];
        if (file_exists($storedPath)) {
            @unlink($storedPath);
        }
        unset($registry[$formKey]);
        saveRegistry($registryFile, $registry);
    }

    echo json_encode([
        'success' => true,
        'message' => "Reset template for {$masterForms[$formKey]['code']} to official system placeholder."
    ]);
    exit;
}

function getShortKpNumber(string $key, string $code): string {
    if (preg_match('/(?:KP\s*Form\s*(?:No\.?)?|Pormularyo\s*ng\s*KP\s*Blg\.?)\s*([0-9]+(?:\s*-\s*[A-Za-z0-9]+)?)/i', $code, $m)) {
        return 'KP Form ' . strtoupper(str_replace(' ', '', $m[1]));
    }
    if (str_starts_with($key, 'kp_')) {
        $part = strtoupper(str_replace('kp_', '', $key));
        $part = str_replace('_', '-', $part);
        return 'KP Form ' . $part;
    }
    return strtoupper($code);
}

// 4. LIST ALL FORMS & TEMPLATE STATUS
header('Content-Type: application/json; charset=utf-8');
$list = [];
$publishedCount = 0;

foreach ($masterForms as $k => $f) {
    $hasUpload = isset($registry[$k]);
    $custom = $hasUpload ? $registry[$k] : null;

    // Badges matching wireframe: 'DOCX template', 'Official template', 'Published'
    $badge = 'Official template';
    if ($hasUpload) {
        if (($custom['status'] ?? 'published') === 'published') {
            $badge = 'Published';
            $publishedCount++;
        } else {
            $badge = 'DOCX template';
        }
    } else {
        if (in_array($k, ['kp_8', 'kp_9', 'kp_24', 'kp_25'], true)) {
            $badge = 'Published';
            $publishedCount++;
        } elseif ($k === 'patawag') {
            $badge = 'DOCX template';
        }
    }

    $kpNumber = getShortKpNumber($k, $f['code']);

    $list[] = [
        'key' => $k,
        'code' => $f['code'],
        'kp_number' => $kpNumber,
        'title' => $f['title'],
        'subtitle' => $f['subtitle'],
        'category' => getWireframeCategory($k, $f),
        'original_category' => $f['category'],
        'description' => $f['description'],
        'has_custom_template' => $hasUpload,
        'custom_template' => $custom,
        'badge' => $badge,
        'is_docx' => $custom['is_docx'] ?? false,
        'header_mode' => $custom['header_mode'] ?? 'shared',
        'paper_mode' => $custom['paper_mode'] ?? 'shared',
        'preview_url' => "../../../backend/api/documents/templates.php?action=preview&form_key=" . urlencode($k),
        'download_url' => "../../../backend/api/documents/templates.php?action=download_docx&form_key=" . urlencode($k)
    ];
}

$branding = getActiveBranding();
$paper = getActivePaperSettings();

echo json_encode([
    'success' => true,
    'data' => $list,
    'total_forms' => '25+',
    'published_count' => 'Published',
    'active_count' => $publishedCount,
    'branding_profile' => 'Shared',
    'branding' => $branding,
    'paper' => $paper
]);
exit;
