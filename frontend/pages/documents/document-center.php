<?php
session_start();
$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($roleId, [1, 2, 3], true)) {
    http_response_code(403);
    die('Access Denied');
}
$canManageDocuments = in_array($roleId, [1, 2], true);

require_once __DIR__ . '/../../../backend/config/Database.php';
require_once __DIR__ . '/../../../backend/models/Setting.php';
require_once __DIR__ . '/../../../backend/services/CsrfService.php';

$settingModel = new Setting();
$settings = $settingModel->getAllSettings();

$pageTitle = 'KP Documents & Forms';
include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/documents.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/documents.css'); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="doc-page-container">
            <!-- Header Section -->
            <div class="doc-header-banner">
                <div>
                    <h1 class="doc-main-title">KP Documents &amp; Forms</h1>
                    <p class="doc-main-desc">Manage official templates, shared branding, and document output.</p>
                </div>
            </div>

            <div id="documentMessage" role="alert" style="display: none;"></div>

            <!-- Top 3 Metric Cards (Matching Wireframe) -->
            <div class="doc-stats-grid">
                <div class="doc-stat-card">
                    <div class="doc-stat-number" id="statTotalForms">25+</div>
                    <div class="doc-stat-label">KP form templates</div>
                    <div class="doc-stat-sub">Pre-docketing, Lupon, Mediation, Pangkat, and Certifications</div>
                </div>
                <div class="doc-stat-card">
                    <div class="doc-stat-number stat-badge-published">
                        <span class="pulse-indicator"></span> Published
                    </div>
                    <div class="doc-stat-label">Active templates</div>
                    <div class="doc-stat-sub" id="statPublishedDesc">Master templates active for document generation</div>
                </div>
                <div class="doc-stat-card">
                    <div class="doc-stat-number stat-badge-shared">Shared</div>
                    <div class="doc-stat-label">Branding profile</div>
                    <div class="doc-stat-sub">Barangay Tumana letterhead, official seals, and paper settings</div>
                </div>
            </div>

            <!-- Top Navigation Tabs (Pills) -->
            <div class="doc-tabs-bar">
                <div class="doc-tabs-nav">
                    <button type="button" class="doc-tab-btn active" data-tab="templates">Templates</button>
                    <button type="button" class="doc-tab-btn" data-tab="letterhead">Letterhead &amp; Branding</button>
                    <button type="button" class="doc-tab-btn" data-tab="print-settings">Print Settings</button>
                </div>
            </div>

            <!-- =========================================================================
                 TAB 1: TEMPLATES (Default Active Tab)
                 ========================================================================= -->
            <div class="doc-tab-panel active" id="tab-templates">
                <div class="doc-card">
                    <div class="doc-section-header">
                        <div>
                            <h2 class="doc-section-heading">Document templates</h2>
                            <p class="doc-section-subtext">Manage master templates, uploaded DOCX files, and form-level customizations.</p>
                        </div>
                        <?php if ($canManageDocuments): ?>
                        <button type="button" class="btn-primary-upload" id="btnOpenUploadModal">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                            Upload DOCX
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Search Input -->
                    <div class="doc-search-container">
                        <div class="doc-search-box">
                            <svg class="doc-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            <input type="text" id="catalogSearchInput" class="doc-search-field" placeholder="Search templates...">
                        </div>
                    </div>

                    <!-- Category Filter Pills -->
                    <div class="doc-category-pills" id="catalogCategoryPills">
                        <button type="button" class="doc-cat-pill active" data-category="ALL">All Forms</button>
                        <button type="button" class="doc-cat-pill" data-category="Pre-Docketing">Pre-Docketing</button>
                        <button type="button" class="doc-cat-pill" data-category="Mediation">Mediation</button>
                        <button type="button" class="doc-cat-pill" data-category="Pangkat">Pangkat</button>
                        <button type="button" class="doc-cat-pill" data-category="Certifications">Certifications</button>
                    </div>

                    <!-- Grid of Template Cards (Matching Wireframe) -->
                    <div class="doc-templates-grid" id="kpFormsGrid">
                        <div class="doc-loading-state">
                            <div class="doc-spinner"></div>
                            <span>Loading KP Form templates...</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 TAB 2: LETTERHEAD & BRANDING
                 ========================================================================= -->
            <div class="doc-tab-panel" id="tab-letterhead">
                <div class="doc-card">
                    <div class="doc-section-header">
                        <div>
                            <h2 class="doc-section-heading">Letterhead &amp; Official Branding Profile</h2>
                            <p class="doc-section-subtext">Centralized settings for Republic heading, LGU hierarchy, official logos, and authorized signatories.</p>
                        </div>
                    </div>

                    <div class="letterhead-editor-grid">
                        <!-- Configuration Form Column -->
                        <div class="branding-form-col">
                            <form id="brandingConfigForm">
                                <h3 class="editor-subhead">🏛️ Republic &amp; Barangay Tumana Hierarchy</h3>

                                <div class="form-group-field">
                                    <label class="form-label-custom">Republic Title Line</label>
                                    <input type="text" id="brand_republic_heading" name="republic_heading" class="form-control-custom" placeholder="REPUBLIKA NG PILIPINAS">
                                </div>

                                <div class="form-grid-2">
                                    <div class="form-group-field">
                                        <label class="form-label-custom">Province Title Line</label>
                                        <input type="text" id="brand_province_name" name="province_name" class="form-control-custom" placeholder="LALAWIGAN NG RIZAL">
                                    </div>
                                    <div class="form-group-field">
                                        <label class="form-label-custom">City Title Line</label>
                                        <input type="text" id="brand_city_name" name="city_name" class="form-control-custom" placeholder="LUNGSOD NG MARIKINA">
                                    </div>
                                </div>

                                <div class="form-group-field">
                                    <label class="form-label-custom">Barangay Title Line</label>
                                    <input type="text" id="brand_barangay_name" name="barangay_name" class="form-control-custom" placeholder="BARANGAY TUMANA">
                                </div>

                                <div class="form-group-field">
                                    <label class="form-label-custom">Official Office Address</label>
                                    <input type="text" id="brand_address" name="address" class="form-control-custom" placeholder="Barangay Hall, Tumana, Marikina City, Metro Manila">
                                </div>

                                <div class="form-grid-2">
                                    <div class="form-group-field">
                                        <label class="form-label-custom">Official Contact / Phone</label>
                                        <input type="text" id="brand_phone" name="phone" class="form-control-custom" placeholder="(02) 8942-0123 / 0917-123-4567">
                                    </div>
                                    <div class="form-group-field">
                                        <label class="form-label-custom">Official Email Address</label>
                                        <input type="email" id="brand_email" name="email" class="form-control-custom" placeholder="barangay.tumana@marikina.gov.ph">
                                    </div>
                                </div>

                                <h3 class="editor-subhead" style="margin-top: 24px;">🛡️ Official Seals &amp; Logos</h3>
                                <p style="font-size: 0.8rem; color: #64748b; margin: -8px 0 14px 0;">
                                    Upload custom official seals for Barangay Tumana and City of Marikina. Changes update immediately across all forms and master templates.
                                </p>
                                <div class="seals-preview-row">
                                    <div class="seal-item-card">
                                        <img src="../../assets/images/tumana_logo.jpg" id="previewTumanaSeal" alt="Barangay Tumana Seal" class="seal-thumbnail">
                                        <div class="seal-meta">
                                            <strong>Barangay Tumana Seal</strong>
                                            <span>Position: Primary (Left) &bull; 20mm</span>
                                            <span class="badge-status-active" id="statusTumanaSeal">Active on templates</span>
                                            <div class="seal-actions-row">
                                                <input type="file" id="inputPrimaryLogo" accept=".png,.jpg,.jpeg,.webp,.svg" style="display: none;">
                                                <button type="button" class="btn-seal-upload" onclick="triggerLogoUpload('primary')">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                                                    Change Seal
                                                </button>
                                                <button type="button" class="btn-seal-reset" onclick="resetLogoToDefault('primary')" title="Reset to official system default">
                                                    ↺ Reset
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="seal-item-card">
                                        <img src="../../assets/images/marikina_logo.jpg" id="previewMarikinaSeal" alt="City of Marikina Seal" class="seal-thumbnail">
                                        <div class="seal-meta">
                                            <strong>City of Marikina Seal</strong>
                                            <span>Position: Secondary (Right) &bull; 20mm</span>
                                            <span class="badge-status-active" id="statusMarikinaSeal">Active on templates</span>
                                            <div class="seal-actions-row">
                                                <input type="file" id="inputSecondaryLogo" accept=".png,.jpg,.jpeg,.webp,.svg" style="display: none;">
                                                <button type="button" class="btn-seal-upload" onclick="triggerLogoUpload('secondary')">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                                                    Change Seal
                                                </button>
                                                <button type="button" class="btn-seal-reset" onclick="resetLogoToDefault('secondary')" title="Reset to official system default">
                                                    ↺ Reset
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <h3 class="editor-subhead" style="margin-top: 24px;">✍️ Authorized Signatory Profiles</h3>

                                <div class="form-grid-2">
                                    <div class="form-group-field">
                                        <label class="form-label-custom">Punong Barangay / Lupon Chairman</label>
                                        <input type="text" id="sig_pb_name" class="form-control-custom" placeholder="HON. FRANCISCO G. SANDOVAL">
                                    </div>
                                    <div class="form-group-field">
                                        <label class="form-label-custom">Title / Designation</label>
                                        <input type="text" id="sig_pb_title" class="form-control-custom" placeholder="Punong Barangay / Lupon Chairman">
                                    </div>
                                </div>

                                <div class="form-grid-2">
                                    <div class="form-group-field">
                                        <label class="form-label-custom">Lupon Secretary</label>
                                        <input type="text" id="sig_sec_name" class="form-control-custom" placeholder="MARIA CLARA DE GUZMAN">
                                    </div>
                                    <div class="form-group-field">
                                        <label class="form-label-custom">Title / Designation</label>
                                        <input type="text" id="sig_sec_title" class="form-control-custom" placeholder="Kalihim ng Lupon">
                                    </div>
                                </div>

                                <div class="form-grid-2">
                                    <div class="form-group-field">
                                        <label class="form-label-custom">Pangkat Chairman</label>
                                        <input type="text" id="sig_pangkat_c_name" class="form-control-custom" placeholder="JUAN C. DELA CRUZ">
                                    </div>
                                    <div class="form-group-field">
                                        <label class="form-label-custom">Title / Designation</label>
                                        <input type="text" id="sig_pangkat_c_title" class="form-control-custom" placeholder="Tagapangulo ng Pangkat">
                                    </div>
                                </div>

                                <?php if ($canManageDocuments): ?>
                                <div class="form-actions-footer">
                                    <button type="submit" id="btnSaveBranding" class="btn-save-primary">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                                        Save Letterhead &amp; Branding Profile
                                    </button>
                                </div>
                                <?php endif; ?>
                            </form>
                        </div>

                        <!-- Live Header Preview Column -->
                        <div class="branding-preview-col">
                            <div class="live-preview-box">
                                <div class="live-preview-header">
                                    <span class="preview-badge">LIVE SIMULATED PREVIEW</span>
                                    <span class="preview-tag">Legal (8.5" &times; 14")</span>
                                </div>
                                <div class="live-preview-paper" id="liveHeaderSheet">
                                    <div class="live-paper-header">
                                        <img src="../../assets/images/tumana_logo.jpg" class="live-logo live-logo-left" alt="Tumana Seal">
                                        <div class="live-header-text">
                                            <div class="live-rep" id="liveTextRepublic">REPUBLIKA NG PILIPINAS</div>
                                            <div class="live-prov" id="liveTextProvince">LALAWIGAN NG RIZAL</div>
                                            <div class="live-city" id="liveTextCity">LUNGSOD NG MARIKINA</div>
                                            <div class="live-brgy" id="liveTextBarangay">BARANGAY TUMANA</div>
                                        </div>
                                        <img src="../../assets/images/marikina_logo.jpg" class="live-logo live-logo-right" alt="Marikina Seal">
                                    </div>
                                    <div class="live-contact" id="liveTextContact">
                                        Barangay Hall, Tumana, Marikina City, Metro Manila &bull; Tel: (02) 8942-0123 / 0917-123-4567
                                    </div>
                                    <div class="live-divider"></div>

                                    <!-- Simulated Case Content Placeholder -->
                                    <div class="live-sample-body">
                                        <div class="live-case-title">PORMULARYO NG KP BLG. 7 (SAMPLE)</div>
                                        <div class="live-case-sub">REKLAMO (COMPLAINT)</div>
                                        <div class="live-sample-lines">
                                            <div class="live-sample-line full"></div>
                                            <div class="live-sample-line two-thirds"></div>
                                            <div class="live-sample-line full"></div>
                                            <div class="live-sample-line half"></div>
                                        </div>
                                    </div>
                                </div>
                                <p class="preview-caption">
                                    ℹ️ Body-only DOCX master templates automatically receive this shared letterhead, seals, and formatting during generation.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 TAB 3: PRINT SETTINGS
                 ========================================================================= -->
            <div class="doc-tab-panel" id="tab-print-settings">
                <div class="doc-card">
                    <div class="doc-section-header">
                        <div>
                            <h2 class="doc-section-heading">Print &amp; Paper Configuration</h2>
                            <p class="doc-section-subtext">Manage default paper sizes, printable margins, typography, and statutory SLA thresholds.</p>
                        </div>
                    </div>

                    <!-- Precedence Rule Banner -->
                    <div class="precedence-banner">
                        <div class="precedence-icon">ℹ️</div>
                        <div class="precedence-body">
                            <strong>Precedence Hierarchy: Form-Specific Profile &gt; Shared Document Defaults</strong>
                            <p>Any individual KP template can define its own dimensions or margins. When set to <em>Inherit Shared Settings</em>, templates strictly adopt the paper settings configured below.</p>
                        </div>
                    </div>

                    <form id="printSettingsForm">
                        <div class="settings-section">
                            <h3 class="editor-subhead">📄 Official Paper Dimensions</h3>
                            <div class="paper-selector-cards">
                                <label class="paper-card-option">
                                    <input type="radio" name="paper_size" value="Legal" id="paper_size_legal" checked>
                                    <div class="paper-card-content">
                                        <span class="paper-badge-rec">Recommended</span>
                                        <strong>Legal (8.5" &times; 14")</strong>
                                        <p>Standard Philippine Katarungang Pambarangay statutory format (RA 7160).</p>
                                    </div>
                                </label>
                                <label class="paper-card-option">
                                    <input type="radio" name="paper_size" value="A4" id="paper_size_a4">
                                    <div class="paper-card-content">
                                        <span class="paper-badge-opt">Optional</span>
                                        <strong>A4 (8.27" &times; 11.69")</strong>
                                        <p>Standard International / ISO format for administrative correspondence.</p>
                                    </div>
                                </label>
                            </div>

                            <div class="form-grid-2" style="margin-top: 16px;">
                                <div class="form-group-field">
                                    <label class="form-label-custom">Default Page Orientation</label>
                                    <select name="orientation" id="print_orientation" class="form-control-custom">
                                        <option value="portrait" selected>Portrait (Standard KP Forms)</option>
                                        <option value="landscape">Landscape (Special Registers / Rosters)</option>
                                    </select>
                                </div>
                                <div class="form-group-field">
                                    <label class="form-label-custom">Default Typography / Font Family</label>
                                    <select name="font_family" id="print_font_family" class="form-control-custom">
                                        <option value="Arial" selected>Arial (Standard KP Documents)</option>
                                        <option value="Times New Roman">Times New Roman (KP Statutory Official)</option>
                                        <option value="Calibri">Calibri (Standard Word Default)</option>
                                        <option value="Bookman Old Style">Bookman Old Style (Formal Legal)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="settings-section" style="margin-top: 24px;">
                            <h3 class="editor-subhead">📐 Standard Printable Margins (Millimeters)</h3>
                            <div class="form-grid-4">
                                <div class="form-group-field">
                                    <label class="form-label-custom">Top Margin (mm)</label>
                                    <input type="number" id="margin_top" name="margin_top" class="form-control-custom" value="15" min="5" max="40">
                                </div>
                                <div class="form-group-field">
                                    <label class="form-label-custom">Bottom Margin (mm)</label>
                                    <input type="number" id="margin_bottom" name="margin_bottom" class="form-control-custom" value="15" min="5" max="40">
                                </div>
                                <div class="form-group-field">
                                    <label class="form-label-custom">Left Margin (mm)</label>
                                    <input type="number" id="margin_left" name="margin_left" class="form-control-custom" value="18" min="5" max="40">
                                </div>
                                <div class="form-group-field">
                                    <label class="form-label-custom">Right Margin (mm)</label>
                                    <input type="number" id="margin_right" name="margin_right" class="form-control-custom" value="18" min="5" max="40">
                                </div>
                            </div>
                        </div>

                        <div class="settings-section" style="margin-top: 24px;">
                            <h3 class="editor-subhead">⏱️ Statutory Summons SLA &amp; Automation Rules</h3>
                            <div class="form-grid-2">
                                <div class="form-group-field">
                                    <label class="form-label-custom">Statutory Issuance SLA (Working Days)</label>
                                    <input type="number" id="sla_issuance" name="summons_sla_working_days" class="form-control-custom" value="<?php echo htmlspecialchars($settings['summons_sla_working_days'] ?? '1'); ?>" min="1" max="5">
                                    <span class="field-hint">RA 7160 Sec. 410 requires next-working-day summons generation upon docketing.</span>
                                </div>
                                <div class="form-group-field">
                                    <label class="form-label-custom">Unserved Delivery Warning (Working Days)</label>
                                    <input type="number" id="sla_warning" name="summons_delivery_warning_days" class="form-control-custom" value="<?php echo htmlspecialchars($settings['summons_delivery_warning_days'] ?? '3'); ?>" min="1" max="10">
                                    <span class="field-hint">Highlights overdue summons deliveries on officer dispatch board.</span>
                                </div>
                            </div>

                            <label class="toggle-switch-card" style="margin-top: 14px;">
                                <div class="toggle-info">
                                    <strong>Auto-Serve Complainant Notice (KP Form 8)</strong>
                                    <span>Automatically generate and record notice of hearing for complainant when summons is dispatched.</span>
                                </div>
                                <div class="toggle-control">
                                    <input type="checkbox" id="auto_serve_notice" name="auto_serve_complainant_notice" value="1" <?php echo ($settings['auto_serve_complainant_notice'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </div>
                            </label>
                        </div>

                        <?php if ($canManageDocuments): ?>
                        <div class="form-actions-footer">
                            <button type="submit" id="btnSavePrintSettings" class="btn-save-primary">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                                Save Print &amp; Paper Settings
                            </button>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL 1: TEMPLATE CONFIGURATION & INSPECTION MODAL (Edit / Upload DOCX)
     ========================================================================= -->
<div class="modal-backdrop-custom" id="templateEditModal" style="display: none;">
    <div class="modal-dialog-custom modal-dialog-lg">
        <div class="modal-header-custom">
            <div>
                <h3 class="modal-title-custom" id="editModalTitle">Upload template</h3>
                <p class="modal-desc-custom" id="editModalSubtitle">Configure master DOCX file, header behavior, and paper profile.</p>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeEditModal()">&times;</button>
        </div>

        <div class="modal-body-custom">
            <!-- Form Selection (Visible if opened from "+ Upload DOCX") -->
            <div class="form-group-field" id="modalFormSelectGroup">
                <label class="form-label-custom">Target Katarungang Pambarangay Form</label>
                <select id="modalFormKeySelect" class="form-control-custom">
                    <!-- Populated dynamically -->
                </select>
            </div>

            <!-- DOCX File Upload Dropzone -->
            <div class="dropzone-area" id="templateDropzone">
                <input type="file" id="modalFileInput" accept=".docx,.doc,.pdf" style="display: none;">
                <div class="dropzone-icon">📄</div>
                <div class="dropzone-text">
                    <strong>Click to select or drag and drop a DOCX template file</strong>
                    <span>Supports OpenXML .docx (Body-Only or Full-Header formats)</span>
                </div>
                <div class="dropzone-current" id="dropzoneCurrentFile" style="display: none;"></div>
            </div>

            <!-- 3 Header Modes (Prompt Requirement) -->
            <div class="form-group-field" style="margin-top: 18px;">
                <label class="form-label-custom">Document Header Mode</label>
                <div class="header-modes-grid">
                    <label class="mode-radio-card">
                        <input type="radio" name="modal_header_mode" value="shared" checked>
                        <div class="mode-radio-body">
                            <strong>Use Shared Letterhead</strong>
                            <span>Automatically injects centralized Barangay Tumana seal, Marikina seal, and republic header. (Recommended for Body-Only templates)</span>
                        </div>
                    </label>
                    <label class="mode-radio-card">
                        <input type="radio" name="modal_header_mode" value="keep">
                        <div class="mode-radio-body">
                            <strong>Keep Uploaded DOCX Header</strong>
                            <span>Preserves the custom embedded header and artwork existing in the uploaded Word document.</span>
                        </div>
                    </label>
                    <label class="mode-radio-card">
                        <input type="radio" name="modal_header_mode" value="custom">
                        <div class="mode-radio-body">
                            <strong>Custom Header for This Form</strong>
                            <span>Applies form-specific header override without altering shared document defaults.</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Paper & Margins Mode (Inherit vs Custom) -->
            <div class="form-group-field" style="margin-top: 18px;">
                <label class="form-label-custom">Paper Dimensions &amp; Margins</label>
                <div class="form-grid-2">
                    <label class="mode-radio-card">
                        <input type="radio" name="modal_paper_mode" value="shared" checked>
                        <div class="mode-radio-body">
                            <strong>Inherit Shared Settings</strong>
                            <span>Follows central paper setup (Legal, 15/15/18/18 mm).</span>
                        </div>
                    </label>
                    <label class="mode-radio-card">
                        <input type="radio" name="modal_paper_mode" value="custom">
                        <div class="mode-radio-body">
                            <strong>Custom for This Form</strong>
                            <span>Specify unique dimensions for this form.</span>
                        </div>
                    </label>
                </div>
            </div>

            <div id="customPaperInputsRow" class="form-grid-4" style="display: none; margin-top: 12px; padding: 12px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div class="form-group-field">
                    <label class="form-label-custom">Paper Size</label>
                    <select id="modal_custom_paper_size" class="form-control-custom">
                        <option value="Legal">Legal (8.5" x 14")</option>
                        <option value="A4">A4 (8.27" x 11.69")</option>
                    </select>
                </div>
                <div class="form-group-field">
                    <label class="form-label-custom">Top Margin (mm)</label>
                    <input type="number" id="modal_custom_margin_top" class="form-control-custom" value="15" min="5" max="40">
                </div>
                <div class="form-group-field">
                    <label class="form-label-custom">Bottom Margin (mm)</label>
                    <input type="number" id="modal_custom_margin_bottom" class="form-control-custom" value="15" min="5" max="40">
                </div>
                <div class="form-group-field">
                    <label class="form-label-custom">Left/Right (mm)</label>
                    <input type="number" id="modal_custom_margin_left" class="form-control-custom" value="18" min="5" max="40">
                </div>
            </div>

            <!-- Signatory Profile -->
            <div class="form-group-field" style="margin-top: 18px;">
                <label class="form-label-custom">Authorized Signatory Profile</label>
                <select id="modal_signatory_profile" class="form-control-custom">
                    <option value="punong_barangay">Punong Barangay / Lupon Chairman</option>
                    <option value="lupon_secretary">Kalihim ng Lupon (Lupon Secretary)</option>
                    <option value="pangkat_chairman">Tagapangulo ng Pangkat (Pangkat Chairman)</option>
                    <option value="pangkat_secretary">Kalihim ng Pangkat (Pangkat Secretary)</option>
                </select>
            </div>
        </div>

        <div class="modal-footer-custom">
            <div class="modal-footer-left">
                <button type="button" class="btn-outline-modal" id="btnModalDownloadDocx">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    Download Master DOCX
                </button>
                <button type="button" class="btn-outline-modal" id="btnModalPreviewDocx">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    Preview Output
                </button>
            </div>
            <div class="modal-footer-right">
                <button type="button" class="btn-cancel-modal" onclick="closeEditModal()">Cancel</button>
                <button type="button" class="btn-save-primary" id="btnSaveTemplateConfig">Save &amp; Publish Template</button>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL 2: SYNCHRONIZED PREVIEW MODAL
     ========================================================================= -->
<div class="modal-backdrop-custom" id="templatePreviewModal" style="display: none;">
    <div class="modal-dialog-custom modal-dialog-xl">
        <div class="modal-header-custom">
            <div>
                <h3 class="modal-title-custom" id="previewModalTitle">Template Preview</h3>
                <p class="modal-desc-custom" id="previewModalSubtitle" style="display: none;"></p>
            </div>
            <div class="preview-header-actions">
                <button type="button" class="btn-preview-action" onclick="printPreviewIframe()">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                    Print
                </button>
                <a href="#" class="btn-preview-action btn-preview-accent" id="previewDownloadDocxBtn">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    Download DOCX
                </a>
                <button type="button" class="modal-close-btn" onclick="closePreviewModal()">&times;</button>
            </div>
        </div>

        <div class="modal-body-custom modal-body-iframe">
            <iframe id="previewIframe" class="preview-iframe-element" src="about:blank"></iframe>
        </div>
    </div>
</div>

<script>
window.AGAP_DOCUMENTS = Object.freeze({
    canManage: <?php echo $canManageDocuments ? 'true' : 'false'; ?>,
    csrfToken: '<?php echo htmlspecialchars(CsrfService::token(), ENT_QUOTES, 'UTF-8'); ?>'
});
</script>
<script src="../../assets/js/documents.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/documents.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
