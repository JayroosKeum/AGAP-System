<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/services/auth.php';

startSecureSession();
requireAdmin();

if (empty($_SESSION['complaint_csrf'])) {
    $_SESSION['complaint_csrf'] = bin2hex(random_bytes(32));
}
$currentPage = 'complaints';

// Fetch Categories
$categoriesStmt = $pdo->query('SELECT category_id, category_name FROM complaint_categories ORDER BY category_id ASC');
$categories = $categoriesStmt ? $categoriesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch Profiles for combobox autocomplete
$profilesStmt = $pdo->query("
    SELECT profile_id, profile_no, first_name, middle_name, last_name, suffix, contact_no,
           purok_sitio, address_line, residency_status
    FROM profiles
    WHERE status = 'Active'
    ORDER BY last_name ASC, first_name ASC
");
$profiles = $profilesStmt ? $profilesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$residentsJsonData = [];
foreach ($profiles as $res) {
    $fullName = trim(implode(' ', array_filter([$res['first_name'], $res['middle_name'] ?? '', $res['last_name'], $res['suffix'] ?? ''])));
    $displayName = ($res['last_name'] !== '-')
        ? ($res['last_name'] . ', ' . $res['first_name'] . (!empty($res['middle_name']) ? ' ' . $res['middle_name'] : '') . (!empty($res['suffix']) ? ' ' . $res['suffix'] : ''))
        : $fullName;
    $addrParts = [];
    if (!empty($res['address_line'])) {
        $addrParts[] = trim($res['address_line']);
    }
    if (!empty($res['purok_sitio']) && (empty($res['address_line']) || stripos($res['address_line'], $res['purok_sitio']) === false)) {
        $addrParts[] = trim($res['purok_sitio']);
    }
    $addressText = !empty($addrParts) ? implode(', ', $addrParts) : 'No address on file';
    $searchText = strtolower($fullName . ' ' . $displayName . ' ' . ($res['purok_sitio'] ?? '') . ' ' . ($res['address_line'] ?? '') . ' ' . ($res['contact_no'] ?? '') . ' ' . ($res['profile_no'] ?? ''));

    $rStatus = $res['residency_status'] ?? 'Permanent Resident';
    $isTenantInt = ($rStatus === 'Non-Resident') ? 2 : (($rStatus === 'Tenant') ? 1 : 0);
    $resLabel = ($isTenantInt === 2) ? 'Non-Resident' : (($isTenantInt === 1) ? 'Tenant - Tumana' : 'Resident');

    $residentsJsonData[] = [
        'resident_id' => (int) $res['profile_id'],
        'name' => $fullName,
        'display_name' => $displayName,
        'purok' => $res['purok_sitio'] ?? '',
        'address' => $res['address_line'] ?? '',
        'address_text' => $addressText,
        'contact_no' => $res['contact_no'] ?? '',
        'is_tenant' => $isTenantInt,
        'residency_label' => $resLabel,
        'search_text' => $searchText,
    ];
}

$complaintFlash = $_SESSION['complaint_flash'] ?? null;
$old = $complaintFlash['old'] ?? [];
unset($_SESSION['complaint_flash']);

$marikinaBarangays = [
    'Tumana', 'Barangka', 'Calumpang', 'Concepcion Uno', 'Concepcion Dos',
    'Fortune', 'Industrial Valley Complex', 'Jesus Dela Peña', 'Malanday',
    'Marikina Heights', 'Nangka', 'Parang', 'San Roque', 'Santa Elena',
    'Santo Niño', 'Tañong'
];
$currBgy = $old['incident_barangay'] ?? 'Tumana';
$currPurok = $old['incident_purok'] ?? '';
$knownPuroks = [
    'Outside Tumana', 'Non-Resident',
    'Purok 1', 'Purok 2', 'Purok 3', 'Purok 4', 'Purok 5', 'Purok 6', 'Purok 7', 'Purok 8',
    'Doña Petra', 'Bagong Farmers', 'Bukang Liwayway', 'Libis Tumana', 'Bagong Purok', 'Palay', 'Mais', 'Singkamas'
];
$isCustomPurok = !empty($currPurok) && !in_array($currPurok, $knownPuroks, true);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New Complaint Intake | AGAP-Remastered</title>
    <link rel="stylesheet" href="frontend/assets/css/app.css">
    <link rel="stylesheet" href="frontend/assets/css/dashboard.css">
    <link rel="stylesheet" href="frontend/assets/css/navigation-pane.css">
    <link rel="stylesheet" href="frontend/assets/css/complaints.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <style>
        .complaint-intake-workspace {
            min-width: 0;
            overflow-y: auto;
            background: #f4f6f8;
            height: 100vh;
        }
        .complaint-intake-container {
            padding: 28px 36px 48px;
            max-width: 1680px;
            margin: 0 auto;
            box-sizing: border-box;
        }
        .page-header-intake {
            margin-bottom: 24px;
        }
        .page-header-intake h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: #0f172a;
            margin: 6px 0 4px;
        }
        .page-header-intake p {
            color: #64748b;
            font-size: 0.95rem;
            margin: 0;
        }
        .complaint-intake-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr);
            gap: 24px;
            margin: 0 0 24px 0 !important;
        }
        .intake-actions-bar {
            position: static !important;
            display: flex !important;
            justify-content: flex-end !important;
            align-items: center !important;
            gap: 12px !important;
            margin: 0 0 40px 0 !important;
            padding: 18px 24px !important;
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 12px !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04) !important;
            box-sizing: border-box !important;
        }
        .intake-actions-bar .btn-secondary {
            background: #f1f5f9 !important;
            color: #334155 !important;
            border: 1px solid #cbd5e1 !important;
            font-weight: 600 !important;
            padding: 10px 22px !important;
            border-radius: 6px !important;
            text-decoration: none !important;
            cursor: pointer !important;
            display: inline-flex !important;
            align-items: center !important;
            font-size: 0.95rem !important;
            transition: all 0.15s ease !important;
        }
        .intake-actions-bar .btn-secondary:hover {
            background: #e2e8f0 !important;
            color: #0f172a !important;
        }
        .intake-actions-bar .btn-create {
            background: #0f172a !important;
            color: #ffffff !important;
            font-weight: 700 !important;
            padding: 10px 24px !important;
            border-radius: 6px !important;
            border: none !important;
            font-size: 0.95rem !important;
            cursor: pointer !important;
            transition: background 0.15s ease !important;
        }
        .intake-actions-bar .btn-create:hover {
            background: #1e293b !important;
        }
        .party-badge-bar {
            display: inline-flex;
            padding: 4px 10px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            border-radius: 4px;
        }
        .party-badge-bar.complainant {
            background: #e0f2fe;
            color: #0284c7;
        }
        .party-badge-bar.respondent {
            background: #fee2e2;
            color: #dc2626;
        }
        .party-badge-bar.witness {
            background: #fef3c7;
            color: #d97706;
        }
        .party-selected-preview {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 10px 14px;
            margin-top: 8px;
        }
        .party-search-combobox {
            position: relative;
        }
        .party-search-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .party-search-clear {
            position: absolute;
            right: 10px;
            background: transparent;
            border: none;
            font-size: 1.25rem;
            color: #94a3b8;
            cursor: pointer;
            line-height: 1;
        }
        .party-search-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 260px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            z-index: 100;
            margin-top: 4px;
        }
        .party-search-item {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: background 0.12s ease;
        }
        .party-search-item:hover, .party-search-item.highlighted {
            background: #f8fafc;
        }
        .party-search-item-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: #0f172a;
        }
        .party-search-item-meta {
            font-size: 0.78rem;
            color: #64748b;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 3px;
        }
        .party-search-item-badge {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: 2px 6px;
            border-radius: 4px;
        }
        .party-search-item-badge.badge-resident { background: #dcfce7; color: #15803d; }
        .party-search-item-badge.badge-tenant { background: #fef3c7; color: #b45309; }
        .party-search-item-badge.badge-non-resident { background: #f1f5f9; color: #475569; }
        .party-search-badge-verified { background: #dcfce7; color: #166534; font-size: 0.72rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; }
        
        /* Dashed / Outline buttons */
        [data-enhance-narrative] {
            width: 100%;
            border: 1px dashed #cbd5e1;
            background: #ffffff;
            color: #334155;
            font-size: 0.88rem;
            font-weight: 600;
            padding: 10px 14px;
            border-radius: 6px;
            cursor: pointer;
            text-align: center;
            transition: all 0.15s ease;
            display: block;
        }
        [data-enhance-narrative]:hover {
            border-color: #d97706;
            color: #b45309;
            background: #fffbeb;
        }
        #addPartyBtn {
            display: inline-block;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #1e293b;
            padding: 8px 16px;
            font-size: 0.85rem;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        #addPartyBtn:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            color: #0f172a;
        }
        #btnProofOcr {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #334155;
            padding: 6px 12px;
            font-size: 0.825rem;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        #btnProofOcr:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            color: #0f172a;
        }
        
        .evidence-upload-zone {
            border: 2px dashed #cbd5e1;
            border-radius: 8px;
            padding: 24px 16px;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: border-color 0.15s ease, background 0.15s ease;
            position: relative;
        }
        .evidence-upload-zone:hover, .evidence-upload-zone.dragover {
            border-color: #2563eb;
            background: #eff6ff;
        }
        .evidence-file-input {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }
        .upload-zone-body {
            pointer-events: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }
        .upload-zone-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #475569;
        }
        .btn-browse-files {
            pointer-events: auto;
            background: #fff;
            border: 1px solid #cbd5e1;
            color: #0f172a;
            padding: 6px 16px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            margin-top: 4px;
        }
        .btn-browse-files:hover {
            background: #f1f5f9;
        }
        .evidence-queue-container {
            margin-top: 12px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px 14px;
        }
        .evidence-queue-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: 8px;
        }
        .evidence-queue-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .evidence-queue-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.825rem;
            padding: 6px 8px;
            background: #f8fafc;
            border-radius: 4px;
        }
        .evidence-item-left {
            display: flex;
            align-items: center;
            gap: 8px;
            overflow: hidden;
        }
        .evidence-item-thumb {
            width: 32px;
            height: 32px;
            object-fit: cover;
            border-radius: 4px;
        }
        .evidence-item-icon {
            width: 32px;
            height: 32px;
            background: #e2e8f0;
            color: #475569;
            font-size: 0.65rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
        }
        .evidence-item-name {
            font-weight: 600;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }
        .btn-remove-evidence {
            background: transparent;
            border: none;
            color: #dc2626;
            cursor: pointer;
            font-size: 1.1rem;
            line-height: 1;
            padding: 2px 6px;
        }
        .btn-clear-evidence {
            background: transparent;
            border: none;
            color: #dc2626;
            font-size: 0.75rem;
            cursor: pointer;
            font-weight: 600;
        }
        .ai-narrative-preview {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 6px;
            padding: 12px;
            margin-top: 10px;
        }
        .ai-narrative-preview strong {
            color: #166534;
            font-size: 0.85rem;
            display: block;
            margin-bottom: 6px;
        }
        .ai-narrative-preview p {
            color: #14532d;
            font-size: 0.9rem;
            line-height: 1.5;
            margin: 0 0 10px;
            white-space: pre-wrap;
        }
        .ai-narrative-preview-actions {
            display: flex;
            gap: 8px;
        }
    </style>
</head>
<body class="dashboard-body">
<div class="dashboard-layout">
    <?php require __DIR__ . '/../components/sidebar.php'; ?>

    <main class="dashboard-workspace complaint-intake-workspace">
        <div class="complaint-intake-container">
        <div class="page-header-intake">
            <a href="index.php?page=complaints" class="back-link">
                <svg class="back-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                <span>Back to Complaints</span>
            </a>
            <h1>New Complaint Intake</h1>
            <p>Record a new barangay complaint with parties, incident details, and location.</p>
        </div>

        <?php if ($complaintFlash && !empty($complaintFlash['message'])): ?>
            <div class="alert alert-<?= ($complaintFlash['type'] ?? '') === 'success' ? 'success' : 'danger' ?>" style="margin-bottom: 20px; padding: 12px 16px; border-radius: 6px; background: <?= ($complaintFlash['type'] ?? '') === 'success' ? '#dcfce7; color: #166534;' : '#fee2e2; color: #991b1b;' ?> font-size: 0.925rem;">
                <?= htmlspecialchars($complaintFlash['message'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form id="createComplaintForm" action="backend/api/complaints/create.php" method="POST" enctype="multipart/form-data" class="complaint-intake-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['complaint_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="bypass_office_hours" value="1">

            <div class="complaint-intake-grid">
                <!-- LEFT COLUMN -->
                <div class="intake-col">
                    <!-- CARD 1: Core Complaint Information -->
                    <div class="intake-card">
                        <div class="intake-card-header">
                            <h3>1. Incident &amp; Classification</h3>
                            <span class="card-subtitle">Basic details about the incident</span>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label for="categoryId">Complaint Category <span class="required-mark">*</span></label>
                                <select id="categoryId" name="category_id" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= (int)$cat['category_id'] ?>" <?= ((string)($old['category_id'] ?? '') === (string)$cat['category_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="caseType">Case Type <span class="required-mark">*</span></label>
                                <select id="caseType" name="case_type" required>
                                    <option value="Civil" <?= (($old['case_type'] ?? 'Civil') === 'Civil') ? 'selected' : '' ?>>Civil</option>
                                    <option value="Criminal" <?= (($old['case_type'] ?? '') === 'Criminal') ? 'selected' : '' ?>>Criminal</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label for="complaintTitle">Complaint Title <span class="required-mark">*</span></label>
                                <input type="text" id="complaintTitle" name="complaint_title" maxlength="255" required placeholder="e.g. Boundary dispute, Trespassing" value="<?= htmlspecialchars($old['complaint_title'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>

                            <div class="form-group">
                                <label for="incidentDateTime">Incident Date &amp; Time <span class="required-mark">*</span></label>
                                <?php
                                $defaultDt = $old['incident_datetime'] ?? '';
                                if (!$defaultDt && !empty($old['incident_date'])) {
                                    $defaultDt = $old['incident_date'] . (!empty($old['incident_time']) ? 'T' . substr($old['incident_time'], 0, 5) : 'T00:00');
                                }
                                ?>
                                <input type="datetime-local" id="incidentDateTime" name="incident_datetime" required value="<?= htmlspecialchars($defaultDt, ENT_QUOTES, 'UTF-8') ?>">
                                <small class="field-hint">Incident date and time.</small>
                            </div>
                        </div>
                    </div>

                    <!-- CARD 2: Involved Parties -->
                    <div class="intake-card">
                        <div class="intake-card-header">
                            <h3>2. Involved Parties</h3>
                            <span class="card-subtitle">Select registered profiles for the dispute parties</span>
                        </div>

                        <div id="partyDistinctError" class="alert alert-danger" style="display:none; margin: 0 0 16px; padding: 10px 14px; font-size: 0.88rem; border-radius: 6px; background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5;">
                            ⚠️ The complainant and respondent cannot be the same profile. Please select different individuals.
                        </div>

                        <!-- Complainant Searchable Field -->
                        <div class="form-group party-intake-group" id="complainantGroup">
                            <label for="complainantSearchInput" style="display:flex; align-items:center; gap:8px;">
                                <span class="party-badge-bar complainant">COMPLAINANT DETAILS</span>
                                <span>Profile <span class="required-mark">*</span></span>
                            </label>
                            <div class="party-search-combobox" data-party-role="complainant">
                                <div class="party-search-input-wrap">
                                    <input type="text" id="complainantSearchInput" class="party-search-input" placeholder="Type complainant name, purok, or address to search..." autocomplete="off">
                                    <button type="button" class="party-search-clear" title="Clear selection" style="display:none;">&times;</button>
                                </div>
                                <input type="hidden" id="complainantResidentId" name="complainant_resident_id" required value="<?= htmlspecialchars((string)($old['complainant_resident_id'] ?? $old['complainant_profile_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" id="complainantName" name="complainant_name" value="<?= htmlspecialchars($old['complainant_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                <div class="party-search-dropdown" style="display:none;"></div>
                            </div>
                            <div id="complainantPreview" class="party-selected-preview" style="display:none;"></div>
                            <small class="field-hint">Person filing the complaint (can be a resident, tenant, or non-resident with dispute standing).</small>
                        </div>

                        <!-- Person Being Complained Against (Respondent) Searchable Field -->
                        <div class="form-group party-intake-group" id="respondentGroup">
                            <label for="respondentSearchInput" style="display:flex; align-items:center; gap:8px;">
                                <span class="party-badge-bar respondent">RESPONDENT DETAILS</span>
                                <span>Person Being Complained Against <span class="required-mark">*</span></span>
                            </label>
                            <div class="party-search-combobox" data-party-role="respondent">
                                <div class="party-search-input-wrap">
                                    <input type="text" id="respondentSearchInput" class="party-search-input" placeholder="Type resident name, purok, or address to search..." autocomplete="off">
                                    <button type="button" class="party-search-clear" title="Clear selection" style="display:none;">&times;</button>
                                </div>
                                <input type="hidden" id="respondentResidentId" name="respondent_resident_id" required value="<?= htmlspecialchars((string)($old['respondent_resident_id'] ?? $old['respondent_profile_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" id="respondentName" name="respondent_name" value="<?= htmlspecialchars($old['respondent_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                <div class="party-search-dropdown" style="display:none;"></div>
                            </div>
                            <div id="respondentPreview" class="party-selected-preview" style="display:none;"></div>
                            <small class="field-hint">Person or entity against whom the complaint is filed (strictly restricted to verified residents of Barangay Tumana).</small>
                        </div>

                        <!-- Dynamic Additional Parties: Witnesses & Extra Parties -->
                        <div class="witness-section-header" style="margin: 16px 0 8px;">
                            <span class="party-badge-bar witness">WITNESS(ES) &amp; ADDITIONAL PARTIES</span>
                        </div>
                        <div id="additionalPartiesContainer" class="additional-parties-container"></div>

                        <div class="add-party-action-row" style="margin-top: 10px;">
                            <button type="button" id="addPartyBtn" class="btn-outline-sm">
                                + Add Witness / Extra Party
                            </button>
                        </div>
                    </div>

                    <!-- CARD 3: Incident Narrative -->
                    <div class="intake-card">
                        <div class="intake-card-header">
                            <h3>3. Narrative &amp; Facts</h3>
                            <span class="card-subtitle">Statement and description of the complaint</span>
                        </div>

                        <div class="form-group">
                            <label for="narrative">Narrative <span class="required-mark">*</span></label>
                            <textarea id="narrative" name="narrative" rows="5" maxlength="15000" required placeholder="Describe in detail what occurred, when, and the circumstances surrounding the incident..."><?= htmlspecialchars($old['narrative'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                            <div class="ai-narrative-actions" style="margin-top: 8px;">
                                <button type="button" class="btn-outline-sm" data-enhance-narrative>✨ Enhance with AI</button>
                                <small class="field-hint">Improves wording only. Review the suggestion before applying it.</small>
                            </div>
                            <div class="ai-narrative-preview" data-narrative-preview hidden>
                                <strong>AI suggestion</strong>
                                <p data-narrative-suggestion></p>
                                <div class="ai-narrative-preview-actions">
                                    <button type="button" class="btn-create" style="padding: 6px 14px; font-size: 0.825rem;" data-apply-narrative>Apply suggestion</button>
                                    <button type="button" class="btn-secondary" style="padding: 6px 14px; font-size: 0.825rem;" data-discard-narrative>Keep my original</button>
                                </div>
                            </div>
                            <small class="field-hint">Required; up to 15,000 characters.</small>
                        </div>

                        <div class="form-group">
                            <label for="additionalDetails">Additional Details <span class="optional-label">Optional</span></label>
                            <textarea id="additionalDetails" name="additional_details" rows="2" maxlength="5000" placeholder="Prior reconciliation attempts, witnesses present, injuries/damages, or other relevant facts..."><?= htmlspecialchars($old['additional_details'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN -->
                <div class="intake-col">
                    <!-- CARD 4: Incident Location & Map Pin -->
                    <div class="intake-card location-card">
                        <div class="intake-card-header">
                            <h3>4. Incident Location</h3>
                            <span class="card-subtitle">Where the incident took place</span>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label for="incidentCity">City <span class="required-mark">*</span></label>
                                <input type="text" id="incidentCity" name="incident_city" maxlength="100" required readonly value="<?= htmlspecialchars($old['incident_city'] ?? 'Marikina City', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="form-group">
                                <label for="incidentBarangay">Barangay <span class="required-mark">*</span></label>
                                <select id="incidentBarangay" name="incident_barangay" required>
                                    <?php foreach ($marikinaBarangays as $bgy): ?>
                                        <option value="<?= htmlspecialchars($bgy, ENT_QUOTES, 'UTF-8') ?>" <?= ($currBgy === $bgy) ? 'selected' : '' ?>><?= htmlspecialchars($bgy, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="incidentStreet">Street / Specific Location <span class="required-mark">*</span></label>
                            <input type="text" id="incidentStreet" name="incident_street" maxlength="255" required placeholder="Street, building, or nearby place" value="<?= htmlspecialchars($old['incident_street'] ?? $old['incident_location'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <div class="form-group">
                            <label for="incidentPurok">Purok / Zone (Tumana) <span class="optional-label">Optional</span></label>
                            <select name="incident_purok" id="incidentPurok">
                                <option value="">Select Purok / Area</option>
                                <option value="Outside Tumana" id="purokOutsideTumanaOption" <?= ($currPurok === 'Outside Tumana' || $currPurok === 'Non-Resident') ? 'selected' : '' ?>>Outside Tumana</option>
                                <optgroup label="Numbered Puroks">
                                    <?php for ($p = 1; $p <= 8; $p++): $pv = "Purok $p"; ?>
                                        <option value="<?= $pv ?>" <?= ($currPurok === $pv) ? 'selected' : '' ?>><?= $pv ?></option>
                                    <?php endfor; ?>
                                </optgroup>
                                <optgroup label="Zones &amp; Compounds">
                                    <?php foreach (['Doña Petra', 'Bagong Farmers', 'Bukang Liwayway', 'Libis Tumana', 'Bagong Purok', 'Palay', 'Mais', 'Singkamas'] as $zone): ?>
                                        <option value="<?= $zone ?>" <?= ($currPurok === $zone) ? 'selected' : '' ?>><?= $zone ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php if ($isCustomPurok): ?>
                                    <option value="<?= htmlspecialchars($currPurok, ENT_QUOTES, 'UTF-8') ?>" selected><?= htmlspecialchars($currPurok, ENT_QUOTES, 'UTF-8') ?> (Existing)</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="incidentLandmark">Nearby Landmark <span class="optional-label">Optional</span></label>
                            <input type="text" id="incidentLandmark" name="incident_landmark" maxlength="255" placeholder="e.g. Near Barangay Hall, Beside Elementary School" value="<?= htmlspecialchars($old['incident_landmark'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <!-- Map Group -->
                        <div class="form-group complaint-map-group">
                            <label>Exact Map Location <span class="optional-label">Optional</span></label>
                            <input type="hidden" name="map_location_state" value="<?= htmlspecialchars($old['map_location_state'] ?? 'none', ENT_QUOTES, 'UTF-8') ?>" data-map-state>
                            <input type="hidden" name="location_latitude" value="<?= htmlspecialchars($old['location_latitude'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-map-latitude>
                            <input type="hidden" name="location_longitude" value="<?= htmlspecialchars($old['location_longitude'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-map-longitude>

                            <p class="map-help">Click on the map or drag the pin to set the exact coordinates, or type the address above.</p>

                            <div id="addComplaintMap" class="complaint-location-map compact-map" style="height: 280px;" aria-label="Map for selecting the exact incident location"></div>

                            <div class="map-selection-row">
                                <span class="map-selection-status" data-map-status>No map point selected.</span>
                                <button type="button" class="btn-secondary btn-sm" data-clear-map>Clear pin</button>
                            </div>
                        </div>
                    </div>

                    <!-- CARD 5: Evidence / Attachments -->
                    <div class="intake-card evidence-card">
                        <div class="intake-card-header">
                            <h3>5. Evidence / Attachments</h3>
                            <span class="card-subtitle">Supporting documents, pictures, or videos <span class="optional-label">Optional</span></span>
                        </div>

                        <div class="evidence-upload-zone" id="evidenceDropZone">
                            <input type="file" id="evidenceFiles" name="evidence[]" multiple accept="image/*,video/*,.pdf,.doc,.docx" class="evidence-file-input">
                            <div class="upload-zone-body">
                                <div class="upload-zone-icon">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                        <polyline points="17 8 12 3 7 8"/>
                                        <line x1="12" y1="3" x2="12" y2="15"/>
                                    </svg>
                                </div>
                                <div class="upload-zone-text">
                                    <p class="upload-primary-text" style="margin: 0;"><strong>Choose files</strong> or drag &amp; drop here</p>
                                    <p class="upload-secondary-text" style="margin: 4px 0 0; color: #64748b; font-size: 0.8rem;">Images, videos, PDF, and Word documents up to 25 MB each</p>
                                </div>
                                <button type="button" class="btn-browse-files" id="btnBrowseEvidence">Browse Files</button>
                            </div>
                        </div>

                        <div id="evidenceValidationAlert" class="evidence-alert-inline" style="display: none; color: #dc2626; font-size: 0.85rem; margin-top: 8px;"></div>

                        <!-- Selected files queue preview -->
                        <div id="evidenceQueueContainer" class="evidence-queue-container" style="display: none;">
                            <div class="evidence-queue-header">
                                <span class="evidence-queue-title">Selected Files (<span id="evidenceCount">0</span>)</span>
                                <button type="button" id="clearAllEvidenceBtn" class="btn-clear-evidence">Clear All</button>
                            </div>
                            <ul id="evidenceQueueList" class="evidence-queue-list"></ul>
                        </div>

                        <!-- Proof OCR Notes -->
                        <div class="form-group" style="margin-top: 18px;">
                            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:6px;">
                                <label for="proofOcrNotes" style="margin:0;">
                                    Proof Notes <span class="optional-label">Optional</span>
                                </label>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <button type="button" class="btn-outline-sm" id="btnProofOcr" title="Scan a handwritten or printed image and extract text into Proof Notes">📷 Scan Proof (OCR)</button>
                                    <input type="file" id="proofOcrFileInput" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;">
                                </div>
                            </div>
                            <textarea id="proofOcrNotes" name="proof_ocr_notes" rows="4" maxlength="10000"
                                placeholder="Extracted text from scanned proofs will appear here. You may also type or paste additional context about the attached evidence..."
                                style="resize:vertical;"><?= htmlspecialchars($old['proof_ocr_notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                            <small class="field-hint">Use ✨ OCR on any uploaded image above, or click “Scan Proof” to pick a file directly. Extracted text is appended here.</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Standalone Actions Card -->
            <div class="intake-actions-bar">
                <a href="index.php?page=complaints" class="btn-secondary">Cancel</a>
                <button type="submit" class="btn-create" id="submitComplaintBtn">Submit Complaint</button>
            </div>
        </form>
        </div>
    </main>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script src="frontend/assets/js/complaints.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Expose registered profiles data for combobox components
    window.AGAP_RESIDENTS = <?= json_encode($residentsJsonData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    // Distinct parties validation
    window.validateDistinctParties = function() {
        const compInput = document.getElementById('complainantResidentId');
        const respInput = document.getElementById('respondentResidentId');
        const errorBox  = document.getElementById('partyDistinctError');
        const compGroup = document.getElementById('complainantGroup');
        const respGroup = document.getElementById('respondentGroup');
        if (!compInput || !respInput) return true;

        const compVal = compInput.value.trim();
        const respVal = respInput.value.trim();

        if (compVal && respVal && compVal === respVal) {
            if (errorBox) {
                errorBox.innerHTML = '<strong>⚠️ Same person selected!</strong> The complainant and respondent cannot be the same resident profile. Please select different individuals.';
                errorBox.style.display = 'block';
            }
            [compGroup, respGroup].forEach(g => {
                if (!g) return;
                g.style.outline = '2px solid #dc2626';
                g.style.outlineOffset = '2px';
                g.style.borderRadius = '6px';
                const inp = g.querySelector('.party-search-input');
                if (inp) inp.style.borderColor = '#dc2626';
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
            const inp = g.querySelector('.party-search-input');
            if (inp) inp.style.borderColor = '';
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
            div.style.marginBottom = '10px';
            div.innerHTML = `
                <div class="party-row-fields" style="display:flex; gap:8px; align-items:center;">
                    <div class="form-group party-type-col" style="margin:0; width:130px;">
                        <select name="party_types[]" class="party-type-select" style="padding:8px;">
                            <option value="Witness">Witness</option>
                            <option value="Complainant">Complainant</option>
                            <option value="Respondent">Respondent</option>
                        </select>
                    </div>
                    <div class="form-group party-name-col" style="margin:0; flex:1;">
                        <div class="party-search-combobox" data-party-role="additional">
                            <div class="party-search-input-wrap">
                                <input type="text" class="party-search-input" placeholder="Type resident name or address to search..." autocomplete="off" style="padding:8px 10px;">
                                <button type="button" class="party-search-clear" title="Clear selection" style="display:none;">&times;</button>
                            </div>
                            <input type="hidden" name="party_resident_ids[]" required value="">
                            <input type="hidden" name="party_names[]" value="">
                            <div class="party-search-dropdown" style="display:none;"></div>
                        </div>
                    </div>
                    <button type="button" class="btn-remove-party" title="Remove party" style="background:#fee2e2; border:1px solid #fca5a5; color:#dc2626; border-radius:6px; padding:6px 10px; cursor:pointer;" onclick="document.getElementById('${rowId}').remove()">&times;</button>
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

    // Initialize standalone map
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
            metaSpan.innerHTML = `<span class="party-search-item-badge" style="background:#e0f2fe; color:#0369a1;">${fileInfo.badge}</span> ${formatFileSize(file.size)}`;

            detailsDiv.appendChild(nameSpan);
            detailsDiv.appendChild(metaSpan);
            leftDiv.appendChild(detailsDiv);

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn-remove-evidence';
            removeBtn.innerHTML = '&times;';
            removeBtn.title = 'Remove file';
            removeBtn.addEventListener('click', () => {
                const newDt = new DataTransfer();
                Array.from(evidenceDataTransfer.files).forEach((f, i) => {
                    if (i !== index) newDt.items.add(f);
                });
                evidenceDataTransfer = newDt;
                evidenceInput.files = evidenceDataTransfer.files;
                renderEvidenceQueue();
            });

            li.appendChild(leftDiv);
            li.appendChild(removeBtn);
            queueList.appendChild(li);
        });
    }

    if (evidenceInput) {
        evidenceInput.addEventListener('change', () => {
            Array.from(evidenceInput.files).forEach(f => {
                if (f.size <= 25 * 1024 * 1024) {
                    evidenceDataTransfer.items.add(f);
                }
            });
            evidenceInput.files = evidenceDataTransfer.files;
            renderEvidenceQueue();
        });
    }

    if (btnBrowseEvidence && evidenceInput) {
        btnBrowseEvidence.addEventListener('click', (e) => {
            e.preventDefault();
            evidenceInput.click();
        });
    }

    if (clearAllBtn) {
        clearAllBtn.addEventListener('click', () => {
            evidenceDataTransfer = new DataTransfer();
            if (evidenceInput) evidenceInput.files = evidenceDataTransfer.files;
            renderEvidenceQueue();
        });
    }

    // Proof OCR handling
    const btnProofOcr = document.getElementById('btnProofOcr');
    const proofOcrFileInput = document.getElementById('proofOcrFileInput');
    const proofOcrNotes = document.getElementById('proofOcrNotes');

    if (btnProofOcr && proofOcrFileInput) {
        btnProofOcr.addEventListener('click', () => proofOcrFileInput.click());
        proofOcrFileInput.addEventListener('change', async () => {
            const file = proofOcrFileInput.files?.[0];
            if (!file) return;

            btnProofOcr.disabled = true;
            btnProofOcr.textContent = 'Scanning Proof...';

            const formData = new FormData();
            formData.append('image', file);

            try {
                const res = await fetch('backend/api/ai/ocr-notes.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json().catch(() => ({}));
                if (data.success && data.text) {
                    proofOcrNotes.value = (proofOcrNotes.value ? proofOcrNotes.value + '\n\n' : '') + data.text;
                    proofOcrNotes.dispatchEvent(new Event('input', { bubbles: true }));
                    alert('Extracted text appended to Proof Notes.');
                } else {
                    alert(data.message || 'Unable to scan document. You can still type notes manually.');
                }
            } catch (err) {
                alert('OCR service error. You can still type notes manually.');
            } finally {
                btnProofOcr.disabled = false;
                btnProofOcr.textContent = '📷 Scan Proof (OCR)';
                proofOcrFileInput.value = '';
            }
        });
    }

    // AI narrative enhancement
    const btnEnhance = document.querySelector('[data-enhance-narrative]');
    const narrativeInput = document.getElementById('narrative');
    const previewBox = document.querySelector('[data-narrative-preview]');
    const suggestionText = document.querySelector('[data-narrative-suggestion]');
    const applyBtn = document.querySelector('[data-apply-narrative]');
    const discardBtn = document.querySelector('[data-discard-narrative]');

    if (btnEnhance && narrativeInput) {
        btnEnhance.addEventListener('click', async () => {
            const val = narrativeInput.value.trim();
            if (val.length < 10) {
                alert('Enter at least 10 characters before using AI enhancement.');
                narrativeInput.focus();
                return;
            }

            btnEnhance.disabled = true;
            btnEnhance.textContent = '✨ Enhancing...';
            if (previewBox) previewBox.hidden = true;

            try {
                const res = await fetch('backend/api/ai/refine-narrative.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ narrative: val })
                });
                const data = await res.json().catch(() => ({}));
                if (data.success && data.narrative) {
                    if (suggestionText) suggestionText.textContent = data.narrative;
                    if (previewBox) {
                        previewBox.hidden = false;
                        previewBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                } else {
                    alert(data.message || 'Unable to enhance narrative at this time.');
                }
            } catch (err) {
                alert('AI service is temporarily unavailable. You may continue writing manually.');
            } finally {
                btnEnhance.disabled = false;
                btnEnhance.textContent = '✨ Enhance with AI';
            }
        });

        applyBtn?.addEventListener('click', () => {
            if (suggestionText?.textContent) {
                narrativeInput.value = suggestionText.textContent;
                narrativeInput.dispatchEvent(new Event('input', { bubbles: true }));
                if (previewBox) previewBox.hidden = true;
            }
        });

        discardBtn?.addEventListener('click', () => {
            if (previewBox) previewBox.hidden = true;
        });
    }
});
</script>
</body>
</html>
