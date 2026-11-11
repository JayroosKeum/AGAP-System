<footer class="login-footer">
    AGAP <span aria-hidden="true">·</span> Automated Grievance Assistance Platform <span aria-hidden="true">·</span> © <?php echo date('Y'); ?> <span aria-hidden="true">·</span> <a href="#agapLegalModal" id="openLegalModalFooterBtn" class="login-footer-link">Terms &amp; Data Privacy</a>
</footer>

<!-- Terms and Conditions, Data Privacy & Legal Compliance Modal -->
<div id="agapLegalModal" class="login-legal-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="agapLegalModalTitle" hidden>
    <div class="login-legal-modal-card">
        <header class="login-legal-modal-header">
            <div>
                <p class="login-legal-modal-eyebrow">BARANGAY TUMANA · LUPONG TAGAPAMAYAPA</p>
                <h2 id="agapLegalModalTitle">Terms and Conditions, Data Privacy &amp; Legal Compliance Policy</h2>
            </div>
            <button type="button" id="closeLegalModalTopBtn" class="login-legal-modal-close" aria-label="Close policy dialog">&times;</button>
        </header>

        <div class="login-legal-modal-body" tabindex="0">
            <div class="login-legal-badges">
                <span class="login-legal-badge">RA 10173 · Data Privacy Act of 2012</span>
                <span class="login-legal-badge">RA 7160 · Local Government Code (KP Law)</span>
                <span class="login-legal-badge">NPC Circular No. 16-01 · Government Data Security</span>
            </div>

            <p class="login-legal-preamble">
                The <strong>Automated Grievance Assistance Platform (AGAP)</strong> is an official web-based case and resolution management system for the <em>Lupong Tagapamayapa</em> of Barangay Tumana, Marikina City. Access to and use of this platform is governed by relevant Philippine laws on data privacy, local governance, and public service accountability.
            </p>

            <section class="login-legal-section">
                <h3>1. General Use &amp; Statutory Mandate</h3>
                <p><strong>1.1.</strong> The system is provided as an official web-based platform for recording, managing, and monitoring complaint records, preliminary invitations (<em>Paanyaya</em>), mediation and Pangkat conciliation proceedings, arbitration records, hearing schedules, and related barangay case documentation.</p>
                <p><strong>1.2.</strong> Users may only access and use the system for official barangay functions pursuant to <strong>Republic Act No. 7160 (Local Government Code of 1991)</strong>, including complaint intake, mediation, conciliation, summons dispatch, statutory form generation (KP Forms 7 to 25), and administrative reporting (including <em>Lupong Tagapamayapa Incentives Awards</em> / LTIA compliance).</p>
                <p><strong>1.3.</strong> All system content—including resident profiles, complaint narratives, session minutes, settlement agreements, Certificates to File Action (CFA), and statistical reports—is protected by applicable laws and must not be used beyond the official purposes defined in these Terms and Conditions.</p>
            </section>

            <section class="login-legal-section">
                <h3>2. User Accounts, Role-Based Access Control &amp; Audit Monitoring</h3>
                <p><strong>2.1.</strong> Access is strictly restricted to authorized Barangay Tumana personnel and registered Barangay Residents using Role-Based Access Control (RBAC), each provisioned with an individual account.</p>
                <p><strong>2.2.</strong> Users are strictly responsible for maintaining the confidentiality of their login credentials and for all actions performed under their accounts. Sharing accounts, passwords, or active sessions is prohibited.</p>
                <p><strong>2.3.</strong> <strong>System Audit Trail &amp; Session Monitoring:</strong> All authentication events, record views, case modifications, document generations, and archival actions are recorded in system audit logs with user identifiers and timestamps.</p>
            </section>

            <section class="login-legal-section">
                <h3>3. Submission and Management of Complaint &amp; Case Data</h3>
                <p><strong>3.1.</strong> By entering, uploading, or modifying complaint and case data, users confirm that the submitted details are accurate, objective, and faithful to the actual incident or official proceedings.</p>
                <p><strong>3.2.</strong> Authorized barangay administrators and officials process and maintain submitted records within AGAP for legitimate barangay justice administration and statutory dispute resolution purposes.</p>
            </section>

            <section class="login-legal-section">
                <h3>4. Privacy and Data Protection (RA 10173 &amp; NPC Standards)</h3>
                <p><strong>4.1.</strong> AGAP adheres to <strong>Republic Act No. 10173 (Data Privacy Act of 2012)</strong>, its Implementing Rules and Regulations (IRR), and <strong>National Privacy Commission (NPC) Circular No. 16-01</strong>.</p>
                <p><strong>4.2.</strong> Personal information collected through the platform—including profile contact details verified by the Barangay Administrator or Secretary—shall be processed solely for barangay dispute resolution and legal compliance.</p>
            </section>

            <section class="login-legal-section">
                <h3>5. AI-Assisted Features &amp; Geolocation Tools</h3>
                <p><strong>5.1.</strong> AI capabilities act solely as clerical assistance; authorized <em>Lupong Tagapamayapa</em> personnel remain responsible for confirming official classifications, schedules, and resolutions.</p>
                <p><strong>5.2.</strong> Map coordinates and Purok details are used to verify Barangay Tumana territorial jurisdiction under RA 7160 and guide service of notices and summons.</p>
            </section>
        </div>

        <footer class="login-legal-modal-footer">
            <span class="login-legal-modal-meta">Republic Act No. 10173 &amp; Republic Act No. 7160 Compliant</span>
            <button type="button" id="closeLegalModalBottomBtn" class="login-legal-modal-ack-btn">I Understand &amp; Close</button>
        </footer>
    </div>
</div>

<script>
(() => {
    const modal = document.getElementById('agapLegalModal');
    const openBtns = [
        document.getElementById('openLegalModalBtn'),
        document.getElementById('openLegalModalFooterBtn')
    ];
    const closeBtns = [
        document.getElementById('closeLegalModalTopBtn'),
        document.getElementById('closeLegalModalBottomBtn')
    ];

    if (!modal) return;

    const openModal = (e) => {
        if (e) e.preventDefault();
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        document.getElementById('closeLegalModalBottomBtn')?.focus();
    };

    const closeModal = () => {
        modal.hidden = true;
        document.body.style.overflow = '';
        (document.getElementById('openLegalModalBtn') || document.getElementById('openLegalModalFooterBtn'))?.focus();
    };

    openBtns.forEach((btn) => btn?.addEventListener('click', openModal));
    closeBtns.forEach((btn) => btn?.addEventListener('click', closeModal));

    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });
})();
</script>
