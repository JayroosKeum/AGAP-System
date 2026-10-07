<?php

session_start();

if (
    !isset($_SESSION['role_id']) ||
    !in_array((int)$_SESSION['role_id'], [1, 2], true)
) {
    header('Location: ../auth/login.php');
    exit;
}

$complaintId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$complaintId || $complaintId <= 0) {
    header('Location: complaint-list.php');
    exit;
}

require_once __DIR__ . '/../../../backend/config/database.php';
$db = (new Database())->connect();

// Fetch complaint record
$stmt = $db->prepare('SELECT * FROM complaints WHERE complaint_id = ?');
$stmt->execute([$complaintId]);
$complaint = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$complaint) {
    $_SESSION['complaint_flash'] = ['type' => 'error', 'message' => 'The requested complaint could not be found.'];
    header('Location: complaint-list.php');
    exit;
}

// Fetch categories
$categoriesStmt = $db->query('SELECT category_id, category_name FROM complaint_categories ORDER BY category_id ASC');
$categories = $categoriesStmt ? $categoriesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch residents for selection
$residentsStmt = $db->query("
    SELECT resident_id, first_name, middle_name, last_name, contact_no, purok, address, is_tenant
    FROM residents 
    ORDER BY last_name ASC, first_name ASC
");
$residents = $residentsStmt ? $residentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

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
        'purok' => $res['purok'] ?? '',
        'address' => $res['address'] ?? '',
        'address_text' => $addressText,
        'contact_no' => $res['contact_no'] ?? '',
        'is_tenant' => $isTenantInt,
        'residency_label' => $resLabel,
        'search_text' => $searchText,
    ];
}

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
            . ' data-is-tenant="' . $isTenantInt . '"'
            . $isSelected . '>'
            . htmlspecialchars($optionLabel, ENT_QUOTES)
            . '</option>';
    }
    return $html;
};

// Fetch location
$locStmt = $db->prepare('SELECT latitude, longitude, address FROM incident_locations WHERE complaint_id = ? LIMIT 1');
$locStmt->execute([$complaintId]);
$location = $locStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Fetch parties
$partyStmt = $db->prepare("
    SELECT cp.party_id, cp.party_type, cp.resident_id, r.first_name, r.middle_name, r.last_name, r.contact_no, r.purok, r.address, r.is_tenant
    FROM complaint_parties cp
    LEFT JOIN residents r ON r.resident_id = cp.resident_id
    WHERE cp.complaint_id = ?
    ORDER BY cp.party_id ASC
");
$partyStmt->execute([$complaintId]);
$parties = $partyStmt->fetchAll(PDO::FETCH_ASSOC);

$primaryComplainant = null;
$primaryRespondent = null;
$additionalParties = [];

foreach ($parties as $p) {
    $resName = trim(implode(' ', array_filter([$p['first_name'], $p['middle_name'] ?? '', $p['last_name']])));
    $entry = [
        'party_id' => (int) $p['party_id'],
        'party_type' => $p['party_type'],
        'resident_id' => (int) $p['resident_id'],
        'name' => $resName,
        'contact_no' => $p['contact_no'] ?? '',
        'purok' => $p['purok'] ?? '',
        'address' => $p['address'] ?? ''
    ];
    if ($p['party_type'] === 'Complainant' && $primaryComplainant === null) {
        $primaryComplainant = $entry;
    } elseif ($p['party_type'] === 'Respondent' && $primaryRespondent === null) {
        $primaryRespondent = $entry;
    } else {
        $additionalParties[] = $entry;
    }
}

// Fetch existing attachments
$attStmt = $db->prepare('SELECT attachment_id, file_name, file_type, uploaded_at FROM complaint_attachments WHERE complaint_id = ? ORDER BY uploaded_at DESC');
$attStmt->execute([$complaintId]);
$attachments = $attStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch linked case if any
$caseStmt = $db->prepare('SELECT case_id, case_number, case_status FROM cases WHERE complaint_id = ? LIMIT 1');
$caseStmt->execute([$complaintId]);
$linkedCase = $caseStmt->fetch(PDO::FETCH_ASSOC) ?: null;

// Flash message and old input override if validation failed
$complaintFlash = $_SESSION['complaint_flash'] ?? null;
$old = $complaintFlash['old'] ?? [];
unset($_SESSION['complaint_flash']);

if (!empty($old['party_resident_ids']) && is_array($old['party_resident_ids'])) {
    $additionalParties = [];
    foreach ($old['party_resident_ids'] as $idx => $rId) {
        $rIdInt = (int) $rId;
        if ($rIdInt > 0) {
            $additionalParties[] = [
                'party_id' => 0,
                'party_type' => $old['party_types'][$idx] ?? 'Witness',
                'resident_id' => $rIdInt,
                'name' => $old['party_names'][$idx] ?? ''
            ];
        }
    }
}

// Determine effective values (Old input > Database record)
$effCategoryId = $old['category_id'] ?? $complaint['category_id'];
$effCaseType = $old['case_type'] ?? ($complaint['case_type'] ?? 'Civil');
$effTitle = $old['complaint_title'] ?? $complaint['complaint_title'];

// Datetime formatting
$effDatetime = $old['incident_datetime'] ?? '';
if (!$effDatetime && !empty($complaint['incident_date'])) {
    $effDatetime = $complaint['incident_date'] . (!empty($complaint['incident_time']) ? 'T' . substr($complaint['incident_time'], 0, 5) : 'T00:00');
}

$effNarrative = $old['narrative'] ?? $complaint['narrative'];
$effDetails = $old['additional_details'] ?? ($complaint['additional_details'] ?? '');
$effProofOcrNotes = $old['proof_ocr_notes'] ?? ($complaint['proof_ocr_notes'] ?? '');
$effLocation = $old['incident_location'] ?? ($complaint['incident_location'] ?? '');
$effCity = $old['incident_city'] ?? ($complaint['incident_city'] ?? 'Marikina City');
$effBarangay = $old['incident_barangay'] ?? ($complaint['incident_barangay'] ?? 'Tumana');
$effStreet = $old['incident_street'] ?? ($complaint['incident_street'] ?? $effLocation);
$effPurok = $old['incident_purok'] ?? ($complaint['incident_purok'] ?? '');
$effLandmark = $old['incident_landmark'] ?? ($complaint['incident_landmark'] ?? '');

$effCompName = $old['complainant_name'] ?? ($primaryComplainant['name'] ?? '');
$effCompId = $old['complainant_resident_id'] ?? ($primaryComplainant['resident_id'] ?? '');

$effRespName = $old['respondent_name'] ?? ($primaryRespondent['name'] ?? '');
$effRespId = $old['respondent_resident_id'] ?? ($primaryRespondent['resident_id'] ?? '');

$savedLat = $location['latitude'] ?? '';
$savedLng = $location['longitude'] ?? '';

$currentCategoryName = 'General';
foreach ($categories as $cat) {
    if ((string)$cat['category_id'] === (string)$effCategoryId) {
        $currentCategoryName = $cat['category_name'];
        break;
    }
}

$statusSlug = strtolower(preg_replace('/[^a-z0-9]/', '-', $complaint['status'] ?: 'filed'));

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <!-- Modern Page Header with Status & Action Toolbar (copied layout from complaint-details.php) -->
        <div class="complaint-details-header">
            <div class="complaint-details-heading">
                <a href="complaint-details.php?id=<?php echo $complaintId; ?>" class="back-link" onclick="handleBackLink(event)">
                    <svg class="back-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                    <span>Back to Complaint Details</span>
                </a>
                <div class="complaint-title-row">
                    <h1 id="editComplaintHeading">Edit Complaint — <?php echo htmlspecialchars($complaint['complaint_number'] ?: ('CMP-' . str_pad($complaintId, 5, '0', STR_PAD_LEFT))); ?></h1>
                    <span id="complaintStatusBadge" class="status-pill status-<?php echo $statusSlug; ?>"><?php echo htmlspecialchars($complaint['status'] ?: 'Filed'); ?></span>
                </div>
                <p id="editComplaintSubheading" class="complaint-details-subheading"><?php echo htmlspecialchars($effTitle ?: 'Untitled Complaint'); ?></p>
                <div class="complaint-header-badges">
                    <span id="headerCaseTypeBadge" class="meta-pill type-pill"><?php echo htmlspecialchars($effCaseType); ?></span>
                    <span id="headerCategoryBadge" class="meta-pill category-pill"><?php echo htmlspecialchars($currentCategoryName); ?></span>
                    <?php if (!empty($linkedCase)): ?>
                        <span id="complaintCaseLink" class="meta-pill case-pill">
                            📁 Case #<?php echo htmlspecialchars($linkedCase['case_number'] ?: ('Case #' . $linkedCase['case_id'])); ?> &rarr;
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="complaint-header-actions">
                <button type="button" class="btn-secondary" onclick="openDiscardModal()" style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                    Cancel
                </button>
                <button type="button" class="btn-create" onclick="openSaveModal()" style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                    Save Changes
                </button>
            </div>
        </div>

        <?php if ($complaintFlash && !empty($complaintFlash['message'])): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    window.agapNotify && window.agapNotify(
                        <?php echo json_encode($complaintFlash['message']); ?>,
                        <?php echo json_encode(($complaintFlash['type'] ?? '') === 'success' ? 'success' : 'error') ?>
                    );
                });
            </script>
        <?php endif; ?>

        <form id="editComplaintForm" action="../../../backend/api/complaints/update.php" method="POST" enctype="multipart/form-data" class="complaint-intake-form">
            <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">

            <!-- Main Workspace Grid (copied layout from complaint-details.php) -->
            <div class="complaint-details-workspace">
                <div class="complaint-intake-grid">

                    <!-- LEFT COLUMN -->
                    <div class="intake-col">

                        <!-- CARD 1: Incident & Classification -->
                        <div class="intake-card">
                            <div class="intake-card-header">
                                <h3>1. Incident &amp; Classification</h3>
                                <span class="card-subtitle">Category, case type, and timeline details</span>
                            </div>

                            <div class="form-group">
                                <label for="complaintTitle">Complaint Title <span class="required-mark">*</span></label>
                                <input type="text" id="complaintTitle" name="complaint_title" maxlength="255" required placeholder="e.g. Boundary dispute, Trespassing" value="<?php echo htmlspecialchars($effTitle); ?>">
                            </div>

                            <div class="form-row-2">
                                <div class="form-group">
                                    <label for="categoryId">Category <span class="required-mark">*</span></label>
                                    <select id="categoryId" name="category_id" required>
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo (int)$cat['category_id']; ?>" <?php echo ((string)$effCategoryId === (string)$cat['category_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cat['category_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="caseType">Case Type <span class="required-mark">*</span></label>
                                    <select id="caseType" name="case_type" required>
                                        <option value="Civil" <?php echo ($effCaseType === 'Civil') ? 'selected' : ''; ?>>Civil</option>
                                        <option value="Criminal" <?php echo ($effCaseType === 'Criminal') ? 'selected' : ''; ?>>Criminal</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="incidentDateTime">Incident Date &amp; Time <span class="required-mark">*</span></label>
                                <input type="datetime-local" id="incidentDateTime" name="incident_datetime" required value="<?php echo htmlspecialchars($effDatetime); ?>">
                                <small class="field-hint">Merged incident date and time of occurrence.</small>
                            </div>

                            <div class="detail-info-grid" style="margin-top: 14px; padding-top: 14px; border-top: 1px dashed #e2e8f0;">
                                <div class="detail-info-item">
                                    <span class="detail-info-label">Date Filed</span>
                                    <span class="detail-info-value"><?php echo !empty($complaint['created_at']) ? date('M d, Y', strtotime($complaint['created_at'])) : '—'; ?></span>
                                </div>
                                <div class="detail-info-item">
                                    <span class="detail-info-label">Administrative Status</span>
                                    <span class="detail-info-value">
                                        <span class="status-pill status-<?php echo $statusSlug; ?>"><?php echo htmlspecialchars($complaint['status'] ?: 'Filed'); ?></span>
                                    </span>
                                </div>
                                <div class="detail-info-item span-full">
                                    <span class="detail-info-label">Docketed Case</span>
                                    <span class="detail-info-value">
                                        <?php if (!empty($linkedCase)): ?>
                                            <span class="meta-pill case-pill" style="display:inline-flex;">
                                                📁 <?php echo htmlspecialchars($linkedCase['case_number'] ?: ('Case #' . $linkedCase['case_id'])); ?> &rarr;
                                            </span>
                                        <?php else: ?>
                                            Not yet docketed
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- CARD 2: Involved Parties -->
                        <div class="intake-card">
                            <div class="intake-card-header">
                                <h3>2. Involved Parties (<span id="partiesCount"><?php echo count($parties); ?></span>)</h3>
                                <span class="card-subtitle">Choose from registered resident profiles to avoid misreporting</span>
                            </div>

                            <div id="partyDistinctError" class="alert alert-danger" style="display:none; margin: 0 0 16px; padding: 10px 14px; font-size: 0.88rem; border-radius: 6px; background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5;">
                                ⚠️ The complainant and respondent cannot be the same resident profile. Please select different individuals.
                            </div>

                            <!-- Primary Complainant Selection -->
                            <div class="form-group party-intake-group" id="complainantGroup">
                                <label for="complainantSearchInput">
                                    <span class="party-badge badge-complainant">Complainant</span>
                                    Resident Profile <span class="required-mark">*</span>
                                </label>
                                <div class="party-search-combobox" data-party-role="complainant">
                                    <div class="party-search-input-wrap">
                                        <svg class="party-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                        <input type="text" id="complainantSearchInput" class="party-search-input" placeholder="Type resident name, purok, or address to search..." autocomplete="off">
                                        <button type="button" class="party-search-clear" title="Clear selection" style="display:none;">&times;</button>
                                    </div>
                                    <input type="hidden" id="complainantResidentId" name="complainant_resident_id" required value="<?php echo htmlspecialchars((string)$effCompId); ?>">
                                    <input type="hidden" id="complainantName" name="complainant_name" value="<?php echo htmlspecialchars($effCompName); ?>">
                                    <div class="party-search-dropdown" style="display:none;"></div>
                                </div>
                                <div id="complainantPreview" class="party-selected-preview" style="display:none;"></div>
                                <small class="field-hint">Person filing the complaint (type to search; only registered resident profiles can be selected).</small>
                            </div>

                            <!-- Primary Respondent Selection -->
                            <div class="form-group party-intake-group" id="respondentGroup">
                                <label for="respondentSearchInput">
                                    <span class="party-badge badge-respondent">Respondent</span>
                                    Person Being Complained Against <span class="required-mark">*</span>
                                </label>
                                <div class="party-search-combobox" data-party-role="respondent">
                                    <div class="party-search-input-wrap">
                                        <svg class="party-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                        <input type="text" id="respondentSearchInput" class="party-search-input" placeholder="Type resident name, purok, or address to search..." autocomplete="off">
                                        <button type="button" class="party-search-clear" title="Clear selection" style="display:none;">&times;</button>
                                    </div>
                                    <input type="hidden" id="respondentResidentId" name="respondent_resident_id" required value="<?php echo htmlspecialchars((string)$effRespId); ?>">
                                    <input type="hidden" id="respondentName" name="respondent_name" value="<?php echo htmlspecialchars($effRespName); ?>">
                                    <div class="party-search-dropdown" style="display:none;"></div>
                                </div>
                                <div id="respondentPreview" class="party-selected-preview" style="display:none;"></div>
                                <small class="field-hint">Person or entity against whom the complaint is filed (type to search; only registered resident profiles can be selected).</small>
                            </div>

                            <!-- Dynamic Additional Parties -->
                            <div id="additionalPartiesContainer" class="additional-parties-container">
                                <?php foreach ($additionalParties as $idx => $ap): ?>
                                    <div class="additional-party-row" id="partyRow_<?php echo $idx; ?>">
                                        <div class="party-row-fields">
                                            <div class="form-group party-type-col">
                                                <select name="party_types[]">
                                                    <option value="Witness" <?php echo ($ap['party_type'] === 'Witness') ? 'selected' : ''; ?>>Witness</option>
                                                    <option value="Complainant" <?php echo ($ap['party_type'] === 'Complainant') ? 'selected' : ''; ?>>Complainant</option>
                                                    <option value="Respondent" <?php echo ($ap['party_type'] === 'Respondent') ? 'selected' : ''; ?>>Respondent</option>
                                                </select>
                                            </div>
                                            <div class="form-group party-name-col">
                                                <div class="party-search-combobox" data-party-role="additional">
                                                    <div class="party-search-input-wrap">
                                                        <svg class="party-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                                        <input type="text" class="party-search-input" placeholder="Type resident name or address to search..." autocomplete="off">
                                                        <button type="button" class="party-search-clear" title="Clear selection" style="display:none;">&times;</button>
                                                    </div>
                                                    <input type="hidden" name="party_resident_ids[]" required value="<?php echo !empty($ap['resident_id']) ? (int)$ap['resident_id'] : ''; ?>">
                                                    <input type="hidden" name="party_names[]" value="<?php echo htmlspecialchars($ap['name']); ?>">
                                                    <div class="party-search-dropdown" style="display:none;"></div>
                                                </div>
                                            </div>
                                            <button type="button" class="btn-remove-party" title="Remove party" onclick="removePartyRow('partyRow_<?php echo $idx; ?>')">&times;</button>
                                        </div>
                                        <div class="party-selected-preview additional-party-preview" style="display:none;"></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="add-party-action-row">
                                <button type="button" id="addPartyBtn" class="btn-outline-sm">
                                    + Add Another Party (Witness / Extra Party)
                                </button>
                            </div>
                        </div>

                        <!-- CARD 3: Narrative & Facts -->
                        <div class="intake-card">
                            <div class="intake-card-header">
                                <h3>3. Narrative &amp; Facts</h3>
                                <span class="card-subtitle">Statement and description of the complaint</span>
                            </div>

                            <div class="form-group">
                                <label for="narrative">Statement of Complaint / Narrative <span class="required-mark">*</span></label>
                                <textarea id="narrative" name="narrative" rows="5" maxlength="15000" required placeholder="Describe in detail what occurred, when, and the circumstances surrounding the incident..."><?php echo htmlspecialchars($effNarrative); ?></textarea>
                                <div class="ai-narrative-actions">
                                    <button type="button" class="btn-outline-sm" data-enhance-narrative>✨ Enhance with AI</button>
                                    <small class="field-hint">Improves wording only. Review the suggestion before applying it.</small>
                                </div>
                                <div class="ai-narrative-preview" data-narrative-preview hidden>
                                    <strong>AI suggestion</strong>
                                    <p data-narrative-suggestion></p>
                                    <div class="ai-narrative-preview-actions">
                                        <button type="button" class="btn-create btn-sm" data-apply-narrative>Apply suggestion</button>
                                        <button type="button" class="btn-secondary btn-sm" data-discard-narrative>Keep my original</button>
                                    </div>
                                </div>
                                <small class="field-hint">Required; up to 15,000 characters.</small>
                            </div>

                            <div class="form-group">
                                <label for="additionalDetails">Additional Details / Prior Attempts <span class="optional-label">Optional</span></label>
                                <textarea id="additionalDetails" name="additional_details" rows="3" maxlength="5000" placeholder="Prior reconciliation attempts, witnesses present, injuries/damages, or other relevant facts..."><?php echo htmlspecialchars($effDetails); ?></textarea>
                                <small class="field-hint">Optional; up to 5,000 characters.</small>
                            </div>
                        </div>

                    </div>

                    <!-- RIGHT COLUMN -->
                    <div class="intake-col">

                        <!-- CARD 4: Incident Location -->
                        <div class="intake-card location-card">
                            <div class="intake-card-header">
                                <h3>4. Incident Location</h3>
                                <span class="card-subtitle">Geographic location and pinned coordinates</span>
                            </div>

                            <div class="detail-info-grid">
                                <div class="form-group">
                                    <label for="incidentCity">City <span class="required-mark">*</span></label>
                                    <input type="text" id="incidentCity" name="incident_city" maxlength="100" required readonly value="<?php echo htmlspecialchars($effCity); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="incidentBarangay">Barangay <span class="required-mark">*</span></label>
                                    <?php
                                    $marikinaBarangays = [
                                        'Tumana',
                                        'Barangka',
                                        'Calumpang',
                                        'Concepcion Uno',
                                        'Concepcion Dos',
                                        'Fortune',
                                        'Industrial Valley Complex',
                                        'Jesus Dela Peña',
                                        'Malanday',
                                        'Marikina Heights',
                                        'Nangka',
                                        'Parang',
                                        'San Roque',
                                        'Santa Elena',
                                        'Santo Niño',
                                        'Tañong'
                                    ];
                                    $currBgy = $effBarangay ?: 'Tumana';
                                    ?>
                                    <select id="incidentBarangay" name="incident_barangay" required>
                                        <?php foreach ($marikinaBarangays as $bgy): ?>
                                            <option value="<?php echo htmlspecialchars($bgy); ?>" <?php echo ($currBgy === $bgy) ? 'selected' : ''; ?>><?php echo htmlspecialchars($bgy); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="incidentStreet">Street / Specific Location <span class="required-mark">*</span></label>
                                <input type="text" id="incidentStreet" name="incident_street" maxlength="255" required placeholder="Street, building, or nearby place" value="<?php echo htmlspecialchars($effStreet); ?>">
                            </div>
                            <div class="form-group">
                                <label for="incidentPurok">Purok / Zone (Tumana) <span class="optional-label">Optional</span></label>
                                <?php
                                $currPurok = $effPurok;
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
                                <label for="incidentLandmark">Nearby Landmark <span class="optional-label">Optional</span></label>
                                <input type="text" id="incidentLandmark" name="incident_landmark" maxlength="255" placeholder="e.g. Near Barangay Hall, Beside Elementary School" value="<?php echo htmlspecialchars($effLandmark); ?>">
                            </div>

                            <!-- Map Group -->
                            <div class="form-group complaint-map-group">
                                <label>Exact Map Location <span class="optional-label">Optional</span></label>
                                <input type="hidden" name="map_location_state" id="mapLocationState" value="<?php echo ($savedLat && $savedLng) ? 'unchanged' : 'none'; ?>" data-map-state>
                                <input type="hidden" name="location_latitude" id="locationLatitude" value="<?php echo htmlspecialchars($savedLat); ?>" data-map-latitude>
                                <input type="hidden" name="location_longitude" id="locationLongitude" value="<?php echo htmlspecialchars($savedLng); ?>" data-map-longitude>

                                <p class="map-help">Click on the map or drag the pin to set the exact coordinates in Marikina City, or type the address above.</p>

                                <div id="editComplaintMap" class="complaint-location-map compact-map" aria-label="Map for selecting the exact incident location"></div>

                                <div class="map-selection-row">
                                    <span class="map-selection-status" data-map-status>
                                        <?php echo ($savedLat && $savedLng) ? "Saved map point: " . number_format((float)$savedLat, 6) . ", " . number_format((float)$savedLng, 6) : "No map point selected."; ?>
                                    </span>
                                    <button type="button" class="btn-secondary btn-sm" data-clear-map>Clear pin</button>
                                </div>
                            </div>
                        </div>

                        <!-- CARD 5: Evidence & Attachments -->
                        <div class="intake-card evidence-card">
                            <div class="intake-card-header">
                                <h3>5. Evidence / Attachments (<span id="attachmentsCount"><?php echo count($attachments); ?></span>)</h3>
                                <span class="card-subtitle">Supporting documents, pictures, or videos</span>
                            </div>

                            <!-- Existing Attachments List -->
                            <div id="existingAttachmentsList" class="attachments-detail-list" style="margin-bottom: 16px;">
                                <?php if (empty($attachments)): ?>
                                    <div class="empty-detail-state" id="existingEmptyState" style="padding: 12px; margin-bottom: 8px;">
                                        <p style="margin:0;">No evidence or attachments uploaded yet.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($attachments as $att): ?>
                                        <?php
                                        $attId = (int)$att['attachment_id'];
                                        $downloadUrl = "../../../backend/api/complaints/download-attachment.php?id={$attId}";
                                        $ext = strtolower(pathinfo($att['file_name'], PATHINFO_EXTENSION));
                                        $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) || str_starts_with((string)$att['file_type'], 'image/');
                                        $isVid = in_array($ext, ['mp4', 'webm'], true) || str_starts_with((string)$att['file_type'], 'video/');
                                        $isPdf = $ext === 'pdf' || $att['file_type'] === 'application/pdf';
                                        $isDoc = in_array($ext, ['doc', 'docx'], true) || str_contains((string)$att['file_type'], 'word');

                                        $iconClass = 'icon-file';
                                        $iconText = 'FILE';
                                        if ($isVid) { $iconClass = 'icon-video'; $iconText = 'VID'; }
                                        elseif ($isPdf) { $iconClass = 'icon-pdf'; $iconText = 'PDF'; }
                                        elseif ($isDoc) { $iconClass = 'icon-doc'; $iconText = 'DOC'; }
                                        ?>
                                        <div class="attachment-detail-card" id="attCard_<?php echo $attId; ?>">
                                            <div class="attachment-detail-left">
                                                <?php if ($isImg): ?>
                                                    <img src="<?php echo htmlspecialchars($downloadUrl); ?>" class="attachment-thumb-img" alt="<?php echo htmlspecialchars($att['file_name']); ?>">
                                                <?php else: ?>
                                                    <div class="attachment-type-icon <?php echo $iconClass; ?>"><?php echo $iconText; ?></div>
                                                <?php endif; ?>
                                                <div style="min-width: 0; flex: 1;">
                                                    <a href="<?php echo htmlspecialchars($downloadUrl); ?>" class="attachment-detail-name" title="Download <?php echo htmlspecialchars($att['file_name']); ?>">
                                                        <?php echo htmlspecialchars($att['file_name']); ?>
                                                    </a>
                                                    <div class="attachment-detail-meta">
                                                        <span><?php echo htmlspecialchars($att['file_type'] ?: strtoupper($ext)); ?></span> &bull;
                                                        <span><?php echo !empty($att['uploaded_at']) ? date('M d, Y h:i A', strtotime($att['uploaded_at'])) : ''; ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="attachment-detail-actions">
                                                <?php if ($isImg): ?>
                                                    <button type="button" class="btn-icon-action btn-ocr-existing" title="Extract text from this image into the narrative" data-att-url="<?php echo htmlspecialchars($downloadUrl); ?>" data-att-name="<?php echo htmlspecialchars($att['file_name']); ?>" onclick="runOcrOnExistingAttachment(this)">
                                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                                                    </button>
                                                <?php endif; ?>
                                                <a href="<?php echo htmlspecialchars($downloadUrl); ?>" class="btn-icon-action" title="Download file" download>
                                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                                </a>
                                                <button type="button" class="btn-icon-action delete" title="Delete attachment" onclick="deleteExistingAttachment(<?php echo $attId; ?>, this)">
                                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <!-- Upload New Evidence Zone -->
                            <label style="font-weight: 600; font-size: 0.88rem; color: #334155; margin-bottom: 6px; display: block;">Upload Additional Evidence <span class="optional-label">Optional</span></label>
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
                                        <p class="upload-primary-text"><strong>Choose files</strong> or drag &amp; drop here</p>
                                        <p class="upload-secondary-text">Images, videos, PDF, and Word documents up to 25 MB each</p>
                                    </div>
                                    <button type="button" class="btn-browse-files" id="btnBrowseEvidence">Browse Files</button>
                                </div>
                            </div>

                            <div id="evidenceValidationAlert" class="evidence-alert-inline" style="display: none;"></div>

                            <!-- Selected files queue preview -->
                            <div id="evidenceQueueContainer" class="evidence-queue-container" style="display: none;">
                                <div class="evidence-queue-header">
                                    <span class="evidence-queue-title">New Files to Upload (<span id="evidenceCount">0</span>)</span>
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
                                    style="resize:vertical;"><?php echo htmlspecialchars($effProofOcrNotes); ?></textarea>
                                <small class="field-hint">Use ✨ OCR on any image above, or click “Scan Proof” to pick a file. Extracted text is appended here.</small>
                            </div>
                        </div>

                    </div>

                </div>
            </div>

            <!-- Hidden element for backward compatibility with scripts querying review notes -->
            <div id="complaintReviewNotes" style="display:none;"></div>
        </form>

        <!-- Template for dynamic additional party resident options -->
        <template id="residentOptionsTemplate">
            <?php echo $renderResidentOptions(null); ?>
        </template>
    </div>
</div>

<!-- Modal: Discard Changes Confirmation -->
<div id="discardChangesModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="discardModalTitle" style="display: none;">
    <div class="modal-content" style="width: 440px; max-width: calc(100% - 32px); padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15), 0 10px 10px -5px rgba(0, 0, 0, 0.05);">
        <div class="modal-header" style="margin-bottom: 14px; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: #fee2e2; display: flex; align-items: center; justify-content: center; color: #dc2626; flex-shrink: 0;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                </div>
                <h2 id="discardModalTitle" style="font-size: 1.15rem; font-weight: 600; color: #1e293b; margin: 0;">Discard Changes?</h2>
            </div>
            <button type="button" class="close-btn" onclick="closeDiscardModal()" aria-label="Close modal">&times;</button>
        </div>
        <p style="color: #475569; font-size: 0.95rem; line-height: 1.5; margin: 0 0 22px 0;">
            Are you sure you want to discard the changes?
        </p>
        <div class="modal-actions" style="margin-top: 0; display: flex; justify-content: flex-end; gap: 10px;">
            <button type="button" class="btn-secondary" onclick="closeDiscardModal()">No, Keep Editing</button>
            <a href="complaint-details.php?id=<?php echo $complaintId; ?>" class="btn-danger" style="text-decoration: none; padding: 9px 18px; border-radius: 6px; font-weight: 500; font-size: 0.9rem; background: #dc2626; color: #ffffff; display: inline-flex; align-items: center;">Yes, Discard</a>
        </div>
    </div>
</div>

<!-- Modal: Save Changes Confirmation -->
<div id="saveChangesModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="saveModalTitle" style="display: none;">
    <div class="modal-content" style="width: 440px; max-width: calc(100% - 32px); padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15), 0 10px 10px -5px rgba(0, 0, 0, 0.05);">
        <div class="modal-header" style="margin-bottom: 14px; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: #ecfdf5; display: flex; align-items: center; justify-content: center; color: #059669; flex-shrink: 0;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                </div>
                <h2 id="saveModalTitle" style="font-size: 1.15rem; font-weight: 600; color: #1e293b; margin: 0;">Save Changes?</h2>
            </div>
            <button type="button" class="close-btn" onclick="closeSaveModal()" aria-label="Close modal">&times;</button>
        </div>
        <p style="color: #475569; font-size: 0.95rem; line-height: 1.5; margin: 0 0 22px 0;">
            Do you want to save the changes made to this complaint?
        </p>
        <div class="modal-actions" style="margin-top: 0; display: flex; justify-content: flex-end; gap: 10px;">
            <button type="button" class="btn-secondary" onclick="closeSaveModal()">No, Keep Editing</button>
            <button type="button" class="btn-create" id="btnConfirmSave" onclick="confirmAndSubmitForm()">Yes, Save Changes</button>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../../assets/js/complaints.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/complaints.js'); ?>"></script>
<script>
let isConfirmedSubmit = false;

function openDiscardModal() {
    const m = document.getElementById('discardChangesModal');
    if (m) m.style.display = 'flex';
}

function closeDiscardModal() {
    const m = document.getElementById('discardChangesModal');
    if (m) m.style.display = 'none';
}

function handleBackLink(e) {
    e.preventDefault();
    openDiscardModal();
}

function openSaveModal() {
    const form = document.getElementById('editComplaintForm');
    if (!form) return;

    const compId = document.getElementById('complainantResidentId')?.value?.trim();
    const respId = document.getElementById('respondentResidentId')?.value?.trim();
    const compSearch = document.getElementById('complainantSearchInput');
    const respSearch = document.getElementById('respondentSearchInput');

    if (!compId) {
        if (compSearch) {
            compSearch.classList.add('is-invalid-unselected');
            compSearch.focus();
        }
        window.agapNotify?.('Please select a registered resident profile for the Complainant.', 'error');
        return;
    }

    if (!respId) {
        if (respSearch) {
            respSearch.classList.add('is-invalid-unselected');
            respSearch.focus();
        }
        window.agapNotify?.('Please select a registered profile for the Respondent.', 'error');
        return;
    }

    // Check that respondent is strictly a resident or tenant living within Barangay Tumana
    const selectedResp = window.AGAP_RESIDENTS?.find(r => String(r.resident_id) === String(respId));
    if (selectedResp) {
        const isTenant = Number(selectedResp.is_tenant);
        const purok = (selectedResp.purok || '').trim().toLowerCase();
        if (isTenant === 2 || purok === 'non-resident' || (isTenant !== 0 && isTenant !== 1)) {
            if (respSearch) {
                respSearch.classList.add('is-invalid-unselected');
                respSearch.focus();
            }
            window.agapNotify?.('Only residents and tenants living within Barangay Tumana can be named as respondents in this barangay.', 'error');
            return;
        }
    }

    const cityInput = document.getElementById('incidentCity');
    if (cityInput && (!cityInput.value || !cityInput.value.toLowerCase().includes('marikina'))) {
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
            window.agapNotify?.('Incident location must be within Marikina City.', 'error');
            return;
        }
    }

    if (!validateDistinctParties()) {
        window.agapNotify?.('The complainant and respondent cannot be the same resident profile.', 'error');
        if (respSearch) respSearch.focus();
        return;
    }

    let invalidAdditional = false;
    document.querySelectorAll('.additional-party-row').forEach(row => {
        const hidden = row.querySelector('input[name="party_resident_ids[]"]');
        const search = row.querySelector('.party-search-input');
        if (hidden && (!hidden.value || parseInt(hidden.value, 10) <= 0)) {
            invalidAdditional = true;
            if (search) search.classList.add('is-invalid-unselected');
        }
    });

    if (invalidAdditional) {
        window.agapNotify?.('Please select a registered resident for all added additional parties, or remove empty party rows.', 'error');
        return;
    }

    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const m = document.getElementById('saveChangesModal');
    if (m) m.style.display = 'flex';
}

function closeSaveModal() {
    const m = document.getElementById('saveChangesModal');
    if (m) m.style.display = 'none';
}

function confirmAndSubmitForm() {
    const form = document.getElementById('editComplaintForm');
    const btn = document.getElementById('btnConfirmSave');
    if (!validateDistinctParties()) {
        closeSaveModal();
        return;
    }
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Saving...';
    }
    isConfirmedSubmit = true;
    if (form) {
        HTMLFormElement.prototype.submit.call(form);
    }
}

function removePartyRow(rowId) {
    const el = document.getElementById(rowId);
    if (el) {
        el.remove();
        updatePartiesCount();
    }
}

function updatePartiesCount() {
    const countEl = document.getElementById('partiesCount');
    if (!countEl) return;
    const baseParties = 2; // Primary Complainant & Primary Respondent
    const additionalRows = document.querySelectorAll('.additional-party-row').length;
    countEl.textContent = baseParties + additionalRows;
}

function validateDistinctParties() {
    const compInput = document.getElementById('complainantResidentId');
    const respInput = document.getElementById('respondentResidentId');
    const respSearch = document.getElementById('respondentSearchInput');
    const errorBox   = document.getElementById('partyDistinctError');
    const compGroup  = document.getElementById('complainantGroup');
    const respGroup  = document.getElementById('respondentGroup');
    if (!compInput || !respInput) return true;

    const compVal = compInput.value.trim();
    const respVal = respInput.value.trim();

    if (compVal && respVal && compVal === respVal) {
        if (respSearch) respSearch.classList.add('is-invalid-unselected');
        if (errorBox) {
            errorBox.innerHTML = '<strong>⚠️ Same person selected!</strong> The complainant and respondent cannot be the same resident profile. Please select different individuals.';
            errorBox.style.display = 'block';
        }
        // Add red border to both party combobox groups
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

    if (respSearch) respSearch.classList.remove('is-invalid-unselected');
    if (errorBox) {
        errorBox.style.display = 'none';
    }
    // Remove red border
    [compGroup, respGroup].forEach(g => {
        if (!g) return;
        g.style.outline = '';
        g.style.outlineOffset = '';
        const inp = g.querySelector('.party-search-input');
        if (inp) inp.style.borderColor = '';
    });
    return true;
}

window.validateDistinctParties = validateDistinctParties;
window.syncDistinctPartyOptions = function() {
    validateDistinctParties();
};

async function deleteExistingAttachment(attachmentId, btn) {
    if (!confirm('Are you sure you want to delete this attachment?')) return;
    try {
        const response = await fetch('../../../backend/api/complaints/attachments.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'attachment_id=' + encodeURIComponent(attachmentId)
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Failed to delete attachment.');

        const card = btn.closest('.attachment-detail-card');
        if (card) {
            card.remove();
            const list = document.getElementById('existingAttachmentsList');
            const countBadge = document.getElementById('attachmentsCount');
            if (list) {
                const remaining = list.querySelectorAll('.attachment-detail-card').length;
                if (countBadge) countBadge.textContent = remaining;
                if (remaining === 0) {
                    const empty = document.getElementById('existingEmptyState');
                    if (empty) empty.style.display = 'block';
                    else {
                        list.innerHTML = '<div class="empty-detail-state" id="existingEmptyState" style="padding: 12px; margin-bottom: 8px;"><p style="margin:0;">No evidence or attachments uploaded yet.</p></div>';
                    }
                }
            }
        }
        window.agapNotify?.('Attachment deleted successfully.', 'success');
    } catch (err) {
        alert(err.message || 'Unable to delete attachment.');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // Intercept form submit event (e.g. Enter key pressed)
    const editForm = document.getElementById('editComplaintForm');
    if (editForm) {
        editForm.addEventListener('submit', (e) => {
            if (!isConfirmedSubmit) {
                e.preventDefault();
                openSaveModal();
            }
        });
    }

    // Modal dismiss on backdrop click
    ['discardChangesModal', 'saveChangesModal'].forEach(id => {
        const modal = document.getElementById(id);
        if (modal) {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }
    });

    // Modal dismiss on Escape key
    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeDiscardModal();
            closeSaveModal();
        }
    });

    // Expose registered residents data for combobox components
    window.AGAP_RESIDENTS = <?php echo json_encode($residentsJsonData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    // Initialize all searchable resident comboboxes
    if (window.initAllPartyComboboxes) {
        window.initAllPartyComboboxes();
    }
    updatePartiesCount();

    // Dynamic additional parties
    const addPartyBtn = document.getElementById('addPartyBtn');
    const container = document.getElementById('additionalPartiesContainer');
    let partyCounter = <?php echo count($additionalParties) + 1; ?>;

    if (addPartyBtn && container) {
        addPartyBtn.addEventListener('click', () => {
            partyCounter++;
            const rowId = `extraPartyRow_${partyCounter}`;
            const div = document.createElement('div');
            div.className = 'additional-party-row';
            div.id = rowId;
            div.innerHTML = `
                <div class="party-row-fields">
                    <div class="form-group party-type-col">
                        <select name="party_types[]">
                            <option value="Witness">Witness</option>
                            <option value="Complainant">Complainant</option>
                            <option value="Respondent">Respondent</option>
                        </select>
                    </div>
                    <div class="form-group party-name-col">
                        <div class="party-search-combobox" data-party-role="additional">
                            <div class="party-search-input-wrap">
                                <svg class="party-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                <input type="text" class="party-search-input" placeholder="Type resident name or address to search..." autocomplete="off">
                                <button type="button" class="party-search-clear" title="Clear selection" style="display:none;">&times;</button>
                            </div>
                            <input type="hidden" name="party_resident_ids[]" required value="">
                            <input type="hidden" name="party_names[]" value="">
                            <div class="party-search-dropdown" style="display:none;"></div>
                        </div>
                    </div>
                    <button type="button" class="btn-remove-party" title="Remove party" onclick="removePartyRow('${rowId}')">&times;</button>
                </div>
                <div class="party-selected-preview additional-party-preview" style="display:none;"></div>
            `;
            container.appendChild(div);

            const newCombobox = div.querySelector('.party-search-combobox');
            if (newCombobox && window.initPartySearchCombobox) {
                window.initPartySearchCombobox(newCombobox, window.AGAP_RESIDENTS);
            }
            updatePartiesCount();
        });
    }

    // Sync header badges with form controls
    const titleInput = document.getElementById('complaintTitle');
    const titleHeading = document.getElementById('editComplaintSubheading');
    if (titleInput && titleHeading) {
        titleInput.addEventListener('input', () => {
            titleHeading.textContent = titleInput.value.trim() || 'Untitled Complaint';
        });
    }

    const categorySelect = document.getElementById('categoryId');
    const categoryBadge = document.getElementById('headerCategoryBadge');
    if (categorySelect && categoryBadge) {
        categorySelect.addEventListener('change', () => {
            const opt = categorySelect.options[categorySelect.selectedIndex];
            categoryBadge.textContent = opt ? opt.text : 'General';
        });
    }

    const caseTypeSelect = document.getElementById('caseType');
    const caseTypeBadge = document.getElementById('headerCaseTypeBadge');
    if (caseTypeSelect && caseTypeBadge) {
        caseTypeSelect.addEventListener('change', () => {
            caseTypeBadge.textContent = caseTypeSelect.value || 'Civil';
        });
    }

    // The shared complaints script initializes the Tumana-restricted map.

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
            metaSpan.innerHTML = `<span class="evidence-type-badge">${fileInfo.badge}</span> &bull; ${formatFileSize(file.size)}`;

            detailsDiv.appendChild(nameSpan);
            detailsDiv.appendChild(metaSpan);
            leftDiv.appendChild(detailsDiv);

            const actionsDiv = document.createElement('div');
            actionsDiv.className = 'evidence-item-actions';
            actionsDiv.style.cssText = 'display:flex;align-items:center;gap:6px;flex-shrink:0;';

            if (fileInfo.type === 'image') {
                const ocrBtn = document.createElement('button');
                ocrBtn.type = 'button';
                ocrBtn.className = 'btn-outline-sm btn-ocr-queue';
                ocrBtn.title = 'Extract text from this image and append to narrative';
                ocrBtn.style.cssText = 'font-size:0.75rem;padding:3px 8px;white-space:nowrap;';
                ocrBtn.textContent = '✨ OCR';
                ocrBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    runProofOcrOnQueuedFile(file, ocrBtn);
                });
                actionsDiv.appendChild(ocrBtn);
            }

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn-remove-queue-item';
            removeBtn.title = 'Remove file';
            removeBtn.innerHTML = '&times;';
            removeBtn.addEventListener('click', () => {
                removeFileFromQueue(index);
            });
            actionsDiv.appendChild(removeBtn);

            li.appendChild(leftDiv);
            li.appendChild(actionsDiv);
            queueList.appendChild(li);
        });
    }

    function removeFileFromQueue(indexToRemove) {
        const nextDt = new DataTransfer();
        Array.from(evidenceDataTransfer.files).forEach((file, index) => {
            if (index !== indexToRemove) {
                nextDt.items.add(file);
            }
        });
        evidenceDataTransfer = nextDt;
        if (evidenceInput) {
            evidenceInput.files = evidenceDataTransfer.files;
        }
        renderEvidenceQueue();
    }

    function handleFilesAdded(fileList) {
        hideEvidenceAlert();
        let errors = [];

        Array.from(fileList).forEach(file => {
            const ext = file.name.split('.').pop().toLowerCase();
            if (!allowedExtensions.includes(ext)) {
                errors.push(`"${file.name}": Unsupported format. Allowed: JPG, PNG, GIF, WebP, MP4, WebM, PDF, DOC, DOCX.`);
                return;
            }
            if (file.size > maxFileSize) {
                errors.push(`"${file.name}": File size exceeds 25 MB limit.`);
                return;
            }
            const exists = Array.from(evidenceDataTransfer.files).some(existing => existing.name === file.name && existing.size === file.size);
            if (!exists) {
                evidenceDataTransfer.items.add(file);
            }
        });

        if (evidenceInput) {
            evidenceInput.files = evidenceDataTransfer.files;
        }

        renderEvidenceQueue();

        if (errors.length > 0) {
            showEvidenceAlert(errors.join(' '));
        }
    }

    if (btnBrowseEvidence && evidenceInput) {
        btnBrowseEvidence.addEventListener('click', () => {
            evidenceInput.click();
        });
    }

    if (evidenceInput) {
        evidenceInput.addEventListener('change', (e) => {
            if (e.target.files && e.target.files.length > 0) {
                handleFilesAdded(e.target.files);
            }
        });
    }

    if (clearAllBtn) {
        clearAllBtn.addEventListener('click', () => {
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
                evidenceDropZone.classList.add('is-dragover');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            evidenceDropZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                evidenceDropZone.classList.remove('is-dragover');
            }, false);
        });

        evidenceDropZone.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            if (dt && dt.files && dt.files.length > 0) {
                handleFilesAdded(dt.files);
            }
        }, false);
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
        triggerBtn.textContent = '⏳ Scanning…';

        try {
            const formData = new FormData();
            formData.append('image', file, file.name);

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
                window.agapNotify?.(data.error || 'OCR did not extract any text.', 'error', 'Proof OCR');
            }
        } catch (err) {
            console.error('Proof OCR error:', err);
            window.agapNotify?.('Failed to connect to the OCR service.', 'error', 'Proof OCR');
        } finally {
            triggerBtn.disabled = false;
            triggerBtn.textContent = originalLabel;
        }
    }

    // OCR helper: existing saved attachments (fetches the image URL, converts to blob)
    window.runOcrOnExistingAttachment = async function(btn) {
        const narrativeTa = document.getElementById('narrative');
        if (!narrativeTa) return;

        const url     = btn.dataset.attUrl;
        const attName = btn.dataset.attName || 'attachment';

        if (!url) return;

        const originalTitle = btn.title;
        btn.disabled = true;
        btn.title = 'Scanning…';
        btn.style.opacity = '0.6';

        try {
            // Fetch the image as blob so we can POST it
            const imgResp = await fetch(url);
            if (!imgResp.ok) throw new Error('Could not fetch image.');
            const blob = await imgResp.blob();
            const file = new File([blob], attName, { type: blob.type });

            const formData = new FormData();
            formData.append('image', file, attName);

            const ocrResp = await fetch('../../../backend/api/ai/ocr-notes.php', {
                method: 'POST',
                body: formData
            });
            const data = await ocrResp.json();

            if (data.success && data.text && data.text.trim()) {
                const label = `[Scanned Proof – ${attName}]:\n${data.text.trim()}`;
                const current = narrativeTa.value.trim();
                narrativeTa.value = current ? current + '\n\n' + label : label;
                narrativeTa.dispatchEvent(new Event('input'));
                window.agapNotify?.('Proof text extracted and appended to the narrative.', 'success', 'Proof OCR');
            } else {
                window.agapNotify?.(data.error || 'OCR did not extract any text.', 'error', 'Proof OCR');
            }
        } catch (err) {
            console.error('Existing attachment OCR error:', err);
            window.agapNotify?.('Failed to scan attachment. Please try again.', 'error', 'Proof OCR');
        } finally {
            btn.disabled = false;
            btn.title = originalTitle;
            btn.style.opacity = '';
        }
    };
});
</script>

<?php include '../../layouts/footer.php'; ?>
