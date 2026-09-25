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
    SELECT resident_id, first_name, middle_name, last_name, purok, address
    FROM residents 
    ORDER BY last_name ASC, first_name ASC
");
$residents = $residentsStmt ? $residentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

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
                            <span class="card-subtitle">Identify the complainant and respondent</span>
                        </div>

                        <!-- Complainant Textbox -->
                        <div class="form-group party-intake-group">
                            <label for="complainantName">
                                <span class="party-badge badge-complainant">Complainant</span>
                                Full Name <span class="required-mark">*</span>
                            </label>
                            <div class="party-input-wrap">
                                <input type="text" id="complainantName" name="complainant_name" list="residentsDatalist" placeholder="Search resident or type full name..." autocomplete="off" required value="<?php echo htmlspecialchars($old['complainant_name'] ?? ''); ?>">
                                <input type="hidden" id="complainantResidentId" name="complainant_resident_id" value="<?php echo htmlspecialchars($old['complainant_resident_id'] ?? ''); ?>">
                            </div>
                            <small class="field-hint">Person filing the complaint.</small>
                        </div>

                        <!-- Person Being Complained Against (Respondent) Textbox -->
                        <div class="form-group party-intake-group">
                            <label for="respondentName">
                                <span class="party-badge badge-respondent">Respondent</span>
                                Person Being Complained Against <span class="required-mark">*</span>
                            </label>
                            <div class="party-input-wrap">
                                <input type="text" id="respondentName" name="respondent_name" list="residentsDatalist" placeholder="Search resident or type full name..." autocomplete="off" required value="<?php echo htmlspecialchars($old['respondent_name'] ?? ''); ?>">
                                <input type="hidden" id="respondentResidentId" name="respondent_resident_id" value="<?php echo htmlspecialchars($old['respondent_resident_id'] ?? ''); ?>">
                            </div>
                            <small class="field-hint">Person or entity against whom the complaint is filed.</small>
                        </div>

                        <!-- Dynamic Additional Parties (Optional) -->
                        <div id="additionalPartiesContainer" class="additional-parties-container"></div>

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
                            <label for="incidentPurok">Purok <span class="optional-label">Optional</span></label>
                            <input type="text" id="incidentPurok" name="incident_purok" maxlength="100" placeholder="e.g. Purok 3" value="<?php echo htmlspecialchars($old['incident_purok'] ?? ''); ?>">
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

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../../assets/js/complaints.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/complaints.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
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

    // Dynamic additional parties
    const container = document.getElementById('additionalPartiesContainer');
    const addBtn = document.getElementById('addPartyBtn');
    let partyCount = 0;

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
                        <input type="text" name="party_names[]" list="residentsDatalist" placeholder="Party full name..." autocomplete="off" required>
                        <input type="hidden" name="party_resident_ids[]" value="">
                    </div>
                    <button type="button" class="btn-remove-party" title="Remove party" onclick="document.getElementById('${rowId}').remove()">&times;</button>
                </div>
            `;
            container.appendChild(div);

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
