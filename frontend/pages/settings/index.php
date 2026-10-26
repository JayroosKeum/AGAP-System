<?php
require_once __DIR__ . '/../../../backend/config/Database.php';
require_once __DIR__ . '/../../../backend/models/Setting.php';
require_once __DIR__ . '/../../../backend/services/CsrfService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$roleId = (int)($_SESSION['role_id'] ?? 0);

if ($userId <= 0) {
    header('Location: ../auth/login.php');
    exit;
}

$isAdmin = in_array($roleId, [1, 2], true);
$activeTab = trim((string)($_GET['tab'] ?? ''));
if (!$activeTab) {
    $activeTab = $isAdmin ? 'tab-barangay' : 'tab-profile';
}
if (!str_starts_with($activeTab, 'tab-')) {
    $activeTab = 'tab-' . $activeTab;
}
if ($activeTab === 'tab-documents') {
    $activeTab = 'tab-barangay';
}
if (!$isAdmin) {
    $activeTab = 'tab-profile';
}

$settingModel = new Setting();
$settings = $settingModel->getAllSettings();
$categories = $settingModel->getCategories();
$profile = $settingModel->getUserProfile($userId) ?: [];
$storage = $isAdmin ? $settingModel->getStorageBreakdown() : [];
$diagnostics = $isAdmin ? $settingModel->getDiagnostics() : [];

$holidaysJson = $settings['configured_holidays'] ?? '[]';
$workingDays = json_decode($settings['working_days'] ?? '["Mon","Tue","Wed","Thu","Fri"]', true) ?: ["Mon","Tue","Wed","Thu","Fri"];
$puroksList = json_decode($settings['barangay_puroks'] ?? '[]', true) ?: [];

$pageTitle = 'System & Personal Settings';
include '../../layouts/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/settings.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/settings.css'); ?>">

<script>
    window.currentHolidaysList = <?php echo $holidaysJson ?: '[]'; ?>;
</script>

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="settings-container">
            <!-- Header -->
            <div class="settings-header">
                <div>
                    <h1 class="settings-header-title">
                        <span>⚙️</span>
                        <span><?php echo $isAdmin ? 'System & Operational Settings' : 'Personal Account Settings'; ?></span>
                    </h1>
                    <p class="settings-header-subtitle">
                        <?php echo $isAdmin 
                            ? 'Configure Barangay Tumana LGU profile, KP hearing statutory limits, categories, document templates, and system maintenance.'
                            : 'Manage your personal user profile, security credentials, notifications, and language preferences.'; ?>
                    </p>
                </div>
                <?php if ($isAdmin): ?>
                    <div>
                        <a href="../../../backend/api/settings/backup-db.php" class="btn-secondary" title="Export complete database backup">
                            💾 Database Backup (.sql)
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Two-Column Workspace -->
            <div class="settings-layout">
                <!-- Navigation Column -->
                <div class="settings-nav-card">
                    <?php if ($isAdmin): ?>
                        <div class="settings-nav-header">Administrative Controls</div>
                        <ul class="settings-nav-list">
                            <li class="settings-nav-item">
                                <button type="button" class="settings-nav-btn <?php echo $activeTab === 'tab-barangay' ? 'active' : ''; ?>" data-tab="tab-barangay">
                                    <span class="nav-icon">🏛️</span>
                                    <span>Barangay &amp; Signatories</span>
                                </button>
                            </li>
                            <li class="settings-nav-item">
                                <button type="button" class="settings-nav-btn <?php echo $activeTab === 'tab-logistics' ? 'active' : ''; ?>" data-tab="tab-logistics">
                                    <span class="nav-icon">⚖️</span>
                                    <span>KP Rules &amp; Logistics</span>
                                </button>
                            </li>
                            <li class="settings-nav-item">
                                <button type="button" class="settings-nav-btn <?php echo $activeTab === 'tab-categories' ? 'active' : ''; ?>" data-tab="tab-categories">
                                    <span class="nav-icon">📁</span>
                                    <span>Categories &amp; Intake</span>
                                </button>
                            </li>
                            <li class="settings-nav-item">
                                <button type="button" class="settings-nav-btn <?php echo $activeTab === 'tab-external' ? 'active' : ''; ?>" data-tab="tab-external">
                                    <span class="nav-icon">🤖</span>
                                    <span>AI &amp; External Services</span>
                                </button>
                            </li>
                            <li class="settings-nav-item">
                                <button type="button" class="settings-nav-btn <?php echo $activeTab === 'tab-security' ? 'active' : ''; ?>" data-tab="tab-security">
                                    <span class="nav-icon">🔒</span>
                                    <span>Security &amp; Maintenance</span>
                                </button>
                            </li>
                        </ul>
                        <div class="settings-nav-divider"></div>
                    <?php endif; ?>

                    <div class="settings-nav-header">Personal Preferences</div>
                    <ul class="settings-nav-list">
                        <li class="settings-nav-item">
                            <button type="button" class="settings-nav-btn <?php echo $activeTab === 'tab-profile' ? 'active' : ''; ?>" data-tab="tab-profile">
                                <span class="nav-icon">👤</span>
                                <span>User Profile &amp; Account</span>
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- Content Column -->
                <div class="settings-content-column">
                    <form id="settingsMainForm" onsubmit="event.preventDefault(); saveAllSettings();">
                        
                        <?php if ($isAdmin): ?>
                            <!-- ===================================================================
                                 TAB 1: BARANGAY INFO & SIGNATORIES
                                 =================================================================== -->
                            <div class="settings-tab-pane <?php echo $activeTab === 'tab-barangay' ? 'active' : ''; ?>" id="tab-barangay">
                                <div class="settings-card">
                                    <div class="settings-card-header">
                                        <div>
                                            <h3 class="settings-card-title">🏛️ LGU Identity &amp; Contact Details</h3>
                                            <p class="settings-card-subtitle">Official local government details printed on KP letterheads, summons, and transmittals.</p>
                                        </div>
                                    </div>
                                    <div class="settings-card-body">
                                        <div class="form-grid-3">
                                            <div class="form-group">
                                                <label class="form-label">Barangay Name <span class="required">*</span></label>
                                                <input type="text" name="barangay_name" class="form-control" value="<?php echo htmlspecialchars($settings['barangay_name'] ?? 'Barangay Tumana'); ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">City / Municipality <span class="required">*</span></label>
                                                <input type="text" name="city_name" class="form-control" value="<?php echo htmlspecialchars($settings['city_name'] ?? 'Lungsod ng Marikina'); ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Province / Region <span class="required">*</span></label>
                                                <input type="text" name="province_name" class="form-control" value="<?php echo htmlspecialchars($settings['province_name'] ?? 'Kalakhang Maynila (NCR)'); ?>" required>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">Barangay Hall Physical Address</label>
                                            <input type="text" name="barangay_hall_address" class="form-control" value="<?php echo htmlspecialchars($settings['barangay_hall_address'] ?? ''); ?>">
                                        </div>

                                        <div class="form-grid-2">
                                            <div class="form-group">
                                                <label class="form-label">Telephone / Mobile Hotline</label>
                                                <input type="text" name="barangay_contact" class="form-control" value="<?php echo htmlspecialchars($settings['barangay_contact'] ?? ''); ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Official Correspondence Email</label>
                                                <input type="email" name="barangay_email" class="form-control" value="<?php echo htmlspecialchars($settings['barangay_email'] ?? ''); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="settings-card">
                                    <div class="settings-card-header">
                                        <div>
                                            <h3 class="settings-card-title">🖋️ Key Signatories &amp; Legal Officers</h3>
                                            <p class="settings-card-subtitle">Default officials populated on KP notices, endorsements, and arbitration awards.</p>
                                        </div>
                                    </div>
                                    <div class="settings-card-body">
                                        <div class="form-grid-2">
                                            <div class="form-group">
                                                <label class="form-label">Punong Barangay / Lupon Chairman <span class="required">*</span></label>
                                                <input type="text" name="captain_name" class="form-control" value="<?php echo htmlspecialchars($settings['captain_name'] ?? 'HON. AKIKO LYNNE CENTENO'); ?>" required>
                                                <div class="form-help">Automatically presides over 1st &amp; 2nd Mediation hearings.</div>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Barangay Secretary</label>
                                                <input type="text" name="barangay_secretary_name" class="form-control" value="<?php echo htmlspecialchars($settings['barangay_secretary_name'] ?? 'MARY JANE G. RAMOS'); ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Lupon Secretary</label>
                                                <input type="text" name="lupon_secretary_name" class="form-control" value="<?php echo htmlspecialchars($settings['lupon_secretary_name'] ?? 'MA. CRISTINA D. SANTOS'); ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Legal Officer / City Legal Consultant</label>
                                                <input type="text" name="legal_officer_name" class="form-control" value="<?php echo htmlspecialchars($settings['legal_officer_name'] ?? 'ATTY. CARLOS M. VALDEZ'); ?>">
                                            </div>
                                        </div>

                                        <div class="form-group" style="margin-top: 12px;">
                                            <label class="form-label">Default Document Signature Style</label>
                                            <div style="display: flex; gap: 20px; align-items: center; margin-top: 6px;">
                                                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.88rem; cursor: pointer;">
                                                    <input type="radio" name="signature_style" value="wet" <?php echo ($settings['signature_style'] ?? 'wet') === 'wet' ? 'checked' : ''; ?>>
                                                    <span><strong>Wet-Signature</strong> (Clean blank line for physical signing)</span>
                                                </label>
                                                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.88rem; cursor: pointer;">
                                                    <input type="radio" name="signature_style" value="digital" <?php echo ($settings['signature_style'] ?? '') === 'digital' ? 'checked' : ''; ?>>
                                                    <span><strong>Digital Placeholder</strong> (Renders e-signature / seal stamp)</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="settings-card">
                                    <div class="settings-card-header">
                                        <div>
                                            <h3 class="settings-card-title">🛡️ Official Government Seals &amp; Insignia</h3>
                                            <p class="settings-card-subtitle">Upload high-resolution transparent PNG seals embedded in Dompdf forms and preview modals.</p>
                                        </div>
                                    </div>
                                    <div class="settings-card-body">
                                        <div class="seal-uploader-grid">
                                            <div class="seal-upload-box">
                                                <img id="barangaySealPreview" class="seal-preview-img" src="<?php echo htmlspecialchars($settings['barangay_seal_path'] ?? '../../assets/images/tumana-logo.png'); ?>" alt="Barangay Seal">
                                                <div class="seal-upload-title">Barangay Tumana Seal</div>
                                                <div class="seal-upload-desc">Recommended: 300x300 PNG with transparent background.</div>
                                                <input type="file" id="inputBarangaySeal" accept="image/png,image/jpeg,image/webp" style="display:none;" onchange="handleSealUpload('barangay', this)">
                                                <button type="button" class="btn-secondary btn-sm" onclick="document.getElementById('inputBarangaySeal').click()">
                                                    📷 Upload Barangay Seal
                                                </button>
                                                <div id="barangaySealStatus" style="font-size:0.75rem; color:#64748b; margin-top:6px;"></div>
                                            </div>

                                            <div class="seal-upload-box">
                                                <img id="citySealPreview" class="seal-preview-img" src="<?php echo htmlspecialchars($settings['city_seal_path'] ?? '../../assets/images/marikina-logo.png'); ?>" alt="City Seal">
                                                <div class="seal-upload-title">Marikina City Seal</div>
                                                <div class="seal-upload-desc">Recommended: 300x300 PNG with transparent background.</div>
                                                <input type="file" id="inputCitySeal" accept="image/png,image/jpeg,image/webp" style="display:none;" onchange="handleSealUpload('city', this)">
                                                <button type="button" class="btn-secondary btn-sm" onclick="document.getElementById('inputCitySeal').click()">
                                                    📷 Upload City Seal
                                                </button>
                                                <div id="citySealStatus" style="font-size:0.75rem; color:#64748b; margin-top:6px;"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ===================================================================
                                 TAB 2: KP RULES & HEARING LOGISTICS
                                 =================================================================== -->
                            <div class="settings-tab-pane <?php echo $activeTab === 'tab-logistics' ? 'active' : ''; ?>" id="tab-logistics">
                                <div class="settings-card">
                                    <div class="settings-card-header">
                                        <div>
                                            <h3 class="settings-card-title">⚖️ Katarungang Pambarangay Statutory Caps (RA 7160)</h3>
                                            <p class="settings-card-subtitle">Mandatory legal session limits and policy thresholds enforced by AGAP automated engines.</p>
                                        </div>
                                    </div>
                                    <div class="settings-card-body">
                                        <div class="form-grid-3">
                                            <div class="form-group">
                                                <label class="form-label">Max Mediation Hearings <span class="required">*</span></label>
                                                <input type="number" name="mediation_max_sessions" class="form-control" value="<?php echo htmlspecialchars($settings['mediation_max_sessions'] ?? '2'); ?>" min="1" max="3" required>
                                                <div class="form-help">Barangay Tumana Policy Cap: strictly 2 sessions.</div>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Max Conciliation Sessions <span class="required">*</span></label>
                                                <input type="number" name="conciliation_max_sessions" class="form-control" value="<?php echo htmlspecialchars($settings['conciliation_max_sessions'] ?? '3'); ?>" min="1" max="5" required>
                                                <div class="form-help">RA 7160 statutory maximum (3 sessions).</div>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Max Paanyaya Attempts <span class="required">*</span></label>
                                                <input type="number" name="paanyaya_max_attempts" class="form-control" value="<?php echo htmlspecialchars($settings['paanyaya_max_attempts'] ?? '6'); ?>" min="1" max="10" required>
                                                <div class="form-help">Tumana informal invitation ceiling per stage.</div>
                                            </div>
                                        </div>

                                        <div class="form-grid-3" style="margin-top: 14px;">
                                            <div class="form-group">
                                                <label class="form-label">Daily Barangay Capacity</label>
                                                <input type="number" name="daily_hearing_capacity" class="form-control" value="<?php echo htmlspecialchars($settings['daily_hearing_capacity'] ?? '8'); ?>" min="1" max="20">
                                                <div class="form-help">Total hearings across all rooms.</div>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Officer Daily Capacity</label>
                                                <input type="number" name="officer_daily_hearing_capacity" class="form-control" value="<?php echo htmlspecialchars($settings['officer_daily_hearing_capacity'] ?? '6'); ?>" min="1" max="10">
                                                <div class="form-help">Max hearings per presiding officer.</div>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Party Reschedule Limit</label>
                                                <input type="number" name="party_reschedule_limit" class="form-control" value="<?php echo htmlspecialchars($settings['party_reschedule_limit'] ?? '2'); ?>" min="1" max="5">
                                                <div class="form-help">Allowed reschedules before escalation.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="settings-card">
                                    <div class="settings-card-header">
                                        <div>
                                            <h3 class="settings-card-title">⏱️ Session Durations &amp; Office Hours</h3>
                                            <p class="settings-card-subtitle">Standard durations consumed by schedule conflict validators and calendar managers.</p>
                                        </div>
                                    </div>
                                    <div class="settings-card-body">
                                        <div class="form-grid-2">
                                            <div class="form-group">
                                                <label class="form-label">Mediation Duration (Default / Max)</label>
                                                <div style="display: flex; gap: 8px;">
                                                    <input type="number" name="mediation_default_duration_min" class="form-control" value="<?php echo htmlspecialchars($settings['mediation_default_duration_min'] ?? '45'); ?>" placeholder="Default min">
                                                    <input type="number" name="mediation_max_duration_min" class="form-control" value="<?php echo htmlspecialchars($settings['mediation_max_duration_min'] ?? '60'); ?>" placeholder="Max min">
                                                </div>
                                                <div class="form-help">Minutes allocated per Punong Barangay session.</div>
                                            </div>

                                            <div class="form-group">
                                                <label class="form-label">Conciliation Duration (Default / Max)</label>
                                                <div style="display: flex; gap: 8px;">
                                                    <input type="number" name="conciliation_default_duration_min" class="form-control" value="<?php echo htmlspecialchars($settings['conciliation_default_duration_min'] ?? '60'); ?>" placeholder="Default min">
                                                    <input type="number" name="conciliation_max_duration_min" class="form-control" value="<?php echo htmlspecialchars($settings['conciliation_max_duration_min'] ?? '90'); ?>" placeholder="Max min">
                                                </div>
                                                <div class="form-help">Minutes allocated per Pangkat conciliation session.</div>
                                            </div>
                                        </div>

                                        <div class="form-grid-3" style="margin-top: 14px;">
                                            <div class="form-group">
                                                <label class="form-label">Working Hours Start</label>
                                                <input type="time" name="working_hours_start" class="form-control" value="<?php echo htmlspecialchars($settings['working_hours_start'] ?? '08:00'); ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Working Hours End</label>
                                                <input type="time" name="working_hours_end" class="form-control" value="<?php echo htmlspecialchars($settings['working_hours_end'] ?? '17:00'); ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Mediation Day of Week</label>
                                                <select name="mediation_day_of_week" class="form-control">
                                                    <option value="Tue" <?php echo ($settings['mediation_day_of_week'] ?? 'Tue') === 'Tue' ? 'selected' : ''; ?>>Tuesday (Barangay Tumana Rule)</option>
                                                    <option value="Mon" <?php echo ($settings['mediation_day_of_week'] ?? '') === 'Mon' ? 'selected' : ''; ?>>Monday</option>
                                                    <option value="Wed" <?php echo ($settings['mediation_day_of_week'] ?? '') === 'Wed' ? 'selected' : ''; ?>>Wednesday</option>
                                                    <option value="Thu" <?php echo ($settings['mediation_day_of_week'] ?? '') === 'Thu' ? 'selected' : ''; ?>>Thursday</option>
                                                    <option value="Fri" <?php echo ($settings['mediation_day_of_week'] ?? '') === 'Fri' ? 'selected' : ''; ?>>Friday</option>
                                                </select>
                                                <div class="form-help">Restricts Punong Barangay mediation slots.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="settings-card">
                                    <div class="settings-card-header">
                                        <div>
                                            <h3 class="settings-card-title">📅 Marikina City &amp; National Holiday Catalog</h3>
                                            <p class="settings-card-subtitle">Non-working days excluded automatically by MediationDeadlineService from statutory SLA calculations.</p>
                                        </div>
                                        <div>
                                            <button type="button" class="btn-primary btn-sm" onclick="openAddHolidayModal()">+ Add Holiday</button>
                                        </div>
                                    </div>
                                    <div class="settings-card-body" style="padding: 0;">
                                        <div class="settings-table-wrapper" style="border: none; border-radius: 0;">
                                            <table class="settings-table">
                                                <thead>
                                                    <tr>
                                                        <th>Date (YYYY-MM-DD)</th>
                                                        <th>Holiday / Observance Name</th>
                                                        <th>Classification</th>
                                                        <th style="text-align: right;">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="holidaysTableTbody">
                                                    <!-- Rendered via JS -->
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ===================================================================
                                 TAB 3: DISPUTE CATEGORIES & GEOGRAPHIC SCREENING
                                 =================================================================== -->
                            <div class="settings-tab-pane <?php echo $activeTab === 'tab-categories' ? 'active' : ''; ?>" id="tab-categories">
                                <div class="settings-card">
                                    <div class="settings-card-header">
                                        <div>
                                            <h3 class="settings-card-title">📁 Dispute Categories &amp; LTIA Tracking Codes</h3>
                                            <p class="settings-card-subtitle">Master classification catalog compliant with DILG LTIA reporting standards.</p>
                                        </div>
                                        <div>
                                            <button type="button" class="btn-primary btn-sm" onclick="openCategoryModal()">+ Add New Category</button>
                                        </div>
                                    </div>
                                    <div class="settings-card-body" style="padding: 0;">
                                        <div class="settings-table-wrapper" style="border: none; border-radius: 0;">
                                            <table class="settings-table">
                                                <thead>
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Category Name (English)</th>
                                                        <th>Tagalog Translation</th>
                                                        <th>LTIA Code</th>
                                                        <th>Status</th>
                                                        <th>Linked Cases</th>
                                                        <th style="text-align: right;">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="categoriesTableTbody">
                                                    <?php foreach ($categories as $c): ?>
                                                        <tr>
                                                            <td style="font-weight: 700; color: #64748b;">#<?php echo $c['category_id']; ?></td>
                                                            <td><strong><?php echo htmlspecialchars($c['category_name']); ?></strong></td>
                                                            <td style="color: #047857;"><?php echo htmlspecialchars($c['tagalog_name'] ?? '—'); ?></td>
                                                            <td><span class="badge-ltia"><?php echo htmlspecialchars($c['ltia_code'] ?? 'N/A'); ?></span></td>
                                                            <td>
                                                                <span class="<?php echo (int)$c['is_active'] === 1 ? 'badge-active' : 'badge-inactive'; ?>">
                                                                    <?php echo (int)$c['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo (int)($c['complaint_count'] ?? 0); ?></td>
                                                            <td style="text-align: right;">
                                                                <button type="button" class="btn-secondary btn-sm" onclick='openCategoryModal(<?php echo json_encode($c, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>✏️ Edit</button>
                                                                <button type="button" class="btn-danger btn-sm" onclick='deleteCategory(<?php echo $c['category_id']; ?>, "<?php echo addslashes($c['category_name']); ?>")'>🗑️</button>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <div class="settings-card">
                                    <div class="settings-card-header">
                                        <div>
                                            <h3 class="settings-card-title">⚖️ Statutory KP Jurisdiction Thresholds (RA 7160 Sec. 408)</h3>
                                            <p class="settings-card-subtitle">Offenses with penalties exceeding these thresholds require automatic court referral advisories.</p>
                                        </div>
                                    </div>
                                    <div class="settings-card-body">
                                        <div class="form-grid-2">
                                            <div class="form-group">
                                                <label class="form-label">Max Imprisonment Threshold (Years)</label>
                                                <input type="number" name="jurisdiction_max_imprisonment_years" class="form-control" value="<?php echo htmlspecialchars($settings['jurisdiction_max_imprisonment_years'] ?? '1'); ?>" min="1" max="10">
                                                <div class="form-help">Cases with penalties exceeding 1 year imprisonment are barred from KP.</div>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Max Statutory Fine Threshold (PHP)</label>
                                                <input type="number" name="jurisdiction_max_fine" class="form-control" value="<?php echo htmlspecialchars($settings['jurisdiction_max_fine'] ?? '5000'); ?>" min="500" max="50000" step="500">
                                                <div class="form-help">Cases with statutory fines exceeding ₱5,000 are barred from KP.</div>
                                            </div>
                                        </div>

                                        <label class="switch-label" style="margin-top: 14px;">
                                            <div class="switch-info">
                                                <span class="switch-title">Automatic Legal Advisory Notice</span>
                                                <span class="switch-desc">Display an automated notice in complaint intake when entered facts indicate offense is beyond KP jurisdiction.</span>
                                            </div>
                                            <div class="switch">
                                                <input type="checkbox" name="jurisdiction_auto_referral_notice" value="1" <?php echo ($settings['jurisdiction_auto_referral_notice'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </div>
                                        </label>
                                    </div>
                                </div>

                                <div class="settings-card">
                                    <div class="settings-card-header">
                                        <div>
                                            <h3 class="settings-card-title">📍 Geographic Screening &amp; Leaflet Map Defaults</h3>
                                            <p class="settings-card-subtitle">Default center point and zoom for Leaflet incident pin placement in Barangay Tumana.</p>
                                        </div>
                                    </div>
                                    <div class="settings-card-body">
                                        <div class="form-grid-3">
                                            <div class="form-group">
                                                <label class="form-label">Default Center Latitude</label>
                                                <input type="text" id="inputMapLat" name="default_map_latitude" class="form-control" value="<?php echo htmlspecialchars($settings['default_map_latitude'] ?? '14.6644'); ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Default Center Longitude</label>
                                                <input type="text" id="inputMapLng" name="default_map_longitude" class="form-control" value="<?php echo htmlspecialchars($settings['default_map_longitude'] ?? '121.0967'); ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Default Zoom Level</label>
                                                <input type="number" id="inputMapZoom" name="default_map_zoom" class="form-control" value="<?php echo htmlspecialchars($settings['default_map_zoom'] ?? '15'); ?>" min="10" max="19">
                                            </div>
                                        </div>

                                        <div style="margin-top: 14px;">
                                            <label class="form-label">Interactive Mini-Map Picker</label>
                                            <div id="settingsMiniMap" style="height: 250px; border-radius: 8px; border: 1px solid #cbd5e1;"></div>
                                            <div class="form-help">Drag the marker above to test or update the default map location coordinates.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ===================================================================
                                 TAB 4: AI & EXTERNAL SERVICES
                                 =================================================================== -->
                            <div class="settings-tab-pane <?php echo $activeTab === 'tab-external' ? 'active' : ''; ?>" id="tab-external">
                                <div class="settings-card">
                                    <div class="settings-card-header">
                                        <div>
                                            <h3 class="settings-card-title">🤖 Google Gemini AI Configuration</h3>
                                            <p class="settings-card-subtitle">Powers automated Salaysay legal refinement, OCR handwritten scan indexing, and case briefs.</p>
                                        </div>
                                        <div>
                                            <button type="button" class="btn-secondary btn-sm" id="btnTestGemini" onclick="testGeminiApiConnection()">
                                                ⚡ Test Connection
                                            </button>
                                        </div>
                                    </div>
                                    <div class="settings-card-body">
                                        <div class="form-grid-2">
                                            <div class="form-group">
                                                <label class="form-label">Gemini API Key</label>
                                                <input type="password" id="inputGeminiApiKey" name="gemini_api_key" class="form-control" value="<?php echo htmlspecialchars($settings['gemini_api_key_masked'] ?? ''); ?>" placeholder="Enter API Key to update">
                                                <div class="form-help">Google Cloud Console / AI Studio Gemini API Key.</div>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Gemini Model Identifier</label>
                                                <select name="gemini_model" class="form-control">
                                                    <option value="gemini-1.5-flash" <?php echo ($settings['gemini_model'] ?? 'gemini-1.5-flash') === 'gemini-1.5-flash' ? 'selected' : ''; ?>>Gemini 1.5 Flash (Fast, Recommended)</option>
                                                    <option value="gemini-1.5-pro" <?php echo ($settings['gemini_model'] ?? '') === 'gemini-1.5-pro' ? 'selected' : ''; ?>>Gemini 1.5 Pro (Deep Analysis)</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div id="geminiTestResultChip" style="display: none; padding: 8px 14px; border-radius: 6px; font-size: 0.82rem; margin-bottom: 16px;"></div>

                                        <label class="switch-label">
                                            <div class="switch-info">
                                                <span class="switch-title">AI Salaysay Narrative Assistant</span>
                                                <span class="switch-desc">Allows Lupon intake clerks to polish raw citizen narratives into chronologically structured legal complaint facts.</span>
                                            </div>
                                            <div class="switch">
                                                <input type="checkbox" name="ai_narrative_refinement_enabled" value="1" <?php echo ($settings['ai_narrative_refinement_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </div>
                                        </label>

                                        <label class="switch-label">
                                            <div class="switch-info">
                                                <span class="switch-title">AI OCR Document Extraction</span>
                                                <span class="switch-desc">Automatically parses handwritten or printed KP Form 7 scans to extract names, incident dates, and dispute allegations.</span>
                                            </div>
                                            <div class="switch">
                                                <input type="checkbox" name="ai_ocr_processing_enabled" value="1" <?php echo ($settings['ai_ocr_processing_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </div>
                                        </label>

                                        <div class="form-group" style="max-width: 300px; margin-top: 14px;">
                                            <label class="form-label">Rate Limit per Minute</label>
                                            <input type="number" name="ai_rate_limit_per_minute" class="form-control" value="<?php echo htmlspecialchars($settings['ai_rate_limit_per_minute'] ?? '15'); ?>" min="5" max="60">
                                            <div class="form-help">Maximum AI requests per minute to prevent quota exhaustion.</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="settings-card">
                                    <div class="settings-card-header">
                                        <div>
                                            <h3 class="settings-card-title">📱 SMS Gateway Service (Citizen Alerts)</h3>
                                            <p class="settings-card-subtitle">Automated outbound SMS notifications for hearings, summons, and Paanyaya invitations.</p>
                                        </div>
                                        <div>
                                            <button type="button" class="btn-secondary btn-sm" onclick="openTestSmsModal()">
                                                📤 Send Test SMS
                                            </button>
                                        </div>
                                    </div>
                                    <div class="settings-card-body">
                                        <div class="form-grid-3">
                                            <div class="form-group">
                                                <label class="form-label">Gateway Provider</label>
                                                <select id="selectSmsProvider" name="sms_provider" class="form-control">
                                                    <option value="Semaphore" <?php echo ($settings['sms_provider'] ?? 'Semaphore') === 'Semaphore' ? 'selected' : ''; ?>>Semaphore (Philippines)</option>
                                                    <option value="Twilio" <?php echo ($settings['sms_provider'] ?? '') === 'Twilio' ? 'selected' : ''; ?>>Twilio</option>
                                                    <option value="Disabled" <?php echo ($settings['sms_provider'] ?? '') === 'Disabled' ? 'selected' : ''; ?>>Disabled</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">SMS API Key / Auth Token</label>
                                                <input type="password" id="inputSmsApiKey" name="sms_api_key" class="form-control" value="<?php echo htmlspecialchars($settings['sms_api_key_masked'] ?? ''); ?>" placeholder="Enter API Key to update">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Sender Name (Alphanumeric)</label>
                                                <input type="text" name="sms_sender_name" class="form-control" value="<?php echo htmlspecialchars($settings['sms_sender_name'] ?? 'BRGY_TUMANA'); ?>">
                                            </div>
                                        </div>

                                        <div class="form-group" style="margin-top: 14px;">
                                            <label class="form-label">Paanyaya Invitation SMS Template</label>
                                            <textarea name="sms_paanyaya_template" class="form-control"><?php echo htmlspecialchars($settings['sms_paanyaya_template'] ?? ''); ?></textarea>
                                            <div class="form-help">Variables available: <code>{case_number}</code>, <code>{date}</code>, <code>{time}</code>, <code>{venue}</code>.</div>
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">Hearing Notice SMS Template</label>
                                            <textarea name="sms_hearing_template" class="form-control"><?php echo htmlspecialchars($settings['sms_hearing_template'] ?? ''); ?></textarea>
                                            <div class="form-help">Variables available: <code>{case_number}</code>, <code>{date}</code>, <code>{time}</code>, <code>{venue}</code>.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ===================================================================
                                 TAB 5: SECURITY, BACKUPS & MAINTENANCE
                                 =================================================================== -->
                            <div class="settings-tab-pane <?php echo $activeTab === 'tab-security' ? 'active' : ''; ?>" id="tab-security">
                                <div class="settings-card">
                                    <div class="settings-card-header">
                                        <div>
                                            <h3 class="settings-card-title">🔒 Session &amp; Access Security</h3>
                                            <p class="settings-card-subtitle">Password policy, brute-force lockout, and idle session auto-timeout.</p>
                                        </div>
                                    </div>
                                    <div class="settings-card-body">
                                        <div class="form-grid-2">
                                            <div class="form-group">
                                                <label class="form-label">Session Inactivity Timeout (Minutes)</label>
                                                <input type="number" name="session_inactivity_timeout_min" class="form-control" value="<?php echo htmlspecialchars($settings['session_inactivity_timeout_min'] ?? '30'); ?>" min="5" max="240">
                                                <div class="form-help">Auto-logout inactive sessions to protect sensitive resident records.</div>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Password Rotation (Days)</label>
                                                <input type="number" name="password_expiration_days" class="form-control" value="<?php echo htmlspecialchars($settings['password_expiration_days'] ?? '0'); ?>" min="0" max="365">
                                                <div class="form-help">Set to 0 to disable mandatory password rotation.</div>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Max Failed Login Attempts</label>
                                                <input type="number" name="max_failed_login_attempts" class="form-control" value="<?php echo htmlspecialchars($settings['max_failed_login_attempts'] ?? '5'); ?>" min="3" max="10">
                                                <div class="form-help">Triggers temporary account lockout.</div>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Lockout Duration (Minutes)</label>
                                                <input type="number" name="lockout_duration_min" class="form-control" value="<?php echo htmlspecialchars($settings['lockout_duration_min'] ?? '15'); ?>" min="5" max="120">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="settings-card">
                                    <div class="settings-card-header">
                                        <div>
                                            <h3 class="settings-card-title">💾 1-Click Database Backup &amp; Storage Monitor</h3>
                                            <p class="settings-card-subtitle">Download an immediate SQL database snapshot and inspect active storage volume metrics.</p>
                                        </div>
                                        <div>
                                            <a href="../../../backend/api/settings/backup-db.php" class="btn-primary btn-sm">
                                                💾 Download Full Database Backup (.sql)
                                            </a>
                                        </div>
                                    </div>
                                    <div class="settings-card-body">
                                        <div style="margin-bottom: 20px;">
                                            <div style="display: flex; justify-content: space-between; font-size: 0.84rem; font-weight: 700; color: #334155;">
                                                <span>Total Server Disk Storage: <?php echo htmlspecialchars($storage['disk']['free_readable'] ?? 'N/A'); ?> free of <?php echo htmlspecialchars($storage['disk']['total_readable'] ?? 'N/A'); ?></span>
                                                <span><?php echo htmlspecialchars($storage['disk']['used_percentage'] ?? '0'); ?>% Used</span>
                                            </div>
                                            <div class="storage-progress-bar">
                                                <div class="storage-progress-fill" style="width: <?php echo min(100, max(0, (float)($storage['disk']['used_percentage'] ?? 0))); ?>%;"></div>
                                            </div>
                                        </div>

                                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px;">
                                            <div class="storage-item-row">
                                                <span>📁 Complaint Evidence Vault (<code>storage/uploads/complaint-evidence/</code>)</span>
                                                <strong><?php echo htmlspecialchars($storage['evidence']['readable'] ?? '0 B'); ?> (<?php echo (int)($storage['evidence']['file_count'] ?? 0); ?> files)</strong>
                                            </div>
                                            <div class="storage-item-row">
                                                <span>📄 Scanned KP Form 7 Archives (<code>storage/uploads/complaint-scans/</code>)</span>
                                                <strong><?php echo htmlspecialchars($storage['scans']['readable'] ?? '0 B'); ?> (<?php echo (int)($storage['scans']['file_count'] ?? 0); ?> files)</strong>
                                            </div>
                                            <div class="storage-item-row">
                                                <span>✉️ Paanyaya Invitation PDFs (<code>storage/paanyaya/</code>)</span>
                                                <strong><?php echo htmlspecialchars($storage['paanyaya']['readable'] ?? '0 B'); ?> (<?php echo (int)($storage['paanyaya']['file_count'] ?? 0); ?> files)</strong>
                                            </div>
                                            <div class="storage-item-row">
                                                <span>📜 Generated KP Notices &amp; Summons (<code>storage/generated-documents/</code>)</span>
                                                <strong><?php echo htmlspecialchars($storage['documents']['readable'] ?? '0 B'); ?> (<?php echo (int)($storage['documents']['file_count'] ?? 0); ?> files)</strong>
                                            </div>
                                            <div class="storage-item-row" style="font-weight: 800; color: #0f172a;">
                                                <span>Total Application Storage Utilized</span>
                                                <span><?php echo htmlspecialchars($storage['total']['readable'] ?? '0 B'); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="settings-card">
                                    <div class="settings-card-header">
                                        <div>
                                            <h3 class="settings-card-title">🖥️ Server Diagnostics &amp; Runtime Environment</h3>
                                            <p class="settings-card-subtitle">Active PHP/MySQL platform parameters and required extension health status.</p>
                                        </div>
                                    </div>
                                    <div class="settings-card-body">
                                        <div class="diagnostics-grid">
                                            <div class="diagnostics-chip">
                                                <div class="diagnostics-chip-title">PHP Runtime Version</div>
                                                <div class="diagnostics-chip-value"><?php echo htmlspecialchars($diagnostics['php_version'] ?? PHP_VERSION); ?></div>
                                            </div>
                                            <div class="diagnostics-chip">
                                                <div class="diagnostics-chip-title">MySQL Database Engine</div>
                                                <div class="diagnostics-chip-value"><?php echo htmlspecialchars($diagnostics['mysql_version'] ?? 'Unknown'); ?></div>
                                            </div>
                                            <div class="diagnostics-chip">
                                                <div class="diagnostics-chip-title">Operating System</div>
                                                <div class="diagnostics-chip-value"><?php echo htmlspecialchars($diagnostics['os'] ?? PHP_OS); ?></div>
                                            </div>
                                            <div class="diagnostics-chip">
                                                <div class="diagnostics-chip-title">Memory Allocation</div>
                                                <div class="diagnostics-chip-value"><?php echo htmlspecialchars($diagnostics['memory_limit'] ?? 'N/A'); ?></div>
                                            </div>
                                            <div class="diagnostics-chip">
                                                <div class="diagnostics-chip-title">Upload File Limit</div>
                                                <div class="diagnostics-chip-value"><?php echo htmlspecialchars($diagnostics['upload_max_filesize'] ?? 'N/A'); ?></div>
                                            </div>
                                            <div class="diagnostics-chip">
                                                <div class="diagnostics-chip-title">System Timezone</div>
                                                <div class="diagnostics-chip-value"><?php echo htmlspecialchars($diagnostics['server_timezone'] ?? 'Asia/Manila'); ?></div>
                                            </div>
                                        </div>

                                        <div style="margin-top: 16px;">
                                            <div class="form-label">Required Extension Verification:</div>
                                            <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px;">
                                                <?php foreach (($diagnostics['extensions'] ?? []) as $ext => $loaded): ?>
                                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; background: <?php echo $loaded ? '#dcfce7' : '#fee2e2'; ?>; color: <?php echo $loaded ? '#15803d' : '#b91c1c'; ?>; border: 1px solid <?php echo $loaded ? '#86efac' : '#fca5a5'; ?>;">
                                                        <?php echo $loaded ? '✓' : '✗'; ?> <?php echo strtoupper($ext); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- ===================================================================
                             TAB 6: USER PROFILE & PERSONAL PREFERENCES (All Roles)
                             =================================================================== -->
                        <div class="settings-tab-pane <?php echo $activeTab === 'tab-profile' ? 'active' : ''; ?>" id="tab-profile">
                            <div class="settings-card">
                                <div class="settings-card-header">
                                    <div>
                                        <h3 class="settings-card-title">👤 Personal Profile Information</h3>
                                        <p class="settings-card-subtitle">Manage your personal display name, official email, and contact details.</p>
                                    </div>
                                    <div>
                                        <button type="button" class="btn-primary btn-sm" id="btnSaveProfile" onclick="savePersonalProfile()">
                                            💾 Save Profile
                                        </button>
                                    </div>
                                </div>
                                <div class="settings-card-body">
                                    <div class="form-grid-3">
                                        <div class="form-group">
                                            <label class="form-label">First Name <span class="required">*</span></label>
                                            <input type="text" id="profileFirstName" class="form-control" value="<?php echo htmlspecialchars($profile['first_name'] ?? ''); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Middle Name</label>
                                            <input type="text" id="profileMiddleName" class="form-control" value="<?php echo htmlspecialchars($profile['middle_name'] ?? ''); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Last Name <span class="required">*</span></label>
                                            <input type="text" id="profileLastName" class="form-control" value="<?php echo htmlspecialchars($profile['last_name'] ?? ''); ?>" required>
                                        </div>
                                    </div>

                                    <div class="form-grid-3" style="margin-top: 14px;">
                                        <div class="form-group">
                                            <label class="form-label">System Username</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($profile['username'] ?? ''); ?>" readonly>
                                            <div class="form-help">Username is locked to ensure audit trail integrity.</div>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Official Email</label>
                                            <input type="email" id="profileEmail" class="form-control" value="<?php echo htmlspecialchars($profile['email'] ?? ''); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Contact Number</label>
                                            <input type="text" id="profileContact" class="form-control" value="<?php echo htmlspecialchars($profile['contact_no'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div style="margin-top: 18px; border-top: 1px solid #f1f5f9; padding-top: 18px;">
                                        <h4 style="font-size: 0.95rem; font-weight: 800; color: #0f172a; margin: 0 0 12px;">🔔 Notification &amp; Language Preferences</h4>
                                        <div class="form-grid-2">
                                            <label class="switch-label">
                                                <div class="switch-info">
                                                    <span class="switch-title">Notification Audio Chimes</span>
                                                    <span class="switch-desc">Play an audible chime when hearings or summons delivery updates occur.</span>
                                                </div>
                                                <div class="switch">
                                                    <input type="checkbox" id="profileAudioToggle" <?php echo (int)($profile['notification_audio'] ?? 1) === 1 ? 'checked' : ''; ?>>
                                                    <span class="slider"></span>
                                                </div>
                                            </label>

                                            <label class="switch-label">
                                                <div class="switch-info">
                                                    <span class="switch-title">In-App Notification Alerts</span>
                                                    <span class="switch-desc">Display real-time toast alerts on top of your dashboard.</span>
                                                </div>
                                                <div class="switch">
                                                    <input type="checkbox" id="profileInboxToggle" <?php echo (int)($profile['notification_inbox'] ?? 1) === 1 ? 'checked' : ''; ?>>
                                                    <span class="slider"></span>
                                                </div>
                                            </label>
                                        </div>

                                        <div class="form-group" style="max-width: 320px; margin-top: 14px;">
                                            <label class="form-label">System Interface Language</label>
                                            <select id="profileLangSelect" class="form-control">
                                                <option value="fil" <?php echo ($profile['preferred_language'] ?? 'fil') === 'fil' ? 'selected' : ''; ?>>Filipino / Tagalog (Default)</option>
                                                <option value="en" <?php echo ($profile['preferred_language'] ?? '') === 'en' ? 'selected' : ''; ?>>English</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="settings-card">
                                <div class="settings-card-header">
                                    <div>
                                        <h3 class="settings-card-title">🔑 Change Account Password</h3>
                                        <p class="settings-card-subtitle">Verify your current credentials to establish a new secure password.</p>
                                    </div>
                                    <div>
                                        <button type="button" class="btn-primary btn-sm" id="btnSavePassword" onclick="savePersonalPassword()">
                                            🔒 Update Password
                                        </button>
                                    </div>
                                </div>
                                <div class="settings-card-body">
                                    <div class="form-grid-3">
                                        <div class="form-group">
                                            <label class="form-label">Current Password <span class="required">*</span></label>
                                            <input type="password" id="passCurrent" class="form-control" autocomplete="current-password" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">New Password (Min 8 chars) <span class="required">*</span></label>
                                            <input type="password" id="passNew" class="form-control" autocomplete="new-password" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Confirm New Password <span class="required">*</span></label>
                                            <input type="password" id="passConfirm" class="form-control" autocomplete="new-password" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Floating Save Changes Bar -->
<?php if ($isAdmin): ?>
    <div id="settingsFloatingBar" class="settings-floating-bar">
        <span>⚠️ You have unsaved changes in your system settings.</span>
        <button type="button" class="btn-secondary btn-sm" onclick="resetSettingsForm()">Discard</button>
        <button type="button" class="btn-primary btn-sm" id="btnSaveSettings" onclick="saveAllSettings()">💾 Save All Changes</button>
    </div>
<?php endif; ?>

<!-- =========================================================================
     MODAL 1: ADD / EDIT DISPUTE CATEGORY
     ========================================================================= -->
<div class="settings-modal-overlay" id="modalCategoryForm">
    <div class="settings-modal">
        <div class="settings-modal-header">
            <h3 class="settings-modal-title" id="catModalTitle">Add New Dispute Category</h3>
            <button type="button" class="settings-modal-close" onclick="closeCategoryModal()">&times;</button>
        </div>
        <div class="settings-modal-body">
            <input type="hidden" id="catModalId" value="">
            <div class="form-group">
                <label class="form-label">Category Name (English) <span class="required">*</span></label>
                <input type="text" id="catModalName" class="form-control" placeholder="e.g. Boundary &amp; Fence Dispute" required>
            </div>
            <div class="form-group">
                <label class="form-label">Tagalog Translation / Katutubong Wika</label>
                <input type="text" id="catModalTagalog" class="form-control" placeholder="e.g. Alitan sa Hangganan at Bakod">
            </div>
            <div class="form-group">
                <label class="form-label">DILG LTIA Tracking Code</label>
                <input type="text" id="catModalLtia" class="form-control" placeholder="e.g. LTIA-CIV-05">
            </div>
            <div class="form-group">
                <label class="form-label">Legal Scope / Notes</label>
                <textarea id="catModalDesc" class="form-control" placeholder="Brief legal definition or KP applicability remarks..."></textarea>
            </div>
            <label class="switch-label">
                <div class="switch-info">
                    <span class="switch-title">Active Category</span>
                    <span class="switch-desc">Available for selection in complaint filing forms.</span>
                </div>
                <div class="switch">
                    <input type="checkbox" id="catModalActive" checked>
                    <span class="slider"></span>
                </div>
            </label>
        </div>
        <div class="settings-modal-footer">
            <button type="button" class="btn-secondary" onclick="closeCategoryModal()">Cancel</button>
            <button type="button" class="btn-primary" onclick="submitCategoryForm()">Save Category</button>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL 2: ADD MARIKINA / NATIONAL HOLIDAY
     ========================================================================= -->
<div class="settings-modal-overlay" id="modalHolidayForm">
    <div class="settings-modal">
        <div class="settings-modal-header">
            <h3 class="settings-modal-title">Add Holiday / Observance Exclusion</h3>
            <button type="button" class="settings-modal-close" onclick="closeHolidayModal()">&times;</button>
        </div>
        <div class="settings-modal-body">
            <div class="form-group">
                <label class="form-label">Observance Date <span class="required">*</span></label>
                <input type="date" id="holidayDate" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Holiday Name <span class="required">*</span></label>
                <input type="text" id="holidayName" class="form-control" placeholder="e.g. Marikina Cityhood Day" required>
            </div>
            <div class="form-group">
                <label class="form-label">Classification</label>
                <select id="holidayType" class="form-control">
                    <option value="Regular Holiday">Regular Holiday (National)</option>
                    <option value="Special Non-Working">Special Non-Working Day</option>
                    <option value="Local Holiday">Local Holiday (Marikina City)</option>
                </select>
            </div>
        </div>
        <div class="settings-modal-footer">
            <button type="button" class="btn-secondary" onclick="closeHolidayModal()">Cancel</button>
            <button type="button" class="btn-primary" onclick="submitHolidayForm()">Add Holiday</button>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL 3: TEST SMS GATEWAY
     ========================================================================= -->
<div class="settings-modal-overlay" id="modalTestSms">
    <div class="settings-modal">
        <div class="settings-modal-header">
            <h3 class="settings-modal-title">📤 Send Test SMS Dispatch</h3>
            <button type="button" class="settings-modal-close" onclick="closeTestSmsModal()">&times;</button>
        </div>
        <div class="settings-modal-body">
            <div class="form-group">
                <label class="form-label">Recipient Mobile Number <span class="required">*</span></label>
                <input type="text" id="testSmsRecipient" class="form-control" placeholder="e.g. 09171234567" required>
                <div class="form-help">Enter an 11-digit mobile number for immediate SMS dispatch.</div>
            </div>
            <div class="form-group">
                <label class="form-label">Message Content</label>
                <textarea id="testSmsMessage" class="form-control">AGAP System: Ito ay isang pagsubok na mensahe mula sa Lupong Tagapamayapa ng Barangay Tumana.</textarea>
            </div>
        </div>
        <div class="settings-modal-footer">
            <button type="button" class="btn-secondary" onclick="closeTestSmsModal()">Cancel</button>
            <button type="button" class="btn-primary" id="btnSendTestSms" onclick="submitTestSms()">Send Test SMS</button>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../../assets/js/settings.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/settings.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
