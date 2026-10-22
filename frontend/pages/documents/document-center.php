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

$settingModel = new Setting();
$settings = $settingModel->getAllSettings();

$pageTitle = 'KP Documents and Forms';
include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/documents.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/documents.css'); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>
        
        <div class="page-header">
            <div>
                <h1>KP Documents and Forms</h1>
                <p>Official Katarungang Pambarangay forms catalog, statutory templates, and printing configuration.</p>
            </div>
        </div>
        <div id="documentMessage" role="alert" style="display: none;"></div>

        <!-- =========================================================================
             SECTION 1: KP DOCUMENTS & FORMS CONFIGURATION (Paper sizing, margins, SLA)
             ========================================================================= -->
        <?php if ($canManageDocuments): ?>
        <section class="document-card" id="documentSettingsCard">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #e2e8f0; padding-bottom: 14px;">
                <div>
                    <h2 style="margin: 0; font-size: 1.25rem; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                        <span>⚙️</span> KP Documents &amp; Forms Configuration
                    </h2>
                    <p style="margin: 4px 0 0; color: #64748b; font-size: 0.86rem;">
                        Standard printing dimensions, SLA delivery thresholds, and official headers for Katarungang Pambarangay forms.
                    </p>
                </div>
            </div>

            <form id="docSettingsForm">
                <!-- Document Printing & Page Size Rules -->
                <div style="margin-bottom: 24px;">
                    <h3 style="font-size: 0.95rem; font-weight: 700; color: #1e293b; margin: 0 0 14px; display: flex; align-items: center; gap: 6px;">
                        <span>📄</span> Document Printing &amp; Page Size Rules
                    </h3>
                    
                    <div class="doc-form-group">
                        <label class="doc-form-label">Official Paper Size Format</label>
                        <div style="display: flex; gap: 24px; align-items: center; margin-top: 6px; flex-wrap: wrap;">
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; cursor: pointer;">
                                <input type="radio" name="document_paper_size" value="Legal" <?php echo ($settings['document_paper_size'] ?? 'Legal') === 'Legal' ? 'checked' : ''; ?>>
                                <span><strong>Legal (8.5" &times; 14")</strong> &bull; Philippine KP Statutory Standard (Recommended)</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; cursor: pointer;">
                                <input type="radio" name="document_paper_size" value="A4" <?php echo ($settings['document_paper_size'] ?? '') === 'A4' ? 'checked' : ''; ?>>
                                <span><strong>A4 (8.27" &times; 11.69")</strong> &bull; International Standard</span>
                            </label>
                        </div>
                    </div>

                    <div class="doc-form-grid-4" style="margin-top: 14px;">
                        <div class="doc-form-group">
                            <label class="doc-form-label">Top Margin (mm)</label>
                            <input type="number" name="document_margin_top" class="doc-form-control" value="<?php echo htmlspecialchars($settings['document_margin_top'] ?? '15'); ?>" min="5" max="40">
                        </div>
                        <div class="doc-form-group">
                            <label class="doc-form-label">Bottom Margin (mm)</label>
                            <input type="number" name="document_margin_bottom" class="doc-form-control" value="<?php echo htmlspecialchars($settings['document_margin_bottom'] ?? '15'); ?>" min="5" max="40">
                        </div>
                        <div class="doc-form-group">
                            <label class="doc-form-label">Left Margin (mm)</label>
                            <input type="number" name="document_margin_left" class="doc-form-control" value="<?php echo htmlspecialchars($settings['document_margin_left'] ?? '18'); ?>" min="5" max="40">
                        </div>
                        <div class="doc-form-group">
                            <label class="doc-form-label">Right Margin (mm)</label>
                            <input type="number" name="document_margin_right" class="doc-form-control" value="<?php echo htmlspecialchars($settings['document_margin_right'] ?? '18'); ?>" min="5" max="40">
                        </div>
                    </div>

                    <label class="doc-switch-label" style="margin-top: 16px;">
                        <div class="doc-switch-info">
                            <span class="doc-switch-title">Auto-Serve Complainant Notice (KP Form 8)</span>
                            <span class="doc-switch-desc">Automatically generate and log as 'Served' the Notice of Hearing for the Complainant whenever Respondent Summons is issued.</span>
                        </div>
                        <div class="doc-switch">
                            <input type="checkbox" name="auto_serve_complainant_notice" value="1" <?php echo ($settings['auto_serve_complainant_notice'] ?? '1') === '1' ? 'checked' : ''; ?>>
                            <span class="doc-slider"></span>
                        </div>
                    </label>
                </div>

                <!-- Statutory Summons SLA Rules -->
                <div style="margin-bottom: 24px; padding-top: 18px; border-top: 1px solid #f1f5f9;">
                    <h3 style="font-size: 0.95rem; font-weight: 700; color: #1e293b; margin: 0 0 6px; display: flex; align-items: center; gap: 6px;">
                        <span>⏱️</span> Statutory Summons SLA Rules (RA 7160 Sec. 410)
                    </h3>
                    <p style="margin: 0 0 14px; font-size: 0.82rem; color: #64748b;">
                        SLA thresholds feeding into the DILG 21-column Excel report and summons officer dispatch board.
                    </p>

                    <div class="doc-form-grid-2">
                        <div class="doc-form-group">
                            <label class="doc-form-label">Statutory Issuance SLA (Working Days)</label>
                            <input type="number" name="summons_sla_working_days" class="doc-form-control" value="<?php echo htmlspecialchars($settings['summons_sla_working_days'] ?? '1'); ?>" min="1" max="5">
                            <div class="doc-form-help">RA 7160 requires next-working-day summons generation upon docketing.</div>
                        </div>
                        <div class="doc-form-group">
                            <label class="doc-form-label">Unserved Warning Alert (Working Days)</label>
                            <input type="number" name="summons_delivery_warning_days" class="doc-form-control" value="<?php echo htmlspecialchars($settings['summons_delivery_warning_days'] ?? '3'); ?>" min="1" max="10">
                            <div class="doc-form-help">Highlights overdue summons deliveries on the officer GPS dashboard.</div>
                        </div>
                    </div>

                    <div class="doc-form-group" style="margin-top: 14px;">
                        <label class="doc-form-label">Header Top Banner (Republika ng Pilipinas)</label>
                        <input type="text" name="kp_header_title" class="doc-form-control" value="<?php echo htmlspecialchars($settings['kp_header_title'] ?? 'REPUBLIKA NG PILIPINAS'); ?>">
                    </div>

                    <div class="doc-form-group">
                        <label class="doc-form-label">LGU Hierarchy Sub-Header</label>
                        <input type="text" name="kp_lgu_header" class="doc-form-control" value="<?php echo htmlspecialchars($settings['kp_lgu_header'] ?? 'LALAWIGAN NG RIZAL / LUNGSOD NG MARIKINA / BARANGAY TUMANA'); ?>">
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; padding-top: 16px; border-top: 1px solid #f1f5f9;">
                    <button type="submit" id="btnSaveDocSettings" class="btn-create" style="display: inline-flex; align-items: center; gap: 8px;">
                        <span>💾</span> Save KP Document Settings
                    </button>
                </div>
            </form>
        </section>
        <?php endif; ?>

        <!-- =========================================================================
             SECTION 2: OFFICIAL KP FORMS CATALOG & PLACEHOLDERS (KP Forms 1 - 25)
             ========================================================================= -->
        <section class="document-card" id="kpFormsCatalogSection">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; gap: 14px;">
                <div>
                    <h2 style="margin: 0; font-size: 1.3rem; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                        <span>📜</span> Official Katarungang Pambarangay Form Templates
                    </h2>
                    <p style="margin: 4px 0 0; color: #64748b; font-size: 0.88rem;">
                        Statutory Katarungang Pambarangay forms (KP Forms 1 to 25). Upload custom office templates (.pdf, .docx, .doc, images), preview online, or print directly.
                    </p>
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <span id="formsCountBadge" style="background: #e2e8f0; color: #334155; font-size: 0.82rem; font-weight: 700; padding: 6px 12px; border-radius: 20px;">
                        27 Forms Available
                    </span>
                    <span id="uploadedCountBadge" style="background: #dcfce7; color: #166534; font-size: 0.82rem; font-weight: 700; padding: 6px 12px; border-radius: 20px;">
                        0 Custom Uploaded
                    </span>
                </div>
            </div>

            <!-- Toolbar: Search & Category Filter Pills -->
            <div class="catalog-toolbar">
                <div class="catalog-search-box">
                    <span class="catalog-search-icon">🔍</span>
                    <input type="text" id="catalogSearchInput" class="catalog-search-input" placeholder="Search forms by number (e.g., KP 7), title, or keywords...">
                </div>
            </div>

            <div class="catalog-filter-pills" id="catalogCategoryPills">
                <button type="button" class="filter-pill active" data-category="ALL">All Forms (27)</button>
                <button type="button" class="filter-pill" data-category="Lupon Organization">Lupon Organization (KP 1-6)</button>
                <button type="button" class="filter-pill" data-category="Mediation & Summons">Mediation &amp; Summons (KP 7-9)</button>
                <button type="button" class="filter-pill" data-category="Conciliation & Pangkat">Conciliation &amp; Pangkat (KP 10-13)</button>
                <button type="button" class="filter-pill" data-category="Arbitration & Settlement">Arbitration &amp; Settlement (KP 14-17)</button>
                <button type="button" class="filter-pill" data-category="Hearings & Compliance">Hearings &amp; Compliance (KP 18-19)</button>
                <button type="button" class="filter-pill" data-category="Certifications & Barred Actions">Certifications (KP 20-22)</button>
                <button type="button" class="filter-pill" data-category="Execution of Settlement">Execution (KP 23-25)</button>
            </div>

            <!-- Grid of 27 Form Cards -->
            <div class="kp-forms-grid" id="kpFormsGrid">
                <!-- Populated dynamically via documents.js -->
                <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #64748b;">
                    Loading KP Form templates...
                </div>
            </div>
        </section>

    </div>
</div>

<!-- Hidden File Input for Template Uploads -->
<input type="file" id="templateFileInput" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" style="display: none;">

<!-- Interactive Template Preview Modal -->
<div class="kp-modal-overlay" id="templatePreviewModal">
    <div class="kp-modal-container">
        <div class="kp-modal-header">
            <h3 id="modalFormTitle">
                <span>📄</span> KP Form Preview
            </h3>
            <div class="kp-modal-actions">
                <button type="button" class="btn-print-kp" id="btnModalPrint" onclick="printModalIframe()">
                    <span>🖨️</span> Print Template
                </button>
                <a href="#" target="_blank" class="btn-preview-kp" id="btnModalNewTab" style="background: #334155;">
                    <span>↗</span> Open Full Window
                </a>
                <button type="button" class="kp-modal-close" onclick="closeTemplateModal()">&times;</button>
            </div>
        </div>
        <div class="kp-modal-body">
            <iframe id="templatePreviewIframe" class="kp-modal-iframe" src="about:blank"></iframe>
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
