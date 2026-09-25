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

// Fetch residents for datalist autocomplete
$residentsStmt = $db->query("
    SELECT resident_id, first_name, middle_name, last_name, purok, address
    FROM residents 
    ORDER BY last_name ASC, first_name ASC
");
$residents = $residentsStmt ? $residentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch location
$locStmt = $db->prepare('SELECT latitude, longitude, address FROM incident_locations WHERE complaint_id = ? LIMIT 1');
$locStmt->execute([$complaintId]);
$location = $locStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Fetch parties
$partyStmt = $db->prepare("
    SELECT cp.party_id, cp.party_type, cp.resident_id, r.first_name, r.middle_name, r.last_name, r.contact_no, r.purok, r.address
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
        'party_type' => $p['party_type'],
        'resident_id' => $p['resident_id'],
        'name' => $resName
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
                        <a id="complaintCaseLink" href="../cases/case-details.php?id=<?php echo (int)$linkedCase['case_id']; ?>" class="meta-pill case-pill" title="View linked case workspace">
                            📁 Case #<?php echo htmlspecialchars($linkedCase['case_number'] ?: ('KP-' . str_pad($linkedCase['case_id'], 5, '0', STR_PAD_LEFT))); ?> &rarr;
                        </a>
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
            <div class="alert alert-<?php echo htmlspecialchars($complaintFlash['type'] ?? 'error'); ?>" role="alert" style="margin: 0 30px 20px;">
                <?php echo htmlspecialchars($complaintFlash['message']); ?>
            </div>
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
                                            <a href="../cases/case-details.php?id=<?php echo (int)$linkedCase['case_id']; ?>" class="meta-pill case-pill" style="display:inline-flex;">
                                                📁 <?php echo htmlspecialchars($linkedCase['case_number'] ?: ('Case #' . $linkedCase['case_id'])); ?> &rarr;
                                            </a>
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
                                <span class="card-subtitle">Complainants, respondents, and witnesses</span>
                            </div>

                            <!-- Primary Complainant Textbox -->
                            <div class="form-group party-intake-group">
                                <label for="complainantName">
                                    <span class="party-badge badge-complainant">Complainant</span>
                                    Full Name <span class="required-mark">*</span>
                                </label>
                                <div class="party-input-wrap">
                                    <input type="text" id="complainantName" name="complainant_name" list="residentsDatalist" placeholder="Search resident or type full name..." autocomplete="off" required value="<?php echo htmlspecialchars($effCompName); ?>">
                                    <input type="hidden" id="complainantResidentId" name="complainant_resident_id" value="<?php echo htmlspecialchars($effCompId); ?>">
                                </div>
                                <small class="field-hint">Person filing the complaint.</small>
                            </div>

                            <!-- Primary Respondent Textbox -->
                            <div class="form-group party-intake-group">
                                <label for="respondentName">
                                    <span class="party-badge badge-respondent">Respondent</span>
                                    Person Being Complained Against <span class="required-mark">*</span>
                                </label>
                                <div class="party-input-wrap">
                                    <input type="text" id="respondentName" name="respondent_name" list="residentsDatalist" placeholder="Search resident or type full name..." autocomplete="off" required value="<?php echo htmlspecialchars($effRespName); ?>">
                                    <input type="hidden" id="respondentResidentId" name="respondent_resident_id" value="<?php echo htmlspecialchars($effRespId); ?>">
                                </div>
                                <small class="field-hint">Person or entity against whom the complaint is filed.</small>
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
                                                <input type="text" name="party_names[]" list="residentsDatalist" placeholder="Party full name..." autocomplete="off" required value="<?php echo htmlspecialchars($ap['name']); ?>">
                                                <input type="hidden" name="party_resident_ids[]" value="<?php echo htmlspecialchars($ap['resident_id'] ?? ''); ?>">
                                            </div>
                                            <button type="button" class="btn-remove-party" title="Remove party" onclick="removePartyRow('partyRow_<?php echo $idx; ?>')">&times;</button>
                                        </div>
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
                                    <input type="text" id="incidentBarangay" name="incident_barangay" maxlength="100" required readonly value="<?php echo htmlspecialchars($effBarangay); ?>">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="incidentStreet">Street / Specific Location <span class="required-mark">*</span></label>
                                <input type="text" id="incidentStreet" name="incident_street" maxlength="255" required placeholder="Street, building, or nearby place" value="<?php echo htmlspecialchars($effStreet); ?>">
                            </div>
                            <div class="form-group">
                                <label for="incidentPurok">Purok <span class="optional-label">Optional</span></label>
                                <input type="text" id="incidentPurok" name="incident_purok" maxlength="100" placeholder="e.g. Purok 3" value="<?php echo htmlspecialchars($effPurok); ?>">
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

                                <p class="map-help">Type an address to move the pin, or click/drag the pin to fill the address. Pins are limited to Barangay Tumana.</p>

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
                        </div>

                    </div>

                </div>
            </div>

            <!-- Hidden element for backward compatibility with scripts querying review notes -->
            <div id="complaintReviewNotes" style="display:none;"></div>
        </form>

        <!-- Datalist for autocomplete -->
        <datalist id="residentsDatalist">
            <?php foreach ($residents as $res): ?>
                <?php
                $fullName = trim(implode(' ', array_filter([$res['first_name'], $res['middle_name'] ?? '', $res['last_name']])));
                $purokInfo = !empty($res['purok']) ? " ({$res['purok']})" : '';
                ?>
                <option value="<?php echo htmlspecialchars($fullName); ?>" data-resident-id="<?php echo (int)$res['resident_id']; ?>">
                    <?php echo htmlspecialchars($fullName . $purokInfo); ?>
                </option>
            <?php endforeach; ?>
        </datalist>
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

    // Autocomplete mapping helper
    function setupResidentAutocomplete(nameInputId, hiddenIdId) {
        const nameInput = document.getElementById(nameInputId);
        const hiddenId = document.getElementById(hiddenIdId);
        if (!nameInput || !hiddenId) return;

        nameInput.addEventListener('input', () => {
            const val = nameInput.value.trim().toLowerCase();
            const option = Array.from(document.querySelectorAll('#residentsDatalist option')).find(opt => {
                return opt.value.trim().toLowerCase() === val;
            });
            if (option) {
                hiddenId.value = option.getAttribute('data-resident-id') || '';
            } else {
                hiddenId.value = '';
            }
        });
    }

    setupResidentAutocomplete('complainantName', 'complainantResidentId');
    setupResidentAutocomplete('respondentName', 'respondentResidentId');

    // Attach autocomplete to existing additional parties
    document.querySelectorAll('.additional-party-row').forEach(row => {
        const textInput = row.querySelector('input[type="text"]');
        const hiddenInput = row.querySelector('input[type="hidden"]');
        if (textInput && hiddenInput) {
            textInput.addEventListener('input', () => {
                const val = textInput.value.trim().toLowerCase();
                const option = Array.from(document.querySelectorAll('#residentsDatalist option')).find(opt => {
                    return opt.value.trim().toLowerCase() === val;
                });
                hiddenInput.value = option ? (option.getAttribute('data-resident-id') || '') : '';
            });
        }
    });

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
                        <input type="text" name="party_names[]" list="residentsDatalist" placeholder="Party full name..." autocomplete="off" required>
                        <input type="hidden" name="party_resident_ids[]" value="">
                    </div>
                    <button type="button" class="btn-remove-party" title="Remove party" onclick="removePartyRow('${rowId}')">&times;</button>
                </div>
            `;
            container.appendChild(div);
            updatePartiesCount();

            const input = div.querySelector('input[type="text"]');
            const hidden = div.querySelector('input[type="hidden"]');
            input.addEventListener('input', () => {
                const val = input.value.trim().toLowerCase();
                const option = Array.from(document.querySelectorAll('#residentsDatalist option')).find(opt => {
                    return opt.value.trim().toLowerCase() === val;
                });
                hidden.value = option ? (option.getAttribute('data-resident-id') || '') : '';
            });
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

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn-remove-queue-item';
            removeBtn.title = 'Remove file';
            removeBtn.innerHTML = '&times;';
            removeBtn.addEventListener('click', () => {
                removeFileFromQueue(index);
            });

            li.appendChild(leftDiv);
            li.appendChild(removeBtn);
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
});
</script>

<?php include '../../layouts/footer.php'; ?>
