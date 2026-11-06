<?php
require_once __DIR__ . '/../../../backend/config/session.php';
$loginError = ($_GET['error'] ?? '') === 'invalid_credentials';
$returnTo = trim((string)($_GET['return_to'] ?? $_POST['return_to'] ?? ''));
if ($returnTo !== '' && !preg_match('#^/(frontend|backend)/#i', $returnTo) && !preg_match('#^\.\./#', $returnTo)) {
    $returnTo = '';
}
include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/login.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/login.css'); ?>">

<main class="login-screen">
    <div class="login-shell">
        <section class="login-welcome" aria-labelledby="login-welcome-title">
            <a class="login-brand" href="../landing/index.php" aria-label="AGAP home">
                <img src="../../assets/images/tumana_logo.jpg" alt="">
                <span><strong>AGAP</strong><small>Barangay Tumana</small></span>
            </a>

            <div class="welcome-copy">
                <p class="login-eyebrow">Katarungang Pambarangay · Marikina City</p>
                <h1 id="login-welcome-title">Good work starts with a <span>clear process.</span></h1>
            </div>

            <div class="welcome-foot">
                <img src="../../assets/images/marikina_logo.jpg" alt="City of Marikina seal">
                <span>Supporting fair and orderly community resolution.</span>
            </div>
            <div class="login-decoration login-decoration-one" aria-hidden="true"></div>
            <div class="login-decoration login-decoration-two" aria-hidden="true"></div>
        </section>

        <section class="login-form-area" aria-label="Staff sign in">
            <form action="../../../backend/api/auth/login.php" method="POST" class="login-card">
                <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8'); ?>">
                <p class="login-eyebrow">AGAP STAFF PORTAL</p>
                <h2>Welcome back</h2>
                <p class="login-intro">Sign in with your staff account to continue.</p>

                <?php if ($loginError): ?>
                    <div class="auth-message error" role="alert">
                        Invalid username or password.
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" autocomplete="username" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" autocomplete="current-password" required>
                </div>

                <button type="submit">Sign in</button>
                <p class="auth-link"><a href="forgot-password.php">Forgot your password?</a></p>

                <!-- Passive Authorized Use & Data Privacy Notice -->
                <div class="login-legal-notice" role="note" aria-label="Authorized Use and Data Privacy Notice">
                    <div class="login-legal-notice-head">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                        </svg>
                        <strong>Authorized Personnel Notice</strong>
                    </div>
                    <p class="login-legal-notice-text">
                        By signing in, you acknowledge that this system is restricted to official <em>Katarungang Pambarangay</em> functions of Barangay Tumana under <strong>RA 7160</strong> and <strong>RA 10173 (Data Privacy Act of 2012)</strong>. All user sessions and actions are recorded in system audit logs.
                        <a href="#agapLegalModal" id="openLegalModalBtn" class="login-legal-link">Terms, Conditions &amp; Data Privacy Policy</a>.
                    </p>
                </div>
            </form>
        </section>
    </div>
</main>

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
                <p><strong>2.1.</strong> Access is strictly restricted to authorized Barangay Tumana personnel—specifically the Punong Barangay / Barangay Secretary (Admin), Lupon Clerk, Lupon Members, and Summons / Process Servers—each provisioned with an official individual account.</p>
                <p><strong>2.2.</strong> Users are strictly responsible for maintaining the confidentiality of their login credentials and for all actions performed under their accounts. Sharing accounts, passwords, or active sessions is prohibited.</p>
                <p><strong>2.3.</strong> <strong>Role-Based Access Control (RBAC)</strong> is enforced across the platform so that personnel may only view, modify, or execute functions and data appropriate to their official designation.</p>
                <p><strong>2.4.</strong> <strong>System Audit Trail &amp; Session Monitoring:</strong> All authentication events, record views, case modifications, conflict-of-interest overrides, document generations, and archival actions are automatically recorded in immutable system audit logs with user identifiers and timestamps.</p>
                <p><strong>2.5.</strong> The system administrator reserves the right to lock, suspend, or revoke accounts immediately due to unauthorized access, policy violations, security risks, or cessation of official duty.</p>
            </section>

            <section class="login-legal-section">
                <h3>3. Submission and Management of Complaint &amp; Case Data</h3>
                <p><strong>3.1.</strong> By entering, uploading, or modifying complaint and case data, authorized personnel confirm that:</p>
                <ul>
                    <li>The information recorded is accurate, objective, and faithful to actual complainant/respondent statements, physical blotters, or official hearing proceedings.</li>
                    <li>Personal data, evidentiary attachments, and pre-signed intake scans are collected and encoded with lawful authority and informed party notice where required.</li>
                </ul>
                <p><strong>3.2.</strong> Authorized barangay administrators are granted the right to store, process, archive, and manage submitted records within the centralized database and cloud storage infrastructure for barangay governance and statutory dispute resolution purposes.</p>
                <p><strong>3.3.</strong> System administrators and supervising barangay officials reserve the right to review, audit, correct, or restrict access to records or submissions that violate procedural standards or legal requirements.</p>
            </section>

            <section class="login-legal-section">
                <h3>4. Use of System Data, Confidentiality &amp; Record Protection</h3>
                <p><strong>4.1.</strong> Data stored in AGAP may only be accessed and processed for official <em>Katarungang Pambarangay</em> administration, dispute resolution, statutory deadline monitoring, and official reporting.</p>
                <p><strong>4.2.</strong> Users must uphold strict confidentiality over sensitive dispute narratives, party identities, contact numbers, mediation discussions, and settlement terms.</p>
                <p><strong>4.3.</strong> Unauthorized copying, downloading, screen-capturing, printing for personal use, or external disclosure/distribution of case records, KP forms, or resident information to unauthorized third parties is strictly prohibited and punishable under applicable laws.</p>
            </section>

            <section class="login-legal-section">
                <h3>5. Privacy and Data Protection (RA 10173 &amp; NPC Standards)</h3>
                <p><strong>5.1.</strong> AGAP adheres to <strong>Republic Act No. 10173 (Data Privacy Act of 2012)</strong>, its Implementing Rules and Regulations (IRR), and <strong>National Privacy Commission (NPC) Circular No. 16-01</strong> (<em>Security of Personal Data in Government Agencies</em>).</p>
                <p><strong>5.2.</strong> Personal and sensitive personal information collected through the platform shall be processed solely for legitimate barangay justice administration, statutory documentation, and legal compliance.</p>
                <p><strong>5.3.</strong> Technical and organizational safeguards—including role-based access control, session regeneration, CSRF protection, parameterized database queries (PDO), SSL/TLS encryption in transit, and managed cloud storage protection—are enforced to prevent unauthorized access, alteration, or disclosure.</p>
                <p><strong>5.4.</strong> Closed, settled, or inactive dockets are retained and archived in accordance with statutory record-keeping schedules and barangay archival policies.</p>
            </section>

            <section class="login-legal-section">
                <h3>6. AI-Assisted Features &amp; Geolocation / Field Dispatch Tools</h3>
                <p><strong>6.1.</strong> <strong>AI Administrative Support Only:</strong> AGAP integrates artificial intelligence capabilities (speech-to-text transcription, intelligent document scanning/extraction, and narrative drafting assistance via Google Gemini API) strictly as clerical and administrative support tools.</p>
                <p><strong>6.2.</strong> <strong>Mandatory Human Review:</strong> AI features do not make legal determinations. Authorized Lupong Tagapamayapa personnel must review, verify, and validate all AI-transcribed text, extracted form fields, and summarized narratives prior to saving or issuing any official record.</p>
                <p><strong>6.3.</strong> <strong>Spatial Mapping &amp; Proof of Service:</strong> Geographic coordinates (Leaflet/OpenStreetMap), Purok boundary tags, and field service photographic proofs uploaded by Summons Servers shall be used exclusively to verify territorial jurisdiction within Barangay Tumana and document official service of summons and notices.</p>
            </section>

            <section class="login-legal-section">
                <h3>7. Intellectual Property &amp; Ownership of Official Records</h3>
                <p><strong>7.1.</strong> All complaint dockets, resident records, hearing minutes, settlement agreements, and generated official KP documents remain the exclusive property of <strong>Barangay Tumana</strong> and the respective stakeholders involved in each case.</p>
                <p><strong>7.2.</strong> Aggregate, strictly anonymized system metrics and non-identifiable statistical outputs may be utilized for official LGU/DILG evaluations, academic research, and system improvement purposes without compromising individual privacy.</p>
            </section>

            <section class="login-legal-section">
                <h3>8. Prohibited Activities</h3>
                <p>Users are strictly prohibited from:</p>
                <ul>
                    <li>Engaging in any activity that compromises system security, database integrity, audit logs, or server performance;</li>
                    <li>Entering fabricated, misleading, or unauthorized complaint entries, attendance logs, or officer's returns;</li>
                    <li>Accessing, searching, or viewing confidential case files of neighbors, relatives, or residents without an official duty-related purpose;</li>
                    <li>Misusing administrative overrides or system functions to manipulate Pangkat assignments, hearing schedules, or case outcomes;</li>
                    <li>Using the platform or its data for personal, political, commercial, or non-official purposes unrelated to barangay operations.</li>
                </ul>
            </section>

            <section class="login-legal-section">
                <h3>9. System Maintenance, Cloud Infrastructure &amp; Availability</h3>
                <p><strong>9.1.</strong> The system undergoes scheduled maintenance, database indexing, and security updates across its application and cloud database infrastructure to ensure reliability and data protection.</p>
                <p><strong>9.2.</strong> Temporary service interruptions may occur during scheduled maintenance windows, cloud hosting updates, or network disruptions. Standard physical KP forms remain available as operational contingency when needed.</p>
            </section>

            <section class="login-legal-section">
                <h3>10. Legal Compliance &amp; Administrative Responsibility</h3>
                <p><strong>10.1.</strong> AGAP is designed to support Barangay Tumana's statutory obligations under <strong>Republic Act No. 7160</strong> and official <em>Katarungang Pambarangay</em> procedures, including the monitoring of the mandatory 15-day mediation and Pangkat conciliation periods.</p>
                <p><strong>10.2.</strong> <strong>No Automated Legal Judgment:</strong> The platform does not replace human legal judgment, mediation facilitation, or official decision-making; it serves solely as an administrative documentation, scheduling, and monitoring instrument.</p>
            </section>

            <section class="login-legal-section">
                <h3>11. Limitation of Liability</h3>
                <p><strong>11.1.</strong> System developers and technical administrators are not liable for substantive mediation outcomes, legal interpretations, or administrative decisions made by barangay personnel based on encoded data or reports.</p>
                <p><strong>11.2.</strong> Final legal and administrative responsibility for case handling, party verification, settlement execution, and issuance of Certificates to File Action (CFA) remains solely with the Punong Barangay, Lupon Secretary, and authorized Lupong Tagapamayapa officials.</p>
            </section>

            <section class="login-legal-section">
                <h3>12. Amendments to Terms and Conditions</h3>
                <p><strong>12.1.</strong> These Terms and Conditions and Data Privacy provisions may be updated as necessary to reflect system enhancements, DILG/NPC regulatory directives, or operational changes in Barangay Tumana.</p>
                <p><strong>12.2.</strong> Continued sign-in and use of the AGAP platform constitutes acknowledgment of and compliance with the current Terms and Conditions.</p>
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
        document.getElementById('openLegalModalBtn')?.focus();
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

<?php include '../../layouts/footer.php'; ?>
