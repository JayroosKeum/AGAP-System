<?php

session_start();

if (
    !isset($_SESSION['role_id']) ||
    !in_array((int)$_SESSION['role_id'], [1, 2], true)
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../../../backend/config/database.php';

$db = (new Database())->connect();
$categoriesStmt = $db->query("
    SELECT category_id, category_name 
    FROM complaint_categories 
    ORDER BY 
        CASE 
            WHEN category_name LIKE 'Others%' OR category_name LIKE 'Iba pa%' THEN 1 
            ELSE 0 
        END ASC, 
        category_name ASC
");
$categories = $categoriesStmt ? $categoriesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$residentsStmt = $db->query("
    SELECT resident_id, first_name, middle_name, last_name, suffix, birth_date, gender, civil_status, occupation, contact_no, email, preferred_contact, purok, address, home_latitude, home_longitude, is_tenant, residency_verified
    FROM residents 
    ORDER BY last_name ASC, first_name ASC
");
$residents = $residentsStmt ? $residentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$marikinaPath = __DIR__ . '/../../assets/images/marikina_logo.jpg';
$tumanaPath = __DIR__ . '/../../assets/images/tumana_logo.jpg';
$marikinaLogoB64 = file_exists($marikinaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($marikinaPath)) : '';
$tumanaLogoB64 = file_exists($tumanaPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tumanaPath)) : '';

$renderResidentOptions = function(?int $selectedId = null) use ($residents): string {
    $html = '<option value="">Select Profile...</option>';
    foreach ($residents as $res) {
        $fullName = trim(implode(' ', array_filter([$res['first_name'], $res['middle_name'] ?? '', $res['last_name']])));
        $displayName = ($res['last_name'] !== '-')
            ? ($res['last_name'] . ', ' . $res['first_name'] . (!empty($res['middle_name']) ? ' ' . $res['middle_name'] : ''))
            : $fullName;
        $addrParts = [];
        if (!empty($res['address'])) {
            $addrParts[] = trim($res['address']);
        }
        if (!empty($res['purok']) && (empty($res['address']) || stripos($res['address'], $res['purok']) === false)) {
            $addrParts[] = trim($res['purok']);
        }
        $addressText = !empty($addrParts) ? implode(', ', $addrParts) : 'No address on file';
        $isTenantInt = (int)($res['is_tenant'] ?? 0);
        $purokVal = trim((string)($res['purok'] ?? ''));
        if ($isTenantInt === 2 || strcasecmp($purokVal, 'Non-Resident') === 0) {
            $residencyTag = 'Non-Resident';
        } elseif ($isTenantInt === 1) {
            $residencyTag = 'Tenant - Tumana';
        } else {
            $residencyTag = 'Resident';
        }
        $optionLabel = $displayName . ' [' . $residencyTag . '] — ' . $addressText;
        $isSelected = ($selectedId !== null && (int)$selectedId === (int)$res['resident_id']) ? ' selected' : '';

        $html .= '<option value="' . (int)$res['resident_id'] . '"'
            . ' data-name="' . htmlspecialchars($fullName, ENT_QUOTES) . '"'
            . ' data-purok="' . htmlspecialchars($res['purok'] ?? '', ENT_QUOTES) . '"'
            . ' data-address="' . htmlspecialchars($res['address'] ?? '', ENT_QUOTES) . '"'
            . ' data-contact="' . htmlspecialchars($res['contact_no'] ?? '', ENT_QUOTES) . '"'
            . ' data-is-tenant="' . (int)($res['is_tenant'] ?? 0) . '"'
            . $isSelected . '>'
            . htmlspecialchars($optionLabel, ENT_QUOTES)
            . '</option>';
    }
    return $html;
};

$residentsJsonData = [];
foreach ($residents as $res) {
    $fullName = trim(implode(' ', array_filter([$res['first_name'], $res['middle_name'] ?? '', $res['last_name']])));
    $displayName = ($res['last_name'] !== '-')
        ? ($res['last_name'] . ', ' . $res['first_name'] . (!empty($res['middle_name']) ? ' ' . $res['middle_name'] : ''))
        : $fullName;
    $addrParts = [];
    if (!empty($res['address'])) {
        $addrParts[] = trim($res['address']);
    }
    if (!empty($res['purok']) && (empty($res['address']) || stripos($res['address'], $res['purok']) === false)) {
        $addrParts[] = trim($res['purok']);
    }
    $addressText = !empty($addrParts) ? implode(', ', $addrParts) : 'No address on file';
    $searchText = strtolower($fullName . ' ' . $displayName . ' ' . ($res['purok'] ?? '') . ' ' . ($res['address'] ?? '') . ' ' . ($res['contact_no'] ?? ''));

    $isTenantInt = (int)($res['is_tenant'] ?? 0);
    $purokVal = trim((string)($res['purok'] ?? ''));
    $resLabel = ($isTenantInt === 2 || strcasecmp($purokVal, 'Non-Resident') === 0) ? 'Non-Resident' : ($isTenantInt === 1 ? 'Tenant - Tumana' : 'Resident');

    $residentsJsonData[] = [
        'resident_id' => (int) $res['resident_id'],
        'name' => $fullName,
        'display_name' => $displayName,
        'first_name' => $res['first_name'] ?? '',
        'middle_name' => $res['middle_name'] ?? '',
        'last_name' => $res['last_name'] ?? '',
        'suffix' => $res['suffix'] ?? '',
        'birth_date' => $res['birth_date'] ?? '',
        'gender' => $res['gender'] ?? '',
        'civil_status' => $res['civil_status'] ?? '',
        'occupation' => $res['occupation'] ?? '',
        'preferred_contact' => $res['preferred_contact'] ?? 'SMS',
        'purok' => $res['purok'] ?? '',
        'address' => $res['address'] ?? '',
        'address_text' => $addressText,
        'contact_no' => $res['contact_no'] ?? '',
        'email' => $res['email'] ?? '',
        'home_latitude' => isset($res['home_latitude']) && $res['home_latitude'] !== '' && $res['home_latitude'] !== null ? (float)$res['home_latitude'] : null,
        'home_longitude' => isset($res['home_longitude']) && $res['home_longitude'] !== '' && $res['home_longitude'] !== null ? (float)$res['home_longitude'] : null,
        'is_tenant' => $isTenantInt,
        'residency_label' => $resLabel,
        'residency_verified' => (int)($res['residency_verified'] ?? 1),
        'search_text' => $searchText,
    ];
}

$complaintFlash = $_SESSION['complaint_flash'] ?? null;
$old = $complaintFlash['old'] ?? [];
unset($_SESSION['complaint_flash']);

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">
<link rel="stylesheet" href="../../assets/css/residents.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/residents.css'); ?>">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="intake-main-wrapper">
            <!-- Header Bar -->
            <div class="intake-header-bar">
                <div>
                    <a href="complaint-list.php" class="back-link">
                        <svg class="back-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                        <span>Back to Complaints</span>
                    </a>
                    <h1 class="intake-title">File Complaint</h1>
                </div>
            </div>

            <?php if ($complaintFlash && !empty($complaintFlash['message'])): ?>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    window.agapNotify?.(<?php echo json_encode($complaintFlash['message']); ?>, <?php echo json_encode($complaintFlash['type'] ?? 'error'); ?>, 'Complaint Intake');
                });
                </script>
            <?php endif; ?>

            <form id="createComplaintForm" action="../../../backend/api/complaints/create.php" method="POST" enctype="multipart/form-data" class="complaint-intake-form">
                <input type="hidden" name="bypass_office_hours" value="1">

                <div class="intake-layout-container" id="intakeLayoutContainer">

                    <!-- LEFT PANEL COLUMN: Quick-Nav in Digital mode; Document Viewer in Pre-Signed Paper mode -->
                    <div class="intake-left-panel-col" id="intakeLeftPanel">
                        <!-- STICKY QUICK-NAV SIDEBAR (Digital Mode) -->
                        <nav class="intake-quicknav-sidebar" id="intakeQuickNav" aria-label="Form Quick Navigation">
                            <div class="quicknav-title">Form Quick-Nav</div>
                            
                            <a href="#section-ingestion" id="nav-sec-ingestion" class="quicknav-link is-active">
                                <span class="quicknav-badge">1</span> Filing Method &amp; Case Classification
                            </a>
                            
                            <a href="#section-parties" id="nav-sec-parties" class="quicknav-link">
                                <span class="quicknav-badge">2</span> Involved Parties
                            </a>

                            <a href="#section-narrative" id="nav-sec-narrative" class="quicknav-link">
                                <span class="quicknav-badge">3</span> Statement of Facts &amp; Relief Sought
                            </a>
                            
                            <a href="#section-location" id="nav-sec-location" class="quicknav-link">
                                <span class="quicknav-badge">4</span> Incident Location &amp; Map Pin
                            </a>

                            <a href="#section-evidence" id="nav-sec-evidence" class="quicknav-link">
                                <span class="quicknav-badge">5</span> Evidence &amp; Attachments
                            </a>

                            <div class="quicknav-footer">
                                Fill out required (<span class="required-mark">*</span>) fields before final submission to Lupon Tagapamayapa.
                            </div>
                        </nav>

                        <!-- DOCUMENT VIEWER (Pre-Signed Paper Form Mode) -->
                        <div class="paper-doc-viewer" id="paperDocViewer" style="display: none;">
                            <div class="doc-viewer-card">
                                <div class="doc-viewer-header">
                                    <div class="doc-viewer-title-group">
                                        <span class="doc-viewer-icon">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: #38bdf8; display: block;">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                                <polyline points="14 2 14 8 20 8"></polyline>
                                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                                <line x1="16" y1="17" x2="8" y2="17"></line>
                                                <polyline points="10 9 9 9 8 9"></polyline>
                                            </svg>
                                        </span>
                                        <div>
                                            <div class="doc-viewer-title">Document Viewer</div>
                                            <div class="doc-viewer-subtitle" id="docViewerStatusText">No document loaded</div>
                                        </div>
                                    </div>
                                    <div class="doc-viewer-toolbar">
                                        <button type="button" class="btn-viewer-tool btn-viewer-scan" id="btnScanDocViewer" title="Scan document using AI OCR and auto-fill form fields">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><circle cx="12" cy="12" r="3"/><line x1="12" y1="9" x2="12" y2="3"/></svg>
                                            <span style="font-weight: 700;">Scan &amp; Auto-Fill</span>
                                        </button>
                                        <button type="button" class="btn-viewer-tool" id="btnDocZoomIn" title="Zoom In">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line><line x1="11" y1="8" x2="11" y2="14"></line><line x1="8" y1="11" x2="14" y2="11"></line></svg>
                                            <span>Zoom In</span>
                                        </button>
                                        <button type="button" class="btn-viewer-tool" id="btnDocZoomOut" title="Zoom Out">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line><line x1="8" y1="11" x2="14" y2="11"></line></svg>
                                            <span>Zoom Out</span>
                                        </button>
                                        <button type="button" class="btn-viewer-tool" id="btnDocRotate" title="Rotate 90 degrees">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                                            <span>Rotate</span>
                                        </button>
                                        <button type="button" class="btn-viewer-tool" id="btnDocReset" title="Reset View">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                            <span>Reset</span>
                                        </button>
                                    </div>
                                </div>

                                <div class="doc-viewer-viewport" id="docViewerViewport">
                                    <!-- Empty State -->
                                    <div class="doc-viewer-empty" id="docViewerEmpty">
                                        <div class="doc-empty-icon">
                                            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                                <polyline points="14 2 14 8 20 8"></polyline>
                                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                                <line x1="16" y1="17" x2="8" y2="17"></line>
                                                <polyline points="10 9 9 9 8 9"></polyline>
                                            </svg>
                                        </div>
                                        <h4>Scanned Form Viewer</h4>
                                        <p>Upload a scanned handwritten KP Form No. 7 on the right panel to read, transcribe, or auto-fill dispute fields.</p>
                                        <div style="display: flex; gap: 8px; justify-content: center; margin-top: 10px; flex-wrap: wrap;">
                                            <button type="button" class="btn-outline-sm" id="btnTriggerUploadFromViewer">
                                                Select Document
                                            </button>
                                            <button type="button" class="btn-outline-sm btn-viewer-scan" id="btnTriggerScanFromViewer" style="color: #ffffff;">
                                                ⚡ Auto-Scan &amp; Fill Form
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Laser Scanner Beam & Status Overlay -->
                                    <div class="doc-scanner-beam" id="docScannerBeam" style="display: none;"></div>
                                    <div class="doc-scanner-status" id="docScannerStatus" style="display: none;">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="animation: spin 1s linear infinite;"><line x1="12" y1="2" x2="12" y2="6"></line><line x1="12" y1="18" x2="12" y2="22"></line><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"></line><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"></line><line x1="2" y1="12" x2="6" y2="12"></line><line x1="18" y1="12" x2="22" y2="12"></line><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"></line><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"></line></svg>
                                        <span id="docScannerStatusLabel">AI OCR Scanner analyzing document &amp; reading fields...</span>
                                    </div>

                                    <!-- Floating Quick-Scan Bar when document is loaded -->
                                    <div class="doc-floating-scan-bar" id="docFloatingScanBar" style="display: none;">
                                        <span class="floating-scan-text">⚡ Document loaded</span>
                                        <button type="button" class="btn-floating-scan" id="btnFloatingScanDoc">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/></svg>
                                            Auto-Fill Fields
                                        </button>
                                    </div>

                                    <!-- PDF Viewer Container -->
                                    <iframe id="docViewerPdf" style="display: none; width: 100%; height: 100%; min-height: 560px; border: none; background: #525659;"></iframe>

                                    <!-- Image Viewer Container -->
                                    <div id="docViewerImgWrapper" style="display: none; width: 100%; height: 100%; min-height: 560px; overflow: auto; align-items: center; justify-content: center; background: #1e293b; padding: 20px;">
                                        <img id="docViewerImg" alt="KP Form 7 Scan" style="max-width: 100%; box-shadow: 0 10px 30px rgba(0,0,0,0.5); border-radius: 4px; transition: transform 0.15s ease-out; transform-origin: center center;" />
                                    </div>
                                </div>

                                <div class="doc-viewer-footer">
                                    <span id="docViewerFileName" style="font-size: 0.76rem; color: #94a3b8; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 250px;">Waiting for document...</span>
                                    <span id="docViewerZoomDisplay" style="font-size: 0.76rem; color: #cbd5e1; font-weight: 700;">100%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- LONG-FORM SECTIONS STACK / FAST INTAKE FORM -->
                    <div class="intake-sections-container" id="intakeSectionsContainer">

                        <div class="fast-intake-badge">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                            <span>Dispute Intake &amp; Paanyaya Scheduling</span>
                        </div>

                        <!-- SECTION 1: FILING METHOD & CASE CLASSIFICATION -->
                        <section id="section-ingestion" class="intake-section-card">
                            <div class="intake-section-header">
                                <div class="section-header-left">
                                    <span class="section-badge-num">1</span>
                                    <div class="section-title-wrap">
                                        <h3>Filing Method &amp; Case Classification</h3>
                                        <p>Select intake channel and specify dispute categorization</p>
                                    </div>
                                </div>
                                <span class="section-step-indicator">Section 1 of 5</span>
                            </div>

                            <!-- Filing Method Dynamic Selector -->
                            <div style="margin-bottom: 20px;">
                                <label style="display:block; font-size:0.75rem; font-weight:800; color:#475569; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:10px;">
                                    How is this complaint being received? <span class="required-mark">*</span>
                                </label>
                                <div class="filing-method-grid">
                                    <label id="labelMethodDigital" class="filing-method-card is-selected">
                                        <input type="radio" name="filing_method" value="digital" checked>
                                        <div>
                                            <span class="filing-method-title">Digital Filing</span>
                                            <span class="filing-method-desc">Encode complaint narrative and dispute details directly into the digital record.</span>
                                        </div>
                                    </label>

                                    <label id="labelMethodPaper" class="filing-method-card">
                                        <input type="radio" name="filing_method" value="paper">
                                        <div>
                                            <span class="filing-method-title">Upload Pre-Signed Paper Form</span>
                                            <span class="filing-method-desc">Upload scanned handwritten KP Form No. 7 signed directly by the complainant.</span>
                                        </div>
                                    </label>
                                </div>

                                <!-- Hardcopy KP Form 7 Upload Section (Collapsible) -->
                                <div id="paperUploadBox" style="display: none; margin-top: 14px;">
                                    <div class="intake-card kp7-upload-card" id="kp7UploadSection" style="margin: 0; background: #f8fafc;">
                                        <div class="intake-card-header">
                                            <div style="display: flex; align-items: center; justify-content: space-between; width: 100%; flex-wrap: wrap; gap: 8px;">
                                                <div>
                                                    <h3 style="margin: 0; display: flex; align-items: center; gap: 8px; font-size: 0.98rem;">
                                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: #0284c7;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                                                        Formal Complaint Document (KP Form 7)
                                                    </h3>
                                                    <span class="card-subtitle">Scanned PDF or high-resolution photo up to 25 MB</span>
                                                </div>
                                                <div>
                                                    <span class="badge-doc-status badge-doc-no-scan" id="kp7DocStatusBadge">No Scan Uploaded</span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Upload Dropzone -->
                                        <div class="kp7-upload-dropzone" id="kp7DropZone">
                                            <input type="file" id="scannedKp7Input" name="scanned_kp7" accept=".pdf,image/jpeg,image/png" style="display: none;">
                                            <div class="kp7-dropzone-icon">
                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                                    <polyline points="17 8 12 3 7 8"/>
                                                    <line x1="12" y1="3" x2="12" y2="15"/>
                                                </svg>
                                            </div>
                                            <div class="kp7-dropzone-title">Upload Handwritten KP Form 7</div>
                                            <div class="kp7-dropzone-hint">Drag &amp; drop your scanned document or photo here, or browse from computer</div>
                                            <button type="button" class="btn-outline-sm" id="btnBrowseKp7">Browse Document</button>
                                            <div style="font-size: 0.74rem; color: #64748b; margin-top: 8px;">Accepted formats: PDF, JPG, JPEG, PNG (up to 25 MB)</div>
                                        </div>

                                        <!-- Selected File Preview Container (hidden by default) -->
                                        <div id="kp7SelectedPreview" class="kp7-selected-preview" style="display: none;">
                                            <div class="kp7-preview-left">
                                                <div id="kp7ThumbWrap"></div>
                                                <div class="kp7-preview-info">
                                                    <div class="kp7-preview-name" id="kp7FileName">filename.pdf</div>
                                                    <div class="kp7-preview-meta">
                                                        <span class="badge-pill" id="kp7FileTypeBadge">PDF</span>
                                                        <span id="kp7FileSize">0 MB</span>
                                                        <span style="color: #16a34a; font-weight: 600;">Ready to attach</span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                                                <button type="button" class="btn-outline-sm" id="btnPreviewKp7Modal" title="Preview document before saving">Preview</button>
                                                <button type="button" class="btn-outline-sm" id="btnReplaceKp7" title="Select a different file">Change</button>
                                                <button type="button" class="btn-outline-sm" id="btnRemoveKp7" style="color: #dc2626; border-color: #fca5a5;" title="Remove this file">&times; Remove</button>
                                            </div>
                                        </div>

                                        <div id="kp7UploadAlert" class="alert alert-danger" style="display: none; margin-top: 10px; font-size: 0.85rem; padding: 8px 12px;"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Pre-Docketing Phase (Paanyaya Invitation) -->
                            <div class="paanyaya-intake-card" style="margin-bottom: 24px; background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 10px; padding: 18px 20px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 10px;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div>
                                            <h4 style="margin: 0; font-size: 1.02rem; color: #166534; font-weight: 800; letter-spacing: -0.01em;">
                                                Pre-Docketing Phase: Paanyaya (Informal Invitation)
                                            </h4>
                                            <span style="font-size: 0.82rem; color: #15803d; line-height: 1.4; display: block; margin-top: 2px;">
                                                Under LTIA &amp; Barangay Tumana KP rules, disputes may start with an informal dialogue. Paanyaya tracks only the invitee name, date, and time.
                                            </span>
                                        </div>
                                    </div>
                                    <span class="badge" style="background: #bbf7d0; color: #14532d; font-weight: 700; padding: 5px 10px; border-radius: 6px; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em;">
                                        Stage 0: Pre-Docketing
                                    </span>
                                </div>

                                <div class="form-group" style="margin-bottom: 12px;">
                                    <label for="paanyayaInvitee" style="color: #166534; font-weight: 700; font-size: 0.85rem;">Invitee Name (Person Being Invited)</label>
                                    <input type="text" id="paanyayaInvitee" name="paanyaya_invitee_name" placeholder="Leave blank to use respondent's name" style="border-color: #86efac; width: 100%; padding: 8px 12px; border-radius: 6px; border: 1px solid #86efac; background: #ffffff; font-size: 0.88rem;">
                                </div>

                                <div class="form-row-2">
                                    <div class="form-group">
                                        <label for="paanyayaDate" style="color: #166534; font-weight: 700; font-size: 0.85rem;">Invitation Date</label>
                                        <input type="date" id="paanyayaDate" name="paanyaya_date" value="<?php echo date('Y-m-d', strtotime('+3 weekdays')); ?>" style="border-color: #86efac;">
                                    </div>
                                    <div class="form-group">
                                        <label for="paanyayaTime" style="color: #166534; font-weight: 700; font-size: 0.85rem;">Invitation Time</label>
                                        <input type="time" id="paanyayaTime" name="paanyaya_time" value="14:00" style="border-color: #86efac;">
                                    </div>
                                </div>

                                <div class="form-group" style="margin-top: 10px;">
                                    <label for="paanyayaVenue" style="color: #166534; font-weight: 700; font-size: 0.85rem;">Dialogue Venue</label>
                                    <input type="text" id="paanyayaVenue" name="paanyaya_venue" value="Tanggapan ng Barangay Tumana" style="border-color: #86efac;">
                                </div>
                            </div>

                            <!-- Dispute Classification Grid -->
                            <div class="form-row-2" style="margin-top: 10px;">
                                <div class="form-group">
                                    <label for="categoryId">Complaint Category <span class="required-mark">*</span></label>
                                    <select id="categoryId" name="category_id" required>
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo (int)$cat['category_id']; ?>" <?php echo ((string)($old['category_id'] ?? '') === (string)$cat['category_id']) ? 'selected' : ''; ?>>
                                                 <?php echo htmlspecialchars($cat['category_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="caseType">Case Type <span class="required-mark">*</span></label>
                                    <select id="caseType" name="case_type" required>
                                        <option value="Civil" <?php echo (($old['case_type'] ?? 'Civil') === 'Civil') ? 'selected' : ''; ?>>Civil</option>
                                        <option value="Criminal" <?php echo (($old['case_type'] ?? '') === 'Criminal') ? 'selected' : ''; ?>>Criminal</option>
                                    </select>
                                </div>
                            </div>

                            <!-- "Others Please Specify" Input (Dynamically shown when Others is selected) -->
                            <div class="form-group" id="otherCategoryWrapper" style="display: none; margin-top: 8px; margin-bottom: 12px;">
                                <label for="otherCategorySpecification" style="font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 6px;">
                                    <span>Specify Complaint Category / Pakitukoy ang Kategorya</span>
                                    <span class="required-mark">*</span>
                                </label>
                                <input type="text" id="otherCategorySpecification" name="other_category_specification" maxlength="255" placeholder="Pakitukoy ang partikular na alitan o kategorya (e.g. Alitan sa bintana, ingay ng aircon)..." value="<?php echo htmlspecialchars($old['other_category_specification'] ?? ''); ?>" style="border: 1.5px solid #3b82f6; background: #f8fafc; border-radius: 6px; padding: 9px 12px; font-size: 0.9rem;">
                                <small style="color: #64748b; font-size: 0.8rem; display: block; margin-top: 4px;">Specify the dispute details covered under Katarungang Pambarangay jurisdiction.</small>
                            </div>

                            <div class="form-row-2">
                                <div class="form-group">
                                    <label for="complaintTitle">Complaint Title / Specific Nature <span class="required-mark">*</span></label>
                                    <input type="text" id="complaintTitle" name="complaint_title" maxlength="255" required placeholder="e.g. Boundary dispute, Unpaid debt, Slander" value="<?php echo htmlspecialchars($old['complaint_title'] ?? ''); ?>">
                                </div>

                                <div class="form-group">
                                    <?php
                                    $isTimeUnknownOld = !empty($old['is_time_unknown']);
                                    $defaultDt = $old['incident_datetime'] ?? '';
                                    if (!$defaultDt && !empty($old['incident_date'])) {
                                        $defaultDt = $old['incident_date'] . ((!$isTimeUnknownOld && !empty($old['incident_time'])) ? 'T' . substr($old['incident_time'], 0, 5) : '');
                                    }
                                    if ($isTimeUnknownOld && str_contains($defaultDt, 'T')) {
                                        $defaultDt = explode('T', $defaultDt)[0];
                                    }
                                    ?>
                                    <label for="incidentDateTime" id="lblIncidentDateTime">
                                        <?php echo $isTimeUnknownOld ? 'Incident Date (Exact Time Unknown)' : 'Incident Date &amp; Time'; ?> <span class="required-mark">*</span>
                                    </label>
                                    <input type="<?php echo $isTimeUnknownOld ? 'date' : 'datetime-local'; ?>" id="incidentDateTime" name="incident_datetime" required value="<?php echo htmlspecialchars($defaultDt); ?>">
                                    <div class="time-options-row">
                                        <label class="time-option-label">
                                            <input type="checkbox" name="is_time_approximate" value="1" id="isTimeApproximate" <?php echo !empty($old['is_time_approximate']) ? 'checked' : ''; ?> <?php echo $isTimeUnknownOld ? 'disabled' : ''; ?>>
                                            <span>Approximate time</span>
                                        </label>
                                        <label class="time-option-label">
                                            <input type="checkbox" name="is_time_unknown" value="1" id="isTimeUnknown" <?php echo $isTimeUnknownOld ? 'checked' : ''; ?>>
                                            <span>Exact time unknown</span>
                                        </label>
                                    </div>
                                    <span id="futureDateNotice" class="future-date-hint" style="display:none;">Notice: Selected incident date is in the future. Please verify before filing.</span>
                                </div>
                            </div>

                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="blotterRefNo">Barangay Blotter Ref No. <span class="optional-label">Optional</span></label>
                                <input type="text" id="blotterRefNo" name="blotter_ref_no" placeholder="e.g. BLOTTER-2026-1089" value="<?php echo htmlspecialchars($old['blotter_ref_no'] ?? ''); ?>">
                                <small class="field-hint">Record internal or physical blotter reference number if applicable.</small>
                            </div>

                            <input type="hidden" name="jurisdiction_screened" id="jurisdictionScreened" value="1">
                            <input type="hidden" name="has_private_offended_party" value="1">
                        </section>


                        <!-- SECTION 2: INVOLVED PARTIES (MODAL-DRIVEN WITH MULTIPLE TOGGLES) -->
                        <section id="section-parties" class="intake-section-card">
                            <div class="intake-section-header">
                                <div class="section-header-left">
                                    <span class="section-badge-num">2</span>
                                    <div class="section-title-wrap">
                                        <h3>Involved Parties</h3>
                                        <p>Manage dispute participants cleanly without cluttering the screen.</p>
                                    </div>
                                </div>
                                <span class="section-step-indicator">Section 2 of 5</span>
                            </div>

                            <!-- Real-time Duplicate Warning Banner -->
                            <div id="duplicateComplaintBanner" class="duplicate-warning-banner" style="display:none;">
                                <div class="duplicate-warning-header">
                                    <span>Potential Duplicate Complaint Detected</span>
                                    <button type="button" class="btn-clear-evidence" id="dismissDuplicateBanner" style="background:none;border:none;cursor:pointer;font-size:1.1rem;color:#9a3412;">&times;</button>
                                </div>
                                <p style="margin:0;font-size:0.83rem;">The system found active complaint record(s) involving the same complainant and respondent:</p>
                                <ul class="duplicate-warning-list" id="duplicateComplaintList"></ul>
                                <label style="display:flex;align-items:flex-start;gap:8px;margin-top:10px;font-size:0.8rem;color:#7c2d12;font-weight:600;">
                                    <input type="checkbox" name="duplicate_acknowledged" id="duplicateAcknowledged" value="1" style="margin-top:2px;">
                                    <span>I reviewed the possible duplicate record(s) and confirm that this filing should proceed.</span>
                                </label>
                            </div>

                            <div id="partyDistinctError" class="alert alert-danger" style="display:none; margin: 0 0 16px; padding: 10px 14px; font-size: 0.88rem; border-radius: 6px; background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5;">
                                The complainant and respondent cannot be the same profile. Please select or enter different individuals.
                            </div>

                            <!-- Checkbox options if there are multiple complainants or respondents -->
                            <div class="party-multiple-toggles">
                                <label class="time-option-label" style="font-weight:600; font-size:0.85rem; color:#1e293b; display:inline-flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" id="chkMultipleComplainants" name="has_multiple_complainants" value="1">
                                    <span>Multiple Complainants (Maraming Nagrereklamo)</span>
                                </label>
                                <label class="time-option-label" style="font-weight:600; font-size:0.85rem; color:#1e293b; display:inline-flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" id="chkMultipleRespondents" name="has_multiple_respondents" value="1">
                                    <span>Multiple Respondents (Maraming Ipinagrereklamo)</span>
                                </label>
                            </div>

                            <div class="parties-fast-grid">
                                <!-- COMPLAINANTS SUB-SECTION -->
                                <div class="party-group-section" id="complainantsGroupSection">
                                    <div class="party-group-header">
                                        <div class="party-group-title-wrap">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                            <h4 class="party-group-title">Complainants (Nagrereklamo) <span class="required-mark">*</span></h4>
                                        </div>
                                        <button type="button" class="btn-create btn-sm" id="btnAddComplainantModal" style="display:inline-flex; align-items:center; gap:6px; font-size:0.8rem; padding:6px 14px;">
                                            + Add Complainant
                                        </button>
                                    </div>
                                    <div id="complainantsSummaryList" class="party-summary-list"></div>
                                </div>

                                <!-- RESPONDENTS SUB-SECTION -->
                                <div class="party-group-section" id="respondentsGroupSection" style="padding-top:16px; border-top:1px solid #e2e8f0;">
                                    <div class="party-group-header">
                                        <div class="party-group-title-wrap">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#e11d48" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="17" y1="8" x2="23" y2="14"/><line x1="23" y1="8" x2="17" y2="14"/></svg>
                                            <h4 class="party-group-title">Respondents (Ipinagrereklamo) <span class="required-mark">*</span></h4>
                                        </div>
                                        <button type="button" class="btn-secondary btn-sm" id="btnAddRespondentModal" style="display:inline-flex; align-items:center; gap:6px; font-size:0.8rem; padding:6px 14px; border-color:#fca5a5; color:#be123c; background:#fff1f2;">
                                            + Add Respondent
                                        </button>
                                    </div>
                                    <div id="respondentsSummaryList" class="party-summary-list"></div>
                                </div>
                            </div>

                            <!-- WITNESSES SUB-SECTION -->
                            <div class="party-group-section" id="witnessesGroupSection" style="padding-top:16px; border-top:1px solid #e2e8f0; margin-bottom:0;">
                                <div class="party-group-header">
                                    <div class="party-group-title-wrap">
                                        <span class="party-badge" style="background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; font-size:0.75rem;">Witness(es) &amp; Additional Parties (Optional)</span>
                                    </div>
                                    <button type="button" id="addPartyBtn" class="btn-outline-sm" style="font-size:0.78rem;">
                                        + Add Witness
                                    </button>
                                </div>
                                <div id="additionalPartiesContainer" class="additional-parties-container"></div>
                            </div>

                            <!-- Hidden Inputs for Primary Complainant -->
                            <input type="hidden" id="complainantResidentId" name="complainant_resident_id" value="<?php echo htmlspecialchars((string)($old['complainant_resident_id'] ?? '')); ?>">
                            <input type="hidden" id="complainantName" name="complainant_name" value="<?php echo htmlspecialchars($old['complainant_name'] ?? ''); ?>">
                            <input type="hidden" id="complainantFirstName" name="complainant_first_name" value="<?php echo htmlspecialchars($old['complainant_first_name'] ?? ''); ?>">
                            <input type="hidden" id="complainantMiddleName" name="complainant_middle_name" value="<?php echo htmlspecialchars($old['complainant_middle_name'] ?? ''); ?>">
                            <input type="hidden" id="complainantLastName" name="complainant_last_name" value="<?php echo htmlspecialchars($old['complainant_last_name'] ?? ''); ?>">
                            <input type="hidden" id="complainantSuffix" name="complainant_suffix" value="<?php echo htmlspecialchars($old['complainant_suffix'] ?? ''); ?>">
                            <input type="hidden" id="complainantBirthdate" name="complainant_birthdate" value="<?php echo htmlspecialchars($old['complainant_birthdate'] ?? ''); ?>">
                            <input type="hidden" id="complainantAge" name="complainant_age" value="<?php echo htmlspecialchars((string)($old['complainant_age'] ?? '')); ?>">
                            <input type="hidden" id="complainantGender" name="complainant_gender" value="<?php echo htmlspecialchars($old['complainant_gender'] ?? ''); ?>">
                            <input type="hidden" id="complainantCivilStatus" name="complainant_civil_status" value="<?php echo htmlspecialchars($old['complainant_civil_status'] ?? ''); ?>">
                            <input type="hidden" id="complainantOccupation" name="complainant_occupation" value="<?php echo htmlspecialchars($old['complainant_occupation'] ?? ''); ?>">
                            <input type="hidden" id="complainantContactNo" name="complainant_contact_no" value="<?php echo htmlspecialchars($old['complainant_contact_no'] ?? ''); ?>">
                            <input type="hidden" id="complainantEmail" name="complainant_email" value="<?php echo htmlspecialchars($old['complainant_email'] ?? ''); ?>">
                            <input type="hidden" id="complainantPreferredContact" name="complainant_preferred_contact" value="<?php echo htmlspecialchars($old['complainant_preferred_contact'] ?? 'SMS'); ?>">
                            <input type="hidden" id="complainantPurok" name="complainant_purok" value="<?php echo htmlspecialchars($old['complainant_purok'] ?? ''); ?>">
                            <input type="hidden" id="complainantIsTenant" name="complainant_is_tenant" value="<?php echo htmlspecialchars((string)($old['complainant_is_tenant'] ?? '0')); ?>">
                            <input type="hidden" id="complainantAddress" name="complainant_address" value="<?php echo htmlspecialchars($old['complainant_address'] ?? ''); ?>">
                            <input type="hidden" id="complainantLatitude" name="complainant_latitude" value="<?php echo htmlspecialchars((string)($old['complainant_latitude'] ?? '')); ?>">
                            <input type="hidden" id="complainantLongitude" name="complainant_longitude" value="<?php echo htmlspecialchars((string)($old['complainant_longitude'] ?? '')); ?>">
                            <input type="hidden" id="complainantIsMinor" name="complainant_is_minor" value="<?php echo !empty($old['complainant_is_minor']) ? '1' : '0'; ?>">
                            <input type="hidden" id="complainantGuardianName" name="complainant_guardian_name" value="<?php echo htmlspecialchars($old['complainant_guardian_name'] ?? ''); ?>">
                            <input type="hidden" id="complainantGuardianRelationship" name="complainant_guardian_relationship" value="<?php echo htmlspecialchars($old['complainant_guardian_relationship'] ?? ''); ?>">
                            <input type="hidden" id="complainantGuardianContact" name="complainant_guardian_contact" value="<?php echo htmlspecialchars($old['complainant_guardian_contact'] ?? ''); ?>">
                            <input type="hidden" id="complainantGuardianAddress" name="complainant_guardian_address" value="<?php echo htmlspecialchars($old['complainant_guardian_address'] ?? ''); ?>">

                            <!-- Hidden Inputs for Primary Respondent -->
                            <input type="hidden" id="respondentResidentId" name="respondent_resident_id" value="<?php echo htmlspecialchars((string)($old['respondent_resident_id'] ?? '')); ?>">
                            <input type="hidden" id="respondentName" name="respondent_name" value="<?php echo htmlspecialchars($old['respondent_name'] ?? ''); ?>">
                            <input type="hidden" id="respondentFirstName" name="respondent_first_name" value="<?php echo htmlspecialchars($old['respondent_first_name'] ?? ''); ?>">
                            <input type="hidden" id="respondentMiddleName" name="respondent_middle_name" value="<?php echo htmlspecialchars($old['respondent_middle_name'] ?? ''); ?>">
                            <input type="hidden" id="respondentLastName" name="respondent_last_name" value="<?php echo htmlspecialchars($old['respondent_last_name'] ?? ''); ?>">
                            <input type="hidden" id="respondentSuffix" name="respondent_suffix" value="<?php echo htmlspecialchars($old['respondent_suffix'] ?? ''); ?>">
                            <input type="hidden" id="respondentBirthdate" name="respondent_birthdate" value="<?php echo htmlspecialchars($old['respondent_birthdate'] ?? ''); ?>">
                            <input type="hidden" id="respondentAge" name="respondent_age" value="<?php echo htmlspecialchars((string)($old['respondent_age'] ?? '')); ?>">
                            <input type="hidden" id="respondentGender" name="respondent_gender" value="<?php echo htmlspecialchars($old['respondent_gender'] ?? ''); ?>">
                            <input type="hidden" id="respondentCivilStatus" name="respondent_civil_status" value="<?php echo htmlspecialchars($old['respondent_civil_status'] ?? ''); ?>">
                            <input type="hidden" id="respondentOccupation" name="respondent_occupation" value="<?php echo htmlspecialchars($old['respondent_occupation'] ?? ''); ?>">
                            <input type="hidden" id="respondentContactNo" name="respondent_contact_no" value="<?php echo htmlspecialchars($old['respondent_contact_no'] ?? ''); ?>">
                            <input type="hidden" id="respondentEmail" name="respondent_email" value="<?php echo htmlspecialchars($old['respondent_email'] ?? ''); ?>">
                            <input type="hidden" id="respondentPurok" name="respondent_purok" value="<?php echo htmlspecialchars($old['respondent_purok'] ?? ''); ?>">
                            <input type="hidden" id="respondentIsTenant" name="respondent_is_tenant" value="<?php echo htmlspecialchars((string)($old['respondent_is_tenant'] ?? '0')); ?>">
                            <input type="hidden" id="respondentAddress" name="respondent_address" value="<?php echo htmlspecialchars($old['respondent_address'] ?? ''); ?>">
                            <input type="hidden" id="respondentLatitude" name="respondent_latitude" value="<?php echo htmlspecialchars((string)($old['respondent_latitude'] ?? '')); ?>">
                            <input type="hidden" id="respondentLongitude" name="respondent_longitude" value="<?php echo htmlspecialchars((string)($old['respondent_longitude'] ?? '')); ?>">
                            <input type="hidden" id="respondentIsMinor" name="respondent_is_minor" value="<?php echo !empty($old['respondent_is_minor']) ? '1' : '0'; ?>">
                            <input type="hidden" id="respondentGuardianName" name="respondent_guardian_name" value="<?php echo htmlspecialchars($old['respondent_guardian_name'] ?? ''); ?>">
                            <input type="hidden" id="respondentGuardianRelationship" name="respondent_guardian_relationship" value="<?php echo htmlspecialchars($old['respondent_guardian_relationship'] ?? ''); ?>">
                            <input type="hidden" id="respondentGuardianContact" name="respondent_guardian_contact" value="<?php echo htmlspecialchars($old['respondent_guardian_contact'] ?? ''); ?>">
                            <input type="hidden" id="respondentGuardianAddress" name="respondent_guardian_address" value="<?php echo htmlspecialchars($old['respondent_guardian_address'] ?? ''); ?>">
                            <input type="hidden" id="respContactUnknown" name="respondent_contact_unknown" value="<?php echo !empty($old['respondent_contact_unknown']) ? '1' : '0'; ?>">
                            <input type="hidden" id="respMiddleUnknown" name="respondent_middle_name_unknown" value="<?php echo !empty($old['respondent_middle_name_unknown']) ? '1' : '0'; ?>">
                            <input type="hidden" id="respBirthUnknown" name="respondent_birthdate_unknown" value="<?php echo !empty($old['respondent_birthdate_unknown']) ? '1' : '0'; ?>">

                            <!-- Container for dynamic additional parties hidden inputs -->
                            <div id="additionalPartiesHiddenContainer"></div>
                        </section>

<!-- SECTION 3: STATEMENT OF FACTS & RELIEF SOUGHT -->
                        <section id="section-narrative" class="intake-section-card">
                            <div class="intake-section-header">
                                <div class="section-header-left">
                                    <span class="section-badge-num">3</span>
                                    <div class="section-title-wrap">
                                        <h3>Statement of Facts &amp; Relief Sought</h3>
                                        <p>Provide narrative according to Katarungang Pambarangay guidelines.</p>
                                    </div>
                                </div>
                                <span class="section-step-indicator">Section 3 of 5</span>
                            </div>

                            <div class="form-group">
                                <label for="narrative">Complaint Narrative <span class="required-mark" id="narrativeRequiredStar">*</span></label>
                                <textarea id="narrative" name="narrative" rows="5" maxlength="15000" required placeholder="Describe in detail what occurred, when, and the circumstances surrounding the incident..."><?php echo htmlspecialchars($old['narrative'] ?? ''); ?></textarea>
                                <div class="ai-narrative-actions">
                                    <div class="ai-narrative-tools">
                                        <button type="button" class="btn-outline-sm" data-enhance-narrative>Enhance with AI</button>
                                        <small class="field-hint">Keep statements chronological, clear, and focused on key incident details.</small>
                                    </div>
                                    <label class="ai-consent-checkbox-label">
                                        <input type="checkbox" data-ai-external-consent>
                                        <span>I understand this narrative will be sent to Google Gemini for AI processing.</span>
                                    </label>
                                </div>
                                <div class="ai-narrative-preview" data-narrative-preview hidden>
                                    <strong>AI suggestion</strong>
                                    <p data-narrative-suggestion></p>
                                    <div class="ai-narrative-preview-actions">
                                        <button type="button" class="btn-create btn-sm" data-apply-narrative>Apply suggestion</button>
                                        <button type="button" class="btn-secondary btn-sm" data-discard-narrative>Keep my original</button>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group" id="prayerForReliefGroup" style="margin-bottom:0;">
                                <label for="requestedResolution">Relief / Settlement Desired by Complainant <span class="optional-label">Optional</span></label>
                                <textarea id="requestedResolution" name="requested_resolution" rows="3" maxlength="5000" placeholder="Specify desired resolution (e.g., payment of debt, boundary wall restoration, public apology, cessation of nuisance)..."><?php echo htmlspecialchars($old['requested_resolution'] ?? ''); ?></textarea>
                            </div>
                        </section>


                        <!-- SECTION 4: INCIDENT LOCATION & INTEGRATED MAP -->
                        <section id="section-location" class="intake-section-card">
                            <div class="intake-section-header">
                                <div class="section-header-left">
                                    <span class="section-badge-num">4</span>
                                    <div class="section-title-wrap">
                                        <h3>Incident Location &amp; Map Pin</h3>
                                        <p>Specify incident address and set precise geographic location coordinates.</p>
                                    </div>
                                </div>
                                <span class="section-step-indicator">Section 4 of 5</span>
                            </div>

                            <div class="location-split-layout">
                                <!-- Left Column: Address Fields -->
                                <div>
                                    <div class="form-group">
                                        <label for="incidentStreet">Street Address / Alley / Landmark <span class="required-mark">*</span></label>
                                        <input type="text" id="incidentStreet" name="incident_street" maxlength="255" required placeholder="House #, Street, building, or nearby place" value="<?php echo htmlspecialchars($old['incident_street'] ?? $old['incident_location'] ?? ''); ?>">
                                    </div>

                                    <div class="detail-info-grid">
                                        <div class="form-group">
                                            <label for="incidentPurok">Sitio / Zone / Purok (Tumana)</label>
                                            <?php
                                            $currPurok = $old['incident_purok'] ?? '';
                                            $knownPuroks = [
                                                'Outside Tumana',
                                                'Non-Resident',
                                                'Purok 1', 'Purok 2', 'Purok 3', 'Purok 4', 'Purok 5', 'Purok 6', 'Purok 7', 'Purok 8',
                                                'Doña Petra', 'Bagong Farmers', 'Bukang Liwayway', 'Libis Tumana', 'Bagong Purok', 'Palay', 'Mais', 'Singkamas'
                                            ];
                                            $isCustomPurok = !empty($currPurok) && !in_array($currPurok, $knownPuroks, true);
                                            ?>
                                            <select name="incident_purok" id="incidentPurok">
                                                <option value="">Select Purok / Area</option>
                                                <option value="Outside Tumana" id="purokOutsideTumanaOption" <?php echo ($currPurok === 'Outside Tumana' || $currPurok === 'Non-Resident') ? 'selected' : ''; ?>>Outside Tumana</option>
                                                <optgroup label="Numbered Puroks">
                                                    <option value="Purok 1" <?php echo ($currPurok === 'Purok 1') ? 'selected' : ''; ?>>Purok 1</option>
                                                    <option value="Purok 2" <?php echo ($currPurok === 'Purok 2') ? 'selected' : ''; ?>>Purok 2</option>
                                                    <option value="Purok 3" <?php echo ($currPurok === 'Purok 3') ? 'selected' : ''; ?>>Purok 3</option>
                                                    <option value="Purok 4" <?php echo ($currPurok === 'Purok 4') ? 'selected' : ''; ?>>Purok 4</option>
                                                    <option value="Purok 5" <?php echo ($currPurok === 'Purok 5') ? 'selected' : ''; ?>>Purok 5</option>
                                                    <option value="Purok 6" <?php echo ($currPurok === 'Purok 6') ? 'selected' : ''; ?>>Purok 6</option>
                                                    <option value="Purok 7" <?php echo ($currPurok === 'Purok 7') ? 'selected' : ''; ?>>Purok 7</option>
                                                    <option value="Purok 8" <?php echo ($currPurok === 'Purok 8') ? 'selected' : ''; ?>>Purok 8</option>
                                                </optgroup>
                                                <optgroup label="Zones & Compounds">
                                                    <option value="Doña Petra" <?php echo ($currPurok === 'Doña Petra') ? 'selected' : ''; ?>>Doña Petra Compound</option>
                                                    <option value="Bagong Farmers" <?php echo ($currPurok === 'Bagong Farmers') ? 'selected' : ''; ?>>Bagong Farmers</option>
                                                    <option value="Bukang Liwayway" <?php echo ($currPurok === 'Bukang Liwayway') ? 'selected' : ''; ?>>Bukang Liwayway</option>
                                                    <option value="Libis Tumana" <?php echo ($currPurok === 'Libis Tumana') ? 'selected' : ''; ?>>Libis Tumana</option>
                                                    <option value="Bagong Purok" <?php echo ($currPurok === 'Bagong Purok') ? 'selected' : ''; ?>>Sitio Bagong Purok</option>
                                                    <option value="Palay" <?php echo ($currPurok === 'Palay') ? 'selected' : ''; ?>>Palay Area</option>
                                                    <option value="Mais" <?php echo ($currPurok === 'Mais') ? 'selected' : ''; ?>>Mais Area</option>
                                                    <option value="Singkamas" <?php echo ($currPurok === 'Singkamas') ? 'selected' : ''; ?>>Singkamas Area</option>
                                                </optgroup>
                                                <?php if ($isCustomPurok): ?>
                                                    <option value="<?php echo htmlspecialchars($currPurok); ?>" selected><?php echo htmlspecialchars($currPurok); ?> (Existing)</option>
                                                <?php endif; ?>
                                            </select>
                                        </div>

                                        <div class="form-group">
                                            <label for="incidentBarangay">Barangay</label>
                                            <input type="text" id="incidentBarangay" name="incident_barangay" value="Tumana" readonly style="background:#f1f5f9; color:#475569; cursor:not-allowed;">
                                        </div>
                                    </div>

                                    <div class="detail-info-grid">
                                        <div class="form-group">
                                            <label for="incidentCity">City / Municipality</label>
                                            <input type="text" id="incidentCity" name="incident_city" value="Marikina City" readonly style="background:#f1f5f9; color:#475569; cursor:not-allowed;">
                                        </div>
                                        <div class="form-group">
                                            <label for="incidentProvince">Province / Region</label>
                                            <input type="text" id="incidentProvince" value="Metro Manila" readonly style="background:#f1f5f9; color:#475569; cursor:not-allowed;">
                                        </div>
                                    </div>


                                    <div class="form-group" style="margin-bottom:0;">
                                        <label for="incidentLocationDetails">Specific Location / Landmark Details <span class="optional-label">Optional</span></label>
                                        <textarea id="incidentLocationDetails" name="incident_location_details" rows="2" maxlength="1000" placeholder="Floor, unit number, corner, access directions, or additional boundary notes..."><?php echo htmlspecialchars($old['incident_location_details'] ?? ''); ?></textarea>
                                    </div>
                                </div>

                                <!-- Right Column: Map Pinpoint Box -->
                                <div>
                                    <button type="button" class="btn-toggle-paper-map" id="btnTogglePaperMap">Pin Precise Map Location (Optional)</button>
                                    <div class="location-map-pinbox" id="locationMapPinbox">
                                        <div class="map-pinbox-topbar">
                                            <span>Location Coordinates: <strong id="mapCoordsDisplay">14.6521° N, 121.0854° E</strong></span>
                                            <button type="button" class="btn-recenter-map" id="btnRecenterMap" title="Center map on Barangay Tumana">Recenter</button>
                                        </div>

                                        <input type="hidden" name="map_location_state" value="<?php echo htmlspecialchars($old['map_location_state'] ?? 'none'); ?>" data-map-state>
                                        <input type="hidden" name="location_latitude" value="<?php echo htmlspecialchars($old['location_latitude'] ?? ''); ?>" data-map-latitude>
                                        <input type="hidden" name="location_longitude" value="<?php echo htmlspecialchars($old['location_longitude'] ?? ''); ?>" data-map-longitude>

                                        <div id="addComplaintMap" class="complaint-location-map" style="height: 320px; border: none; border-radius: 0;" aria-label="Map for selecting the exact incident location"></div>

                                        <div style="padding: 10px 14px; background: #ffffff; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                            <span class="map-selection-status" data-map-status style="font-size:0.8rem; color:#64748b;">No map point selected.</span>
                                            <button type="button" class="btn-secondary btn-sm" data-clear-map style="padding: 4px 10px; font-size: 0.76rem;">Clear pin</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>


                        <!-- SECTION 5: EVIDENCE & ATTACHMENTS -->
                        <section id="section-evidence" class="intake-section-card">
                            <div class="intake-section-header">
                                <div class="section-header-left">
                                    <span class="section-badge-num">5</span>
                                    <div class="section-title-wrap">
                                        <h3>Evidence &amp; Attachments</h3>
                                        <p>Upload documents, blotter copies, or photo evidence with classification tags.</p>
                                    </div>
                                </div>
                                <span class="section-step-indicator">Section 5 of 5</span>
                            </div>

                            <!-- Dropzone -->
                            <div class="evidence-upload-zone" id="evidenceDropZone">
                                <input type="file" id="evidenceFiles" name="evidence[]" multiple accept="image/*,video/*,.pdf,.doc,.docx" class="evidence-file-input">
                                <div class="upload-zone-body">
                                    <div class="upload-zone-icon">
                                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                            <polyline points="17 8 12 3 7 8"/>
                                            <line x1="12" y1="3" x2="12" y2="15"/>
                                        </svg>
                                    </div>
                                    <div class="upload-zone-text">
                                        <p class="upload-primary-text"><strong>Choose files</strong> or drag &amp; drop here</p>
                                        <p class="upload-secondary-text">Images, videos, PDF, and Word documents up to 25 MB each</p>
                                    </div>
                                    <button type="button" class="btn-browse-files" id="btnBrowseEvidence">Browse Files</button>
                                </div>
                            </div>

                            <div id="evidenceValidationAlert" class="evidence-alert-inline" style="display: none;"></div>

                            <!-- Selected files queue preview with Tag Selection -->
                            <div id="evidenceQueueContainer" class="evidence-queue-container" style="display: none; margin-top: 14px;">
                                <div class="evidence-queue-header">
                                    <span class="evidence-queue-title">Attached Files (<span id="evidenceCount">0</span>)</span>
                                    <button type="button" id="clearAllEvidenceBtn" class="btn-clear-evidence">Clear All</button>
                                </div>
                                <ul id="evidenceQueueList" class="evidence-queue-list"></ul>
                            </div>

                            <!-- Proof OCR Notes -->
                            <div class="form-group" style="margin-top: 18px; margin-bottom: 0;">
                                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:6px;">
                                    <label for="proofOcrNotes" style="margin:0;">
                                        Proof Notes <span class="optional-label">Optional</span>
                                    </label>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <button type="button" class="btn-outline-sm" id="btnProofOcr" title="Scan a handwritten or printed image and extract text into Proof Notes">Scan Proof (OCR)</button>
                                        <input type="file" id="proofOcrFileInput" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;">
                                    </div>
                                </div>
                                <textarea id="proofOcrNotes" name="proof_ocr_notes" rows="4" maxlength="10000"
                                    placeholder="Extracted text from scanned proofs will appear here. You may also type or paste additional context about the attached evidence..."
                                    style="resize:vertical;"><?php echo htmlspecialchars($old['proof_ocr_notes'] ?? ''); ?></textarea>
                                <small class="field-hint">Use OCR on any uploaded image above, or click “Scan Proof” to pick a file directly. Extracted text is appended here.</small>
                            </div>
                        </section>

                        <!-- Action Row directly under Section 5 Evidence & Attachments -->
                        <div class="intake-bottom-actions" style="display: flex; justify-content: flex-end; align-items: center; gap: 12px; margin-top: 24px; padding: 20px 24px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.04);">
                            <a href="complaint-list.php" class="btn-secondary" style="text-decoration: none; padding: 10px 20px;">Cancel</a>
                            <button type="submit" form="createComplaintForm" class="btn-create" id="btnSubmitComplaint" style="display: inline-flex; align-items: center; gap: 8px; font-size: 0.95rem; font-weight: 700; padding: 11px 26px;">
                                Save Complaint &amp; Issue 1st Paanyaya →
                            </button>
                        </div>

                    </div>
                </div>


            </form>
        </div>

        <!-- Modal for KP Form 7 document preview -->
        <div id="kp7DocPreviewModal" class="modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.7); z-index:9999; align-items:center; justify-content:center; padding:20px;">
            <div class="modal-card" style="background:#fff; border-radius:12px; max-width:850px; width:100%; max-height:90vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.3);">
                <div style="padding:14px 20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                    <strong style="font-size:1rem; color:#0f172a;">KP Form 7 Document Preview</strong>
                    <button type="button" id="btnCloseKp7PreviewModal" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
                </div>
                <div id="kp7ModalBody" style="padding:20px; overflow-y:auto; flex:1; text-align:center; min-height:300px; display:flex; align-items:center; justify-content:center;">
                </div>
                <div style="padding:12px 20px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end;">
                    <button type="button" class="btn-secondary btn-sm" id="btnCloseKp7PreviewModalBtn">Close</button>
                </div>
            </div>
        </div>

        <!-- Modal: Digital KP Form 7 Preview & Print -->
        <div id="kp7DigitalPreviewModal" class="modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.75); z-index:99999; align-items:center; justify-content:center; padding:12px;">
            <div class="modal-card" style="background:#ffffff; border-radius:14px; max-width:1350px; width:96vw; height:calc(100vh - 24px); max-height:calc(100vh - 24px); display:flex; flex-direction:column; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35);">
                <!-- Header -->
                <div style="padding:14px 24px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; background:#ffffff; flex-shrink:0;">
                    <div style="display:flex; align-items:center; gap:12px;">
                        
                        <div>
                            <h2 style="font-size:1.15rem; margin:0; color:#0f172a; font-weight:700;">Pormularyo ng KP Blg. 7: Reklamo (KP Form No. 7 Preview)</h2>
                            <p style="font-size:0.78rem; color:#64748b; margin:2px 0 0;">Preview and print the official Katarungang Pambarangay complaint before final filing.</p>
                        </div>
                    </div>
                    <button type="button" id="btnCloseKp7DigitalPreviewModal" style="background:none; border:none; font-size:1.6rem; cursor:pointer; color:#64748b; line-height:1;" aria-label="Close modal">&times;</button>
                </div>

                <!-- Document Viewer Body -->
                <div style="flex:1 1 auto; overflow:hidden; background:#334155; display:flex; min-height:0; height:100%;">
                    <iframe id="kp7DigitalPreviewIframe" style="width:100%; height:100%; border:none; background:#334155;"></iframe>
                </div>

                <!-- Footer Actions -->
                <div style="padding:14px 24px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                    <div style="font-size:0.82rem; color:#64748b; display:flex; align-items:center; gap:6px;">
                        <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#16a34a;"></span>
                        <span>Barangay Tumana Official Katarungang Pambarangay Document</span>
                    </div>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <button type="button" class="btn-secondary" id="btnEditComplaintFromModal" style="display:inline-flex; align-items:center; gap:6px;">
                            Edit Details
                        </button>
                        <button type="button" class="btn-secondary" id="btnPrintKp7Digital" style="display:inline-flex; align-items:center; gap:6px; font-weight:600;">
                            Print Form
                        </button>
                        <button type="button" class="btn-create" id="btnConfirmSubmitComplaint" style="display:inline-flex; align-items:center; gap:6px;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            Confirm &amp; Submit Complaint
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Template for dynamic additional parties -->
                <!-- ADD / EDIT PERSON MODAL -->
        <div id="partyDetailsModal" class="party-modal-overlay" style="display:none;">
            <div class="party-modal-card">
                <!-- Modal Header -->
                <div class="party-modal-header">
                    <div class="party-modal-header-left">
                        <h3 id="partyModalTitle">Add Person Details</h3>
                        <p id="partyModalSubtitle">Fill in personal and residence information</p>
                    </div>
                    <button type="button" class="party-modal-close" id="btnClosePartyModal" title="Close modal">&times;</button>
                </div>

                <!-- Modal Body -->
                <div class="party-modal-body">
                    <!-- Quick-Fill from Directory Combobox -->
                    <div class="party-quickfill-bar" style="margin-bottom: 16px;">
                        <div class="quickfill-header">
                            <span class="quickfill-title">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                Quick-Fill from Registered Directory <span class="optional-pill">Optional</span>
                            </span>
                            <button type="button" class="btn-clear-quickfill" id="modalClearQuickFill" style="display:none;">Clear Selection</button>
                        </div>
                        <div class="party-search-combobox" id="modalPartySearchCombobox">
                            <div class="party-search-input-wrap">
                                <input type="text" id="modalPartySearchInput" class="party-search-input" placeholder="Type resident name or address to auto-populate fields..." autocomplete="off">
                                <button type="button" class="party-search-clear" id="modalPartySearchClear" title="Clear selection" style="display:none;">&times;</button>
                            </div>
                            <input type="hidden" id="modalResidentId" value="">
                            <div class="party-search-dropdown" id="modalPartySearchDropdown" style="display:none;"></div>
                        </div>
                    </div>

                    <!-- In-Modal Validation Error Banner -->
                    <div id="modalPartyValidationError" class="party-validation-error-box" style="display:none;">
                        <div style="font-weight:700; margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                            <span>⚠️ Pakikumpleto ang mga kinakailangang impormasyon bago i-save:</span>
                        </div>
                        <ul id="modalPartyValidationList" style="margin:0; padding-left:20px; font-size:0.82rem; line-height:1.6;"></ul>
                    </div>

                    <!-- Personal Information Grid -->
                    <div class="party-section-heading">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        Personal Information
                    </div>
                    <div class="party-fields-grid-4">
                        <div class="party-field-col">
                            <label for="modalFirstName">First Name <span class="required-mark">*</span></label>
                            <input type="text" id="modalFirstName" placeholder="e.g. Juan" maxlength="100">
                        </div>
                        <div class="party-field-col">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                <label for="modalMiddleName" style="margin-bottom:0;">Middle Name</label>
                                <label class="unknown-check-inline" id="modalWrapMiddleUnknown" style="display:none; align-items:center; gap:5px; font-size:0.74rem; font-weight:500; color:#64748b; cursor:pointer; margin-bottom:0; user-select:none; white-space:nowrap;">
                                    <input type="checkbox" id="modalMiddleUnknown" style="width:14px; height:14px; min-height:14px; margin:0; padding:0; cursor:pointer; flex-shrink:0;"> Unknown
                                </label>
                            </div>
                            <input type="text" id="modalMiddleName" placeholder="e.g. Santos (Optional)" maxlength="100">
                        </div>
                        <div class="party-field-col">
                            <label for="modalLastName">Last Name <span class="required-mark">*</span></label>
                            <input type="text" id="modalLastName" placeholder="e.g. Dela Cruz" maxlength="100">
                        </div>
                        <div class="party-field-col">
                            <label for="modalSuffix">Suffix</label>
                            <input type="text" id="modalSuffix" placeholder="e.g. Jr., III" maxlength="20">
                        </div>
                    </div>

                    <!-- Demographics & Contact -->
                    <div class="party-fields-grid-4" style="margin-top: 10px;">
                        <div class="party-field-col">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                <label for="modalBirthdate" style="margin-bottom:0;">Birthdate</label>
                                <label class="unknown-check-inline" id="modalWrapBirthUnknown" style="display:none; align-items:center; gap:5px; font-size:0.74rem; font-weight:500; color:#64748b; cursor:pointer; margin-bottom:0; user-select:none; white-space:nowrap;">
                                    <input type="checkbox" id="modalBirthUnknown" style="width:14px; height:14px; min-height:14px; margin:0; padding:0; cursor:pointer; flex-shrink:0;"> Unknown
                                </label>
                            </div>
                            <input type="date" id="modalBirthdate" max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="party-field-col">
                            <label for="modalAge">Age</label>
                            <input type="number" id="modalAge" min="0" max="130" placeholder="e.g. 35">
                        </div>
                        <div class="party-field-col">
                            <label for="modalGender">Gender</label>
                            <select id="modalGender">
                                <option value="">Select...</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="party-field-col">
                            <label for="modalCivilStatus">Civil Status</label>
                            <select id="modalCivilStatus">
                                <option value="">Select...</option>
                                <option value="Single">Single</option>
                                <option value="Married">Married</option>
                                <option value="Widowed">Widowed</option>
                                <option value="Separated">Separated</option>
                            </select>
                        </div>
                    </div>

                    <div class="party-fields-grid-2" style="margin-top: 10px;">
                        <div class="party-field-col">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                <label for="modalContactNo" style="margin-bottom:0;">Contact Number</label>
                                <label class="unknown-check-inline" id="modalWrapContactUnknown" style="display:none; align-items:center; gap:5px; font-size:0.74rem; font-weight:500; color:#64748b; cursor:pointer; margin-bottom:0; user-select:none; white-space:nowrap;">
                                    <input type="checkbox" id="modalContactUnknown" style="width:14px; height:14px; min-height:14px; margin:0; padding:0; cursor:pointer; flex-shrink:0;"> Unknown
                                </label>
                            </div>
                            <input type="text" id="modalContactNo" placeholder="e.g. 0917 123 4567" maxlength="50">
                        </div>
                        <div class="party-field-col">
                            <label for="modalEmail">Email Address</label>
                            <input type="email" id="modalEmail" placeholder="e.g. name@example.com" maxlength="150">
                        </div>
                    </div>

                    <!-- Residence & Address -->
                    <div class="party-section-heading" style="margin-top: 16px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                        Residence Details <span class="required-mark">*</span>
                    </div>
                    <div class="party-fields-grid-2">
                        <div class="party-field-col">
                            <label for="modalPurok">Purok / Area (Barangay Tumana) <span class="required-mark">*</span></label>
                            <select id="modalPurok" required>
                                <option value="">Select Purok...</option>
                                <optgroup label="Numbered Puroks">
                                    <option value="Purok 1">Purok 1</option>
                                    <option value="Purok 2">Purok 2</option>
                                    <option value="Purok 3">Purok 3</option>
                                    <option value="Purok 4">Purok 4</option>
                                    <option value="Purok 5">Purok 5</option>
                                    <option value="Purok 6">Purok 6</option>
                                    <option value="Purok 7">Purok 7</option>
                                    <option value="Purok 8">Purok 8</option>
                                </optgroup>
                                <optgroup label="Tumana Puroks / Compounds">
                                    <option value="Doña Petra">Doña Petra Compound</option>
                                    <option value="Bagong Farmers">Bagong Farmers</option>
                                    <option value="Bukang Liwayway">Bukang Liwayway</option>
                                    <option value="Libis Tumana">Libis Tumana</option>
                                    <option value="Bagong Purok">Sitio Bagong Purok</option>
                                    <option value="Palay">Palay Area</option>
                                    <option value="Mais">Mais Area</option>
                                    <option value="Singkamas">Singkamas Area</option>
                                </optgroup>
                                <option value="Non-Resident">Non-Resident / Outside Tumana</option>
                            </select>
                        </div>
                        <div class="party-field-col">
                            <label for="modalIsTenant">Residency Status <span class="required-mark">*</span></label>
                            <select id="modalIsTenant" required>
                                <option value="0">Permanent Resident</option>
                                <option value="1">Tenant / Renter</option>
                                <option value="2">Non-Resident</option>
                            </select>
                        </div>
                    </div>

                    <!-- Dynamic KP Law Sec. 409 Non-Resident Respondent Warning & Jurisdiction Confirmation -->
                    <div id="modalRespondentKpWarning" style="display: none; margin-top: 14px; background: #fffbeb; border: 1.5px solid #f59e0b; border-left: 5px solid #d97706; border-radius: 8px; padding: 14px 16px;">
                        <div style="display: flex; align-items: flex-start; gap: 10px;">
                            <span style="font-size: 1.4rem; line-height: 1; flex-shrink: 0;" aria-hidden="true">⚠️</span>
                            <div style="flex: 1;">
                                <div style="font-size: 0.88rem; font-weight: 700; color: #92400e; margin-bottom: 5px;">
                                    Babala alinsunod sa KP Law (Sec. 409):
                                </div>
                                <div id="modalRespondentKpWarningText" style="font-size: 0.82rem; color: #78350f; line-height: 1.5; margin-bottom: 10px;">
                                    Ang inirereklamo ay hindi residente ng Barangay Tumana. Ayon sa batas, ang reklamo ay dapat isampa sa Barangay kung saan nakatira ang Respondent <span id="modalRespondentLocalityText" style="font-weight: 700;"></span>, <strong>MALIBAN</strong> na lamang kung ang alitan ay tungkol sa <strong>Lupa/Paupahan sa Tumana (Sec. 409c)</strong> o naganap sa <strong>Lugar ng Trabaho/Paaralan sa Tumana (Sec. 409d)</strong>.
                                </div>
                                <div style="background: #fef3c7; border: 1px solid #fde68a; border-radius: 6px; padding: 10px 12px;">
                                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; margin: 0; font-size: 0.84rem; font-weight: 600; color: #92400e;">
                                        <input type="checkbox" id="modalRespondentKpExceptionCheck" style="margin-top: 2px; width: 16px; height: 16px; accent-color: #d97706;">
                                        <span>Ang alitan ay naganap sa Ari-arian / Workplace sa loob ng Tumana</span>
                                    </label>
                                </div>
                                <div id="modalRespondentKpBlockedMsg" style="display: none; margin-top: 8px; font-size: 0.8rem; font-weight: 700; color: #b91c1c; background: #fee2e2; border: 1px solid #fecaca; border-radius: 4px; padding: 8px 10px; line-height: 1.4;">
                                    ⛔ Hinarang ang pag-save: Kung hindi iche-check ang kumpirmasyon ng eksepsiyon (Sec. 409c/d), hindi maaaring tanggapin ang reklamo laban sa non-resident sa Barangay Tumana. Mangyaring payuhan ang complainant na lumipat at isampa ang reklamo sa barangay kung saan nakatira ang respondent.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="party-field-col" style="margin-top: 10px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <label for="modalAddress" style="margin-bottom: 0;">Complete Street Address <span class="required-mark">*</span></label>
                            <button type="button" class="btn-secondary btn-sm" id="btnPinPartyAddress" style="padding: 2px 8px; font-size: 0.72rem; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px;" title="Find and drop pin on map">
                                <span>Pin on Map</span>
                            </button>
                        </div>
                        <input type="text" id="modalAddress" required placeholder="House #, Street name, Purok/Compound, Barangay Tumana">
                        <input type="hidden" id="modalLatitude" value="">
                        <input type="hidden" id="modalLongitude" value="">
                    </div>

                    <!-- Interactive Address Pinning Map -->
                    <div class="party-map-pinbox" style="margin-top: 12px; background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 12px; overflow: hidden;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="font-size: 0.82rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 6px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#e11d48" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                Pin Residence Location on Map
                            </span>
                            <div style="display: flex; gap: 6px;">
                                <button type="button" class="btn-secondary btn-sm" id="btnRecenterPartyMap" style="padding: 3px 8px; font-size: 0.72rem; border-radius: 4px;">Recenter Tumana</button>
                                <button type="button" class="btn-secondary btn-sm" id="btnClearPartyMap" style="padding: 3px 8px; font-size: 0.72rem; border-radius: 4px;">Clear Pin</button>
                            </div>
                        </div>
                        <div id="modalPartyAddressMap" style="height: 200px; width: 100%; border-radius: 6px; border: 1px solid #cbd5e1; background: #e2e8f0; position: relative;"></div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 6px; font-size: 0.75rem; color: #64748b;">
                            <span id="modalPartyMapStatus">Click on map or drag pin to auto-fill complete street address with house number.</span>
                            <span id="modalPartyMapCoords" style="font-family: monospace; font-size: 0.72rem; color: #0284c7;"></span>
                        </div>
                    </div>

                    <!-- Minor & Legal Guardian Section -->
                    <input type="hidden" id="modalIsMinor" value="0">

                    <div id="modalGuardianSection" class="conditional-party-details guardian-box" style="display:none; margin-top:16px;">
                        <div class="guardian-box-title" style="font-size:0.84rem; font-weight:700; color:#0369a1; margin-bottom:10px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:6px;">
                            <span>Legal Guardian / Representative Details</span>
                            <span style="background:#e0f2fe; color:#0369a1; font-size:0.75rem; font-weight:600; padding:2px 8px; border-radius:9999px;">Person is a Minor (&lt; 18 years old)</span>
                        </div>
                            <div class="party-fields-grid-2">
                                <div class="party-field-col">
                                    <label for="modalGuardianName">Guardian Full Name <span class="required-mark" id="modalGuardianReqName">*</span></label>
                                    <input type="text" id="modalGuardianName" placeholder="Full name of parent or legal guardian">
                                </div>
                                <div class="party-field-col">
                                    <label for="modalGuardianRelationship">Relationship to Minor <span class="required-mark" id="modalGuardianReqRel">*</span></label>
                                    <input type="text" id="modalGuardianRelationship" placeholder="e.g. Mother, Father, Legal Guardian">
                                </div>
                            </div>
                            <div class="party-fields-grid-2" style="margin-top:8px;">
                                <div class="party-field-col">
                                    <label for="modalGuardianContact">Guardian Contact Number</label>
                                    <input type="text" id="modalGuardianContact" placeholder="e.g. 0917 123 4567">
                                </div>
                                <div class="party-field-col">
                                    <label for="modalGuardianAddress">Guardian Address</label>
                                    <input type="text" id="modalGuardianAddress" placeholder="Address if different from person">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Modal Footer -->
                    <div class="party-modal-footer">
                        <button type="button" class="btn-secondary" id="btnCancelPartyModal">Cancel</button>
                        <button type="button" class="btn-create" id="btnSavePartyModal">Save &amp; Apply Details</button>
                    </div>
                </div>
            </div>

        <template id="residentOptionsTemplate">
            <?php echo $renderResidentOptions(null); ?>
        </template>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../../assets/js/complaints.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/complaints.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#39;',
            '"': '&quot;'
        }[character] || character));
    }

    // Expose registered residents data for combobox components
    window.AGAP_RESIDENTS = <?php echo json_encode($residentsJsonData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    window.MARIKINA_LOGO_B64 = <?php echo json_encode($marikinaLogoB64); ?>;
    window.TUMANA_LOGO_B64 = <?php echo json_encode($tumanaLogoB64); ?>;

    const filipinoMonths = [
        'Enero', 'Pebrero', 'Marso', 'Abril', 'Mayo', 'Hunyo',
        'Hulyo', 'Agosto', 'Setyembre', 'Oktubre', 'Nobyembre', 'Disyembre'
    ];

    function partyLinesForKp7(list, defaultFirst, defaultLast, fallback = '________________________') {
        let parties = (list && list.length > 0) ? list : [];
        if (parties.length === 0 && (defaultFirst || defaultLast)) {
            parties = [{ first_name: defaultFirst, last_name: defaultLast }];
        }
        if (parties.length === 0) {
            return '<div style="border-bottom: 1px solid #1e293b; min-height: 4mm; margin-bottom: 5px;">&nbsp;</div>' +
                   '<div style="border-bottom: 1px solid #1e293b; min-height: 4mm; margin-bottom: 5px;">&nbsp;</div>' +
                   '<div style="border-bottom: 1px solid #1e293b; min-height: 4mm; margin-bottom: 5px;">&nbsp;</div>';
        }
        return parties.map(p => {
            const nameParts = [p.first_name, p.middle_name, p.last_name, p.suffix].filter(Boolean).map(s => String(s).trim()).filter(Boolean);
            const full = nameParts.join(' ');
            let line = `<strong>${escapeHtml(full || fallback)}</strong>`;
            if (p.is_minor && p.guardian_name) {
                line += ` <span style="font-size: 8pt; font-weight: normal; color: #475569;">(Kinatawan: ${escapeHtml(p.guardian_name)}${p.guardian_relationship ? ' — ' + escapeHtml(p.guardian_relationship) : ''})</span>`;
            }
            const addr = p.address || p.purok || '';
            if (addr) {
                line += `<div style="font-size: 8pt; font-weight: normal; color: #475569; margin-top: 0.5mm;">${escapeHtml(addr)}</div>`;
            }
            return `<div style="border-bottom: 1px solid #1e293b; padding-bottom: 1.5px; margin-bottom: 3.5px;">${line}</div>`;
        }).join('');
    }

    function partyNamesList(list, defaultFirst, defaultLast, fallback = '________________________') {
        let parties = (list && list.length > 0) ? list : [];
        if (parties.length === 0 && (defaultFirst || defaultLast)) {
            parties = [{ first_name: defaultFirst, last_name: defaultLast }];
        }
        const names = parties.map(p => {
            return [p.first_name, p.middle_name, p.last_name, p.suffix].filter(Boolean).map(s => String(s).trim()).filter(Boolean).join(' ');
        }).filter(Boolean);
        return names.length > 0 ? names.join(', ') : fallback;
    }

    function partySignatureList(list, defaultFirst, defaultLast, fallback = '___________________________') {
        let parties = (list && list.length > 0) ? list : [];
        if (parties.length === 0 && (defaultFirst || defaultLast)) {
            parties = [{ first_name: defaultFirst, last_name: defaultLast }];
        }
        const names = parties.map(p => {
            return [p.first_name, p.middle_name, p.last_name, p.suffix].filter(Boolean).map(s => String(s).trim()).filter(Boolean).join(' ');
        }).filter(Boolean);
        return names.length > 0 ? names.join(' / ') : fallback;
    }

    function generateKp7DocumentHtml(d) {
        const tumanaLogo = window.TUMANA_LOGO_B64 || '';
        const marikinaLogo = window.MARIKINA_LOGO_B64 || '';

        return `<!DOCTYPE html>
<html lang="tl">
<head>
    <meta charset="UTF-8">
    <title>Pormularyo ng KP Blg. 7 - Reklamo</title>
    <style>
        @page {
            size: 8.5in 14in;
            margin: 0;
        }
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
    <div class="preview-canvas">
        <div class="page-sheet">
            <div class="form-top-code">Pormularyo ng KP Blg. 7</div>

            <table class="header-table">
                <tr>
                    <td class="header-logo-left">
                        ${tumanaLogo ? '<img src="' + tumanaLogo + '" class="header-logo-img" alt="Barangay Tumana">' : ''}
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
                        ${marikinaLogo ? '<img src="' + marikinaLogo + '" class="header-logo-img" alt="Lungsod ng Marikina">' : ''}
                    </td>
                </tr>
            </table>

            <table class="case-table">
                <tr>
                    <td class="parties-col">
                        <div>${d.complainantsHtml}</div>
                        <div class="party-role-tag">(Mga) Nagrereklamo</div>
                        <div class="versus-tag">-laban kay/kina</div>
                        <div>${d.respondentsHtml}</div>
                        <div class="party-role-tag">(Mga) Inirereklamo</div>
                    </td>
                    <td class="meta-col">
                        <div style="margin-bottom: 3.5mm; font-size: 9.5pt;">
                            Usapin ng Barangay Blg. <strong>${d.caseNumber}</strong>
                        </div>
                        <div style="font-size: 9.5pt;">
                            Para sa: <strong>${d.title}</strong>${d.category ? '<br><span style="font-size: 8.5pt; color: #475569;">Kategorya: ' + d.category + '</span>' : ''}
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
                    Personal na nagtungo sa tanggapan sa Barangay si <strong>${d.compNamesStr}</strong> upang ireklamo si <strong>${d.respNamesStr}</strong> sa kadahilanang ${d.narrative ? escapeHtml(d.narrative).replace(/\n/g, '<br>') : 'kami ay may kasunduan na ngunit hindi niya ito natupad.'}
                </p>
                ${d.additional ? '<p class="narrative-indent" style="font-size: 9.5pt; color: #1e293b;"><strong>Karagdagang Salaysay:</strong> ' + escapeHtml(d.additional).replace(/\n/g, '<br>') + '</p>' : ''}
                ${(d.incidentDateStr || d.location) ? `
                <div style="margin-top: 2.5mm; padding: 2mm 3mm; background: #f8fafc; border-left: 2px solid #cbd5e1; font-size: 9pt; color: #334155;">
                    ${d.incidentDateStr ? '<div><strong>Petsa at Oras ng Pangyayari:</strong> ' + d.incidentDateStr + (d.incidentTimeStr ? ' ' + d.incidentTimeStr : '') + '</div>' : ''}
                    ${d.location ? '<div><strong>Lugar ng Pangyayari:</strong> ' + d.location + '</div>' : ''}
                </div>` : ''}

                <p style="margin-top: 3.5mm;">DAHIL DITO, AKO/KAMI ay nakikiusap na ang mga sumusunod na lunas ay ipagkaloob sa akin/amin nang naaayon sa batas at/sa katarungan.</p>
                
                ${d.relief ? '<div class="narrative-indent" style="margin-top: 1.5mm; font-style: italic;">' + escapeHtml(d.relief).replace(/\n/g, '<br>') + '</div>' : ''}
            </div>

            <div class="date-statement">
                <div>Ginawa ngayong ika- <strong>${d.curDay}</strong> ng <strong>${d.curMonth}</strong>, <strong>${d.curYear}</strong>.</div>
                <div style="margin-top: 3.5mm;">Tinanggap ngayong ika- <strong>${d.curDay}</strong> ng <strong>${d.curMonth}</strong>, <strong>${d.curYear}</strong>.</div>
            </div>

            <table class="sig-table">
                <tr>
                    <td style="width: 48%; vertical-align: top; text-align: center;">
                        <div class="sig-comp-name">${d.signatureCompName}</div>
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
                        <div class="sig-capt-line">${d.captainName}</div>
                        <div class="sig-title">Punong Barangay / Tagapangulo ng Lupon</div>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>`;
    }

    window.isKp7Confirmed = false;

    window.openKp7DigitalPreviewModal = function() {
        const compFirst = document.getElementById('complainantFirstName')?.value?.trim();
        const compLast = document.getElementById('complainantLastName')?.value?.trim();
        const respFirst = document.getElementById('respondentFirstName')?.value?.trim();
        const respLast = document.getElementById('respondentLastName')?.value?.trim();

        const catSelect = document.getElementById('categoryId');
        const categoryName = (catSelect && catSelect.selectedIndex > 0) ? catSelect.options[catSelect.selectedIndex].text : '';
        const otherCategorySpecVal = document.getElementById('otherCategorySpecification')?.value?.trim();
        const effectiveCategoryDisplay = (otherCategorySpecVal && (categoryName.includes('Others') || categoryName.includes('Iba pa')))
            ? `Iba pa: ${otherCategorySpecVal}`
            : categoryName;
        const titleVal = document.getElementById('title')?.value?.trim() || 'Dispute';

        let incidentDateStr = '';
        let incidentTimeStr = '';
        const incDtVal = document.getElementById('incidentDateTime')?.value;
        if (incDtVal) {
            const incDate = new Date(incDtVal);
            if (!isNaN(incDate.getTime())) {
                incidentDateStr = 'ika-' + incDate.getDate() + ' ng ' + filipinoMonths[incDate.getMonth()] + ', ' + incDate.getFullYear();
                const isUnknown = document.getElementById('incidentTimeUnknown')?.checked;
                const isApprox = document.getElementById('incidentTimeApproximate')?.checked;
                if (isUnknown) {
                    incidentTimeStr = '(Hindi tiyak ang oras)';
                } else {
                    let hours = incDate.getHours();
                    const minutes = String(incDate.getMinutes()).padStart(2, '0');
                    const ampm = hours >= 12 ? 'PM' : 'AM';
                    hours = hours % 12 || 12;
                    incidentTimeStr = 'sa ganap na ika-' + hours + ':' + minutes + ' ' + ampm + (isApprox ? ' (tinatayang oras)' : '');
                }
            }
        }

        const addressVal = document.getElementById('incidentAddress')?.value?.trim() || '';
        const landmarkVal = document.getElementById('incidentLandmark')?.value?.trim() || '';
        let locationFull = addressVal;
        if (landmarkVal) {
            locationFull += (locationFull ? ' ' : '') + `(Malapit sa: ${landmarkVal})`;
        }

        const narrativeVal = document.getElementById('narrative')?.value?.trim() || '';
        const additionalVal = document.getElementById('proofOcrNotes')?.value?.trim() || '';
        const reliefVal = document.getElementById('prayerForRelief')?.value?.trim() || '';

        const now = new Date();
        const curDay = now.getDate();
        const curMonth = filipinoMonths[now.getMonth()];
        const curYear = now.getFullYear();
        const docketNum = String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0') + '-' + now.getFullYear();

        const htmlData = {
            complainantsHtml: partyLinesForKp7(complainantsList, compFirst, compLast),
            respondentsHtml: partyLinesForKp7(respondentsList, respFirst, respLast),
            compNamesStr: escapeHtml(partyNamesList(complainantsList, compFirst, compLast)),
            respNamesStr: escapeHtml(partyNamesList(respondentsList, respFirst, respLast)),
            signatureCompName: escapeHtml(partySignatureList(complainantsList, compFirst, compLast)),
            caseNumber: docketNum,
            title: escapeHtml(titleVal),
            category: escapeHtml(effectiveCategoryDisplay),
            narrative: narrativeVal,
            additional: additionalVal,
            relief: reliefVal,
            incidentDateStr: incidentDateStr,
            incidentTimeStr: incidentTimeStr,
            location: escapeHtml(locationFull),
            curDay: curDay,
            curMonth: curMonth,
            curYear: curYear,
            captainName: 'HON. AKIKO LYNNE CENTENO'
        };

        const kp7Html = generateKp7DocumentHtml(htmlData);
        const iframe = document.getElementById('kp7DigitalPreviewIframe');
        if (iframe) {
            iframe.srcdoc = kp7Html;
        }

        const modal = document.getElementById('kp7DigitalPreviewModal');
        if (modal) {
            modal.style.display = 'flex';
        }
    };

    // Digital KP7 Preview Modal Actions
    document.getElementById('btnPrintKp7Digital')?.addEventListener('click', function() {
        const iframe = document.getElementById('kp7DigitalPreviewIframe');
        if (iframe && iframe.contentWindow) {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        }
    });

    document.getElementById('btnCloseKp7DigitalPreviewModal')?.addEventListener('click', function() {
        const modal = document.getElementById('kp7DigitalPreviewModal');
        if (modal) modal.style.display = 'none';
    });

    document.getElementById('btnEditComplaintFromModal')?.addEventListener('click', function() {
        const modal = document.getElementById('kp7DigitalPreviewModal');
        if (modal) modal.style.display = 'none';
    });

    document.getElementById('btnConfirmSubmitComplaint')?.addEventListener('click', function() {
        this.disabled = true;
        this.textContent = 'Submitting Complaint...';
        window.isKp7Confirmed = true;
        const form = document.getElementById('createComplaintForm');
        if (form) {
            form.submit();
        }
    });

    // Dynamic "Others (Please Specify)" Category Handler
    const categorySelectEl = document.getElementById('categoryId');
    const otherCategoryWrapperEl = document.getElementById('otherCategoryWrapper');
    const otherCategoryInputEl = document.getElementById('otherCategorySpecification');

    function syncOtherCategoryVisibility() {
        if (!categorySelectEl || !otherCategoryWrapperEl || !otherCategoryInputEl) return;
        const selectedOption = categorySelectEl.options[categorySelectEl.selectedIndex];
        const selectedText = selectedOption ? selectedOption.text : '';
        const isOthers = selectedText.includes('Others') || selectedText.includes('Iba pa');

        if (isOthers) {
            otherCategoryWrapperEl.style.display = 'block';
            otherCategoryInputEl.required = true;
            otherCategoryInputEl.focus();
        } else {
            otherCategoryWrapperEl.style.display = 'none';
            otherCategoryInputEl.required = false;
        }
    }

    categorySelectEl?.addEventListener('change', syncOtherCategoryVisibility);
    syncOtherCategoryVisibility();

    // Distinct parties validation
    window.validateDistinctParties = function() {
        const compInput = document.getElementById('complainantResidentId');
        const respInput = document.getElementById('respondentResidentId');
        const errorBox  = document.getElementById('partyDistinctError');
        const compGroup = document.getElementById('complainantsGroupSection');
        const respGroup = document.getElementById('respondentsGroupSection');

        const compVal = compInput ? compInput.value.trim() : '';
        const respVal = respInput ? respInput.value.trim() : '';

        const compFirst = document.getElementById('complainantFirstName')?.value?.trim().toLowerCase() || '';
        const compLast  = document.getElementById('complainantLastName')?.value?.trim().toLowerCase() || '';
        const respFirst = document.getElementById('respondentFirstName')?.value?.trim().toLowerCase() || '';
        const respLast  = document.getElementById('respondentLastName')?.value?.trim().toLowerCase() || '';

        const isSameProfile = (compVal && respVal && compVal === respVal);
        const isSameName = (compFirst && compLast && respFirst && respLast && compFirst === respFirst && compLast === respLast);

        if (isSameProfile || isSameName) {
            if (errorBox) {
                errorBox.innerHTML = '<strong>Same person selected!</strong> The complainant and respondent cannot be the same person. Please specify different individuals.';
                errorBox.style.display = 'block';
            }
            [compGroup, respGroup].forEach(g => {
                if (!g) return;
                g.style.outline = '2px solid #dc2626';
                g.style.outlineOffset = '2px';
            });
            return false;
        }

        if (errorBox) {
            errorBox.style.display = 'none';
        }
        [compGroup, respGroup].forEach(g => {
            if (!g) return;
            g.style.outline = '';
            g.style.outlineOffset = '';
        });
        return true;
    };

    window.syncDistinctPartyOptions = function() {
        window.validateDistinctParties();
    };

    // Initialize all searchable resident comboboxes
    if (window.initAllPartyComboboxes) {
        window.initAllPartyComboboxes();
    }

    // Dynamic additional parties
    const container = document.getElementById('additionalPartiesContainer');
    const addBtn = document.getElementById('addPartyBtn');
    let partyCount = document.querySelectorAll('.additional-party-row').length;

    if (addBtn && container) {
        addBtn.addEventListener('click', () => {
            partyCount++;
            const rowId = `additionalParty_${partyCount}`;
            const div = document.createElement('div');
            div.className = 'additional-party-row';
            div.id = rowId;
            div.innerHTML = `
                <div class="party-row-fields">
                    <div class="form-group party-type-col">
                        <select name="party_types[]" class="party-type-select">
                            <option value="Witness">Witness</option>
                            <option value="Complainant">Complainant</option>
                            <option value="Respondent">Respondent</option>
                        </select>
                    </div>
                    <div class="form-group party-name-col">
                        <div class="party-search-combobox" data-party-role="additional">
                            <div class="party-search-input-wrap">
                                <input type="text" class="party-search-input" placeholder="Type resident name or address to search..." autocomplete="off">
                                <button type="button" class="party-search-clear" title="Clear selection" style="display:none;">&times;</button>
                            </div>
                            <input type="hidden" name="party_resident_ids[]" value="">
                            <input type="hidden" name="party_names[]" value="">
                            <div class="party-search-dropdown" style="display:none;"></div>
                        </div>
                    </div>
                    <button type="button" class="btn-remove-party" title="Remove party" onclick="document.getElementById('${rowId}').remove()">&times;</button>
                </div>
                <div class="party-selected-preview additional-party-preview" style="display:none;"></div>
            `;
            container.appendChild(div);

            const newCombobox = div.querySelector('.party-search-combobox');
            if (newCombobox && window.initPartySearchCombobox) {
                window.initPartySearchCombobox(newCombobox, window.AGAP_RESIDENTS);
            }
        });
    }

    // Form submission validation (Frictionless: direct party details allowed)
    const createForm = document.getElementById('createComplaintForm');
    if (createForm) {
        createForm.addEventListener('submit', (e) => {
            if (potentialDuplicatesDetected && !document.getElementById('duplicateAcknowledged')?.checked) {
                e.preventDefault();
                window.agapNotify?.('Review the potential duplicate record(s) and confirm whether this filing should proceed.', 'error');
                document.getElementById('duplicateAcknowledged')?.focus();
                return;
            }

            const compFirst = document.getElementById('complainantFirstName')?.value?.trim();
            const compLast  = document.getElementById('complainantLastName')?.value?.trim();
            const compId    = document.getElementById('complainantResidentId')?.value?.trim();

            const respFirst = document.getElementById('respondentFirstName')?.value?.trim();
            const respLast  = document.getElementById('respondentLastName')?.value?.trim();
            const respId    = document.getElementById('respondentResidentId')?.value?.trim();

            if (!compId && (!compFirst || !compLast)) {
                e.preventDefault();
                window.agapNotify?.('Please provide the complainant\'s First Name and Last Name.', 'error');
                window.openPartyModal('complainant', complainantsList.length > 0 ? 'edit' : 'add', 0);
                return;
            }

            if (!respId && (!respFirst || !respLast)) {
                e.preventDefault();
                window.agapNotify?.('Please provide the respondent\'s First Name and Last Name.', 'error');
                window.openPartyModal('respondent', respondentsList.length > 0 ? 'edit' : 'add', 0);
                return;
            }

            const compPurok = document.getElementById('complainantPurok')?.value?.trim();
            const compAddress = document.getElementById('complainantAddress')?.value?.trim();
            if (!compPurok || !compAddress) {
                e.preventDefault();
                window.agapNotify?.('Please provide the complainant\'s complete residence details (Purok/Area and Street Address).', 'error');
                window.openPartyModal('complainant', complainantsList.length > 0 ? 'edit' : 'add', 0);
                return;
            }

            const respPurok = document.getElementById('respondentPurok')?.value?.trim();
            const respAddress = document.getElementById('respondentAddress')?.value?.trim();
            if (!respPurok || !respAddress) {
                e.preventDefault();
                window.agapNotify?.('Please provide the respondent\'s complete residence details (Purok/Area and Street Address).', 'error');
                window.openPartyModal('respondent', respondentsList.length > 0 ? 'edit' : 'add', 0);
                return;
            }

            for (let i = 0; i < complainantsList.length; i++) {
                const c = complainantsList[i];
                if (!c.purok || !c.address) {
                    e.preventDefault();
                    window.agapNotify?.(`Please provide complete residence details for Complainant ${i + 1} (${c.first_name} ${c.last_name}).`, 'error');
                    window.openPartyModal('complainant', 'edit', i);
                    return;
                }
            }

            for (let i = 0; i < respondentsList.length; i++) {
                const r = respondentsList[i];
                if (!r.purok || !r.address) {
                    e.preventDefault();
                    window.agapNotify?.(`Please provide complete residence details for Respondent ${i + 1} (${r.first_name} ${r.last_name}).`, 'error');
                    window.openPartyModal('respondent', 'edit', i);
                    return;
                }
            }

            // Check distinctness
            const fullComp = `${compFirst || ''} ${compLast || ''}`.trim().toLowerCase();
            const fullResp = `${respFirst || ''} ${respLast || ''}`.trim().toLowerCase();
            if ((compId && respId && compId === respId) || (fullComp && fullResp && fullComp === fullResp)) {
                e.preventDefault();
                window.validateDistinctParties();
                window.agapNotify?.('The complainant and respondent cannot be the exact same person.', 'error');
                return;
            }

            // Check if complainant is a minor and require guardian details
            const compIsMinor = document.getElementById('complainantIsMinor')?.value === '1';
            if (compIsMinor) {
                const gName = document.getElementById('complainantGuardianName')?.value?.trim();
                const gRel  = document.getElementById('complainantGuardianRelationship')?.value?.trim();
                if (!gName || !gRel) {
                    e.preventDefault();
                    window.agapNotify?.('Since the complainant is a minor, the legal guardian\'s full name and relationship are required.', 'error');
                    window.openPartyModal('complainant', 'edit', 0);
                    return;
                }
            }

            const cityInput = document.getElementById('incidentCity');
            if (cityInput && (!cityInput.value || !cityInput.value.toLowerCase().includes('marikina'))) {
                e.preventDefault();
                window.agapNotify?.('Incident location must be within Marikina City.', 'error');
                return;
            }

            const mapStateInput = document.querySelector('[data-map-state]');
            const mapLatInput = document.querySelector('[data-map-latitude]');
            const mapLngInput = document.querySelector('[data-map-longitude]');
            if (mapStateInput && mapStateInput.value === 'selected' && mapLatInput && mapLngInput) {
                const latVal = parseFloat(mapLatInput.value);
                const lngVal = parseFloat(mapLngInput.value);
                if (isNaN(latVal) || isNaN(lngVal) || latVal < 14.610 || latVal > 14.690 || lngVal < 121.075 || lngVal > 121.155) {
                    e.preventDefault();
                    window.agapNotify?.('Incident location must be within Marikina City.', 'error');
                    return;
                }
            }

            const selectedMethod = document.querySelector('input[name="filing_method"]:checked')?.value || 'digital';
            if (selectedMethod !== 'digital') {
                if (!currentKp7File && !kp7Input?.files?.length) {
                    const proceed = confirm('No scanned handwritten document file has been attached. Do you still wish to submit this complaint as a paper filing?');
                    if (!proceed) {
                        e.preventDefault();
                        return;
                    }
                }
            }

            const submitBtn = document.getElementById('btnSubmitComplaint');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Saving & Scheduling Paanyaya...';
            }
        });
    }

    // ── Age Calculation Helper ──
    function calculateAge(birthDateStr) {
        if (!birthDateStr) return null;
        const dob = new Date(birthDateStr);
        if (isNaN(dob.getTime())) return null;
        const today = new Date();
        let age = today.getFullYear() - dob.getFullYear();
        const m = today.getMonth() - dob.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) {
            age--;
        }
        return age >= 0 ? age : null;
    }

    // ── Involved Parties State & Modal Controller ──
    let complainantsList = [];
    let respondentsList = [];

    // Initialize lists from existing hidden fields
    const initCompFirst = document.getElementById('complainantFirstName')?.value?.trim() || '';
    const initCompLast  = document.getElementById('complainantLastName')?.value?.trim() || '';
    const initCompId    = document.getElementById('complainantResidentId')?.value?.trim() || '';
    if (initCompFirst || initCompLast || initCompId) {
        complainantsList.push({
            resident_id: initCompId,
            first_name: initCompFirst,
            middle_name: document.getElementById('complainantMiddleName')?.value?.trim() || '',
            last_name: initCompLast,
            suffix: document.getElementById('complainantSuffix')?.value?.trim() || '',
            birthdate: document.getElementById('complainantBirthdate')?.value || '',
            age: document.getElementById('complainantAge')?.value || '',
            gender: document.getElementById('complainantGender')?.value || '',
            civil_status: document.getElementById('complainantCivilStatus')?.value || '',
            occupation: document.getElementById('complainantOccupation')?.value?.trim() || '',
            contact_no: document.getElementById('complainantContactNo')?.value?.trim() || '',
            email: document.getElementById('complainantEmail')?.value?.trim() || '',
            preferred_contact: document.getElementById('complainantPreferredContact')?.value || 'SMS',
            purok: document.getElementById('complainantPurok')?.value || '',
            is_tenant: document.getElementById('complainantIsTenant')?.value || '0',
            address: document.getElementById('complainantAddress')?.value?.trim() || '',
            home_latitude: document.getElementById('complainantLatitude')?.value || null,
            home_longitude: document.getElementById('complainantLongitude')?.value || null,
            is_minor: document.getElementById('complainantIsMinor')?.value === '1',
            guardian_name: document.getElementById('complainantGuardianName')?.value?.trim() || '',
            guardian_relationship: document.getElementById('complainantGuardianRelationship')?.value?.trim() || '',
            guardian_contact: document.getElementById('complainantGuardianContact')?.value?.trim() || '',
            guardian_address: document.getElementById('complainantGuardianAddress')?.value?.trim() || ''
        });
    }

    const initRespFirst = document.getElementById('respondentFirstName')?.value?.trim() || '';
    const initRespLast  = document.getElementById('respondentLastName')?.value?.trim() || '';
    const initRespId    = document.getElementById('respondentResidentId')?.value?.trim() || '';
    if (initRespFirst || initRespLast || initRespId) {
        respondentsList.push({
            resident_id: initRespId,
            first_name: initRespFirst,
            middle_name: document.getElementById('respondentMiddleName')?.value?.trim() || '',
            last_name: initRespLast,
            suffix: document.getElementById('respondentSuffix')?.value?.trim() || '',
            birthdate: document.getElementById('respondentBirthdate')?.value || '',
            age: document.getElementById('respondentAge')?.value || '',
            gender: document.getElementById('respondentGender')?.value || '',
            civil_status: document.getElementById('respondentCivilStatus')?.value || '',
            occupation: document.getElementById('respondentOccupation')?.value?.trim() || '',
            contact_no: document.getElementById('respondentContactNo')?.value?.trim() || '',
            email: document.getElementById('respondentEmail')?.value?.trim() || '',
            purok: document.getElementById('respondentPurok')?.value || '',
            is_tenant: document.getElementById('respondentIsTenant')?.value || '0',
            address: document.getElementById('respondentAddress')?.value?.trim() || '',
            home_latitude: document.getElementById('respondentLatitude')?.value || null,
            home_longitude: document.getElementById('respondentLongitude')?.value || null,
            is_minor: document.getElementById('respondentIsMinor')?.value === '1',
            guardian_name: document.getElementById('respondentGuardianName')?.value?.trim() || '',
            guardian_relationship: document.getElementById('respondentGuardianRelationship')?.value?.trim() || '',
            guardian_contact: document.getElementById('respondentGuardianContact')?.value?.trim() || '',
            guardian_address: document.getElementById('respondentGuardianAddress')?.value?.trim() || '',
            contact_unknown: document.getElementById('respContactUnknown')?.value === '1',
            middle_unknown: document.getElementById('respMiddleUnknown')?.value === '1',
            birth_unknown: document.getElementById('respBirthUnknown')?.value === '1'
        });
    }

    // Modal state variables
    let currentModalRole = 'complainant';
    let currentModalMode = 'add';
    let currentModalIndex = null;

    const modalEl = document.getElementById('partyDetailsModal');
    const modalTitle = document.getElementById('partyModalTitle');
    const modalSubtitle = document.getElementById('partyModalSubtitle');

    window.openPartyModal = function(role, mode = 'add', index = null) {
        currentModalRole = role;
        currentModalMode = mode;
        currentModalIndex = index;

        const isComplainant = (role === 'complainant');
        if (modalTitle) {
            modalTitle.textContent = mode === 'edit'
                ? `Edit ${isComplainant ? 'Complainant' : 'Respondent'} Details`
                : `Add ${isComplainant ? 'Complainant' : 'Respondent'}`;
        }
        if (modalSubtitle) {
            modalSubtitle.textContent = isComplainant
                ? 'Enter complainant personal details directly or quick-fill from resident directory.'
                : 'Enter respondent details directly or indicate unknown information if unavailable.';
        }

        // Show/hide respondent-specific unknown toggles
        const wrapMiddle = document.getElementById('modalWrapMiddleUnknown');
        const wrapBirth  = document.getElementById('modalWrapBirthUnknown');
        const wrapContact= document.getElementById('modalWrapContactUnknown');
        if (wrapMiddle) wrapMiddle.style.display = isComplainant ? 'none' : 'inline-flex';
        if (wrapBirth) wrapBirth.style.display = isComplainant ? 'none' : 'inline-flex';
        if (wrapContact) wrapContact.style.display = isComplainant ? 'none' : 'inline-flex';

        // Reset quick-fill search
        resetModalQuickFill();

        if (mode === 'add') {
            resetModalFields();
        } else {
            const party = isComplainant ? complainantsList[index] : respondentsList[index];
            if (party) {
                populateModalWithData(party);
            } else {
                resetModalFields();
            }
        }

        if (modalEl) {
            checkRespondentKpJurisdictionWarning();
            modalEl.style.display = 'flex';
            setTimeout(() => {
                document.getElementById('modalFirstName')?.focus();
                initPartyAddressMap();
                if (partyMapInstance) {
                    partyMapInstance.invalidateSize();
                    const latVal = parseFloat(document.getElementById('modalLatitude')?.value);
                    const lngVal = parseFloat(document.getElementById('modalLongitude')?.value);
                    if (!isNaN(latVal) && !isNaN(lngVal) && latVal !== 0 && lngVal !== 0) {
                        setPartyAddressMapPoint(latVal, lngVal, false);
                    } else {
                        const curAddr = document.getElementById('modalAddress')?.value?.trim();
                        const curPurok = document.getElementById('modalPurok')?.value?.trim();
                        if (curAddr || curPurok) {
                            window.geocodeAndPinPartyAddress?.(curAddr, curPurok);
                        }
                    }
                }
            }, 150);
        }
    };

    window.closePartyModal = function() {
        if (modalEl) {
            modalEl.style.display = 'none';
        }
    };

    document.getElementById('btnClosePartyModal')?.addEventListener('click', window.closePartyModal);
    document.getElementById('btnCancelPartyModal')?.addEventListener('click', window.closePartyModal);
    modalEl?.addEventListener('click', (e) => {
        if (e.target === modalEl) {
            window.closePartyModal();
        }
    });

    document.getElementById('btnAddComplainantModal')?.addEventListener('click', () => {
        window.openPartyModal('complainant', 'add');
    });

    document.getElementById('btnAddRespondentModal')?.addEventListener('click', () => {
        window.openPartyModal('respondent', 'add');
    });

    function resetModalFields() {
        if (partyMapMarker && partyMapInstance) {
            partyMapInstance.removeLayer(partyMapMarker);
            partyMapMarker = null;
        }
        if (partyMapInstance) {
            partyMapInstance.setView([14.6565, 121.0945], 16);
            partyMapInstance.invalidateSize();
        }
        const pStatus = document.getElementById('modalPartyMapStatus');
        if (pStatus) pStatus.textContent = 'Click on map or drag pin to auto-fill complete street address with house number.';
        const pCoords = document.getElementById('modalPartyMapCoords');
        if (pCoords) pCoords.textContent = '';
        const resIdEl = document.getElementById('modalResidentId');
        if (resIdEl) resIdEl.value = '';
        const latEl = document.getElementById('modalLatitude');
        if (latEl) latEl.value = '';
        const lngEl = document.getElementById('modalLongitude');
        if (lngEl) lngEl.value = '';
        ['modalFirstName', 'modalMiddleName', 'modalLastName', 'modalSuffix',
         'modalBirthdate', 'modalAge', 'modalContactNo', 'modalEmail', 'modalAddress',
         'modalGuardianName', 'modalGuardianRelationship', 'modalGuardianContact', 'modalGuardianAddress'].forEach(id => {
            const el = document.getElementById(id);
            if (el) { el.value = ''; el.disabled = false; }
        });
        ['modalGender', 'modalCivilStatus', 'modalPurok'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        // Preferred contact removed
        const tenant = document.getElementById('modalIsTenant');
        if (tenant) tenant.value = '0';

        const isMinor = document.getElementById('modalIsMinor');
        if (isMinor) {
            if (isMinor.type === 'checkbox') isMinor.checked = false;
            isMinor.value = '0';
        }
        const gSec = document.getElementById('modalGuardianSection');
        if (gSec) gSec.style.display = 'none';
        const kpExChk = document.getElementById('modalRespondentKpExceptionCheck');
        if (kpExChk) kpExChk.checked = false;
        const kpBlocked = document.getElementById('modalRespondentKpBlockedMsg');
        if (kpBlocked) kpBlocked.style.display = 'none';
        const kpWarn = document.getElementById('modalRespondentKpWarning');
        if (kpWarn) kpWarn.style.display = 'none';
        clearModalValidationErrors();

        ['modalMiddleUnknown', 'modalBirthUnknown', 'modalContactUnknown'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.checked = false;
        });
    }

    function populateModalWithData(p) {
        const setV = (id, val) => {
            const el = document.getElementById(id);
            if (!el) return;
            if (el.tagName === 'SELECT') {
                const strVal = String(val ?? '').trim();
                if (strVal) {
                    let matched = false;
                    for (let i = 0; i < el.options.length; i++) {
                        if (el.options[i].value.toLowerCase() === strVal.toLowerCase()) {
                            el.selectedIndex = i;
                            matched = true;
                            break;
                        }
                    }
                    if (!matched) {
                        const newOpt = document.createElement('option');
                        newOpt.value = strVal;
                        newOpt.textContent = strVal;
                        el.appendChild(newOpt);
                        el.value = strVal;
                    }
                } else {
                    el.value = '';
                }
            } else {
                el.value = val ?? '';
            }
        };

        setV('modalResidentId', p.resident_id);
        setV('modalFirstName', p.first_name);
        setV('modalMiddleName', p.middle_name);
        setV('modalLastName', p.last_name);
        setV('modalSuffix', p.suffix);
        setV('modalBirthdate', p.birthdate);
        setV('modalAge', p.age);
        setV('modalGender', p.gender);
        setV('modalCivilStatus', p.civil_status);
        setV('modalContactNo', p.contact_no);
        setV('modalEmail', p.email);
        // Preferred contact removed
        setV('modalPurok', p.purok);
        setV('modalIsTenant', p.is_tenant ?? '0');
        setV('modalAddress', p.address);
        setV('modalLatitude', p.home_latitude ?? p.latitude ?? '');
        setV('modalLongitude', p.home_longitude ?? p.longitude ?? '');

        let isMinorVal = false;
        if (p.age !== undefined && p.age !== null && p.age !== '' && !isNaN(p.age)) {
            isMinorVal = Number(p.age) < 18;
        } else if (p.birthdate) {
            const calculated = calculateAge(p.birthdate);
            isMinorVal = (calculated !== null) ? (calculated < 18) : !!p.is_minor;
        } else {
            isMinorVal = !!p.is_minor;
        }

        const isMinorEl = document.getElementById('modalIsMinor');
        if (isMinorEl) {
            isMinorEl.value = isMinorVal ? '1' : '0';
            if (isMinorEl.type === 'checkbox') isMinorEl.checked = isMinorVal;
        }
        const gSec = document.getElementById('modalGuardianSection');
        if (gSec) gSec.style.display = isMinorVal ? 'block' : 'none';

        setV('modalGuardianName', p.guardian_name);
        setV('modalGuardianRelationship', p.guardian_relationship);
        setV('modalGuardianContact', p.guardian_contact);
        setV('modalGuardianAddress', p.guardian_address);
        const kpExChk = document.getElementById('modalRespondentKpExceptionCheck');
        if (kpExChk) kpExChk.checked = Boolean(p.kp_jurisdiction_exception);
        checkRespondentKpJurisdictionWarning();

        const chkMid = document.getElementById('modalMiddleUnknown');
        const midInput = document.getElementById('modalMiddleName');
        if (chkMid && midInput) {
            chkMid.checked = !!p.middle_unknown;
            midInput.disabled = !!p.middle_unknown;
        }

        const chkBirth = document.getElementById('modalBirthUnknown');
        const bdayInput = document.getElementById('modalBirthdate');
        const ageInput = document.getElementById('modalAge');
        if (chkBirth && bdayInput && ageInput) {
            chkBirth.checked = !!p.birth_unknown;
            bdayInput.disabled = !!p.birth_unknown;
            ageInput.disabled = !!p.birth_unknown;
        }

        const chkContact = document.getElementById('modalContactUnknown');
        const contactInput = document.getElementById('modalContactNo');
        if (chkContact && contactInput) {
            chkContact.checked = !!p.contact_unknown;
            contactInput.disabled = !!p.contact_unknown;
        }

        if (p.resident_id && window.AGAP_RESIDENTS) {
            const res = window.AGAP_RESIDENTS.find(r => String(r.resident_id) === String(p.resident_id));
            if (res) {
                const sInput = document.getElementById('modalPartySearchInput');
                if (sInput) sInput.value = res.display_name;
                const clearBtn = document.getElementById('modalClearQuickFill');
                if (clearBtn) clearBtn.style.display = 'inline-block';
                const sClear = document.getElementById('modalPartySearchClear');
                if (sClear) sClear.style.display = 'inline-block';
            }
        }
    }

    // Modal Directory Quick-Fill Combobox
    const modalSearchInput = document.getElementById('modalPartySearchInput');
    const modalSearchDropdown = document.getElementById('modalPartySearchDropdown');
    const modalSearchClear = document.getElementById('modalPartySearchClear');
    const modalClearQuickFill = document.getElementById('modalClearQuickFill');

    function resetModalQuickFill() {
        if (modalSearchInput) modalSearchInput.value = '';
        if (modalSearchDropdown) {
            modalSearchDropdown.style.display = 'none';
            modalSearchDropdown.innerHTML = '';
        }
        if (modalSearchClear) modalSearchClear.style.display = 'none';
        if (modalClearQuickFill) modalClearQuickFill.style.display = 'none';
    }

    if (modalSearchInput && modalSearchDropdown) {
        modalSearchInput.addEventListener('input', () => {
            const query = modalSearchInput.value.trim().toLowerCase();
            if (modalSearchClear) modalSearchClear.style.display = query ? 'inline-block' : 'none';

            if (!query || query.length < 2 || !window.AGAP_RESIDENTS) {
                modalSearchDropdown.style.display = 'none';
                modalSearchDropdown.innerHTML = '';
                return;
            }

            const matches = window.AGAP_RESIDENTS.filter(r => (r.search_text && r.search_text.includes(query))).slice(0, 8);
            if (matches.length === 0) {
                modalSearchDropdown.innerHTML = '<div class="party-search-item" style="color:#94a3b8;cursor:default;">No matching registered residents found</div>';
                modalSearchDropdown.style.display = 'block';
                return;
            }

            modalSearchDropdown.innerHTML = matches.map(r => `
                <div class="party-search-item" data-id="${r.resident_id}">
                    <div class="party-search-item-name">${escapeHtml(r.display_name)}</div>
                    <div class="party-search-item-meta">${escapeHtml(r.residency_label)} • ${escapeHtml(r.address_text)}</div>
                </div>
            `).join('');
            modalSearchDropdown.style.display = 'block';
        });

        modalSearchDropdown.addEventListener('click', (e) => {
            const item = e.target.closest('.party-search-item[data-id]');
            if (!item) return;
            const resId = parseInt(item.dataset.id, 10);
            const res = window.AGAP_RESIDENTS?.find(r => String(r.resident_id) === String(resId));
            if (res) {
                const resIdInput = document.getElementById('modalResidentId');
                if (resIdInput) resIdInput.value = res.resident_id;
                modalSearchInput.value = res.display_name;
                if (modalClearQuickFill) modalClearQuickFill.style.display = 'inline-block';
                if (modalSearchClear) modalSearchClear.style.display = 'inline-block';

                const setV = (id, val) => {
                    const el = document.getElementById(id);
                    if (!el) return;
                    if (el.tagName === 'SELECT') {
                        const strVal = String(val ?? '').trim();
                        if (strVal) {
                            let matched = false;
                            for (let i = 0; i < el.options.length; i++) {
                                if (el.options[i].value.toLowerCase() === strVal.toLowerCase()) {
                                    el.selectedIndex = i;
                                    matched = true;
                                    break;
                                }
                            }
                            if (!matched) {
                                const newOpt = document.createElement('option');
                                newOpt.value = strVal;
                                newOpt.textContent = strVal;
                                el.appendChild(newOpt);
                                el.value = strVal;
                            }
                        } else {
                            el.value = '';
                        }
                    } else {
                        el.value = val ?? '';
                    }
                };

                setV('modalFirstName', res.first_name || '');
                setV('modalMiddleName', res.middle_name || '');
                setV('modalLastName', res.last_name || '');
                setV('modalSuffix', res.suffix || '');
                if (res.birth_date) {
                    setV('modalBirthdate', res.birth_date);
                    const age = calculateAge(res.birth_date);
                    if (age !== null) {
                        setV('modalAge', age);
                        updateModalMinorState(age);
                    }
                } else if (res.age !== undefined && res.age !== null && res.age !== '') {
                    setV('modalAge', res.age);
                    updateModalMinorState(res.age);
                }
                if (res.gender) setV('modalGender', res.gender);
                if (res.civil_status) setV('modalCivilStatus', res.civil_status);
                if (res.contact_no) setV('modalContactNo', res.contact_no);
                if (res.email) setV('modalEmail', res.email);

                // Set Purok and Residency Status with intelligent fallbacks
                if (res.purok) {
                    setV('modalPurok', res.purok);
                }
                let tenantVal = '0';
                if (res.is_tenant !== undefined && res.is_tenant !== null && res.is_tenant !== '') {
                    tenantVal = String(res.is_tenant);
                } else if (res.purok && (res.purok.toLowerCase() === 'non-resident' || res.purok.toLowerCase() === 'outside tumana')) {
                    tenantVal = '2';
                }
                setV('modalIsTenant', tenantVal);

                if (res.address) {
                    setV('modalAddress', res.address);
                }
                const hasLat = res.home_latitude !== undefined && res.home_latitude !== null && res.home_latitude !== '';
                const hasLng = res.home_longitude !== undefined && res.home_longitude !== null && res.home_longitude !== '';
                if (hasLat && hasLng) {
                    setV('modalLatitude', res.home_latitude);
                    setV('modalLongitude', res.home_longitude);
                    setTimeout(() => {
                        if (!partyMapInstance) initPartyAddressMap();
                        if (partyMapInstance) {
                            setPartyAddressMapPoint(parseFloat(res.home_latitude), parseFloat(res.home_longitude), false);
                        }
                    }, 100);
                } else if (res.address) {
                    setV('modalLatitude', '');
                    setV('modalLongitude', '');
                    setTimeout(() => window.geocodeAndPinPartyAddress?.(res.address, res.purok), 100);
                }
            }
            modalSearchDropdown.style.display = 'none';
            checkRespondentKpJurisdictionWarning();
            clearModalValidationErrors();
        });

        // Enter key in Quick-Fill combobox auto-selects first match
        modalSearchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const firstItem = modalSearchDropdown.querySelector('.party-search-item[data-id]');
                if (firstItem) {
                    firstItem.click();
                }
            }
        });

        document.addEventListener('click', (e) => {
            if (!modalSearchInput.contains(e.target) && !modalSearchDropdown.contains(e.target)) {
                modalSearchDropdown.style.display = 'none';
            }
        });
    }

    modalSearchClear?.addEventListener('click', () => {
        resetModalQuickFill();
        const resId = document.getElementById('modalResidentId');
        if (resId) resId.value = '';
    });
    modalClearQuickFill?.addEventListener('click', () => {
        resetModalQuickFill();
        const resId = document.getElementById('modalResidentId');
        if (resId) resId.value = '';
    });

    // Helper to automatically update Minor status & Guardian section based on age
    function updateModalMinorState(age) {
        const minorInput = document.getElementById('modalIsMinor');
        const gSec = document.getElementById('modalGuardianSection');
        if (age !== null && age !== undefined && !isNaN(age)) {
            const isMinor = (parseInt(age, 10) < 18);
            if (minorInput) {
                minorInput.value = isMinor ? '1' : '0';
                if (minorInput.type === 'checkbox') minorInput.checked = isMinor;
            }
            if (gSec) gSec.style.display = isMinor ? 'block' : 'none';
        }
    }

    // Modal Birthdate, Age & Minor listeners
    const modalBdayInput = document.getElementById('modalBirthdate');
    const onModalBirthdateChange = () => {
        const bday = modalBdayInput?.value;
        const age = calculateAge(bday);
        const ageInput = document.getElementById('modalAge');
        if (age !== null && ageInput) {
            ageInput.value = age;
            updateModalMinorState(age);
        }
    };
    modalBdayInput?.addEventListener('change', onModalBirthdateChange);
    modalBdayInput?.addEventListener('input', onModalBirthdateChange);

    const modalAgeInput = document.getElementById('modalAge');
    const onModalAgeInput = () => {
        const val = modalAgeInput?.value?.trim();
        if (val !== '' && !isNaN(val)) {
            const age = parseInt(val, 10);
            if (!isNaN(age)) {
                updateModalMinorState(age);
            }
        }
    };
    modalAgeInput?.addEventListener('input', onModalAgeInput);
    modalAgeInput?.addEventListener('change', onModalAgeInput);

    // Modal Respondent Unknown Toggles
    document.getElementById('modalMiddleUnknown')?.addEventListener('change', (e) => {
        const inp = document.getElementById('modalMiddleName');
        if (inp) {
            if (e.target.checked) { inp.value = ''; inp.disabled = true; }
            else { inp.disabled = false; }
        }
    });

    document.getElementById('modalBirthUnknown')?.addEventListener('change', (e) => {
        const bday = document.getElementById('modalBirthdate');
        const age = document.getElementById('modalAge');
        if (e.target.checked) {
            if (bday) { bday.value = ''; bday.disabled = true; }
            if (age) { age.value = ''; age.disabled = true; }
        } else {
            if (bday) bday.disabled = false;
            if (age) age.disabled = false;
        }
    });

    document.getElementById('modalContactUnknown')?.addEventListener('change', (e) => {
        const contact = document.getElementById('modalContactNo');
        if (contact) {
            if (e.target.checked) { contact.value = ''; contact.disabled = true; }
            else { contact.disabled = false; }
        }
    });


        function checkRespondentKpJurisdictionWarning() {
        const warningBox = document.getElementById('modalRespondentKpWarning');
        const localitySpan = document.getElementById('modalRespondentLocalityText');
        const blockedMsg = document.getElementById('modalRespondentKpBlockedMsg');
        if (!warningBox) return;

        if (currentModalRole !== 'respondent') {
            warningBox.style.display = 'none';
            if (blockedMsg) blockedMsg.style.display = 'none';
            return;
        }

        const purokVal = document.getElementById('modalPurok')?.value;
        const isTenantVal = document.getElementById('modalIsTenant')?.value;
        const isNonResident = (purokVal === 'Non-Resident' || isTenantVal === '2');

        if (isNonResident) {
            const addr = document.getElementById('modalAddress')?.value || '';
            let detected = '';
            const marikinaBrgys = [
                'Concepcion Uno', 'Concepcion Dos', 'Malanday', 'Nangka', 'Parang',
                'Marikina Heights', 'Fortune', 'Sto. Niño', 'San Roque', 'Santa Elena',
                'Barangka', 'Tañong', 'Jesus Dela Peña', 'Industrial Valley Complex'
            ];
            for (const b of marikinaBrgys) {
                if (new RegExp('\\b' + b.replace('.', '\\.') + '\\b', 'i').test(addr)) {
                    detected = b;
                    break;
                }
            }
            if (!detected) {
                const brgyMatch = addr.match(/(?:Barangay|Brgy\.?)\s+([A-Za-z0-9\s]+?)(?:,|$)/i);
                if (brgyMatch) detected = brgyMatch[1].trim();
            }

            if (localitySpan) {
                localitySpan.textContent = detected ? `(${detected})` : '(sa kanyang tinitirhang barangay)';
            }

            warningBox.style.display = 'block';
        } else {
            warningBox.style.display = 'none';
            if (blockedMsg) blockedMsg.style.display = 'none';
            const chk = document.getElementById('modalRespondentKpExceptionCheck');
            if (chk) chk.checked = false;
        }
    }
    window.checkRespondentKpJurisdictionWarning = checkRespondentKpJurisdictionWarning;

// Residency Status <-> Purok automatic synchronization
    const modalIsTenantEl = document.getElementById('modalIsTenant');
    const modalPurokEl = document.getElementById('modalPurok');
    if (modalIsTenantEl && modalPurokEl) {
        modalIsTenantEl.addEventListener('change', () => {
            if (modalIsTenantEl.value === '2') {
                // Non-Resident -> automatically Outside Tumana
                modalPurokEl.value = 'Non-Resident';
            } else if (modalPurokEl.value === 'Non-Resident') {
                modalPurokEl.value = '';
            }
            checkRespondentKpJurisdictionWarning();
        });
        modalPurokEl.addEventListener('change', () => {
            if (modalPurokEl.value === 'Non-Resident') {
                modalIsTenantEl.value = '2';
            } else if (modalIsTenantEl.value === '2') {
                modalIsTenantEl.value = '0'; // Reset to Permanent Resident when picking a Tumana purok
            }
            checkRespondentKpJurisdictionWarning();
        });

        document.getElementById('modalAddress')?.addEventListener('input', () => {
            checkRespondentKpJurisdictionWarning();
        });
        document.getElementById('modalRespondentKpExceptionCheck')?.addEventListener('change', function() {
            if (this.checked) {
                const bMsg = document.getElementById('modalRespondentKpBlockedMsg');
                if (bMsg) bMsg.style.display = 'none';
            }
        });
    }

    // Party Residence Address Map controller
    let partyMapInstance = null;
    let partyMapMarker = null;
    let partyMapLookupVersion = 0;

    function isWithinTumana(lat, lng) {
        const poly = window.tumanaBoundary || [
            [14.6554682,121.0857081],[14.6562612,121.0859908],[14.6557911,121.0865123],[14.6566853,121.0867891],
            [14.6573361,121.0874608],[14.6566672,121.0882081],[14.6596216,121.0912009],[14.6605249,121.0911456],
            [14.6609324,121.0914765],[14.6617729,121.0920319],[14.6634173,121.0935248],[14.6639892,121.0936321],
            [14.6643486,121.0936995],[14.6645004,121.0938826],[14.6649347,121.0948585],[14.6652695,121.0952371],
            [14.6652424,121.0956829],[14.6648805,121.0961861],[14.664908,121.0963356],[14.6645002,121.0966408],
            [14.6642299,121.0967374],[14.6637829,121.0977901],[14.6629907,121.0988145],[14.6625832,121.0991486],
            [14.6625136,121.0996819],[14.6625395,121.100022],[14.6623861,121.1006225],[14.6621551,121.1005299],
            [14.6614757,121.1023379],[14.6602368,121.1019108],[14.6595948,121.1016599],[14.6589154,121.1019614],
            [14.6581343,121.1022551],[14.6574788,121.102553],[14.6569962,121.1027094],[14.6566377,121.102703],
            [14.656158,121.1026733],[14.6559169,121.1026982],[14.6553478,121.1027577],[14.6550262,121.1027938],
            [14.6548318,121.1027249],[14.6541841,121.1025018],[14.6534713,121.1024013],[14.6532707,121.102373],
            [14.6510737,121.101336],[14.6508768,121.1007973],[14.6507211,121.0992879],[14.650753,121.0989571],
            [14.6509538,121.098811],[14.6514718,121.0983827],[14.6521496,121.0978851],[14.6527322,121.0972236],
            [14.6535364,121.0959443],[14.6539003,121.0955034],[14.654028,121.0953166],[14.6537619,121.0950108],
            [14.6533329,121.0942299],[14.6530062,121.0934184]
        ];
        let inside = false;
        for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
            const xi = poly[i][0], yi = poly[i][1];
            const xj = poly[j][0], yj = poly[j][1];
            const intersect = ((yi > lng) !== (yj > lng)) && (lat < (xj - xi) * (lng - yi) / (yj - yi) + xi);
            if (intersect) inside = !inside;
        }
        return inside;
    }
    window.isWithinTumana = isWithinTumana;

    function autoDetectPurokFromAddress(addr) {
        if (!addr) return '';
        const lower = addr.toLowerCase();
        
        // Check for non-resident indicators
        const outsideKeywords = [
            'concepcion', 'malanday', 'nangka', 'parang', 'marikina heights', 'fortune',
            'sto. niño', 'sto nino', 'san roque', 'santa elena', 'sta. elena', 'barangka',
            'tañong', 'tanong', 'jesus dela peña', 'ivc', 'industrial valley', 'pasig',
            'quezon city', 'antipolo', 'san mateo', 'montalban', 'cainta', 'manila'
        ];
        for (const kw of outsideKeywords) {
            if (new RegExp('\\b' + kw.replace('.', '\\.') + '\\b', 'i').test(lower)) {
                return 'Non-Resident';
            }
        }

        const purokMap = [
            { key: 'dona petra', val: 'Doña Petra' },
            { key: 'doña petra', val: 'Doña Petra' },
            { key: 'bagong farmers', val: 'Bagong Farmers' },
            { key: 'farmers', val: 'Bagong Farmers' },
            { key: 'bukang liwayway', val: 'Bukang Liwayway' },
            { key: 'libis tumana', val: 'Libis Tumana' },
            { key: 'libis', val: 'Libis Tumana' },
            { key: 'bagong purok', val: 'Bagong Purok' },
            { key: 'palay', val: 'Palay' },
            { key: 'mais', val: 'Mais' },
            { key: 'singkamas', val: 'Singkamas' },
            { key: 'purok 1', val: 'Purok 1' },
            { key: 'purok 2', val: 'Purok 2' },
            { key: 'purok 3', val: 'Purok 3' },
            { key: 'purok 4', val: 'Purok 4' },
            { key: 'purok 5', val: 'Purok 5' },
            { key: 'purok 6', val: 'Purok 6' },
            { key: 'purok 7', val: 'Purok 7' },
            { key: 'purok 8', val: 'Purok 8' },
            { key: 'sampaguita', val: 'Purok 1' },
            { key: 'rosal', val: 'Purok 2' },
            { key: 'kalayaan', val: 'Purok 3' }
        ];

        for (const item of purokMap) {
            if (new RegExp('\\b' + item.key + '\\b', 'i').test(lower)) {
                return item.val;
            }
        }
        return '';
    }

    function clearModalValidationErrors() {
        const errorBox = document.getElementById('modalPartyValidationError');
        if (errorBox) errorBox.style.display = 'none';
        const errorList = document.getElementById('modalPartyValidationList');
        if (errorList) errorList.innerHTML = '';
        document.querySelectorAll('.party-modal-body .field-error-highlight').forEach(el => {
            el.classList.remove('field-error-highlight');
        });
    }

    function showModalValidationErrors(errors, errorFieldIds) {
        const errorBox = document.getElementById('modalPartyValidationError');
        const errorList = document.getElementById('modalPartyValidationList');
        if (errorBox && errorList) {
            errorList.innerHTML = errors.map(msg => `<li>${escapeHtml(msg)}</li>`).join('');
            errorBox.style.display = 'block';
            errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        errorFieldIds.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.classList.add('field-error-highlight');
                const clearFn = () => {
                    el.classList.remove('field-error-highlight');
                    el.removeEventListener('input', clearFn);
                    el.removeEventListener('change', clearFn);
                };
                el.addEventListener('input', clearFn);
                el.addEventListener('change', clearFn);
            }
        });
        if (errorFieldIds.length > 0) {
            document.getElementById(errorFieldIds[0])?.focus();
        }
        window.agapNotify?.('Pakikumpleto ang mga kinakailangang impormasyon sa modal.', 'error');
    }

    function initPartyAddressMap() {
        const container = document.getElementById('modalPartyAddressMap');
        if (!container || !window.L) return;

        if (!partyMapInstance) {
            partyMapInstance = L.map(container).setView([14.6565, 121.0945], 16);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors',
                maxZoom: 19
            }).addTo(partyMapInstance);

            const boundary = window.tumanaBoundary || [[14.6554682,121.0857081],[14.6562612,121.0859908],[14.6557911,121.0865123],[14.6566853,121.0867891],[14.6573361,121.0874608],[14.6566672,121.0882081],[14.6596216,121.0912009],[14.6605249,121.0911456],[14.6609324,121.0914765],[14.6617729,121.0920319],[14.6634173,121.0935248],[14.6639892,121.0936321],[14.6643486,121.0936995],[14.6645004,121.0938826],[14.6649347,121.0948585],[14.6652695,121.0952371],[14.6652424,121.0956829],[14.6648805,121.0961861],[14.664908,121.0963356],[14.6645002,121.0966408],[14.6642299,121.0967374],[14.6637829,121.0977901],[14.6629907,121.0988145],[14.6625832,121.0991486],[14.6625136,121.0996819],[14.6625395,121.100022],[14.6623861,121.1006225],[14.6621551,121.1005299],[14.6614757,121.1023379],[14.6602368,121.1019108],[14.6595948,121.1016599],[14.6589154,121.1019614],[14.6581343,121.1022551],[14.6574788,121.102553],[14.6569962,121.1027094],[14.6566377,121.102703],[14.656158,121.1026733],[14.6559169,121.1026982],[14.6553478,121.1027577],[14.6550262,121.1027938],[14.6548318,121.1027249],[14.6541841,121.1025018],[14.6534713,121.1024013],[14.6532707,121.102373],[14.6510737,121.101336],[14.6508768,121.1007973],[14.6507211,121.0992879],[14.650753,121.0989571],[14.6509538,121.098811],[14.6514718,121.0983827],[14.6521496,121.0978851],[14.6527322,121.0972236],[14.6535364,121.0959443],[14.6539003,121.0955034],[14.654028,121.0953166],[14.6537619,121.0950108],[14.6533329,121.0942299],[14.6530062,121.0934184]];
            L.polygon(boundary, {
                color: '#2563eb',
                weight: 2,
                fillColor: '#60a5fa',
                fillOpacity: 0.10,
                interactive: false
            }).addTo(partyMapInstance);

            partyMapInstance.on('click', (e) => {
                setPartyAddressMapPoint(e.latlng.lat, e.latlng.lng);
            });

            document.getElementById('btnRecenterPartyMap')?.addEventListener('click', (e) => {
                e.preventDefault();
                partyMapInstance.setView([14.6565, 121.0945], 16);
                partyMapInstance.invalidateSize();
            });

            document.getElementById('btnClearPartyMap')?.addEventListener('click', (e) => {
                e.preventDefault();
                if (partyMapMarker && partyMapInstance) {
                    partyMapInstance.removeLayer(partyMapMarker);
                    partyMapMarker = null;
                }
                const latEl = document.getElementById('modalLatitude');
                if (latEl) latEl.value = '';
                const lngEl = document.getElementById('modalLongitude');
                if (lngEl) lngEl.value = '';
                if (partyMapInstance) {
                    partyMapInstance.setView([14.6565, 121.0945], 16);
                    partyMapInstance.invalidateSize();
                }
                const statusEl = document.getElementById('modalPartyMapStatus');
                if (statusEl) statusEl.textContent = 'Pin cleared. Click on map to pin address.';
                const coordsEl = document.getElementById('modalPartyMapCoords');
                if (coordsEl) coordsEl.textContent = '';
            });
        }
    }

    async function setPartyAddressMapPoint(lat, lng, doReverseGeocode = true) {
        if (!partyMapInstance) return;

        if (!partyMapMarker) {
            partyMapMarker = L.marker([lat, lng], { draggable: true }).addTo(partyMapInstance);
            partyMapMarker.on('dragend', (e) => {
                const pos = e.target.getLatLng();
                setPartyAddressMapPoint(pos.lat, pos.lng, true);
            });
        } else {
            partyMapMarker.setLatLng([lat, lng]);
            if (!partyMapInstance.hasLayer(partyMapMarker)) {
                partyMapMarker.addTo(partyMapInstance);
            }
        }

        const latInput = document.getElementById('modalLatitude');
        const lngInput = document.getElementById('modalLongitude');
        if (latInput) latInput.value = lat;
        if (lngInput) lngInput.value = lng;

        const coordsEl = document.getElementById('modalPartyMapCoords');
        if (coordsEl) {
            coordsEl.textContent = `${lat.toFixed(6)}° N, ${lng.toFixed(6)}° E`;
        }

        if (!doReverseGeocode) {
            partyMapInstance.setView([lat, lng], 17);
            partyMapInstance.invalidateSize();
            const statusEl = document.getElementById('modalPartyMapStatus');
            if (statusEl) statusEl.textContent = 'Address pinned from saved profile coordinates.';
            return;
        }

        const statusEl = document.getElementById('modalPartyMapStatus');
        if (statusEl) statusEl.textContent = 'Locating complete address including house number...';

        const requestVersion = ++partyMapLookupVersion;

        try {
            const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}&zoom=18&addressdetails=1`, {
                headers: { 'Accept': 'application/json' }
            });
            const result = await response.json();
            if (requestVersion !== partyMapLookupVersion) return;

            const addr = result.address || {};

            // 1. House Number or Building / Unit
            let houseNo = addr.house_number || addr.building || '';
            const existingAddress = document.getElementById('modalAddress')?.value?.trim() || '';
            if (!houseNo && existingAddress) {
                const houseMatch = existingAddress.match(/^(?:(?:#|no\.?|unit|lot|blk|block)\s*[^,]+|[0-9]+[A-Za-z]?(?:\s*-\s*[0-9]+)?)\b/i);
                if (houseMatch) {
                    houseNo = houseMatch[0].trim();
                }
            }

            // 2. Road / Street
            const road = addr.road || addr.pedestrian || addr.footway || addr.street || addr.path || '';

            // 3. Street line with house number
            let streetPart = '';
            if (houseNo && road) {
                streetPart = road.toLowerCase().includes(houseNo.toLowerCase()) ? road : `${houseNo} ${road}`;
            } else {
                streetPart = houseNo || road;
            }

            // 4. Neighbourhood / Suburb / Purok
            const neighbourhood = addr.neighbourhood || addr.suburb || addr.quarter || addr.residential || addr.subdivision || '';

            // 5. Check if inside Barangay Tumana
            const checkTumana = window.isWithinTumana || isWithinTumana;
            const inTumana = (typeof checkTumana === 'function') ? checkTumana(lat, lng) : true;

            let barangay = '';
            if (inTumana) {
                barangay = 'Barangay Tumana';
            } else {
                barangay = addr.village || addr.city_district || '';
                if (!barangay && addr.suburb && addr.suburb.toLowerCase().includes('barangay')) {
                    barangay = addr.suburb;
                }
            }

            // 6. City
            const city = addr.city || addr.town || addr.municipality || 'Marikina City';

            // Assemble the WHOLE ADDRESS (include house number, street, purok, barangay, city)
            const addressParts = [];
            if (streetPart) addressParts.push(streetPart);
            if (neighbourhood && neighbourhood !== road && neighbourhood !== barangay) {
                addressParts.push(neighbourhood);
            }
            if (barangay) addressParts.push(barangay);
            if (city) addressParts.push(city);

            let wholeAddress = addressParts.join(', ');
            if (!wholeAddress && result.display_name) {
                wholeAddress = result.display_name;
            }

            const addressInput = document.getElementById('modalAddress');
            if (addressInput && wholeAddress) {
                isReverseGeocodingPartyMap = true;
                addressInput.value = wholeAddress;
                setTimeout(() => { isReverseGeocodingPartyMap = false; }, 400);
            }

            // Auto-update Purok & Residency Status based on map location
            const purokSelect = document.getElementById('modalPurok');
            const residencySelect = document.getElementById('modalIsTenant');

            if (inTumana) {
                if (purokSelect) {
                    const searchPool = (wholeAddress + ' ' + (result.display_name || '')).toLowerCase();
                    let matchedPurok = '';
                    for (const opt of purokSelect.options) {
                        if (!opt.value || opt.value === 'Non-Resident') continue;
                        const optLower = opt.value.toLowerCase();
                        if (searchPool.includes(optLower)) {
                            matchedPurok = opt.value;
                            break;
                        }
                        const words = optLower.split(/\s+/).filter(w => w.length > 2);
                        if (words.some(w => searchPool.includes(w))) {
                            matchedPurok = opt.value;
                            break;
                        }
                    }
                    if (matchedPurok) {
                        purokSelect.value = matchedPurok;
                    }
                }
                if (residencySelect && residencySelect.value === '2') {
                    residencySelect.value = '0'; // Reset Non-Resident to Permanent Resident
                }
            } else {
                // Outside Tumana -> automatically Non-Resident / Outside Tumana!
                if (purokSelect) purokSelect.value = 'Non-Resident';
                if (residencySelect) residencySelect.value = '2';
            }

            if (statusEl) {
                statusEl.textContent = `Address captured: ${wholeAddress}`;
            }
        } catch (err) {
            if (statusEl) statusEl.textContent = `Point pinned (${lat.toFixed(5)}, ${lng.toFixed(5)}). Complete house number can be edited above.`;
        }
    }

    let isReverseGeocodingPartyMap = false;
    let partyGeocodeTimer = null;

    const TUMANA_PUROK_COORDS = {
        'dona petra': [14.6578, 121.0935],
        'doña petra': [14.6578, 121.0935],
        'bagong farmers': [14.6612, 121.0955],
        'farmers': [14.6612, 121.0955],
        'bukang liwayway': [14.6588, 121.0982],
        'libis tumana': [14.6538, 121.0968],
        'libis': [14.6538, 121.0968],
        'bagong purok': [14.6598, 121.0942],
        'palay': [14.6558, 121.0998],
        'mais': [14.6572, 121.1008],
        'singkamas': [14.6548, 121.1018]
    };

    async function geocodeAndPinPartyAddress(addressText, preferPurok = '', force = false) {
        if (!partyMapInstance) initPartyAddressMap();
        if (!partyMapInstance) return;

        const addr = (addressText || document.getElementById('modalAddress')?.value || '').trim();
        const purok = (preferPurok || document.getElementById('modalPurok')?.value || '').trim();

        if (!addr && !purok) return;

        const statusEl = document.getElementById('modalPartyMapStatus');
        const coordsEl = document.getElementById('modalPartyMapCoords');
        if (statusEl) statusEl.textContent = 'Searching location on map...';

        const requestVersion = ++partyMapLookupVersion;

        // Build candidate search queries
        const queries = [];
        const isNonResident = (purok === 'Non-Resident') || (document.getElementById('modalIsTenant')?.value === '2');

        const clean = addr.replace(/\b(barangay|brgy\.?|bgy\.?|city)\b/gi, '').replace(/\s+/g, ' ').trim();
        const strippedStreet = clean.replace(/^(?:(?:#|no\.?|unit|lot|blk|block)\s*[^,]+|[0-9]+[A-Za-z]?(?:\s*-\s*[0-9]+)?)\s*,?\s*/i, '').trim();

        if (clean) {
            if (/tumana/i.test(clean)) {
                queries.push(`${clean}, Marikina`);
            } else if (!isNonResident) {
                queries.push(`${clean}, Tumana, Marikina`);
                queries.push(`${clean}, Marikina`);
            } else {
                queries.push(clean);
                queries.push(`${clean}, Metro Manila`);
            }
        }
        if (strippedStreet && strippedStreet !== clean && strippedStreet.length >= 3) {
            if (!isNonResident) {
                queries.push(`${strippedStreet}, Tumana, Marikina`);
                queries.push(`${strippedStreet}, Marikina`);
            } else {
                queries.push(`${strippedStreet}, Philippines`);
            }
        }
        if (purok && purok !== 'Non-Resident') {
            const cleanPurok = purok.replace(/\b(area|compound|sitio)\b/gi, '').trim();
            queries.push(`${cleanPurok}, Tumana, Marikina`);
            queries.push(`${cleanPurok}, Marikina`);
        }




        let foundLat = null;
        let foundLng = null;
        let matchedDisplay = '';

        for (const q of queries) {
            if (requestVersion !== partyMapLookupVersion) return;
            try {
                const res = await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=${encodeURIComponent(q)}`, {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await res.json();
                if (Array.isArray(data) && data.length > 0 && data[0].lat && data[0].lon) {
                    foundLat = parseFloat(data[0].lat);
                    foundLng = parseFloat(data[0].lon);
                    matchedDisplay = data[0].display_name || q;
                    break;
                }
            } catch (e) {
                // Continue to next query
            }
        }

        // Fallback to local Tumana Purok coordinates if Nominatim returned 0 or was unreachable
        if (foundLat === null && !isNonResident) {
            const searchPool = `${addr} ${purok}`.toLowerCase();
            for (const [key, coords] of Object.entries(TUMANA_PUROK_COORDS)) {
                if (searchPool.includes(key)) {
                    foundLat = coords[0];
                    foundLng = coords[1];
                    matchedDisplay = `${purok || key}, Barangay Tumana`;
                    break;
                }
            }
            if (foundLat === null && /tumana/i.test(addr)) {
                foundLat = 14.6565;
                foundLng = 121.0945;
                matchedDisplay = 'Barangay Tumana, Marikina City';
            }
        }

        if (requestVersion !== partyMapLookupVersion) return;

        if (foundLat !== null && foundLng !== null) {
            const latInput = document.getElementById('modalLatitude');
            const lngInput = document.getElementById('modalLongitude');
            if (latInput) latInput.value = foundLat;
            if (lngInput) lngInput.value = foundLng;

            if (!partyMapMarker) {
                partyMapMarker = L.marker([foundLat, foundLng], { draggable: true }).addTo(partyMapInstance);
                partyMapMarker.on('dragend', (e) => {
                    const pos = e.target.getLatLng();
                    setPartyAddressMapPoint(pos.lat, pos.lng);
                });
            } else {
                partyMapMarker.setLatLng([foundLat, foundLng]);
                if (!partyMapInstance.hasLayer(partyMapMarker)) {
                    partyMapMarker.addTo(partyMapInstance);
                }
            }

            partyMapInstance.setView([foundLat, foundLng], 17);
            partyMapInstance.invalidateSize();

            if (coordsEl) {
                coordsEl.textContent = `${foundLat.toFixed(6)}° N, ${foundLng.toFixed(6)}° E`;
            }
            if (statusEl) {
                statusEl.textContent = `Address pinned on map: ${addr || matchedDisplay}`;
            }
        } else {
            if (statusEl) {
                statusEl.textContent = 'Could not pinpoint address automatically. Click on map to place pin.';
            }
        }
    }
    window.geocodeAndPinPartyAddress = geocodeAndPinPartyAddress;

    // Address input events for auto-pinning
    const modalAddrInput = document.getElementById('modalAddress');
    if (modalAddrInput) {
        modalAddrInput.addEventListener('input', () => {
            const curPurok = document.getElementById('modalPurok')?.value;
            if (!curPurok && modalAddrInput.value) {
                const detected = autoDetectPurokFromAddress(modalAddrInput.value);
                if (detected) {
                    const pEl = document.getElementById('modalPurok');
                    if (pEl) {
                        pEl.value = detected;
                        if (detected === 'Non-Resident') {
                            const isTen = document.getElementById('modalIsTenant');
                            if (isTen) isTen.value = '2';
                        }
                        checkRespondentKpJurisdictionWarning();
                    }
                }
            }
            if (isReverseGeocodingPartyMap) return;
            clearTimeout(partyGeocodeTimer);
            partyGeocodeTimer = setTimeout(() => {
                geocodeAndPinPartyAddress(modalAddrInput.value);
            }, 600);
        });
        modalAddrInput.addEventListener('change', () => {
            if (isReverseGeocodingPartyMap) return;
            clearTimeout(partyGeocodeTimer);
            partyGeocodeTimer = setTimeout(() => {
                geocodeAndPinPartyAddress(modalAddrInput.value);
            }, 200);
        });
    }

    document.getElementById('btnPinPartyAddress')?.addEventListener('click', (e) => {
        e.preventDefault();
        geocodeAndPinPartyAddress(document.getElementById('modalAddress')?.value, '', true);
    });

    const modalPurokSelectEl = document.getElementById('modalPurok');
    if (modalPurokSelectEl) {
        modalPurokSelectEl.addEventListener('change', () => {
            if (isReverseGeocodingPartyMap) return;
            const currentAddr = document.getElementById('modalAddress')?.value?.trim() || '';
            clearTimeout(partyGeocodeTimer);
            partyGeocodeTimer = setTimeout(() => {
                geocodeAndPinPartyAddress(currentAddr, modalPurokSelectEl.value);
            }, 300);
        });
    }

    // Save Party from Modal
    document.getElementById('btnSavePartyModal')?.addEventListener('click', () => {
        clearModalValidationErrors();

        let firstName = document.getElementById('modalFirstName')?.value?.trim() || '';
        let lastName  = document.getElementById('modalLastName')?.value?.trim() || '';

        // Fallback: If user entered the full name into the Quick-Fill search box instead of First/Last Name inputs
        if ((!firstName || !lastName) && modalSearchInput?.value?.trim()) {
            const rawSearch = modalSearchInput.value.trim();
            if (rawSearch.includes(',')) {
                const parts = rawSearch.split(',').map(s => s.trim());
                if (parts.length >= 2) {
                    if (!lastName) {
                        lastName = parts[0];
                        const lEl = document.getElementById('modalLastName');
                        if (lEl) lEl.value = lastName;
                    }
                    if (!firstName) {
                        firstName = parts[1].split(' ')[0];
                        const fEl = document.getElementById('modalFirstName');
                        if (fEl) fEl.value = firstName;
                    }
                }
            } else {
                const parts = rawSearch.split(/\s+/);
                if (parts.length >= 2) {
                    if (!firstName) {
                        firstName = parts[0];
                        const fEl = document.getElementById('modalFirstName');
                        if (fEl) fEl.value = firstName;
                    }
                    if (!lastName) {
                        lastName = parts.slice(1).join(' ');
                        const lEl = document.getElementById('modalLastName');
                        if (lEl) lEl.value = lastName;
                    }
                }
            }
        }

        const errors = [];
        const errorFieldIds = [];

        if (!firstName) {
            errors.push('Ilagay ang First Name (Pangalan).');
            errorFieldIds.push('modalFirstName');
        }

        if (!lastName) {
            errors.push('Ilagay ang Last Name (Apelyido).');
            errorFieldIds.push('modalLastName');
        }

        let purok = document.getElementById('modalPurok')?.value?.trim() || '';
        let isTenant = document.getElementById('modalIsTenant')?.value;
        let address = document.getElementById('modalAddress')?.value?.trim() || '';

        // If purok is missing, attempt auto-detect from street address
        if (!purok && address) {
            purok = autoDetectPurokFromAddress(address);
            if (purok) {
                const pEl = document.getElementById('modalPurok');
                if (pEl) pEl.value = purok;
            }
        }

        if (!purok) {
            errors.push('Piliin ang Purok / Area sa ilalim ng Residence Details.');
            errorFieldIds.push('modalPurok');
        }

        if (isTenant === '' || isTenant === undefined || isTenant === null) {
            isTenant = (purok === 'Non-Resident') ? '2' : '0';
            const tEl = document.getElementById('modalIsTenant');
            if (tEl) tEl.value = isTenant;
        }

        if (!address) {
            errors.push('Ilagay ang Complete Street Address sa ilalim ng Residence Details.');
            errorFieldIds.push('modalAddress');
        }

        if (currentModalRole === 'respondent') {
            const isNonResident = (purok === 'Non-Resident' || isTenant === '2');
            if (isNonResident) {
                const exceptionChecked = document.getElementById('modalRespondentKpExceptionCheck')?.checked;
                if (!exceptionChecked) {
                    const blockedMsg = document.getElementById('modalRespondentKpBlockedMsg');
                    if (blockedMsg) blockedMsg.style.display = 'block';

                    const warningBox = document.getElementById('modalRespondentKpWarning');
                    if (warningBox) {
                        warningBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        warningBox.style.boxShadow = '0 0 0 3px rgba(220, 38, 38, 0.45)';
                        setTimeout(() => { if (warningBox) warningBox.style.boxShadow = 'none'; }, 2500);
                    }

                    errors.push('Babala sa Non-Resident Respondent: Ayon sa KP Law (Sec. 409), lagyan ng tsek ang "Kumpirmasyon ng Hurisdiksyon" kung may legal na eksepsiyon (ari-arian o trabaho sa Tumana).');
                    errorFieldIds.push('modalRespondentKpExceptionCheck');
                }
            }
        }

        const minorEl = document.getElementById('modalIsMinor');
        const isMinor = (minorEl?.type === 'checkbox') ? !!minorEl?.checked : (minorEl?.value === '1');
        if (isMinor) {
            const gName = document.getElementById('modalGuardianName')?.value?.trim();
            const gRel  = document.getElementById('modalGuardianRelationship')?.value?.trim();
            if (!gName) {
                errors.push('Dahil menor de edad (< 18), kailangan ang buong pangalan ng Legal Guardian.');
                errorFieldIds.push('modalGuardianName');
            }
            if (!gRel) {
                errors.push('Tukuyin ang relasyon ng Legal Guardian sa menor de edad (hal. Nanay, Tatay, Tagapangalaga).');
                errorFieldIds.push('modalGuardianRelationship');
            }
        }

        if (errors.length > 0) {
            showModalValidationErrors(errors, errorFieldIds);
            return;
        }

        const partyData = {
            resident_id: document.getElementById('modalResidentId')?.value?.trim() || '',
            first_name: firstName,
            middle_name: document.getElementById('modalMiddleName')?.value?.trim() || '',
            last_name: lastName,
            suffix: document.getElementById('modalSuffix')?.value?.trim() || '',
            birthdate: document.getElementById('modalBirthdate')?.value || '',
            age: document.getElementById('modalAge')?.value || '',
            gender: document.getElementById('modalGender')?.value || '',
            civil_status: document.getElementById('modalCivilStatus')?.value || '',
            occupation: '',
            contact_no: document.getElementById('modalContactNo')?.value?.trim() || '',
            email: document.getElementById('modalEmail')?.value?.trim() || '',
            preferred_contact: 'SMS',
            purok: document.getElementById('modalPurok')?.value || '',
            is_tenant: document.getElementById('modalIsTenant')?.value || '0',
            address: document.getElementById('modalAddress')?.value?.trim() || '',
            home_latitude: document.getElementById('modalLatitude')?.value || null,
            home_longitude: document.getElementById('modalLongitude')?.value || null,
            is_minor: !!isMinor,
            guardian_name: isMinor ? (document.getElementById('modalGuardianName')?.value?.trim() || '') : '',
            guardian_relationship: isMinor ? (document.getElementById('modalGuardianRelationship')?.value?.trim() || '') : '',
            guardian_contact: isMinor ? (document.getElementById('modalGuardianContact')?.value?.trim() || '') : '',
            guardian_address: isMinor ? (document.getElementById('modalGuardianAddress')?.value?.trim() || '') : '',
            contact_unknown: document.getElementById('modalContactUnknown')?.checked || false,
            middle_unknown: document.getElementById('modalMiddleUnknown')?.checked || false,
            birth_unknown: document.getElementById('modalBirthUnknown')?.checked || false,
            kp_jurisdiction_exception: (currentModalRole === 'respondent' && (purok === 'Non-Resident' || isTenant === '2')) ? (document.getElementById('modalRespondentKpExceptionCheck')?.checked ? 1 : 0) : 0,
        };

        if (currentModalRole === 'complainant') {
            if (currentModalMode === 'add') {
                complainantsList.push(partyData);
            } else if (currentModalIndex !== null && complainantsList[currentModalIndex]) {
                complainantsList[currentModalIndex] = partyData;
            } else {
                complainantsList[0] = partyData;
            }
        } else {
            if (currentModalMode === 'add') {
                respondentsList.push(partyData);
            } else if (currentModalIndex !== null && respondentsList[currentModalIndex]) {
                respondentsList[currentModalIndex] = partyData;
            } else {
                respondentsList[0] = partyData;
            }
        }

        window.closePartyModal();
        syncHiddenPartyInputs();
        renderParties();
        window.validateDistinctParties?.();
        triggerDuplicateCheck();

        const roleText = currentModalRole === 'complainant' ? 'Complainant' : 'Respondent';
        window.agapNotify?.(`Matagumpay na naitala si ${partyData.first_name} ${partyData.last_name} bilang ${roleText}.`, 'success');
    });

    window.removeParty = function(role, index) {
        if (role === 'complainant') {
            if (complainantsList.length <= 1) {
                if (!confirm('Are you sure you want to remove this complainant? A complaint requires at least one complainant.')) {
                    return;
                }
            }
            complainantsList.splice(index, 1);
        } else {
            if (respondentsList.length <= 1) {
                if (!confirm('Are you sure you want to remove this respondent? A complaint requires at least one respondent.')) {
                    return;
                }
            }
            respondentsList.splice(index, 1);
        }
        syncHiddenPartyInputs();
        renderParties();
        window.validateDistinctParties?.();
        triggerDuplicateCheck();
    };

    function syncHiddenPartyInputs() {
        // Sync primary complainant
        const comp = complainantsList[0] || null;
        const setComp = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.value = val ?? '';
        };

        if (comp) {
            const compFullName = [comp.first_name, comp.middle_name, comp.last_name, comp.suffix].filter(Boolean).join(' ');
            setComp('complainantResidentId', comp.resident_id);
            setComp('complainantName', compFullName);
            setComp('complainantFirstName', comp.first_name);
            setComp('complainantMiddleName', comp.middle_name);
            setComp('complainantLastName', comp.last_name);
            setComp('complainantSuffix', comp.suffix);
            setComp('complainantBirthdate', comp.birthdate);
            setComp('complainantAge', comp.age);
            setComp('complainantGender', comp.gender);
            setComp('complainantCivilStatus', comp.civil_status);
            setComp('complainantOccupation', comp.occupation);
            setComp('complainantContactNo', comp.contact_no);
            setComp('complainantEmail', comp.email);
            setComp('complainantPreferredContact', comp.preferred_contact);
            setComp('complainantPurok', comp.purok);
            setComp('complainantIsTenant', comp.is_tenant);
            setComp('complainantAddress', comp.address);
            setComp('complainantLatitude', comp.home_latitude ?? comp.latitude ?? '');
            setComp('complainantLongitude', comp.home_longitude ?? comp.longitude ?? '');
            setComp('complainantIsMinor', comp.is_minor ? '1' : '0');
            setComp('complainantGuardianName', comp.guardian_name);
            setComp('complainantGuardianRelationship', comp.guardian_relationship);
            setComp('complainantGuardianContact', comp.guardian_contact);
            setComp('complainantGuardianAddress', comp.guardian_address);
        } else {
            ['complainantResidentId', 'complainantName', 'complainantFirstName', 'complainantMiddleName',
             'complainantLastName', 'complainantSuffix', 'complainantBirthdate', 'complainantAge',
             'complainantGender', 'complainantCivilStatus', 'complainantOccupation', 'complainantContactNo',
             'complainantEmail', 'complainantPurok', 'complainantAddress', 'complainantLatitude', 'complainantLongitude', 'complainantGuardianName',
             'complainantGuardianRelationship', 'complainantGuardianContact', 'complainantGuardianAddress'].forEach(id => setComp(id, ''));
            setComp('complainantPreferredContact', 'SMS');
            setComp('complainantIsTenant', '0');
            setComp('complainantIsMinor', '0');
        }

        // Sync primary respondent
        const resp = respondentsList[0] || null;
        const setResp = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.value = val ?? '';
        };

        if (resp) {
            const respFullName = [resp.first_name, resp.middle_name, resp.last_name, resp.suffix].filter(Boolean).join(' ');
            setResp('respondentResidentId', resp.resident_id);
            setResp('respondentName', respFullName);
            setResp('respondentFirstName', resp.first_name);
            setResp('respondentMiddleName', resp.middle_name);
            setResp('respondentLastName', resp.last_name);
            setResp('respondentSuffix', resp.suffix);
            setResp('respondentBirthdate', resp.birthdate);
            setResp('respondentAge', resp.age);
            setResp('respondentGender', resp.gender);
            setResp('respondentCivilStatus', resp.civil_status);
            setResp('respondentOccupation', resp.occupation);
            setResp('respondentContactNo', resp.contact_no);
            setResp('respondentEmail', resp.email);
            setResp('respondentPurok', resp.purok);
            setResp('respondentIsTenant', resp.is_tenant);
            setResp('respondentAddress', resp.address);
            setResp('respondentLatitude', resp.home_latitude ?? resp.latitude ?? '');
            setResp('respondentLongitude', resp.home_longitude ?? resp.longitude ?? '');
            setResp('respondentIsMinor', resp.is_minor ? '1' : '0');
            setResp('respondentGuardianName', resp.guardian_name);
            setResp('respondentGuardianRelationship', resp.guardian_relationship);
            setResp('respondentGuardianContact', resp.guardian_contact);
            setResp('respondentGuardianAddress', resp.guardian_address);
            setResp('respContactUnknown', resp.contact_unknown ? '1' : '0');
            setResp('respMiddleUnknown', resp.middle_unknown ? '1' : '0');
            setResp('respBirthUnknown', resp.birth_unknown ? '1' : '0');
        } else {
            ['respondentResidentId', 'respondentName', 'respondentFirstName', 'respondentMiddleName',
             'respondentLastName', 'respondentSuffix', 'respondentBirthdate', 'respondentAge',
             'respondentGender', 'respondentCivilStatus', 'respondentOccupation', 'respondentContactNo',
             'respondentEmail', 'respondentPurok', 'respondentAddress', 'respondentLatitude', 'respondentLongitude', 'respondentGuardianName',
             'respondentGuardianRelationship', 'respondentGuardianContact', 'respondentGuardianAddress',
             'respContactUnknown', 'respMiddleUnknown', 'respBirthUnknown'].forEach(id => setResp(id, ''));
            setResp('respondentIsTenant', '0');
            setResp('respondentIsMinor', '0');
        }

        // Sync additional parties container
        const addContainer = document.getElementById('additionalPartiesHiddenContainer');
        if (addContainer) {
            let html = '';
            // Additional complainants (index 1+)
            for (let i = 1; i < complainantsList.length; i++) {
                const p = complainantsList[i];
                const fn = [p.first_name, p.middle_name, p.last_name, p.suffix].filter(Boolean).join(' ');
                html += `
                    <input type="hidden" name="party_types[]" value="Complainant">
                    <input type="hidden" name="party_resident_ids[]" value="${escapeHtml(p.resident_id || '')}">
                    <input type="hidden" name="party_names[]" value="${escapeHtml(fn)}">
                    <input type="hidden" name="party_first_names[]" value="${escapeHtml(p.first_name || '')}">
                    <input type="hidden" name="party_middle_names[]" value="${escapeHtml(p.middle_name || '')}">
                    <input type="hidden" name="party_last_names[]" value="${escapeHtml(p.last_name || '')}">
                    <input type="hidden" name="party_suffixes[]" value="${escapeHtml(p.suffix || '')}">
                    <input type="hidden" name="party_contacts[]" value="${escapeHtml(p.contact_no || '')}">
                    <input type="hidden" name="party_addresses[]" value="${escapeHtml(p.address || '')}">
                    <input type="hidden" name="party_puroks[]" value="${escapeHtml(p.purok || '')}">
                    <input type="hidden" name="party_latitudes[]" value="${escapeHtml(p.home_latitude ?? p.latitude ?? '')}">
                    <input type="hidden" name="party_longitudes[]" value="${escapeHtml(p.home_longitude ?? p.longitude ?? '')}">
                `;
            }
            // Additional respondents (index 1+)
            for (let i = 1; i < respondentsList.length; i++) {
                const p = respondentsList[i];
                const fn = [p.first_name, p.middle_name, p.last_name, p.suffix].filter(Boolean).join(' ');
                html += `
                    <input type="hidden" name="party_types[]" value="Respondent">
                    <input type="hidden" name="party_resident_ids[]" value="${escapeHtml(p.resident_id || '')}">
                    <input type="hidden" name="party_names[]" value="${escapeHtml(fn)}">
                    <input type="hidden" name="party_first_names[]" value="${escapeHtml(p.first_name || '')}">
                    <input type="hidden" name="party_middle_names[]" value="${escapeHtml(p.middle_name || '')}">
                    <input type="hidden" name="party_last_names[]" value="${escapeHtml(p.last_name || '')}">
                    <input type="hidden" name="party_suffixes[]" value="${escapeHtml(p.suffix || '')}">
                    <input type="hidden" name="party_contacts[]" value="${escapeHtml(p.contact_no || '')}">
                    <input type="hidden" name="party_addresses[]" value="${escapeHtml(p.address || '')}">
                    <input type="hidden" name="party_puroks[]" value="${escapeHtml(p.purok || '')}">
                    <input type="hidden" name="party_latitudes[]" value="${escapeHtml(p.home_latitude ?? p.latitude ?? '')}">
                    <input type="hidden" name="party_longitudes[]" value="${escapeHtml(p.home_longitude ?? p.longitude ?? '')}">
                `;
            }
            addContainer.innerHTML = html;
        }
    }

    function renderParties() {
        renderComplainants();
        renderRespondents();
    }

    function renderComplainants() {
        const listEl = document.getElementById('complainantsSummaryList');
        if (!listEl) return;

        if (complainantsList.length === 0) {
            listEl.innerHTML = `
                <div class="party-empty-card" onclick="openPartyModal('complainant', 'add')" style="border-color:#bae6fd; background:#f0f9ff; color:#0369a1;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                    <div>
                        <strong>No complainant recorded yet</strong>
                        <div style="font-size: 0.78rem; color: #0284c7; margin-top:2px;">Click here or "+ Add Complainant" to fill in complainant details</div>
                    </div>
                </div>
            `;
        } else {
            listEl.innerHTML = complainantsList.map((c, idx) => {
                const initials = ((c.first_name?.[0] || '') + (c.last_name?.[0] || '')).toUpperCase() || 'C';
                const fullName = [c.first_name, c.middle_name, c.last_name, c.suffix].filter(Boolean).join(' ');
                const roleLabel = idx === 0 ? 'Primary Complainant' : `Additional Complainant #${idx + 1}`;
                const rolePillClass = idx === 0 ? 'primary-complainant' : 'additional-party';

                const chips = [];
                if (c.contact_no) {
                    chips.push(`<span class="party-detail-chip"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg> Tel: ${escapeHtml(c.contact_no)}</span>`);
                }
                if (c.purok || c.address) {
                    const addrArr = [];
                    if (c.address) addrArr.push(c.address);
                    if (c.purok && (!c.address || !c.address.includes(c.purok))) addrArr.push(c.purok);
                    chips.push(`<span class="party-detail-chip"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg> ${escapeHtml(addrArr.join(', '))}</span>`);
                }
                const demo = [];
                if (c.age) demo.push(`${escapeHtml(c.age)} yrs old`);
                if (c.gender) demo.push(escapeHtml(c.gender));
                if (c.civil_status) demo.push(escapeHtml(c.civil_status));
                if (demo.length) {
                    chips.push(`<span class="party-detail-chip"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg> ${demo.join(' • ')}</span>`);
                }
                if (c.is_minor && c.guardian_name) {
                    chips.push(`<span class="party-detail-chip chip-accent">Minor (Guardian: ${escapeHtml(c.guardian_name)}${c.guardian_relationship ? ' - ' + escapeHtml(c.guardian_relationship) : ''})</span>`);
                }

                return `
                    <div class="party-summary-card is-complainant-card">
                        <div class="party-summary-top">
                            <div class="party-summary-left">
                                <div class="party-avatar-badge is-complainant">${escapeHtml(initials)}</div>
                                <div class="party-summary-info">
                                    <div class="party-summary-title-row">
                                        <span class="party-summary-name">${escapeHtml(fullName)}</span>
                                        <span class="party-role-pill ${rolePillClass}">${roleLabel}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="party-summary-actions">
                                <button type="button" class="btn-summary-action" onclick="openPartyModal('complainant', 'edit', ${idx})" title="Edit Details">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    <span>Edit</span>
                                </button>
                                <button type="button" class="btn-summary-action remove-party-action" onclick="removeParty('complainant', ${idx})" title="Remove Party">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                    <span>Remove</span>
                                </button>
                            </div>
                        </div>
                        ${chips.length ? `<div class="party-summary-details">${chips.join('')}</div>` : ''}
                    </div>
                `;
            }).join('');
        }

        const addBtn = document.getElementById('btnAddComplainantModal');
        const isMultiple = document.getElementById('chkMultipleComplainants')?.checked;
        if (addBtn) {
            if (complainantsList.length === 0) {
                addBtn.style.display = 'inline-flex';
                addBtn.textContent = '+ Add Complainant';
            } else if (isMultiple) {
                addBtn.style.display = 'inline-flex';
                addBtn.textContent = '+ Add Another Complainant';
            } else {
                addBtn.style.display = 'none';
            }
        }
    }

    function renderRespondents() {
        const listEl = document.getElementById('respondentsSummaryList');
        if (!listEl) return;

        if (respondentsList.length === 0) {
            listEl.innerHTML = `
                <div class="party-empty-card" onclick="openPartyModal('respondent', 'add')" style="border-color:#fecaca; background:#fff1f2; color:#be123c;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="17" y1="8" x2="23" y2="14"/><line x1="23" y1="8" x2="17" y2="14"/></svg>
                    <div>
                        <strong>No respondent recorded yet</strong>
                        <div style="font-size: 0.78rem; color: #be123c; margin-top:2px;">Click here or "+ Add Respondent" to fill in respondent details</div>
                    </div>
                </div>
            `;
        } else {
            listEl.innerHTML = respondentsList.map((r, idx) => {
                const initials = ((r.first_name?.[0] || '') + (r.last_name?.[0] || '')).toUpperCase() || 'R';
                const fullName = [r.first_name, r.middle_name, r.last_name, r.suffix].filter(Boolean).join(' ');
                const roleLabel = idx === 0 ? 'Primary Respondent' : `Additional Respondent #${idx + 1}`;
                const rolePillClass = idx === 0 ? 'primary-respondent' : 'additional-party';

                const chips = [];
                if (r.contact_unknown) {
                    chips.push('<span class="party-detail-chip">Tel: Contact Unknown</span>');
                } else if (r.contact_no) {
                    chips.push(`<span class="party-detail-chip"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg> Tel: ${escapeHtml(r.contact_no)}</span>`);
                }
                if (r.purok || r.address) {
                    const addrArr = [];
                    if (r.address) addrArr.push(r.address);
                    if (r.purok && (!r.address || !r.address.includes(r.purok))) addrArr.push(r.purok);
                    chips.push(`<span class="party-detail-chip"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg> ${escapeHtml(addrArr.join(', '))}</span>`);
                }
                const demo = [];
                if (r.birth_unknown) {
                    demo.push('Birthdate Unknown');
                } else if (r.age) {
                    demo.push(`${escapeHtml(r.age)} yrs old`);
                }
                if (r.gender) demo.push(escapeHtml(r.gender));
                if (r.civil_status) demo.push(escapeHtml(r.civil_status));
                if (demo.length) {
                    chips.push(`<span class="party-detail-chip"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg> ${demo.join(' • ')}</span>`);
                }
                if (r.is_minor && r.guardian_name) {
                    chips.push(`<span class="party-detail-chip chip-accent">Minor (Guardian: ${escapeHtml(r.guardian_name)}${r.guardian_relationship ? ' - ' + escapeHtml(r.guardian_relationship) : ''})</span>`);
                }

                const exceptionPill = (r.purok === "Non-Resident" || r.is_tenant === "2") && r.kp_jurisdiction_exception 
                    ? '<span class="party-role-pill" style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-size: 0.68rem;">KP Sec. 409(c/d) Exception</span>' 
                    : '';

                return `
                    <div class="party-summary-card is-respondent-card">
                        <div class="party-summary-top">
                            <div class="party-summary-left">
                                <div class="party-avatar-badge is-respondent">${escapeHtml(initials)}</div>
                                <div class="party-summary-info">
                                    <div class="party-summary-title-row">
                                        <span class="party-summary-name">${escapeHtml(fullName)}</span>
                                        <span class="party-role-pill ${rolePillClass}">${roleLabel}</span>
                                        ${exceptionPill}
                                    </div>
                                </div>
                            </div>
                            <div class="party-summary-actions">
                                <button type="button" class="btn-summary-action" onclick="openPartyModal('respondent', 'edit', ${idx})" title="Edit Details">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    <span>Edit</span>
                                </button>
                                <button type="button" class="btn-summary-action remove-party-action" onclick="removeParty('respondent', ${idx})" title="Remove Party">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                    <span>Remove</span>
                                </button>
                            </div>
                        </div>
                        ${chips.length ? `<div class="party-summary-details">${chips.join('')}</div>` : ''}
                    </div>
                `;
            }).join('');
        }

        const addBtn = document.getElementById('btnAddRespondentModal');
        const isMultiple = document.getElementById('chkMultipleRespondents')?.checked;
        if (addBtn) {
            if (respondentsList.length === 0) {
                addBtn.style.display = 'inline-flex';
                addBtn.textContent = '+ Add Respondent';
            } else if (isMultiple) {
                addBtn.style.display = 'inline-flex';
                addBtn.textContent = '+ Add Another Respondent';
            } else {
                addBtn.style.display = 'none';
            }
        }
    }

    // Multiple Checkbox Listeners
    const chkMultComp = document.getElementById('chkMultipleComplainants');
    if (chkMultComp) {
        if (complainantsList.length > 1) chkMultComp.checked = true;
        chkMultComp.addEventListener('change', () => {
            if (!chkMultComp.checked && complainantsList.length > 1) {
                if (confirm('Unchecking multiple complainants will keep only the primary complainant. Continue?')) {
                    complainantsList = complainantsList.slice(0, 1);
                    syncHiddenPartyInputs();
                    renderParties();
                } else {
                    chkMultComp.checked = true;
                }
            } else {
                renderComplainants();
            }
        });
    }

    const chkMultResp = document.getElementById('chkMultipleRespondents');
    if (chkMultResp) {
        if (respondentsList.length > 1) chkMultResp.checked = true;
        chkMultResp.addEventListener('change', () => {
            if (!chkMultResp.checked && respondentsList.length > 1) {
                if (confirm('Unchecking multiple respondents will keep only the primary respondent. Continue?')) {
                    respondentsList = respondentsList.slice(0, 1);
                    syncHiddenPartyInputs();
                    renderParties();
                } else {
                    chkMultResp.checked = true;
                }
            } else {
                renderRespondents();
            }
        });
    }

    // Initial render of parties
    renderParties();
    syncHiddenPartyInputs();

    // ── Real-Time Duplicate Complaint Check ──
    let dupTimeout = null;
    let potentialDuplicatesDetected = false;
    function triggerDuplicateCheck() {
        clearTimeout(dupTimeout);
        dupTimeout = setTimeout(runDuplicateCheck, 400);
    }

    async function runDuplicateCheck() {
        const compId = document.getElementById('complainantResidentId')?.value?.trim();
        const respId = document.getElementById('respondentResidentId')?.value?.trim();
        const compFirst = document.getElementById('complainantFirstName')?.value?.trim() || '';
        const compLast = document.getElementById('complainantLastName')?.value?.trim() || '';
        const respFirst = document.getElementById('respondentFirstName')?.value?.trim() || '';
        const respLast = document.getElementById('respondentLastName')?.value?.trim() || '';

        const banner = document.getElementById('duplicateComplaintBanner');
        const list = document.getElementById('duplicateComplaintList');
        if (!banner || !list) return;

        if ((!compId && (!compFirst || !compLast)) || (!respId && (!respFirst || !respLast))) {
            banner.style.display = 'none';
            list.innerHTML = '';
            potentialDuplicatesDetected = false;
            return;
        }

        const title = document.getElementById('complaintTitle')?.value?.trim() || '';
        const dt = document.getElementById('incidentDateTime')?.value?.trim() || '';
        const incDate = dt.includes('T') ? dt.split('T')[0] : dt;

        try {
            const params = new URLSearchParams();
            if (compId) params.append('complainant_resident_id', compId);
            if (respId) params.append('respondent_resident_id', respId);
            if (compFirst) params.append('complainant_first_name', compFirst);
            if (compLast) params.append('complainant_last_name', compLast);
            if (respFirst) params.append('respondent_first_name', respFirst);
            if (respLast) params.append('respondent_last_name', respLast);
            if (title) params.append('complaint_title', title);
            if (incDate) params.append('incident_date', incDate);

            const resp = await fetch(`../../../backend/api/complaints/check-duplicate.php?${params.toString()}`);
            const data = await resp.json();
            if (data && data.has_duplicate && data.duplicates && data.duplicates.length > 0) {
                list.innerHTML = data.duplicates.map(d => `
                    <li>
                        <strong>${escapeHtml(d.complaint_number || 'No Case #')}</strong>:
                        ${escapeHtml(d.complaint_title)}
                        <span class="badge-lifecycle-paanyaya" style="font-size:0.75rem;padding:1px 6px;margin-left:4px;">${escapeHtml(d.status)}</span>
                        <span style="color:#64748b;font-size:0.75rem;">(Incident: ${escapeHtml(d.incident_date || 'N/A')})</span>
                        <a href="complaint-details.php?id=${d.complaint_id}" target="_blank" class="duplicate-warning-link" style="margin-left:6px;">View Record &rarr;</a>
                    </li>
                `).join('');
                banner.style.display = 'block';
                potentialDuplicatesDetected = true;
            } else {
                banner.style.display = 'none';
                list.innerHTML = '';
                potentialDuplicatesDetected = false;
                const acknowledgement = document.getElementById('duplicateAcknowledged');
                if (acknowledgement) acknowledgement.checked = false;
            }
        } catch (err) {
            console.warn('Duplicate check error:', err);
        }
    }

    document.getElementById('dismissDuplicateBanner')?.addEventListener('click', () => {
        const banner = document.getElementById('duplicateComplaintBanner');
        if (banner) banner.style.display = 'none';
    });
    document.getElementById('complaintTitle')?.addEventListener('input', triggerDuplicateCheck);
    document.getElementById('incidentDateTime')?.addEventListener('change', triggerDuplicateCheck);

    // ── Time & Future Date Helpers ──
    const dtField = document.getElementById('incidentDateTime');
    const lblDtField = document.getElementById('lblIncidentDateTime');
    const futureNotice = document.getElementById('futureDateNotice');
    function checkFutureDate() {
        if (!dtField || !futureNotice) return;
        const val = dtField.value;
        if (!val) {
            futureNotice.style.display = 'none';
            return;
        }
        const selDate = new Date(val);
        const now = new Date();
        futureNotice.style.display = (selDate > now) ? 'block' : 'none';
    }
    if (dtField) {
        dtField.addEventListener('change', checkFutureDate);
        dtField.addEventListener('input', checkFutureDate);
        checkFutureDate();
    }

    const timeUnknownCheck = document.getElementById('isTimeUnknown');
    const timeApproxCheck  = document.getElementById('isTimeApproximate');

    function syncDateTimeInputs() {
        if (!dtField) return;
        if (timeUnknownCheck && timeUnknownCheck.checked) {
            if (lblDtField) lblDtField.innerHTML = 'Incident Date (Exact Time Unknown) <span class="required-mark">*</span>';
            const currVal = dtField.value;
            if (currVal && currVal.includes('T')) {
                dtField.value = currVal.split('T')[0];
            }
            dtField.type = 'date';
            if (timeApproxCheck) {
                timeApproxCheck.checked = false;
                timeApproxCheck.disabled = true;
            }
        } else {
            if (lblDtField) lblDtField.innerHTML = 'Incident Date &amp; Time <span class="required-mark">*</span>';
            const currVal = dtField.value;
            dtField.type = 'datetime-local';
            if (currVal && !currVal.includes('T') && currVal.length === 10) {
                dtField.value = currVal + 'T12:00';
            }
            if (timeApproxCheck) {
                timeApproxCheck.disabled = false;
            }
        }
        checkFutureDate();
    }

    if (timeUnknownCheck) {
        timeUnknownCheck.addEventListener('change', syncDateTimeInputs);
        if (timeUnknownCheck.checked) {
            syncDateTimeInputs();
        }
    }

    if (timeApproxCheck) {
        timeApproxCheck.addEventListener('change', () => {
            if (timeApproxCheck.checked && timeUnknownCheck && timeUnknownCheck.checked) {
                timeUnknownCheck.checked = false;
                syncDateTimeInputs();
            }
        });
    }

    // ── Formal Complaint Document (KP Form 7) Upload Handling ──
    const kp7Input = document.getElementById('scannedKp7Input');
    const kp7DropZone = document.getElementById('kp7DropZone');
    const btnBrowseKp7 = document.getElementById('btnBrowseKp7');
    const kp7Preview = document.getElementById('kp7SelectedPreview');
    const kp7FileName = document.getElementById('kp7FileName');
    const kp7FileTypeBadge = document.getElementById('kp7FileTypeBadge');
    const kp7FileSize = document.getElementById('kp7FileSize');
    const kp7ThumbWrap = document.getElementById('kp7ThumbWrap');
    const kp7Badge = document.getElementById('kp7DocStatusBadge');
    const kp7Alert = document.getElementById('kp7UploadAlert');
    const btnReplaceKp7 = document.getElementById('btnReplaceKp7');
    const btnRemoveKp7 = document.getElementById('btnRemoveKp7');
    const btnPreviewModal = document.getElementById('btnPreviewKp7Modal');
    const kp7Modal = document.getElementById('kp7DocPreviewModal');
    const kp7ModalBody = document.getElementById('kp7ModalBody');

    let currentKp7File = null;
    let currentKp7Url = null;

    let currentDocZoom = 1.0;
    let currentDocRotation = 0;

    function applyDocViewerTransforms() {
        const img = document.getElementById('docViewerImg');
        const zoomText = document.getElementById('docViewerZoomDisplay');
        if (img) {
            img.style.transform = `scale(${currentDocZoom}) rotate(${currentDocRotation}deg)`;
        }
        if (zoomText) {
            zoomText.textContent = `${Math.round(currentDocZoom * 100)}%`;
        }
    }

    function renderDocumentInLeftViewer(file, url) {
        const emptyState = document.getElementById('docViewerEmpty');
        const pdfFrame   = document.getElementById('docViewerPdf');
        const imgWrapper = document.getElementById('docViewerImgWrapper');
        const img        = document.getElementById('docViewerImg');
        const statusText = document.getElementById('docViewerStatusText');
        const fileNameEl = document.getElementById('docViewerFileName');

        const floatingScan = document.getElementById('docFloatingScanBar');
        if (!file || !url) {
            if (emptyState) emptyState.style.display = 'block';
            if (pdfFrame) { pdfFrame.style.display = 'none'; pdfFrame.src = ''; }
            if (imgWrapper) { imgWrapper.style.display = 'none'; }
            if (statusText) statusText.textContent = 'No document loaded';
            if (fileNameEl) fileNameEl.textContent = 'Waiting for document...';
            if (floatingScan) floatingScan.style.display = 'none';
            return;
        }
        if (floatingScan) floatingScan.style.display = 'flex';

        const ext = file.name.split('.').pop().toLowerCase();
        if (emptyState) emptyState.style.display = 'none';
        if (fileNameEl) fileNameEl.textContent = file.name;
        if (statusText) statusText.textContent = `${ext.toUpperCase()} (${formatFileSize(file.size)})`;

        currentDocZoom = 1.0;
        currentDocRotation = 0;
        applyDocViewerTransforms();

        if (ext === 'pdf') {
            if (imgWrapper) imgWrapper.style.display = 'none';
            if (pdfFrame) {
                pdfFrame.style.display = 'block';
                pdfFrame.src = url;
            }
        } else {
            if (pdfFrame) {
                pdfFrame.style.display = 'none';
                pdfFrame.src = '';
            }
            if (imgWrapper && img) {
                imgWrapper.style.display = 'flex';
                img.src = url;
            }
        }
    }

    document.getElementById('btnDocZoomIn')?.addEventListener('click', () => {
        currentDocZoom = Math.min(currentDocZoom + 0.25, 4.0);
        applyDocViewerTransforms();
    });
    document.getElementById('btnDocZoomOut')?.addEventListener('click', () => {
        currentDocZoom = Math.max(currentDocZoom - 0.25, 0.5);
        applyDocViewerTransforms();
    });
    document.getElementById('btnDocRotate')?.addEventListener('click', () => {
        currentDocRotation = (currentDocRotation + 90) % 360;
        applyDocViewerTransforms();
    });
    document.getElementById('btnDocReset')?.addEventListener('click', () => {
        currentDocZoom = 1.0;
        currentDocRotation = 0;
        applyDocViewerTransforms();
    });
    document.getElementById('btnTriggerUploadFromViewer')?.addEventListener('click', () => {
        document.getElementById('scannedKp7Input')?.click();
    });

    function handleKp7FileSelection(file) {
        if (!file) return;
        if (kp7Alert) { kp7Alert.style.display = 'none'; kp7Alert.textContent = ''; }

        const maxBytes = 25 * 1024 * 1024;
        const allowedExts = ['pdf', 'jpg', 'jpeg', 'png'];
        const ext = file.name.split('.').pop().toLowerCase();

        if (file.size > maxBytes) {
            if (kp7Alert) {
                kp7Alert.textContent = `File exceeds 25 MB limit (${(file.size / (1024*1024)).toFixed(1)} MB). Please select a smaller scan.`;
                kp7Alert.style.display = 'block';
            }
            return;
        }
        if (!allowedExts.includes(ext)) {
            if (kp7Alert) {
                kp7Alert.textContent = `Format .${ext} is not allowed. Only PDF, JPG, JPEG, and PNG files are accepted.`;
                kp7Alert.style.display = 'block';
            }
            return;
        }

        currentKp7File = file;
        if (currentKp7Url) URL.revokeObjectURL(currentKp7Url);
        currentKp7Url = URL.createObjectURL(file);

        if (kp7FileName) kp7FileName.textContent = file.name;
        if (kp7FileTypeBadge) kp7FileTypeBadge.textContent = ext.toUpperCase();
        if (kp7FileSize) kp7FileSize.textContent = formatFileSize(file.size);

        if (kp7ThumbWrap) {
            if (['jpg', 'jpeg', 'png'].includes(ext)) {
                kp7ThumbWrap.innerHTML = `<img src="${currentKp7Url}" class="kp7-preview-thumb" alt="Preview">`;
            } else {
                kp7ThumbWrap.innerHTML = `<div class="kp7-preview-icon">PDF</div>`;
            }
        }

        if (kp7DropZone) kp7DropZone.style.display = 'none';
        if (kp7Preview) kp7Preview.style.display = 'flex';
        if (kp7Badge) {
            kp7Badge.className = 'badge-doc-status badge-doc-uploaded';
            kp7Badge.textContent = 'Scan Uploaded';
        }

        renderDocumentInLeftViewer(file, currentKp7Url);
    }

    function resetKp7Selection() {
        currentKp7File = null;
        if (currentKp7Url) {
            URL.revokeObjectURL(currentKp7Url);
            currentKp7Url = null;
        }
        if (kp7Input) kp7Input.value = '';
        if (kp7Preview) kp7Preview.style.display = 'none';
        if (kp7DropZone) kp7DropZone.style.display = 'block';
        if (kp7Badge) {
            kp7Badge.className = 'badge-doc-status badge-doc-no-scan';
            kp7Badge.textContent = 'No Scan Uploaded';
        }
        if (kp7Alert) {
            kp7Alert.style.display = 'none';
            kp7Alert.textContent = '';
        }

        renderDocumentInLeftViewer(null, null);
    }

    if (btnBrowseKp7 && kp7Input) {
        btnBrowseKp7.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            kp7Input.click();
        });
    }
    if (kp7DropZone && kp7Input) {
        kp7DropZone.addEventListener('click', (e) => {
            if (e.target !== btnBrowseKp7) {
                kp7Input.click();
            }
        });
        ['dragenter', 'dragover'].forEach(name => {
            kp7DropZone.addEventListener(name, (e) => {
                e.preventDefault();
                e.stopPropagation();
                kp7DropZone.classList.add('dragover');
            });
        });
        ['dragleave', 'dragend', 'drop'].forEach(name => {
            kp7DropZone.addEventListener(name, (e) => {
                e.preventDefault();
                e.stopPropagation();
                kp7DropZone.classList.remove('dragover');
            });
        });
        kp7DropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            e.stopPropagation();
            kp7DropZone.classList.remove('dragover');
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                const file = e.dataTransfer.files[0];
                const dt = new DataTransfer();
                dt.items.add(file);
                kp7Input.files = dt.files;
                handleKp7FileSelection(file);
            }
        });
    }

    if (kp7Input) {
        kp7Input.addEventListener('change', () => {
            if (kp7Input.files && kp7Input.files.length > 0) {
                handleKp7FileSelection(kp7Input.files[0]);
            }
        });
    }

    if (btnReplaceKp7 && kp7Input) {
        btnReplaceKp7.addEventListener('click', () => kp7Input.click());
    }
    if (btnRemoveKp7) {
        btnRemoveKp7.addEventListener('click', resetKp7Selection);
    }

    // Preview Modal
    if (btnPreviewModal && kp7Modal && kp7ModalBody) {
        btnPreviewModal.addEventListener('click', () => {
            if (!currentKp7Url || !currentKp7File) return;
            const ext = currentKp7File.name.split('.').pop().toLowerCase();
            if (['jpg', 'jpeg', 'png'].includes(ext)) {
                kp7ModalBody.innerHTML = `<img src="${currentKp7Url}" style="max-width:100%; max-height:70vh; object-fit:contain; border-radius:6px; box-shadow:0 4px 6px rgba(0,0,0,0.1);" alt="KP Form 7 Preview">`;
            } else {
                kp7ModalBody.innerHTML = `<iframe src="${currentKp7Url}" style="width:100%; height:70vh; border:none; border-radius:6px;"></iframe>`;
            }
            kp7Modal.style.display = 'flex';
        });
    }

    ['btnCloseKp7PreviewModal', 'btnCloseKp7PreviewModalBtn'].forEach(id => {
        document.getElementById(id)?.addEventListener('click', () => {
            if (kp7Modal) kp7Modal.style.display = 'none';
        });
    });
    if (kp7Modal) {
        kp7Modal.addEventListener('click', (e) => {
            if (e.target === kp7Modal) kp7Modal.style.display = 'none';
        });
    }

    // ── Quick-Nav ScrollSpy & Smooth Scrolling ──
    const quickNavLinks = document.querySelectorAll('#intakeQuickNav .quicknav-link');
    const navSectionIds = ['section-ingestion', 'section-parties', 'section-narrative', 'section-location', 'section-evidence'];
    const navSections = navSectionIds.map(id => document.getElementById(id)).filter(Boolean);

    quickNavLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const targetId = link.getAttribute('href')?.replace('#', '');
            const targetEl = document.getElementById(targetId);
            if (targetEl) {
                const topOffset = 70;
                const elementPosition = targetEl.getBoundingClientRect().top + window.pageYOffset;
                window.scrollTo({
                    top: Math.max(0, elementPosition - topOffset),
                    behavior: 'smooth'
                });
                quickNavLinks.forEach(l => l.classList.remove('is-active'));
                link.classList.add('is-active');
            }
        });
    });

    if ('IntersectionObserver' in window && navSections.length > 0) {
        const navObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const id = entry.target.id;
                    quickNavLinks.forEach(link => {
                        const href = link.getAttribute('href');
                        if (href === `#${id}`) {
                            link.classList.add('is-active');
                        } else {
                            link.classList.remove('is-active');
                        }
                    });
                }
            });
        }, {
            root: null,
            rootMargin: '-15% 0px -65% 0px',
            threshold: 0
        });
        navSections.forEach(sec => navObserver.observe(sec));
    }

    // ── Filing Method Dynamic Selector ──
    const methodRadios = document.querySelectorAll('input[name="filing_method"]');
    const cardDigital = document.getElementById('labelMethodDigital');
    const cardPaper = document.getElementById('labelMethodPaper');
    const paperUploadBox = document.getElementById('paperUploadBox');
    const narrativeInput = document.getElementById('narrative');
    const narrativeReqStar = document.getElementById('narrativeRequiredStar');

    function syncFilingMethod() {
        const selected = document.querySelector('input[name="filing_method"]:checked')?.value || 'digital';
        const mainWrapper = document.querySelector('.intake-main-wrapper');
        const layoutContainer = document.getElementById('intakeLayoutContainer');
        const quickNav = document.getElementById('intakeQuickNav');
        const docViewer = document.getElementById('paperDocViewer');

        if (selected === 'paper') {
            cardDigital?.classList.remove('is-selected');
            cardPaper?.classList.add('is-selected');
            if (paperUploadBox) paperUploadBox.style.display = 'block';

            if (mainWrapper) mainWrapper.classList.add('paper-mode-active');
            if (layoutContainer) layoutContainer.classList.add('paper-mode-layout');
            if (quickNav) quickNav.style.display = 'none';
            if (docViewer) docViewer.style.display = 'block';

            if (narrativeInput) {
                narrativeInput.removeAttribute('required');
                if (!narrativeInput.value.trim() || narrativeInput.dataset.autoFilled === 'true') {
                    narrativeInput.value = 'See attached scanned KP Form 7';
                    narrativeInput.dataset.autoFilled = 'true';
                }
            }
            if (narrativeReqStar) narrativeReqStar.style.display = 'none';

            if (currentKp7File && currentKp7Url) {
                renderDocumentInLeftViewer(currentKp7File, currentKp7Url);
            }
        } else {
            cardDigital?.classList.add('is-selected');
            cardPaper?.classList.remove('is-selected');
            if (paperUploadBox) paperUploadBox.style.display = 'none';

            if (mainWrapper) mainWrapper.classList.remove('paper-mode-active');
            if (layoutContainer) layoutContainer.classList.remove('paper-mode-layout');
            if (quickNav) quickNav.style.display = 'block';
            if (docViewer) docViewer.style.display = 'none';

            if (narrativeInput) {
                narrativeInput.setAttribute('required', 'required');
                if (narrativeInput.dataset.autoFilled === 'true' && narrativeInput.value.trim() === 'See attached scanned KP Form 7') {
                    narrativeInput.value = '';
                    delete narrativeInput.dataset.autoFilled;
                }
            }
            if (narrativeReqStar) narrativeReqStar.style.display = 'inline';
        }
    }
    methodRadios.forEach(r => r.addEventListener('change', syncFilingMethod));
    syncFilingMethod();


    // ── Map Controls & Live Coordinates Badge ──
    const btnTogglePaperMap = document.getElementById('btnTogglePaperMap');
    const locationMapPinbox = document.getElementById('locationMapPinbox');
    if (btnTogglePaperMap && locationMapPinbox) {
        btnTogglePaperMap.addEventListener('click', () => {
            const isOpen = locationMapPinbox.classList.toggle('is-open');
            btnTogglePaperMap.textContent = isOpen 
                ? 'Hide Map Pin' 
                : 'Pin Precise Map Location (Optional)';
            if (isOpen) {
                setTimeout(() => {
                    const mapController = window.complaintMaps?.addComplaintMap;
                    mapController?.map?.invalidateSize?.();
                    mapController?.refresh?.();
                }, 150);
            }
        });
    }

    const btnRecenter = document.getElementById('btnRecenterMap');
    if (btnRecenter) {
        btnRecenter.addEventListener('click', (e) => {
            e.preventDefault();
            const mapController = window.complaintMaps?.addComplaintMap;
            if (mapController?.map) {
                mapController.map.setView([14.6521, 121.0854], 16);
                mapController.refresh?.();
            }
        });
    }

    const latInput = document.querySelector('[data-map-latitude]');
    const lngInput = document.querySelector('[data-map-longitude]');
    const coordsDisplay = document.getElementById('mapCoordsDisplay');

    function updateCoordsDisplay() {
        if (!coordsDisplay) return;
        const lat = parseFloat(latInput?.value);
        const lng = parseFloat(lngInput?.value);
        if (!isNaN(lat) && !isNaN(lng)) {
            coordsDisplay.textContent = `${lat.toFixed(6)}° N, ${lng.toFixed(6)}° E`;
        } else {
            coordsDisplay.textContent = '14.6521° N, 121.0854° E (Default)';
        }
    }
    if (latInput) {
        latInput.addEventListener('change', updateCoordsDisplay);
        latInput.addEventListener('input', updateCoordsDisplay);
    }
    const mapBox = document.getElementById('addComplaintMap');
    if (mapBox) {
        mapBox.addEventListener('click', () => setTimeout(updateCoordsDisplay, 200));
    }
    document.querySelector('[data-clear-map]')?.addEventListener('click', () => {
        setTimeout(updateCoordsDisplay, 100);
    });
    updateCoordsDisplay();

    // Default datetime to now if empty
    const dtInput = document.getElementById('incidentDateTime');
    if (dtInput && !dtInput.value) {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        dtInput.value = `${year}-${month}-${day}T${hours}:${minutes}`;
    }

    // Initialize standalone map immediately
    if (window.initialiseComplaintMap) {
        window.initialiseComplaintMap('addComplaintMap', 'none');
    }

    // Evidence file queue handling (DataTransfer backed)
    const evidenceInput = document.getElementById('evidenceFiles');
    const evidenceDropZone = document.getElementById('evidenceDropZone');
    const btnBrowseEvidence = document.getElementById('btnBrowseEvidence');
    const evidenceAlert = document.getElementById('evidenceValidationAlert');
    const queueContainer = document.getElementById('evidenceQueueContainer');
    const queueList = document.getElementById('evidenceQueueList');
    const countDisplay = document.getElementById('evidenceCount');
    const clearAllBtn = document.getElementById('clearAllEvidenceBtn');

    let evidenceDataTransfer = new DataTransfer();
    const maxFileSize = 25 * 1024 * 1024; // 25 MB
    const allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'pdf', 'doc', 'docx'];

    function showEvidenceAlert(msg) {
        if (!evidenceAlert) return;
        evidenceAlert.textContent = msg;
        evidenceAlert.style.display = 'block';
    }

    function hideEvidenceAlert() {
        if (!evidenceAlert) return;
        evidenceAlert.textContent = '';
        evidenceAlert.style.display = 'none';
    }

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    }

    function getFileTypeInfo(file) {
        const ext = file.name.split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
            return { type: 'image', badge: 'Image', iconClass: 'icon-image', text: 'IMG' };
        }
        if (['mp4', 'webm'].includes(ext)) {
            return { type: 'video', badge: 'Video', iconClass: 'icon-video', text: 'VID' };
        }
        if (ext === 'pdf') {
            return { type: 'pdf', badge: 'PDF', iconClass: 'icon-pdf', text: 'PDF' };
        }
        if (['doc', 'docx'].includes(ext)) {
            return { type: 'doc', badge: 'Word', iconClass: 'icon-doc', text: 'DOC' };
        }
        return { type: 'other', badge: ext.toUpperCase(), iconClass: 'icon-file', text: 'FILE' };
    }

    function renderEvidenceQueue() {
        if (!queueContainer || !queueList || !countDisplay) return;
        const files = Array.from(evidenceDataTransfer.files);
        countDisplay.textContent = files.length;

        if (files.length === 0) {
            queueContainer.style.display = 'none';
            queueList.innerHTML = '';
            return;
        }

        queueContainer.style.display = 'block';
        queueList.innerHTML = '';

        files.forEach((file, index) => {
            const fileInfo = getFileTypeInfo(file);
            const li = document.createElement('li');
            li.className = 'evidence-queue-item';

            const leftDiv = document.createElement('div');
            leftDiv.className = 'evidence-item-left';

            if (fileInfo.type === 'image') {
                const img = document.createElement('img');
                img.className = 'evidence-item-thumb';
                img.src = URL.createObjectURL(file);
                img.alt = file.name;
                img.onload = () => URL.revokeObjectURL(img.src);
                leftDiv.appendChild(img);
            } else {
                const icon = document.createElement('div');
                icon.className = `evidence-item-icon ${fileInfo.iconClass}`;
                icon.textContent = fileInfo.text;
                leftDiv.appendChild(icon);
            }

            const detailsDiv = document.createElement('div');
            detailsDiv.className = 'evidence-item-details';

            const nameSpan = document.createElement('span');
            nameSpan.className = 'evidence-item-name';
            nameSpan.textContent = file.name;
            nameSpan.title = file.name;

            const metaSpan = document.createElement('span');
            metaSpan.className = 'evidence-item-meta';
            metaSpan.innerHTML = `<span class="evidence-type-badge">${fileInfo.badge}</span> ${formatFileSize(file.size)}`;

            detailsDiv.appendChild(nameSpan);
            detailsDiv.appendChild(metaSpan);
            leftDiv.appendChild(detailsDiv);

            const actionsDiv = document.createElement('div');
            actionsDiv.className = 'evidence-item-actions';
            actionsDiv.style.cssText = 'display:flex;align-items:center;gap:8px;flex-shrink:0;';

            // Document Category Tag Selector
            const tagSelect = document.createElement('select');
            tagSelect.name = 'evidence_tags[]';
            tagSelect.className = 'evidence-tag-select';
            tagSelect.innerHTML = `
                <option value="Photo Evidence">Photo Evidence</option>
                <option value="Barangay Blotter Copy">Barangay Blotter Copy</option>
                <option value="KP Form 7">KP Form 7</option>
                <option value="Medical Certificate">Medical Certificate</option>
                <option value="Sworn Statement">Sworn Statement</option>
                <option value="Other Document">Other Document</option>
            `;
            const lowerName = file.name.toLowerCase();
            if (lowerName.includes('blotter')) {
                tagSelect.value = 'Barangay Blotter Copy';
            } else if (lowerName.includes('kp') || lowerName.includes('form 7') || lowerName.includes('form7')) {
                tagSelect.value = 'KP Form 7';
            } else if (lowerName.includes('med') || lowerName.includes('cert')) {
                tagSelect.value = 'Medical Certificate';
            } else if (lowerName.includes('affidavit') || lowerName.includes('salaysay') || lowerName.includes('statement')) {
                tagSelect.value = 'Sworn Statement';
            } else if (fileInfo.type === 'image') {
                tagSelect.value = 'Photo Evidence';
            } else {
                tagSelect.value = 'Other Document';
            }
            actionsDiv.appendChild(tagSelect);

            if (fileInfo.type === 'image') {
                const ocrBtn = document.createElement('button');
                ocrBtn.type = 'button';
                ocrBtn.className = 'btn-outline-sm btn-ocr-queue';
                ocrBtn.title = 'Extract text from this image and append to narrative';
                ocrBtn.style.cssText = 'font-size:0.75rem;padding:3px 8px;white-space:nowrap;';
                ocrBtn.textContent = 'OCR';
                ocrBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    runProofOcrOnQueuedFile(file, ocrBtn);
                });
                actionsDiv.appendChild(ocrBtn);
            }

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn-remove-evidence-item';
            removeBtn.innerHTML = '&times;';
            removeBtn.title = 'Remove this file';
            removeBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                removeFileFromQueue(index);
            });
            actionsDiv.appendChild(removeBtn);

            li.appendChild(leftDiv);
            li.appendChild(actionsDiv);
            queueList.appendChild(li);
        });
    }

    function addFilesToQueue(incomingFiles) {
        hideEvidenceAlert();
        let errors = [];

        Array.from(incomingFiles).forEach(file => {
            if (file.size > maxFileSize) {
                errors.push(`"${file.name}" exceeds the 25 MB limit.`);
                return;
            }

            const ext = file.name.split('.').pop().toLowerCase();
            if (!allowedExtensions.includes(ext)) {
                errors.push(`"${file.name}" is not an allowed format (JPG, PNG, GIF, WebP, MP4, WebM, PDF, DOC, DOCX).`);
                return;
            }

            const alreadyExists = Array.from(evidenceDataTransfer.files).some(
                existing => existing.name === file.name && existing.size === file.size && existing.lastModified === file.lastModified
            );
            if (alreadyExists) return;

            evidenceDataTransfer.items.add(file);
        });

        if (errors.length > 0) {
            showEvidenceAlert(errors.join(' '));
        }

        evidenceInput.files = evidenceDataTransfer.files;
        renderEvidenceQueue();
    }

    function removeFileFromQueue(indexToRemove) {
        const newDt = new DataTransfer();
        Array.from(evidenceDataTransfer.files).forEach((file, idx) => {
            if (idx !== indexToRemove) {
                newDt.items.add(file);
            }
        });
        evidenceDataTransfer = newDt;
        evidenceInput.files = evidenceDataTransfer.files;
        renderEvidenceQueue();
    }

    if (evidenceInput) {
        evidenceInput.addEventListener('change', () => {
            if (evidenceInput.files && evidenceInput.files.length > 0) {
                addFilesToQueue(evidenceInput.files);
            }
        });
    }

    if (btnBrowseEvidence && evidenceInput) {
        btnBrowseEvidence.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            evidenceInput.click();
        });
    }

    if (clearAllBtn) {
        clearAllBtn.addEventListener('click', (e) => {
            e.preventDefault();
            evidenceDataTransfer = new DataTransfer();
            if (evidenceInput) evidenceInput.files = evidenceDataTransfer.files;
            hideEvidenceAlert();
            renderEvidenceQueue();
        });
    }

    if (evidenceDropZone) {
        ['dragenter', 'dragover'].forEach(eventName => {
            evidenceDropZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                evidenceDropZone.classList.add('drag-over');
            });
        });

        ['dragleave', 'dragend'].forEach(eventName => {
            evidenceDropZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                evidenceDropZone.classList.remove('drag-over');
            });
        });

        evidenceDropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            e.stopPropagation();
            evidenceDropZone.classList.remove('drag-over');
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                addFilesToQueue(e.dataTransfer.files);
            }
        });
    }

    // ── Proof OCR: Card-5 button ─────────────────────────────────────────────
    (function initProofOcr() {
        const ocrBtn   = document.getElementById('btnProofOcr');
        const ocrInput = document.getElementById('proofOcrFileInput');
        if (!ocrBtn || !ocrInput) return;

        ocrBtn.addEventListener('click', () => ocrInput.click());

        ocrInput.addEventListener('change', () => {
            const file = ocrInput.files[0];
            if (!file) return;
            runProofOcrOnQueuedFile(file, ocrBtn);
            ocrInput.value = '';
        });
    })();

    // Shared OCR helper — appends extracted text to #proofOcrNotes
    async function runProofOcrOnQueuedFile(file, triggerBtn) {
        const notesTa = document.getElementById('proofOcrNotes');
        if (!notesTa) return;

        const originalLabel = triggerBtn.textContent;
        triggerBtn.disabled = true;
        triggerBtn.textContent = 'Scanning…';

        try {
            const formData = new FormData();
            formData.append('image', file, file.name);
            formData.append('external_ai_consent', '1');

            const resp = await fetch('../../../backend/api/ai/ocr-notes.php', {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();

            if (data.success && data.text && data.text.trim()) {
                const label = `[Scanned Proof – ${file.name}]:\n${data.text.trim()}`;
                const current = notesTa.value.trim();
                notesTa.value = current ? current + '\n\n' + label : label;
                notesTa.dispatchEvent(new Event('input'));
                window.agapNotify?.('Proof text extracted and added to Proof Notes.', 'success', 'Proof OCR');
            } else {
                window.agapNotify?.(data.error || 'OCR did not extract any text from the image.', 'error', 'Proof OCR');
            }
        } catch (err) {
            console.error('Proof OCR error:', err);
            window.agapNotify?.('Failed to connect to the OCR service. Please try again.', 'error', 'Proof OCR');
        } finally {
            triggerBtn.disabled = false;
            triggerBtn.textContent = originalLabel;
        }
    }

        // ═════════════════════════════════════════════════════════════════════════
    // DOCUMENT VIEWER AI SCANNER & AUTO-FILL SYSTEM
    // ═════════════════════════════════════════════════════════════════════════
    let isScanningDocument = false;
    let pendingScanData = null;

    async function triggerDocumentScanner(fileToScan) {
        if (isScanningDocument) return;

        const targetFile = fileToScan || currentKp7File;
        if (!targetFile) {
            window._autoScanOnSelect = true;
            window.agapNotify?.('Please select or upload a KP Form 7 document to scan.', 'info', 'Document Scanner');
            document.getElementById('scannedKp7Input')?.click();
            return;
        }

        const beam = document.getElementById('docScannerBeam');
        const statusOverlay = document.getElementById('docScannerStatus');
        const statusLabel = document.getElementById('docScannerStatusLabel');
        const scanBtn = document.getElementById('btnScanDocViewer');
        const floatingScanBtn = document.getElementById('btnFloatingScanDoc');

        isScanningDocument = true;
        if (beam) beam.style.display = 'block';
        if (statusOverlay) statusOverlay.style.display = 'flex';
        if (statusLabel) statusLabel.textContent = `Scanning "${targetFile.name}" with AI OCR...`;
        if (scanBtn) {
            scanBtn.disabled = true;
            scanBtn.innerHTML = `
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="animation: spin 1s linear infinite;"><line x1="12" y1="2" x2="12" y2="6"></line><line x1="12" y1="18" x2="12" y2="22"></line><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"></line><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"></line><line x1="2" y1="12" x2="6" y2="12"></line><line x1="18" y1="12" x2="22" y2="12"></line><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"></line><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"></line></svg>
                <span>Scanning...</span>
            `;
        }
        if (floatingScanBtn) {
            floatingScanBtn.disabled = true;
            floatingScanBtn.textContent = 'Scanning...';
        }

        try {
            const formData = new FormData();
            formData.append('document', targetFile, targetFile.name);

            const resp = await fetch('../../../backend/api/ai/scan-kp-document.php', {
                method: 'POST',
                body: formData
            });
            const result = await resp.json();

            if (result && result.success && result.data) {
                pendingScanData = result.data;
                showScanReviewModal(result.data, targetFile.name);
            } else {
                window.agapNotify?.(result?.message || 'Unable to scan document. Please check the file.', 'error', 'Document Scanner');
            }
        } catch (err) {
            console.error('Scan error:', err);
            window.agapNotify?.('Failed to connect to scanner service. Please try again.', 'error', 'Document Scanner');
        } finally {
            isScanningDocument = false;
            if (beam) beam.style.display = 'none';
            if (statusOverlay) statusOverlay.style.display = 'none';
            if (scanBtn) {
                scanBtn.disabled = false;
                scanBtn.innerHTML = `
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><circle cx="12" cy="12" r="3"/><line x1="12" y1="9" x2="12" y2="3"/></svg>
                    <span style="font-weight: 700;">Scan &amp; Auto-Fill</span>
                `;
            }
            if (floatingScanBtn) {
                floatingScanBtn.disabled = false;
                floatingScanBtn.innerHTML = `
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/></svg>
                    Auto-Fill Fields
                `;
            }
        }
    }

    function showScanReviewModal(data, fileName) {
        let modalEl = document.getElementById('scanReviewModalOverlay');
        if (!modalEl) {
            modalEl = document.createElement('div');
            modalEl.id = 'scanReviewModalOverlay';
            modalEl.className = 'scan-review-modal-overlay';
            document.body.appendChild(modalEl);
        }

        const comp = data.complainants?.[0] || {};
        const resp = data.respondents?.[0] || {};
        const compName = [comp.first_name, comp.middle_name, comp.last_name, comp.suffix].filter(Boolean).join(' ') || 'Complainant detected';
        const respName = [resp.first_name, resp.middle_name, resp.last_name, resp.suffix].filter(Boolean).join(' ') || 'Respondent detected';

        modalEl.innerHTML = `
            <div class="scan-review-modal">
                <div class="scan-review-header">
                    <h3>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        Document Scanned Successfully
                    </h3>
                    <button type="button" id="btnCloseScanReview" style="background:none; border:none; color:#ffffff; font-size:1.5rem; cursor:pointer; line-height:1;">&times;</button>
                </div>
                <div class="scan-review-body">
                    <div style="font-size:0.83rem; color:#64748b; margin-bottom:2px;">
                        Extracted from <strong>${escapeHtml(fileName)}</strong>. Review the detected details below before applying to the complaint form:
                    </div>

                    <div class="scan-review-grid">
                        <div class="scan-review-item">
                            <div class="scan-review-label">Case Classification</div>
                            <div class="scan-review-val">${escapeHtml(data.category_name || 'General Dispute')} <span style="font-size:0.75rem; color:#0284c7; font-weight:700;">(${escapeHtml(data.case_type || 'Civil')})</span></div>
                        </div>
                        <div class="scan-review-item">
                            <div class="scan-review-label">Incident Date &amp; Time</div>
                            <div class="scan-review-val">${escapeHtml(data.incident_date || 'Not specified')} ${data.incident_time ? 'at ' + escapeHtml(data.incident_time) : ''}</div>
                        </div>
                        <div class="scan-review-item full-width">
                            <div class="scan-review-label">Specific Nature of Dispute</div>
                            <div class="scan-review-val">${escapeHtml(data.dispute_nature || 'Dispute intake')}</div>
                        </div>
                    </div>

                    <div class="scan-review-label" style="margin-top:6px;">Involved Parties</div>
                    <div class="scan-review-parties">
                        <div class="scan-review-party-card is-comp">
                            <div style="font-size:0.72rem; font-weight:800; color:#0369a1; text-transform:uppercase;">Complainant</div>
                            <div style="font-weight:700; color:#0f172a; font-size:0.95rem; margin-top:2px;">${escapeHtml(compName)}</div>
                            <div style="font-size:0.78rem; color:#475569; margin-top:4px;">
                                ${comp.contact_no ? '<div>📞 ' + escapeHtml(comp.contact_no) + '</div>' : ''}
                                ${(comp.address || comp.purok) ? '<div>📍 ' + escapeHtml([comp.address, comp.purok].filter(Boolean).join(', ')) + '</div>' : ''}
                            </div>
                        </div>
                        <div class="scan-review-party-card is-resp">
                            <div style="font-size:0.72rem; font-weight:800; color:#be123c; text-transform:uppercase;">Respondent</div>
                            <div style="font-weight:700; color:#0f172a; font-size:0.95rem; margin-top:2px;">${escapeHtml(respName)}</div>
                            <div style="font-size:0.78rem; color:#475569; margin-top:4px;">
                                ${resp.contact_no ? '<div>📞 ' + escapeHtml(resp.contact_no) + '</div>' : ''}
                                ${(resp.address || resp.purok) ? '<div>📍 ' + escapeHtml([resp.address, resp.purok].filter(Boolean).join(', ')) + '</div>' : ''}
                            </div>
                        </div>
                    </div>

                    <div class="scan-review-item full-width">
                        <div class="scan-review-label">Narrative Statement</div>
                        <div class="scan-review-val" style="font-size:0.83rem; font-weight:400; line-height:1.5; max-height:110px; overflow-y:auto;">
                            ${escapeHtml(data.narrative || 'See attached scanned document')}
                        </div>
                    </div>

                    ${data.requested_resolution ? `
                        <div class="scan-review-item full-width">
                            <div class="scan-review-label">Relief / Remedy Demanded</div>
                            <div class="scan-review-val" style="font-size:0.83rem; font-weight:500;">
                                ${escapeHtml(data.requested_resolution)}
                            </div>
                        </div>
                    ` : ''}
                </div>
                <div class="scan-review-footer">
                    <button type="button" class="btn-secondary btn-sm" id="btnCancelScanReview">Discard</button>
                    <button type="button" class="btn-create btn-sm" id="btnApplyScanReview" style="display:inline-flex; align-items:center; gap:6px; font-weight:700;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Apply to Form Fields
                    </button>
                </div>
            </div>
        `;

        modalEl.style.display = 'flex';

        document.getElementById('btnCloseScanReview')?.addEventListener('click', () => { modalEl.style.display = 'none'; });
        document.getElementById('btnCancelScanReview')?.addEventListener('click', () => { modalEl.style.display = 'none'; });
        document.getElementById('btnApplyScanReview')?.addEventListener('click', () => {
            applyScannedDataToForm(data);
            modalEl.style.display = 'none';
        });
    }

    function applyScannedDataToForm(data) {
        if (!data) return;

        function highlightEl(el) {
            if (!el) return;
            el.classList.remove('field-scan-highlight');
            void el.offsetWidth;
            el.classList.add('field-scan-highlight');
        }

        // 1. Classification & Case Type
        const catSelect = document.getElementById('categoryId');
        if (catSelect && data.category_id) {
            catSelect.value = String(data.category_id);
            highlightEl(catSelect);
        } else if (catSelect && data.category_name) {
            for (let i = 0; i < catSelect.options.length; i++) {
                if (catSelect.options[i].text.toLowerCase().includes(data.category_name.toLowerCase())) {
                    catSelect.selectedIndex = i;
                    highlightEl(catSelect);
                    break;
                }
            }
        }

        const caseTypeSelect = document.getElementById('caseType');
        if (caseTypeSelect && data.case_type) {
            caseTypeSelect.value = data.case_type;
            highlightEl(caseTypeSelect);
        }

        // 2. Dispute Title / Nature
        const titleInput = document.getElementById('complaintTitle');
        if (titleInput && data.dispute_nature) {
            titleInput.value = data.dispute_nature;
            titleInput.dispatchEvent(new Event('input'));
            highlightEl(titleInput);
        }

        // 3. Incident Date & Time
        const dtField = document.getElementById('incidentDateTime');
        if (dtField && data.incident_date) {
            const timePart = data.incident_time || '14:00';
            dtField.value = dtField.type === 'date' ? data.incident_date : `${data.incident_date}T${timePart}`;
            dtField.dispatchEvent(new Event('change'));
            highlightEl(dtField);
        }

        // 4. Incident Location
        const streetInput = document.getElementById('incidentStreet');
        if (streetInput && data.incident_street) {
            streetInput.value = data.incident_street;
            highlightEl(streetInput);
        }

        const purokSelect = document.getElementById('incidentPurok');
        if (purokSelect && data.incident_purok) {
            for (let i = 0; i < purokSelect.options.length; i++) {
                if (purokSelect.options[i].value.toLowerCase() === data.incident_purok.toLowerCase() ||
                    purokSelect.options[i].text.toLowerCase().includes(data.incident_purok.toLowerCase())) {
                    purokSelect.selectedIndex = i;
                    highlightEl(purokSelect);
                    break;
                }
            }
        }

        // 5. Blotter Ref
        const blotterInput = document.getElementById('blotterRefNo');
        if (blotterInput && data.blotter_ref_no) {
            blotterInput.value = data.blotter_ref_no;
            highlightEl(blotterInput);
        }

        // 6. Narrative & Relief
        const narrativeTa = document.getElementById('narrative');
        if (narrativeTa && data.narrative) {
            narrativeTa.value = data.narrative;
            narrativeTa.dispatchEvent(new Event('input'));
            highlightEl(narrativeTa);
        }

        const reliefTa = document.getElementById('requestedResolution');
        if (reliefTa && data.requested_resolution) {
            reliefTa.value = data.requested_resolution;
            highlightEl(reliefTa);
        }

        // 7. Complainants List
        if (Array.isArray(data.complainants) && data.complainants.length > 0) {
            complainantsList = data.complainants.map((c, idx) => ({
                resident_id: '',
                first_name: c.first_name || '',
                middle_name: c.middle_name || '',
                last_name: c.last_name || '',
                suffix: c.suffix || '',
                birthdate: c.birthdate || '',
                age: c.age || '',
                gender: c.gender || '',
                civil_status: c.civil_status || '',
                occupation: c.occupation || '',
                contact_no: c.contact_no || '',
                email: c.email || '',
                preferred_contact: 'SMS',
                purok: c.purok || 'Purok 1',
                is_tenant: '0',
                address: c.address || '',
                is_minor: false,
                guardian_name: '',
                guardian_relationship: '',
                guardian_contact: '',
                guardian_address: ''
            }));
            const chkMultComp = document.getElementById('chkMultipleComplainants');
            if (chkMultComp) chkMultComp.checked = complainantsList.length > 1;
        }

        // 8. Respondents List
        if (Array.isArray(data.respondents) && data.respondents.length > 0) {
            respondentsList = data.respondents.map((r, idx) => ({
                resident_id: '',
                first_name: r.first_name || '',
                middle_name: r.middle_name || '',
                last_name: r.last_name || '',
                suffix: r.suffix || '',
                birthdate: r.birthdate || '',
                age: r.age || '',
                gender: r.gender || '',
                civil_status: r.civil_status || '',
                occupation: r.occupation || '',
                contact_no: r.contact_no || '',
                email: r.email || '',
                preferred_contact: 'Personal Delivery',
                purok: r.purok || 'Purok 1',
                is_tenant: '0',
                address: r.address || '',
                is_minor: false,
                guardian_name: '',
                guardian_relationship: '',
                guardian_contact: '',
                guardian_address: '',
                contact_unknown: !r.contact_no,
                middle_unknown: !r.middle_name,
                birth_unknown: !r.age && !r.birthdate,
                kp_jurisdiction_exception: false
            }));
            const chkMultResp = document.getElementById('chkMultipleRespondents');
            if (chkMultResp) chkMultResp.checked = respondentsList.length > 1;
        }

        // Sync & re-render
        syncHiddenPartyInputs();
        renderParties();
        window.validateDistinctParties?.();
        triggerDuplicateCheck();

        const compListEl = document.getElementById('complainantsSummaryList');
        const respListEl = document.getElementById('respondentsSummaryList');
        highlightEl(compListEl);
        highlightEl(respListEl);

        window.agapNotify?.('Form fields successfully populated from document scan!', 'success', 'Document Scanner');
    }

    // Attach Scanner Trigger Listeners
    document.getElementById('btnScanDocViewer')?.addEventListener('click', () => {
        triggerDocumentScanner(currentKp7File);
    });
    document.getElementById('btnTriggerScanFromViewer')?.addEventListener('click', () => {
        triggerDocumentScanner(currentKp7File);
    });
    document.getElementById('btnFloatingScanDoc')?.addEventListener('click', () => {
        triggerDocumentScanner(currentKp7File);
    });

    // Auto-scan callback when file is selected if user clicked scan on empty state
    const origHandleKp7 = handleKp7FileSelection;
    handleKp7FileSelection = function(file) {
        origHandleKp7(file);
        if (window._autoScanOnSelect) {
            window._autoScanOnSelect = false;
            setTimeout(() => { triggerDocumentScanner(file); }, 250);
        }
    };

    // ═════════════════════════════════════════════════════════════════════════
    // ACCIDENTAL REFRESH / NAVIGATION PROTECTION
    // ═════════════════════════════════════════════════════════════════════════
    let isFormDirty = false;
    let isSubmitting = false;

    createForm?.addEventListener('input', () => { isFormDirty = true; });
    createForm?.addEventListener('change', () => { isFormDirty = true; });

    window.addEventListener('beforeunload', (e) => {
        if (isFormDirty && !isSubmitting) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    createForm?.addEventListener('submit', () => {
        isSubmitting = true;
        isFormDirty = false;
    });
});
</script>

<?php
$initialComplainant = null;
if (!empty($old['complainant_resident_id']) || !empty($old['complainant_first_name']) || !empty($old['complainant_name'])) {
    $initialComplainant = [
        'resident_id' => $old['complainant_resident_id'] ?? '',
        'first_name' => $old['complainant_first_name'] ?? '',
        'middle_name' => $old['complainant_middle_name'] ?? '',
        'last_name' => $old['complainant_last_name'] ?? '',
        'suffix' => $old['complainant_suffix'] ?? '',
        'birthdate' => $old['complainant_birthdate'] ?? '',
        'age' => $old['complainant_age'] ?? '',
        'gender' => $old['complainant_gender'] ?? '',
        'civil_status' => $old['complainant_civil_status'] ?? '',
        'occupation' => $old['complainant_occupation'] ?? '',
        'contact_no' => $old['complainant_contact_no'] ?? '',
        'email' => $old['complainant_email'] ?? '',
        'preferred_contact' => $old['complainant_preferred_contact'] ?? 'SMS',
        'purok' => $old['complainant_purok'] ?? '',
        'is_tenant' => $old['complainant_is_tenant'] ?? '0',
        'address' => $old['complainant_address'] ?? '',
        'is_minor' => !empty($old['complainant_is_minor']) ? '1' : '0',
        'guardian_name' => $old['complainant_guardian_name'] ?? '',
        'guardian_relationship' => $old['complainant_guardian_relationship'] ?? '',
        'guardian_contact' => $old['complainant_guardian_contact'] ?? '',
        'guardian_address' => $old['complainant_guardian_address'] ?? ''
    ];
}
$initialRespondent = null;
if (!empty($old['respondent_resident_id']) || !empty($old['respondent_first_name']) || !empty($old['respondent_name'])) {
    $initialRespondent = [
        'resident_id' => $old['respondent_resident_id'] ?? '',
        'first_name' => $old['respondent_first_name'] ?? '',
        'middle_name' => $old['respondent_middle_name'] ?? '',
        'last_name' => $old['respondent_last_name'] ?? '',
        'suffix' => $old['respondent_suffix'] ?? '',
        'birthdate' => $old['respondent_birthdate'] ?? '',
        'age' => $old['respondent_age'] ?? '',
        'gender' => $old['respondent_gender'] ?? '',
        'civil_status' => $old['respondent_civil_status'] ?? '',
        'occupation' => $old['respondent_occupation'] ?? '',
        'contact_no' => $old['respondent_contact_no'] ?? '',
        'email' => $old['respondent_email'] ?? '',
        'purok' => $old['respondent_purok'] ?? '',
        'is_tenant' => $old['respondent_is_tenant'] ?? '0',
        'address' => $old['respondent_address'] ?? '',
        'is_minor' => !empty($old['respondent_is_minor']) ? '1' : '0',
        'guardian_name' => $old['respondent_guardian_name'] ?? '',
        'guardian_relationship' => $old['respondent_guardian_relationship'] ?? '',
        'guardian_contact' => $old['respondent_guardian_contact'] ?? '',
        'guardian_address' => $old['respondent_guardian_address'] ?? '',
        'contact_unknown' => !empty($old['respondent_contact_unknown']) ? '1' : '0',
        'middle_unknown' => !empty($old['respondent_middle_name_unknown']) ? '1' : '0',
        'birth_unknown' => !empty($old['respondent_birthdate_unknown']) ? '1' : '0'
    ];
}
?>
<?php include '../../layouts/footer.php'; ?>
