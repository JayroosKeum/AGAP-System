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
$categoriesStmt = $db->query('SELECT category_id, category_name FROM complaint_categories ORDER BY category_id ASC');
$categories = $categoriesStmt ? $categoriesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$residentsStmt = $db->query("
    SELECT resident_id, first_name, middle_name, last_name, contact_no, purok, address
    FROM residents 
    ORDER BY last_name ASC, first_name ASC
");
$residents = $residentsStmt ? $residentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$renderResidentOptions = function(?int $selectedId = null) use ($residents): string {
    $html = '<option value="">Select Resident Profile...</option>';
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
        $optionLabel = $displayName . ' — ' . $addressText;
        $isSelected = ($selectedId !== null && (int)$selectedId === (int)$res['resident_id']) ? ' selected' : '';

        $html .= '<option value="' . (int)$res['resident_id'] . '"'
            . ' data-name="' . htmlspecialchars($fullName, ENT_QUOTES) . '"'
            . ' data-purok="' . htmlspecialchars($res['purok'] ?? '', ENT_QUOTES) . '"'
            . ' data-address="' . htmlspecialchars($res['address'] ?? '', ENT_QUOTES) . '"'
            . ' data-contact="' . htmlspecialchars($res['contact_no'] ?? '', ENT_QUOTES) . '"'
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

    $residentsJsonData[] = [
        'resident_id' => (int) $res['resident_id'],
        'name' => $fullName,
        'display_name' => $displayName,
        'purok' => $res['purok'] ?? '',
        'address' => $res['address'] ?? '',
        'address_text' => $addressText,
        'contact_no' => $res['contact_no'] ?? '',
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
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="page-header">
            <div>
                <a href="complaint-list.php" class="back-link">
                    <svg class="back-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                    <span>Back to Complaints</span>
                </a>
                <h1>New Complaint Intake</h1>
                <p>Record a new barangay complaint with parties, incident details, and location.</p>
            </div>
        </div>

        <?php if ($complaintFlash && !empty($complaintFlash['message'])): ?>
            <div class="alert alert-<?php echo htmlspecialchars($complaintFlash['type'] ?? 'error'); ?>" role="alert" style="margin: 0 30px 20px;">
                <?php echo htmlspecialchars($complaintFlash['message']); ?>
            </div>
        <?php endif; ?>

        <form id="createComplaintForm" action="../../../backend/api/complaints/create.php" method="POST" enctype="multipart/form-data" class="complaint-intake-form">
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

                        <div class="form-row-2">
                            <div class="form-group">
                                <label for="complaintTitle">Complaint Title <span class="required-mark">*</span></label>
                                <input type="text" id="complaintTitle" name="complaint_title" maxlength="255" required placeholder="e.g. Boundary dispute, Trespassing" value="<?php echo htmlspecialchars($old['complaint_title'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="incidentDateTime">Incident Date &amp; Time <span class="required-mark">*</span></label>
                                <?php
                                $defaultDt = $old['incident_datetime'] ?? '';
                                if (!$defaultDt && !empty($old['incident_date'])) {
                                    $defaultDt = $old['incident_date'] . (!empty($old['incident_time']) ? 'T' . substr($old['incident_time'], 0, 5) : 'T00:00');
                                }
                                ?>
                                <input type="datetime-local" id="incidentDateTime" name="incident_datetime" required value="<?php echo htmlspecialchars($defaultDt); ?>">
                                <small class="field-hint">Merged incident date and time of occurrence.</small>
                            </div>
                        </div>
                    </div>

                    <!-- CARD 2: Involved Parties -->
                    <div class="intake-card">
                        <div class="intake-card-header">
                            <h3>2. Involved Parties</h3>
                            <span class="card-subtitle">Select from registered resident profiles to avoid misreporting</span>
                        </div>

                        <div id="partyDistinctError" class="alert alert-danger" style="display:none; margin: 0 0 16px; padding: 10px 14px; font-size: 0.88rem; border-radius: 6px; background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5;">
                            ⚠️ The complainant and respondent cannot be the same resident profile. Please select different individuals.
                        </div>

                        <!-- Complainant Searchable Field -->
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
                                <input type="hidden" id="complainantResidentId" name="complainant_resident_id" required value="<?php echo htmlspecialchars((string)($old['complainant_resident_id'] ?? '')); ?>">
                                <input type="hidden" id="complainantName" name="complainant_name" value="<?php echo htmlspecialchars($old['complainant_name'] ?? ''); ?>">
                                <div class="party-search-dropdown" style="display:none;"></div>
                            </div>
                            <div id="complainantPreview" class="party-selected-preview" style="display:none;"></div>
                            <small class="field-hint">Person filing the complaint (type to search; only registered resident profiles can be selected).</small>
                        </div>

                        <!-- Person Being Complained Against (Respondent) Searchable Field -->
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
                                <input type="hidden" id="respondentResidentId" name="respondent_resident_id" required value="<?php echo htmlspecialchars((string)($old['respondent_resident_id'] ?? '')); ?>">
                                <input type="hidden" id="respondentName" name="respondent_name" value="<?php echo htmlspecialchars($old['respondent_name'] ?? ''); ?>">
                                <div class="party-search-dropdown" style="display:none;"></div>
                            </div>
                            <div id="respondentPreview" class="party-selected-preview" style="display:none;"></div>
                            <small class="field-hint">Person or entity against whom the complaint is filed (type to search; only registered resident profiles can be selected).</small>
                        </div>

                        <!-- Dynamic Additional Parties (Optional) -->
                        <div id="additionalPartiesContainer" class="additional-parties-container">
                            <?php
                            $oldPartyIds = $old['party_resident_ids'] ?? [];
                            $oldPartyTypes = $old['party_types'] ?? [];
                            $oldPartyNames = $old['party_names'] ?? [];
                            if (is_array($oldPartyIds)) {
                                foreach ($oldPartyIds as $idx => $rId) {
                                    $rIdInt = (int)$rId;
                                    if ($rIdInt <= 0) continue;
                                    $pType = $oldPartyTypes[$idx] ?? 'Witness';
                                    $pName = $oldPartyNames[$idx] ?? '';
                                    ?>
                                    <div class="additional-party-row" id="additionalParty_<?php echo $idx; ?>">
                                        <div class="party-row-fields">
                                            <div class="form-group party-type-col">
                                                <select name="party_types[]" class="party-type-select">
                                                    <option value="Witness" <?php echo ($pType === 'Witness') ? 'selected' : ''; ?>>Witness</option>
                                                    <option value="Complainant" <?php echo ($pType === 'Complainant') ? 'selected' : ''; ?>>Complainant</option>
                                                    <option value="Respondent" <?php echo ($pType === 'Respondent') ? 'selected' : ''; ?>>Respondent</option>
                                                </select>
                                            </div>
                                            <div class="form-group party-name-col">
                                                <div class="party-search-combobox" data-party-role="additional">
                                                    <div class="party-search-input-wrap">
                                                        <svg class="party-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                                        <input type="text" class="party-search-input" placeholder="Type resident name or address to search..." autocomplete="off">
                                                        <button type="button" class="party-search-clear" title="Clear selection" style="display:none;">&times;</button>
                                                    </div>
                                                    <input type="hidden" name="party_resident_ids[]" required value="<?php echo $rIdInt; ?>">
                                                    <input type="hidden" name="party_names[]" value="<?php echo htmlspecialchars($pName); ?>">
                                                    <div class="party-search-dropdown" style="display:none;"></div>
                                                </div>
                                            </div>
                                            <button type="button" class="btn-remove-party" title="Remove party" onclick="document.getElementById('additionalParty_<?php echo $idx; ?>').remove()">&times;</button>
                                        </div>
                                        <div class="party-selected-preview additional-party-preview" style="display:none;"></div>
                                    </div>
                                    <?php
                                }
                            }
                            ?>
                        </div>

                        <div class="add-party-action-row">
                            <button type="button" id="addPartyBtn" class="btn-outline-sm">
                                + Add Another Party (Witness / Extra Party)
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
                            <textarea id="narrative" name="narrative" rows="5" maxlength="15000" required placeholder="Describe in detail what occurred, when, and the circumstances surrounding the incident..."><?php echo htmlspecialchars($old['narrative'] ?? ''); ?></textarea>
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
                            <label for="additionalDetails">Additional Details <span class="optional-label">Optional</span></label>
                            <textarea id="additionalDetails" name="additional_details" rows="2" maxlength="5000" placeholder="Prior reconciliation attempts, witnesses present, injuries/damages, or other relevant facts..."><?php echo htmlspecialchars($old['additional_details'] ?? ''); ?></textarea>
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

                        <div class="detail-info-grid">
                            <div class="form-group">
                                <label for="incidentCity">City <span class="required-mark">*</span></label>
                                <input type="text" id="incidentCity" name="incident_city" maxlength="100" required readonly value="<?php echo htmlspecialchars($old['incident_city'] ?? 'Marikina City'); ?>">
                            </div>
                            <div class="form-group">
                                <label for="incidentBarangay">Barangay <span class="required-mark">*</span></label>
                                <input type="text" id="incidentBarangay" name="incident_barangay" maxlength="100" required readonly value="<?php echo htmlspecialchars($old['incident_barangay'] ?? 'Tumana'); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="incidentStreet">Street / Specific Location <span class="required-mark">*</span></label>
                            <input type="text" id="incidentStreet" name="incident_street" maxlength="255" required placeholder="Street, building, or nearby place" value="<?php echo htmlspecialchars($old['incident_street'] ?? $old['incident_location'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="incidentPurok">Purok / Zone (Tumana) <span class="optional-label">Optional</span></label>
                            <?php
                            $currPurok = $old['incident_purok'] ?? '';
                            $knownPuroks = [
                                'Non-Resident',
                                'Purok 1', 'Purok 2', 'Purok 3', 'Purok 4', 'Purok 5', 'Purok 6', 'Purok 7', 'Purok 8',
                                'Doña Petra', 'Bagong Farmers', 'Bukang Liwayway', 'Libis Tumana', 'Bagong Purok', 'Palay', 'Mais', 'Singkamas'
                            ];
                            $isCustomPurok = !empty($currPurok) && !in_array($currPurok, $knownPuroks, true);
                            ?>
                            <select name="incident_purok" id="incidentPurok">
                                <option value="">Select Purok / Area</option>
                                <option value="Non-Resident" <?php echo ($currPurok === 'Non-Resident') ? 'selected' : ''; ?>>Non-Resident / Outside Tumana</option>
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
                            <input type="text" id="incidentLandmark" name="incident_landmark" maxlength="255" placeholder="e.g. Near Barangay Hall, Beside Elementary School" value="<?php echo htmlspecialchars($old['incident_landmark'] ?? ''); ?>">
                        </div>

                        <!-- Map Group -->
                        <div class="form-group complaint-map-group">
                            <label>Exact Map Location <span class="optional-label">Optional</span></label>
                            <input type="hidden" name="map_location_state" value="<?php echo htmlspecialchars($old['map_location_state'] ?? 'none'); ?>" data-map-state>
                            <input type="hidden" name="location_latitude" value="<?php echo htmlspecialchars($old['location_latitude'] ?? ''); ?>" data-map-latitude>
                            <input type="hidden" name="location_longitude" value="<?php echo htmlspecialchars($old['location_longitude'] ?? ''); ?>" data-map-longitude>
                            
                            <p class="map-help">Type an address to place a pin, or click/drag the pin to fill the address. Pins are limited to Barangay Tumana.</p>
                            
                            <div id="addComplaintMap" class="complaint-location-map compact-map" aria-label="Map for selecting the exact incident location"></div>
                            
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
                                <span class="evidence-queue-title">Selected Files (<span id="evidenceCount">0</span>)</span>
                                <button type="button" id="clearAllEvidenceBtn" class="btn-clear-evidence">Clear All</button>
                            </div>
                            <ul id="evidenceQueueList" class="evidence-queue-list"></ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sticky Actions Footer -->
            <div class="intake-actions-bar">
                <a href="complaint-list.php" class="btn-secondary">Cancel</a>
                <button type="submit" class="btn-create">Submit Complaint</button>
            </div>
        </form>

        <!-- Template for dynamic additional parties -->
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
                                <svg class="party-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                <input type="text" class="party-search-input" placeholder="Type resident name or address to search..." autocomplete="off">
                                <button type="button" class="party-search-clear" title="Clear selection" style="display:none;">&times;</button>
                            </div>
                            <input type="hidden" name="party_resident_ids[]" required value="">
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

    // Form submission validation
    const createForm = document.getElementById('createComplaintForm');
    if (createForm) {
        createForm.addEventListener('submit', (e) => {
            const compId = document.getElementById('complainantResidentId')?.value?.trim();
            const respId = document.getElementById('respondentResidentId')?.value?.trim();
            const compSearch = document.getElementById('complainantSearchInput');
            const respSearch = document.getElementById('respondentSearchInput');

            if (!compId) {
                e.preventDefault();
                if (compSearch) {
                    compSearch.classList.add('is-invalid-unselected');
                    compSearch.focus();
                }
                window.agapNotify?.('Please select a registered resident profile for the Complainant.', 'error');
                return;
            }

            if (!respId) {
                e.preventDefault();
                if (respSearch) {
                    respSearch.classList.add('is-invalid-unselected');
                    respSearch.focus();
                }
                window.agapNotify?.('Please select a registered resident profile for the Respondent.', 'error');
                return;
            }

            if (compId === respId) {
                e.preventDefault();
                window.validateDistinctParties();
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
                e.preventDefault();
                window.agapNotify?.('Please select a registered resident for all added additional parties, or remove empty party rows.', 'error');
                return;
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

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn-remove-evidence-item';
            removeBtn.innerHTML = '&times;';
            removeBtn.title = 'Remove this file';
            removeBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                removeFileFromQueue(index);
            });

            li.appendChild(leftDiv);
            li.appendChild(removeBtn);
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
});
</script>

<?php include '../../layouts/footer.php'; ?>
