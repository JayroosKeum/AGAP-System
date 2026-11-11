<!-- Shared Staff Barangay News & Advisories Manager Modal -->
<div id="staffNewsManagerModal" class="report-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="staffNewsModalTitle">
    <div class="report-modal-container" style="max-width: 780px;">
        <header class="report-modal-header" style="padding: 16px 20px; border-bottom: 1px solid #e2e8f0; background: #f8fafc;">
            <div class="modal-header-info">
                <span class="report-modal-badge">Barangay Tumana Bulletin</span>
                <h2 id="staffNewsModalTitle" class="report-modal-title" style="font-size: 1.15rem; margin: 4px 0 0;">Manage Barangay News &amp; Advisories</h2>
                <p class="report-modal-sub" style="margin: 2px 0 0;">Published news appears on both the Staff bulletin and the Resident Portal Dashboard.</p>
            </div>
            <button type="button" class="btn-modal-close" id="btnCloseStaffNewsModal" aria-label="Close news manager">&times;</button>
        </header>

        <div class="report-modal-body" style="padding: 20px; max-height: 74vh; overflow-y: auto;">
            <div id="staffNewsFormAlert" class="alert" style="display:none; margin-bottom: 14px;"></div>

            <form id="staffNewsForm" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-bottom: 20px;">
                <input type="hidden" id="staffNewsEditId" value="">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:12px;">
                    <strong id="staffNewsFormHeading" style="font-size:0.95rem; color:#102b2d;">Publish Barangay News</strong>
                    <button type="button" class="btn-secondary" id="btnResetStaffNewsForm" style="padding:4px 10px; font-size:0.78rem;">Clear Form</button>
                </div>

                <div class="agap-field-row-2" style="display:grid; grid-template-columns:minmax(0,1.4fr) minmax(0,.8fr) minmax(0,.6fr); gap:10px; margin-bottom:10px;">
                    <div class="form-group" style="margin:0;">
                        <label for="staffNewsTitle" style="display:block; font-size:0.78rem; font-weight:700; margin-bottom:4px;">Headline <span class="req">*</span></label>
                        <input type="text" id="staffNewsTitle" required minlength="3" maxlength="180" placeholder="e.g., Scheduled Lupon Mediation Desk Notice" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:0.84rem;">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label for="staffNewsCategory" style="display:block; font-size:0.78rem; font-weight:700; margin-bottom:4px;">Category</label>
                        <select id="staffNewsCategory" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:0.84rem; background:#fff;">
                            <option value="Announcement">Announcement</option>
                            <option value="Public Service">Public Service</option>
                            <option value="Lupon Notice">Lupon Notice</option>
                            <option value="Community Advisory">Community Advisory</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label for="staffNewsStatus" style="display:block; font-size:0.78rem; font-weight:700; margin-bottom:4px;">Visibility</label>
                        <select id="staffNewsStatus" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:0.84rem; background:#fff;">
                            <option value="Published">Published</option>
                            <option value="Archived">Archived</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin:0 0 10px;">
                    <label for="staffNewsSummary" style="display:block; font-size:0.78rem; font-weight:700; margin-bottom:4px;">Short Summary <small style="font-weight:500; color:#64748b;">(optional &mdash; auto-excerpted if left blank)</small></label>
                    <input type="text" id="staffNewsSummary" maxlength="320" placeholder="Brief 1–2 sentence preview shown on dashboard cards" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:0.84rem;">
                </div>

                <div class="form-group" style="margin:0 0 12px;">
                    <label for="staffNewsContent" style="display:block; font-size:0.78rem; font-weight:700; margin-bottom:4px;">Full News Details <span class="req">*</span></label>
                    <textarea id="staffNewsContent" rows="4" required minlength="10" maxlength="4000" placeholder="Write complete advisory details for Barangay Tumana residents and staff..." style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:0.84rem; font-family:inherit; resize:vertical;"></textarea>
                </div>

                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                    <label style="display:inline-flex; align-items:center; gap:7px; font-size:0.82rem; font-weight:600; color:#102b2d; cursor:pointer;">
                        <input type="checkbox" id="staffNewsPinned" value="1" style="width:15px; height:15px; accent-color:#1b6553;">
                        <span>Pin to top of dashboard news</span>
                    </label>
                    <button type="submit" id="btnSaveStaffNews" style="background:#102b2d; color:#fff; border:0; border-radius:7px; padding:8px 16px; font-size:0.84rem; font-weight:700; cursor:pointer;">
                        Publish News
                    </button>
                </div>
            </form>

            <div>
                <h3 style="margin:0 0 10px; font-size:0.9rem; color:#102b2d;">Existing News &amp; Advisories</h3>
                <div id="staffNewsManagerList" class="staff-news-manager-list">
                    <p style="color:#64748b; font-size:0.84rem;">Loading news entries...</p>
                </div>
            </div>
        </div>

        <footer class="report-modal-footer" style="padding: 14px 20px; border-top: 1px solid #e2e8f0; background: #f8fafc; display:flex; justify-content:flex-end;">
            <button type="button" class="btn-modal-close-footer" id="btnCloseStaffNewsFooter">Close</button>
        </footer>
    </div>
</div>
