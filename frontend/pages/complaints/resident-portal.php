<?php
require_once __DIR__ . '/../../../backend/services/SecurityBootstrap.php';
SecurityBootstrap::startSession();

require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/middleware/rbac.php';
require_once __DIR__ . '/../../../backend/services/CsrfService.php';
require_once __DIR__ . '/../../../backend/services/SingleSessionService.php';

$db = (new Database())->connect();
SingleSessionService::enforceForPage($db);
$csrfToken = CsrfService::token();
$userId = agapSessionUserId();
$roleName = $userId > 0 ? agapGetRoleName($db) : '';

// Redirect non-Resident authenticated staff back to their workspace
if ($userId > 0 && $roleName !== '' && $roleName !== 'Resident') {
    $staffRedirect = match ($roleName) {
        'Lupon Member' => '../dashboard/lupon-dashboard.php',
        'Summons Server' => '../dashboard/server-dashboard.php',
        'Administrator' => '../dashboard/admin-dashboard.php',
        'Lupon Clerk' => '../dashboard/clerk-dashboard.php',
        default => '../complaints/complaint-list.php',
    };
    header('Location: ' . $staffRedirect);
    exit;
}

$isResident = ($userId > 0 && $roleName === 'Resident');
$errorCode = (string) ($_GET['error'] ?? '');
$lockMins = max(1, min(120, (int) ($_GET['minutes'] ?? 15)));
$loginErrorMessage = match ($errorCode) {
    'account_locked' => "Too many failed sign-in attempts. For security, sign-in is temporarily locked for {$lockMins} minute(s).",
    'already_logged_in' => 'This account is already signed in on another device. Sign out there first or wait 15 minutes of inactivity before signing in here.',
    'session_expired' => 'Your session expired due to inactivity or is no longer active. Please sign in again.',
    'invalid_credentials' => 'Invalid username/email or password, or your account is inactive.',
    default => '',
};
$loginError = $loginErrorMessage !== '';
$loggedOutNotice = ($_GET['logged_out'] ?? '') === '1';
$initialTab = (($_GET['tab'] ?? '') === 'register' && !$loginError) ? 'register' : 'signin';
$residentProfile = [
    'resident_id' => (int) ($_SESSION['resident_id'] ?? 0),
    'username' => (string) ($_SESSION['username'] ?? ''),
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'email' => '',
    'contact_no' => '',
    'purok' => '',
    'address' => '',
    'preferred_language' => 'fil',
    'residency_verified' => 0,
];
$categories = [];

if ($isResident) {
    $uStmt = $db->prepare('SELECT username, first_name, middle_name, last_name, email, contact_no, preferred_language FROM users WHERE user_id = ? LIMIT 1');
    $uStmt->execute([$userId]);
    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
    if ($uRow) {
        $residentProfile['username'] = (string) ($uRow['username'] ?: $residentProfile['username']);
        $residentProfile['first_name'] = (string) ($uRow['first_name'] ?? '');
        $residentProfile['middle_name'] = (string) ($uRow['middle_name'] ?? '');
        $residentProfile['last_name'] = (string) ($uRow['last_name'] ?? '');
        $residentProfile['email'] = (string) ($uRow['email'] ?? '');
        $residentProfile['contact_no'] = (string) ($uRow['contact_no'] ?? '');
        $residentProfile['preferred_language'] = in_array((string) ($uRow['preferred_language'] ?? ''), ['en', 'fil'], true)
            ? (string) $uRow['preferred_language']
            : 'fil';
    }

    $resRow = null;
    if ($residentProfile['resident_id'] > 0) {
        $rStmt = $db->prepare('SELECT resident_id, first_name, middle_name, last_name, email, contact_no, purok, address, COALESCE(residency_verified, 0) AS residency_verified FROM residents WHERE resident_id = ? LIMIT 1');
        $rStmt->execute([$residentProfile['resident_id']]);
        $resRow = $rStmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$resRow && $residentProfile['email'] !== '') {
        $rStmt = $db->prepare('SELECT resident_id, first_name, middle_name, last_name, email, contact_no, purok, address, COALESCE(residency_verified, 0) AS residency_verified FROM residents WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $rStmt->execute([$residentProfile['email']]);
        $resRow = $rStmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$resRow && $residentProfile['first_name'] !== '' && $residentProfile['last_name'] !== '') {
        $rStmt = $db->prepare('SELECT resident_id, first_name, middle_name, last_name, email, contact_no, purok, address, COALESCE(residency_verified, 0) AS residency_verified FROM residents WHERE LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) LIMIT 1');
        $rStmt->execute([$residentProfile['first_name'], $residentProfile['last_name']]);
        $resRow = $rStmt->fetch(PDO::FETCH_ASSOC);
    }
    if ($resRow) {
        $_SESSION['resident_id'] = (int) $resRow['resident_id'];
        $residentProfile['resident_id'] = (int) $resRow['resident_id'];
        $residentProfile['first_name'] = (string) ($resRow['first_name'] ?: $residentProfile['first_name']);
        $residentProfile['middle_name'] = (string) ($resRow['middle_name'] ?: $residentProfile['middle_name']);
        $residentProfile['last_name'] = (string) ($resRow['last_name'] ?: $residentProfile['last_name']);
        $residentProfile['email'] = (string) ($resRow['email'] ?: $residentProfile['email']);
        $residentProfile['contact_no'] = (string) ($resRow['contact_no'] ?: $residentProfile['contact_no']);
        $residentProfile['purok'] = (string) ($resRow['purok'] ?? '');
        $residentProfile['address'] = (string) ($resRow['address'] ?? '');
        $residentProfile['residency_verified'] = (int) ($resRow['residency_verified'] ?? 0);
    }

    $catStmt = $db->query("
        SELECT category_id, category_name
        FROM complaint_categories
        WHERE COALESCE(is_active, 1) = 1
        ORDER BY CASE WHEN category_name LIKE 'Others%' OR category_name LIKE 'Iba pa%' THEN 1 ELSE 0 END ASC, category_name ASC
    ");
    $categories = $catStmt ? $catStmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

$purokOptions = [
    'Purok 1', 'Purok 2', 'Purok 3', 'Purok 4', 'Purok 5', 'Purok 6', 'Purok 7', 'Purok 8',
    'Doña Petra', 'Bagong Farmers', 'Bukang Liwayway', 'Libis Tumana', 'Bagong Purok', 'Palay', 'Mais', 'Singkamas'
];

$displayFullName = trim($residentProfile['first_name'] . ' ' . $residentProfile['last_name']) ?: ($residentProfile['username'] ?: 'Resident');
$displayCompFullName = trim(implode(' ', array_filter([
    $residentProfile['first_name'],
    $residentProfile['middle_name'],
    $residentProfile['last_name'],
]))) ?: $displayFullName;
$displayParts = preg_split('/\s+/', $displayFullName) ?: ['R'];
$displayInitials = strtoupper(
    mb_substr($displayParts[0] ?? 'R', 0, 1, 'UTF-8')
    . (isset($displayParts[1]) ? mb_substr($displayParts[1], 0, 1, 'UTF-8') : '')
);
$displaySubIdentity = $residentProfile['email'] !== '' ? $residentProfile['email'] : ('@' . ($residentProfile['username'] ?: 'resident'));
$isVerifiedResident = !empty($residentProfile['residency_verified']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#102b2d">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
    <title><?php echo $isResident ? 'Resident Dashboard | AGAP' : 'Resident Online Complaint Portal — AGAP Barangay Tumana'; ?></title>
    <?php if ($isResident): ?>
        <link rel="stylesheet" href="../../assets/css/app.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/app.css'); ?>">
        <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
        <link rel="stylesheet" href="../../assets/css/resident-dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/resident-dashboard.css'); ?>">
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
        <script>
            (function () {
                try {
                    var saved = localStorage.getItem('agap_sidebar_collapsed');
                    var isMedium = window.innerWidth > 900 && window.innerWidth <= 1024;
                    if (saved === 'true' || (saved === null && isMedium)) {
                        document.documentElement.classList.add('sidebar-collapsed');
                    } else if (saved === 'false') {
                        document.documentElement.classList.add('sidebar-expanded');
                    }
                    var fontPxMap = { '0': '14px', '1': '16px', '2': '18px', '3': '20px' };
                    var fStep = localStorage.getItem('agap_font_scale_step');
                    if (fStep !== null && fontPxMap[fStep]) {
                        document.documentElement.style.fontSize = fontPxMap[fStep];
                        document.documentElement.setAttribute('data-agap-font-step', fStep);
                    }
                } catch (e) {}
            })();
        </script>
    <?php else: ?>
        <link rel="stylesheet" href="../../assets/css/app.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/app.css'); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="../../assets/css/forms.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/forms.css'); ?>">
    <?php if (!$isResident): ?>
        <link rel="stylesheet" href="../../assets/css/login.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/login.css'); ?>">
    <?php endif; ?>
    <script src="../../assets/js/i18n.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/i18n.js'); ?>"></script>
    <script src="../../assets/js/field-validator.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/field-validator.js'); ?>"></script>
    <script src="../../assets/js/forms.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/forms.js'); ?>"></script>
    <script src="../../assets/js/narrative-checklist.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/narrative-checklist.js'); ?>"></script>
</head>
<body>

<?php if (!$isResident): ?>
<main class="login-screen">
    <div class="login-shell" id="residentAuthShell">
        <?php
        $authWelcome = [
            'title_id' => 'resident-welcome-title',
            'title_html' => 'Good work starts with a <span>clear process.</span>',
            'subtitle' => 'Official Katarungang Pambarangay case management, online complaint filing, Paanyaya schedules, and hearing updates.',
        ];
        include '../../layouts/auth-welcome.php';
        ?>

        <section class="login-form-area" aria-label="Account sign in">
            <div class="login-card" id="residentAuthCard">
                <div class="login-utility-bar">
                    <a class="login-util-link" href="../landing/index.php">&larr; Home</a>
                </div>

                <div id="panelResidentSignIn"<?php echo $initialTab === 'register' ? ' style="display:none;"' : ''; ?>>
                    <?php
                    $authSignIn = [
                        'login_portal' => 'resident_portal',
                        'logged_out' => $loggedOutNotice,
                        'login_error' => $loginError,
                        'login_error_message' => $loginErrorMessage,
                        'register_mode' => 'button',
                    ];
                    include '../../layouts/auth-signin-card.php';
                    ?>
                </div>

                <div id="panelResidentRegister"<?php echo $initialTab === 'register' ? '' : ' style="display:none;"'; ?>>
                    <p class="login-eyebrow">AGAP &middot; BARANGAY TUMANA</p>
                    <h2 id="residentAuthHeading">Create resident account</h2>
                    <p class="login-intro" id="residentAuthIntro">Step 1 of 2 &middot; Start with your email address and name.</p>
                    <div id="residentAuthAlert" class="auth-message error" role="alert" style="display:none;"></div>

                    <form id="residentRegisterForm">
                        <div id="regStep1">
                            <div class="form-group">
                                <label for="regEmail">Email Address <span class="req">*</span></label>
                                <input type="email" id="regEmail" name="email" required maxlength="150" autocomplete="email" placeholder="you@example.com">
                                <span class="hint">Mobile number and Barangay address can be added in your Profile after sign-up for verification.</span>
                            </div>
                            <div class="login-form-row-2">
                                <div class="form-group">
                                    <label for="regFirstName">First Name <span class="req">*</span></label>
                                    <input type="text" id="regFirstName" name="first_name" required maxlength="80" autocomplete="given-name">
                                </div>
                                <div class="form-group">
                                    <label for="regLastName">Last Name <span class="req">*</span></label>
                                    <input type="text" id="regLastName" name="last_name" required maxlength="80" autocomplete="family-name">
                                </div>
                            </div>
                            <button type="button" id="btnRegStepNext">Continue &rarr;</button>
                        </div>

                        <div id="regStep2" style="display:none;">
                            <div class="reg-email-chip">
                                <span id="regEmailSummary"></span>
                                <button type="button" class="reg-chip-edit" id="btnRegEditEmail">Edit</button>
                            </div>
                            <div class="form-group">
                                <label for="regUsername">Username <span class="req">*</span></label>
                                <input type="text" id="regUsername" name="username" required minlength="4" maxlength="50" pattern="[A-Za-z0-9_.-]{4,50}" autocomplete="username" placeholder="e.g. juan.delacruz">
                            </div>
                            <div class="login-form-row-2">
                                <div class="form-group">
                                    <label for="regPassword">Password <span class="req">*</span></label>
                                    <input type="password" id="regPassword" name="password" required minlength="12" maxlength="128" data-validate="password" autocomplete="new-password">
                                </div>
                                <div class="form-group">
                                    <label for="regConfirmPassword">Confirm Password <span class="req">*</span></label>
                                    <input type="password" id="regConfirmPassword" name="confirm_password" required minlength="12" maxlength="128" data-validate="password-confirm" data-match="regPassword" autocomplete="new-password">
                                </div>
                            </div>
                            <span class="hint" style="display:block; margin:-10px 0 12px; font-size:11px; color:var(--auth-muted);">Password must be at least 12 characters with uppercase, lowercase, and a number.</span>
                            <div class="reg-terms-box" id="regTermsBox">
                                <p class="reg-terms-reminder">
                                    Reminder: Please read the <a href="#agapLegalModal" id="openLegalModalBtn" class="reg-terms-link">Terms and Conditions &amp; Data Privacy Policy</a> and check the box below before creating your account.
                                </p>
                                <label class="reg-terms-check-label" for="regAcceptTerms">
                                    <input type="checkbox" id="regAcceptTerms" name="accept_terms" value="1" required>
                                    <span>I have read and agree to the <strong>Terms and Conditions</strong> and <strong>Data Privacy Policy</strong> (RA 10173 &amp; RA 7160). <span class="req">*</span></span>
                                </label>
                            </div>
                            <div class="reg-action-row">
                                <button type="button" class="login-btn-muted" id="btnRegStepBack">&larr; Back</button>
                                <button type="submit" id="btnResidentRegister">Create Account</button>
                            </div>
                        </div>
                    </form>
                    <p class="auth-link"><a href="resident-portal.php" id="tabResidentSignInBtn">&larr; Back to Sign In</a></p>
                </div>
            </div>
        </section>
    </div>
</main>

<?php include __DIR__ . '/../../layouts/auth-legal-modal.php'; ?>

<?php else: ?>
    <!-- Unified AGAP Staff-Style Shell (.dashboard-layout -> #appSidebar + .main-content) -->
    <div class="dashboard-layout">

        <!-- Staff-Matched White & Forest-Emerald Sidebar -->
        <aside class="sidebar" id="appSidebar">
            <div class="sidebar-brand">
                <div class="sidebar-brand-main">
                    <img class="brand-mark" src="../../assets/images/tumana_logo.jpg" alt="Barangay Tumana seal">
                    <div class="sidebar-brand-text"><strong>AGAP</strong><span>Resident Portal</span></div>
                </div>
            </div>

            <nav class="sidebar-nav" aria-label="Resident navigation">
                <details class="nav-group" open data-nav-group="resident-workspace">
                    <summary>Workspace</summary>
                    <div class="nav-group-links">
                        <a href="#home" class="is-active" data-rd-nav="home" title="Home">
                            <span class="nav-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"></rect><rect x="14" y="3" width="7" height="5" rx="1"></rect><rect x="14" y="12" width="7" height="9" rx="1"></rect><rect x="3" y="16" width="7" height="5" rx="1"></rect></svg>
                            </span>
                            <span class="nav-link-label">Dashboard</span>
                        </a>
                        <a href="#submit" data-rd-nav="submit" title="Submit Complaint">
                            <span class="nav-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg>
                            </span>
                            <span class="nav-link-label">Submit Complaint</span>
                        </a>
                        <a href="#complaints" data-rd-nav="complaints" title="My Complaints">
                            <span class="nav-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12.01" y2="18"></line><line x1="12" y1="11" x2="12" y2="15"></line></svg>
                            </span>
                            <span class="nav-link-label">My Complaints</span>
                            <span class="rd-nav-count" id="rdNavComplaintsCount">0</span>
                        </a>
                    </div>
                </details>

                <details class="nav-group" open data-nav-group="resident-account">
                    <summary>Personal</summary>
                    <div class="nav-group-links">
                        <a href="#profile" data-rd-nav="profile" title="Profile &amp; Password">
                            <span class="nav-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            </span>
                            <span class="nav-link-label">Profile &amp; Security</span>
                        </a>
                    </div>
                </details>
            </nav>

            <div class="sidebar-footer">
                
                <div class="sidebar-brand-meta"><span>AGAP</span><small>Barangay Tumana</small></div>
            </div>
        </aside>

        <!-- Main Workspace matching Staff UI -->
        <div class="main-content">

            <!-- Staff-Matched Sticky Top Navbar -->
            <nav class="navbar">
                <button class="nav-menu-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false" data-sidebar-toggle title="Toggle navigation">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="3" y1="12" x2="21" y2="12"></line>
                        <line x1="3" y1="6" x2="21" y2="6"></line>
                        <line x1="3" y1="18" x2="21" y2="18"></line>
                    </svg>
                </button>
                <div class="navbar-context">
                    <span class="navbar-eyebrow">Barangay Tumana</span>
                    <span class="navbar-title">Resident Complaint Portal</span>
                </div>
                <div class="navbar-right">
                    <div class="navbar-user-chip">
                        <span class="navbar-avatar" id="rdHeaderAvatar" aria-hidden="true"><?php echo htmlspecialchars($displayInitials); ?></span>
                        <div class="user-summary">
                            <strong id="rdHeaderName"><?php echo htmlspecialchars($displayFullName); ?></strong>
                            <span id="rdHeaderRoleBadge"><?php echo $isVerifiedResident ? 'Resident Verified' : 'Pending Resident Verification'; ?></span>
                        </div>
                    </div>
                    <form id="globalLogoutForm" method="POST" action="../../../backend/api/auth/logout.php" style="display:inline; margin:0;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="redirect_portal" value="resident">
                        <button type="button" class="logout-btn" id="globalLogoutBtn">Sign out</button>
                    </form>
                </div>
            </nav>

            <!-- Staff-Matched Page Header with Top Quick Actions -->
            <div class="page-header page-header-with-actions" style="padding: 24px 30px 14px;">
                <div>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <h1 style="margin:0;">Dashboard</h1>
                    </div>
                    <p style="margin:5px 0 0; color:#64748b;" id="rdHeaderSubIdentity">
                        Welcome, <strong id="rdSubFullName"><?php echo htmlspecialchars($displayFullName); ?></strong>
                    </p>
                </div>
            </div>

            <section class="dashboard-overview" aria-label="Resident Dashboard Overview">

                <!-- VIEW 1: HOME (KEPT HERO CARD + UNIFIED STAFF STAT CARDS + RECENT PANEL) -->
                <div id="residentViewHome" class="rd-view-pane active">

                    <!-- Kept Welcome Hero Card -->
                    <div class="rd-hero-card">
                        <div class="rd-hero-copy">
                            <span class="rd-hero-badge">Katarungang Pambarangay &bull; Online Service</span>
                            <h2>Welcome to AGAP Resident Portal</h2>
                            <p>Alagang Barangay Tumana is now online &mdash; mas madali, mas mabilis at mas maasahan.</p>
                            <p class="rd-hero-sub">e-Reklamo ngayon para sa mabilis at maayos na aksyon ng Lupon Tagapamayapa.</p>
                            <div class="rd-hero-actions">
                                <button type="button" class="rd-hero-cta" data-rd-goto="submit">File a Complaint</button>
                                <button type="button" class="rd-hero-ghost" data-rd-goto="complaints">Track My Complaints</button>
                            </div>
                        </div>
                        <div class="rd-hero-art" aria-hidden="true">
                            <div class="rd-hero-seal">
                                <img src="../../assets/images/tumana_logo.jpg" alt="">
                            </div>
                        </div>
                    </div>

                    <!-- Staff-Unified 5-Card KPI Grid (Mobile: unified 2-column icon-tile card) -->
                    <div class="rd-stats-grid" aria-label="Complaint summary counts">
                        <button type="button" class="stat-card" data-kpi-filter="new">
                            <span class="rd-kpi-icon rd-kpi-icon-amber" aria-hidden="true">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            </span>
                            <div class="rd-kpi-text">
                                <p>New / Filed</p>
                                <h2 id="kpiCountNew">0</h2>
                                <span>For review &amp; intake</span>
                            </div>
                        </button>
                        <button type="button" class="stat-card" data-kpi-filter="ongoing">
                            <span class="rd-kpi-icon rd-kpi-icon-green" aria-hidden="true">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 4 20 12 6 20 6 4"/></svg>
                            </span>
                            <div class="rd-kpi-text">
                                <p>Approved &amp; Ongoing</p>
                                <h2 id="kpiCountOngoing">0</h2>
                                <span>Approved, Paanyaya &amp; mediation</span>
                            </div>
                        </button>
                        <button type="button" class="stat-card" data-kpi-filter="conciliation">
                            <span class="rd-kpi-icon rd-kpi-icon-blue" aria-hidden="true">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>
                            </span>
                            <div class="rd-kpi-text">
                                <p>Conciliation</p>
                                <h2 id="kpiCountConciliation">0</h2>
                                <span>Pangkat &amp; arbitration sessions</span>
                            </div>
                        </button>
                        <button type="button" class="stat-card" data-kpi-filter="settled">
                            <span class="rd-kpi-icon rd-kpi-icon-purple" aria-hidden="true">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
                            </span>
                            <div class="rd-kpi-text">
                                <p>Settled / CFA</p>
                                <h2 id="kpiCountSettled">0</h2>
                                <span>Amicable, arbitration &amp; CFA</span>
                            </div>
                        </button>
                        <button type="button" class="stat-card" data-kpi-filter="cancelled">
                            <span class="rd-kpi-icon rd-kpi-icon-slate" aria-hidden="true">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg>
                            </span>
                            <div class="rd-kpi-text">
                                <p>Drafts &amp; Closed</p>
                                <h2 id="kpiCountCancelled">0</h2>
                                <span>Drafts, repudiated &amp; dismissed</span>
                            </div>
                        </button>
                    </div>

                    <!-- Bottom Two-Column Split: Recent Complaints (Left) + Barangay News Carousel (Right) -->
                    <div class="rd-home-bottom-grid">
                        <!-- Staff-Unified Recent Complaints Panel -->
                        <div class="dashboard-panel">
                            <div class="panel-heading">
                                <div>
                                    <h2>Recent Complaints Activity</h2>
                                    <p>Latest updates across your online filings and walk-in barangay records.</p>
                                </div>
                                <div class="panel-heading-actions">
                                    <span class="panel-count-badge" id="rdRecentCountBadge">0 total</span>
                                    <a href="#complaints" data-rd-goto="complaints">View All &rarr;</a>
                                </div>
                            </div>
                            <div id="rdHomeRecentList" class="dashboard-list">
                                <div style="padding:14px 0; color:#71717a; font-size:0.86rem;">Loading recent complaints...</div>
                            </div>
                        </div>

                        <!-- Barangay Tumana News & Advisories Section -->
                        <div class="dashboard-panel rd-news-panel" id="rdNewsSection" aria-label="Barangay Tumana News and Advisories">
                            <div class="panel-heading">
                                <div>
                                    <h2>Barangay News &amp; Advisories</h2>
                                    <p>Official updates, Lupon schedules, and community advisories from Barangay Tumana.</p>
                                </div>
                                <div class="panel-heading-actions rd-news-header-actions">
                                    <div class="rd-news-dots" id="rdNewsDots" role="tablist" aria-label="Announcement navigation"></div>
                                    <button type="button" class="rd-news-nav-btn" id="btnRdNewsPrev" aria-label="Previous announcement">&lsaquo;</button>
                                    <button type="button" class="rd-news-nav-btn" id="btnRdNewsNext" aria-label="Next announcement">&rsaquo;</button>
                                </div>
                            </div>
                            <div id="rdHomeNewsList" class="rd-news-grid">
                                <div class="rd-news-empty">Loading Barangay Tumana news...</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- VIEW 2: SUBMIT COMPLAINT (4-Step Stepper Wizard inspired by Zanproperty UI) -->
                <div id="residentViewSubmit" class="rd-view-pane">
                    <div class="rd-wizard-top">
                        <button type="button" class="rd-wizard-back-btn" data-rd-goto="home" title="Back to Dashboard" aria-label="Back to Dashboard">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                        </button>
                        <div>
                            <h2 id="portalWizardHeading">File a Complaint</h2>
                            <p id="portalWizardSubheading">Complete the steps below to submit a formal complaint to Barangay Tumana.</p>
                        </div>
                    </div>

                    <div id="portalEditingDraftBanner" class="alert" style="display:none; background:#fffbeb; border:1px solid #fcd34d; color:#78350f; margin-bottom:14px; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                        <div>
                            <strong>Editing Saved Draft (<span id="portalEditingDraftRef"></span>)</strong>
                            <span style="display:block; font-size:0.8rem; color:#92400e; margin-top:2px;">Update any step below, re-save as draft, or proceed to Step 4 to submit your complaint.</span>
                        </div>
                        <button type="button" class="btn-secondary" id="btnPortalCancelDraftEdit" style="background:#fff; border-color:#f59e0b; color:#92400e; padding:6px 12px; font-size:0.8rem; font-weight:700;">Cancel Edit &amp; Start New</button>
                    </div>

                    <div id="portalResultBanner" class="alert" style="display:none;"></div>

                    <form id="residentComplaintForm" enctype="multipart/form-data">
                        <input type="hidden" name="complaint_id" id="portalComplaintId" value="">
                        <input type="hidden" name="bypass_office_hours" value="1">
                        <input type="hidden" name="is_draft" id="portalIsDraft" value="0">
                        <input type="hidden" name="incident_barangay" value="Tumana">
                        <input type="hidden" name="incident_city" value="Marikina City">
                        <input type="hidden" name="map_location_state" id="portalMapState" value="none">
                        <input type="hidden" name="location_latitude" id="portalMapLat" value="">
                        <input type="hidden" name="location_longitude" id="portalMapLng" value="">
                        <input type="hidden" id="portalCompResidentIdHidden" name="complainant_resident_id" value="<?php echo $residentProfile['resident_id'] > 0 ? (int) $residentProfile['resident_id'] : ''; ?>">
                        <input type="hidden" id="portalCompFirst" name="complainant_first_name" value="<?php echo htmlspecialchars($residentProfile['first_name']); ?>">
                        <input type="hidden" id="portalCompMiddle" name="complainant_middle_name" value="<?php echo htmlspecialchars($residentProfile['middle_name']); ?>">
                        <input type="hidden" id="portalCompLast" name="complainant_last_name" value="<?php echo htmlspecialchars($residentProfile['last_name']); ?>">
                        <input type="hidden" id="portalCompEmailHidden" name="complainant_email" value="<?php echo htmlspecialchars($residentProfile['email']); ?>">
                        <input type="hidden" id="portalRespFirst" name="respondent_first_name" value="">
                        <input type="hidden" id="portalRespMiddle" name="respondent_middle_name" value="">
                        <input type="hidden" id="portalRespLast" name="respondent_last_name" value="">

                        <div class="rd-wizard-layout">
                            <!-- Left Stepper Sidebar -->
                            <aside class="rd-wizard-sidebar" aria-label="Complaint filing steps">
                                <div class="rd-wizard-progress-head">
                                    <strong>Complaint Progress</strong>
                                    <span id="rdWizardStepText">Step 1 of 4</span>
                                </div>
                                <div class="rd-wizard-progress-track" aria-hidden="true">
                                    <div class="rd-wizard-progress-fill" id="rdWizardProgressFill" style="width: 25%;"></div>
                                </div>

                                <div class="rd-wizard-steps" id="rdWizardSteps">
                                    <button type="button" class="rd-wizard-step is-active" data-wizard-step-btn="1">
                                        <span class="rd-wizard-step-num" data-step-badge="1">1</span>
                                        <span class="rd-wizard-step-text">
                                            <strong>Complainant Details</strong>
                                            <small>Your residency &amp; contact info</small>
                                        </span>
                                    </button>
                                    <button type="button" class="rd-wizard-step" data-wizard-step-btn="2">
                                        <span class="rd-wizard-step-num" data-step-badge="2">2</span>
                                        <span class="rd-wizard-step-text">
                                            <strong>Respondent Details</strong>
                                            <small>Full name &amp; summons address</small>
                                        </span>
                                    </button>
                                    <button type="button" class="rd-wizard-step" data-wizard-step-btn="3">
                                        <span class="rd-wizard-step-num" data-step-badge="3">3</span>
                                        <span class="rd-wizard-step-text">
                                            <strong>Complaint Narrative</strong>
                                            <small>When, where, what &amp; why</small>
                                        </span>
                                    </button>
                                    <button type="button" class="rd-wizard-step" data-wizard-step-btn="4">
                                        <span class="rd-wizard-step-num" data-step-badge="4">4</span>
                                        <span class="rd-wizard-step-text">
                                            <strong>Review &amp; Submit</strong>
                                            <small>Confirm details before filing</small>
                                        </span>
                                    </button>
                                </div>

                                <div class="rd-wizard-assist">
                                    <div class="rd-wizard-assist-head">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                                        <span>Need Assistance?</span>
                                    </div>
                                    <p>Online complaints go through LISO verification and mandatory Paanyaya at Barangay Tumana Hall.</p>
                                </div>
                            </aside>

                            <!-- Right Focused Step Card -->
                            <div class="rd-wizard-card">
                                <div class="rd-wizard-card-body">

                                    <!-- STEP 1: Complainant Details -->
                                    <div class="rd-wizard-pane active" data-wizard-pane="1">
                                        <div class="rd-wizard-pane-head" style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap;">
                                            <div>
                                                <h3>1. Complainant Details</h3>
                                                <p>Confirm your auto-filled contact and address details before continuing. <?php if (trim($residentProfile['contact_no']) === '' || trim($residentProfile['address']) === ''): ?>Save them in <a href="#profile" data-rd-goto="profile" style="color:#1b6553; font-weight:700; text-decoration:underline;">My Profile</a> for Admin/Secretary residency verification.<?php endif; ?></p>
                                            </div>
                                            <span class="badge <?php echo $isVerifiedResident ? 'badge-green' : 'badge-amber'; ?>">
                                                <?php echo $isVerifiedResident ? 'Verified Tumana Resident' : 'Auto-Filled'; ?>
                                            </span>
                                        </div>

                                        <div class="form-group">
                                            <label for="portalCompFullName">Full Name <span class="req">*</span></label>
                                            <input type="text" id="portalCompFullName" readonly value="<?php echo htmlspecialchars($displayCompFullName); ?>">
                                        </div>

                                        <div class="agap-field-row-2">
                                            <div class="form-group">
                                                <label for="portalCompContact">Contact Number <span class="req">*</span></label>
                                                <input type="tel" id="portalCompContact" name="complainant_contact_no" required maxlength="20" data-validate="phone" placeholder="09XXXXXXXXX" value="<?php echo htmlspecialchars($residentProfile['contact_no']); ?>">
                                            </div>
                                            <div class="form-group">
                                                <label for="portalCompPurok">Purok / Area in Tumana</label>
                                                <select id="portalCompPurok" name="complainant_purok">
                                                    <option value="">Select Purok...</option>
                                                    <?php foreach ($purokOptions as $p): ?>
                                                        <option value="<?php echo htmlspecialchars($p); ?>" <?php echo ($residentProfile['purok'] === $p) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                                                    <?php endforeach; ?>
                                                    <option value="Non-Resident" <?php echo ($residentProfile['purok'] === 'Non-Resident') ? 'selected' : ''; ?>>Non-Resident / Outside Tumana</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="form-group" style="margin-bottom:0;">
                                            <label for="portalCompAddress">Your Complete Address <span class="req">*</span></label>
                                            <input type="text" id="portalCompAddress" name="complainant_address" required maxlength="255" value="<?php echo htmlspecialchars($residentProfile['address']); ?>" placeholder="House #, Street, Barangay Tumana">
                                        </div>
                                    </div>

                                    <!-- STEP 2: Respondent Details -->
                                    <div class="rd-wizard-pane" data-wizard-pane="2">
                                        <div class="rd-wizard-pane-head">
                                            <h3>2. Respondent Details</h3>
                                            <p>Provide the full name and actual address of the person being complained against (legally required for barangay summons).</p>
                                        </div>

                                        <div class="form-group">
                                            <label for="portalRespFullName">Respondent Full Name (First &amp; Last Name) <span class="req">*</span></label>
                                            <input type="text" id="portalRespFullName" name="respondent_name" required maxlength="150" data-validate="name" placeholder="e.g. Maria Santos">
                                            <span class="hint">Include both first name and last name so summons can be addressed properly.</span>
                                        </div>

                                        <div class="form-group">
                                            <label for="portalRespAddress">Actual Address (Legally Required for Summons) <span class="req">*</span></label>
                                            <input type="text" id="portalRespAddress" name="respondent_address" required maxlength="255" placeholder="House #, Street, Sitio / Barangay">
                                        </div>

                                        <div class="agap-field-row-2">
                                            <div class="form-group" style="margin-bottom:0;">
                                                <label for="portalRespPurok">Respondent Purok / Area</label>
                                                <select id="portalRespPurok" name="respondent_purok">
                                                    <option value="">Select Purok (Optional)...</option>
                                                    <?php foreach ($purokOptions as $p): ?>
                                                        <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
                                                    <?php endforeach; ?>
                                                    <option value="Non-Resident">Non-Resident / Outside Tumana</option>
                                                </select>
                                            </div>
                                            <div class="form-group" style="margin-bottom:0;">
                                                <label for="portalRespContact">Mobile Number (Optional)</label>
                                                <input type="tel" id="portalRespContact" name="respondent_contact_no" maxlength="20" data-validate="phone" placeholder="09XXXXXXXXX">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- STEP 3: Complaint Narrative -->
                                    <div class="rd-wizard-pane" data-wizard-pane="3">
                                        <div class="rd-wizard-pane-head">
                                            <h3>3. Complaint Narrative</h3>
                                            <p>Tell your complete complaint story below (type or speak in Tagalog / English) so Barangay Tumana has all required facts.</p>
                                        </div>

                                        <input type="hidden" id="portalResolution" name="requested_resolution" value="">
                                        <input type="hidden" id="portalAutoTitle" name="complaint_title" value="">
                                        <input type="hidden" id="portalCatId" name="category_id" value="">
                                        <input type="hidden" id="portalOtherCatSpec" name="other_category_specification" value="">
                                        <input type="hidden" id="portalCaseType" name="case_type" value="Civil">
                                        <input type="hidden" id="portalAiUsed" name="ai_classification_used" value="0">
                                        <input type="hidden" id="portalAiVerifiedHidden" name="ai_classification_verified" value="0">
                                        <input type="hidden" id="portalAiSuggestedCatId" name="ai_suggested_category_id" value="">
                                        <input type="hidden" id="portalAiSuggestedTitle" name="ai_suggested_title" value="">
                                        <input type="hidden" id="portalAiSuggestionPayload" name="ai_suggestion_payload" value="">

                                        <div class="agap-field-row-2">
                                            <div class="form-group">
                                                <label for="portalIncidentDatetime">When did it happen? (Date &amp; Time) <span class="req">*</span></label>
                                                <input type="datetime-local" id="portalIncidentDatetime" name="incident_datetime" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="portalIncidentPurok">Incident Purok / Area</label>
                                                <select id="portalIncidentPurok" name="incident_purok">
                                                    <option value="">Select Purok (Optional)...</option>
                                                    <?php foreach ($purokOptions as $p): ?>
                                                        <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label for="portalIncidentStreet">Where did it happen? <span class="req">*</span></label>
                                            <input type="text" id="portalIncidentStreet" name="incident_street" required maxlength="255" placeholder="Street, alley, or landmark where the incident happened">
                                            <div class="rd-preset-chips">
                                                <button type="button" class="rd-chip-btn" id="btnPortalUseMyAddr">At My Address</button>
                                                <button type="button" class="rd-chip-btn" id="btnPortalUseRespAddr">At Respondent's</button>
                                                <button type="button" class="rd-chip-btn" id="btnPortalUseGps">Pin in Map</button>
                                                <button type="button" class="rd-chip-btn" id="btnPortalClearMapChip" style="display:none;">Clear Pin</button>
                                            </div>
                                            <span id="portalLocationHint" class="hint" style="margin-top:4px;"></span>
                                        </div>

                                        <div class="rd-wizard-subhead">
                                            <span>Your Complaint Story</span>
                                            <small style="font-weight:600; color:#1b6553; font-size:0.75rem;">Speech-to-Text &amp; AI Ready</small>
                                        </div>

                                        <div class="rd-voice-ai-box" id="rdVoiceAiBox">
                                            <div class="rd-voice-ai-head">
                                                <div class="rd-voice-ai-title">
                                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                                                    <div>
                                                        <strong>Speech-to-Text &amp; AI Suggestion (Tagalog / English)</strong>
                                                        <span>Speak or type your story below. Tap AI Suggestion if you want a read-only preview of the suggested category, or submit directly for Barangay Lupon review.</span>
                                                    </div>
                                                </div>
                                                <div class="rd-voice-lang-group" role="group" aria-label="Speech language">
                                                    <button type="button" class="rd-voice-lang-btn is-active" data-story-lang="tl">Tagalog</button>
                                                    <button type="button" class="rd-voice-lang-btn" data-story-lang="en">English</button>
                                                </div>
                                            </div>

                                            <?php
                                            $narrativeGuideId = 'rdStoryGuide';
                                            $narrativeTargetSelector = '#portalNarrative';
                                            include __DIR__ . '/../../layouts/narrative-accoc-guide.php';
                                            ?>

                                            <div class="form-group" style="margin-bottom:10px;">
                                                <label for="portalNarrative" style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
                                                    <span>Your Complaint Narrative <span class="req">*</span></span>
                                                    <span id="portalVoiceLiveTag" class="rd-voice-live-tag" style="display:none;">Nakikinig...</span>
                                                </label>
                                                <textarea id="portalNarrative" name="narrative" rows="6" required maxlength="15000" placeholder="Pindutin ang 'Magsalita' o i-type dito ang buong kuwento sa Tagalog o English: ano ang ginawa sa iyo, bakit nangyari, sino ang nakakita o epekto, at anong resolusyon ang hiling mo..."></textarea>
                                            </div>

                                            <div class="rd-voice-actions">
                                                <button type="button" class="rd-voice-mic-btn" id="btnPortalVoiceDictate">
                                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/></svg>
                                                    <span id="portalVoiceBtnLabel">Magsalita (Tagalog)</span>
                                                </button>
                                                <button type="button" class="rd-voice-ai-btn" id="btnPortalIdentifyComplaint">
                                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 4.5L18.5 9.4l-4.6 1.9L12 15.8l-1.9-4.5L5.5 9.4l4.6-1.9z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z"/></svg>
                                                    <span>AI Suggestion</span>
                                                </button>
                                            </div>

                                            <label class="rd-voice-consent">
                                                <input type="checkbox" id="portalStoryGeminiConsent">
                                                <span>Send story text / voice note to Google Gemini AI for smarter Tagalog/English identification (leave unchecked for instant built-in keyword matching).</span>
                                            </label>

                                            <div id="portalStoryAiStatus" class="rd-voice-status" style="display:none;"></div>

                                            <div id="portalIdentifySuggestionBox" class="rd-ai-suggest-card" style="display:none;" aria-live="polite">
                                                <div class="rd-ai-suggest-head">
                                                    <span class="rd-ai-pill-badge">AI Suggestion &bull; Read-Only</span>
                                                    <span id="portalAiSourceTag" class="rd-ai-source-tag"></span>
                                                </div>
                                                <div id="portalAiReadonlySummary" class="rd-ai-readonly-box"></div>
                                                <p id="portalAiReasonText" class="rd-ai-reason"></p>
                                                <p id="portalAiConfidenceExplain" class="rd-ai-reason" style="margin-top:6px; color:#334155; display:none;"></p>
                                                <div id="portalAiJurisdictionText" class="rd-ai-advisory" style="display:none;"></div>
                                                <p class="rd-ai-readonly-note"><strong>What is AI Suggestion?</strong> It is an automatic category guide based on the words in your story so Barangay officials can route your complaint faster. You do not need to change or approve it &mdash; Barangay Lupon will confirm the final classification after reviewing your narrative.</p>
                                            </div>
                                        </div>

                                        <details class="rd-optional-details" id="portalOptionalDetailsBox">
                                            <summary>Optional: Photos / Documents, Claim Amount &amp; Extra Notes</summary>
                                            <div class="rd-optional-body">
                                                <div id="portalExistingAttachmentsNotice" style="display:none; margin-bottom:12px; padding:10px 12px; border-radius:8px; background:#f8fafc; border:1px solid #e2e8f0; font-size:0.8rem; color:#334155;"></div>
                                                <div class="agap-field-row-2">
                                                    <div class="form-group">
                                                        <label for="portalEvidence">Supporting Photos / Docs (Max 25MB/file)</label>
                                                        <input type="file" id="portalEvidence" name="evidence[]" multiple data-max-mb="25" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx" style="padding:8px 10px; border:1px solid #cbd5e1; border-radius:10px; min-height:42px; background:#fff;">
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="portalFineMax">Estimated Claim / Damages (&#8369;)</label>
                                                        <input type="number" id="portalFineMax" name="offense_fine_max" step="0.01" min="0" max="999999999.99" placeholder="0.00 (Optional)">
                                                    </div>
                                                </div>
                                                <div class="form-group" style="margin-bottom:0;">
                                                    <label for="portalAdditionalDetails">Additional Notes or Specific Landmarks (Optional)</label>
                                                    <textarea id="portalAdditionalDetails" name="additional_details" rows="2" maxlength="5000" placeholder="Any extra details, landmarks, or prior blotter reference you want officials to know..."></textarea>
                                                </div>
                                            </div>
                                        </details>
                                    </div>

                                    <!-- STEP 4: Review & Submit -->
                                    <div class="rd-wizard-pane" data-wizard-pane="4">
                                        <div class="rd-wizard-pane-head">
                                            <h3>4. Review &amp; Submit</h3>
                                            <p>Double-check your details below before sending your complaint to Barangay Tumana.</p>
                                        </div>

                                        <div id="rdWizardReviewContent" class="rd-review-stack"></div>
                                        <div style="margin-top:14px; padding:12px 15px; border-radius:10px; background:#eef6f2; border:1px solid #d5e4dc; font-size:0.83rem; color:#102b2d; line-height:1.45;">
                                            <strong>Note:</strong> Once submitted, your complaint status will be set to <strong>For Review</strong> while Barangay Tumana officials verify your details before scheduling Paanyaya.
                                        </div>
                                    </div>
                                </div>

                                <!-- Integrated Card Bottom Navigation Footer -->
                                <div class="rd-wizard-footer">
                                    <div class="rd-wizard-footer-left">
                                        <button type="button" class="rd-wizard-btn-ghost" id="btnWizardPrev" style="display:none;">&larr; Previous</button>
                                        <button type="submit" formnovalidate class="rd-wizard-btn-ghost" id="btnPortalSaveDraft">Save as Draft</button>
                                    </div>
                                    <div class="rd-wizard-footer-right">
                                        <button type="button" class="rd-wizard-btn-primary" id="btnWizardNext">Next Step &rarr;</button>
                                        <button type="submit" formnovalidate class="rd-wizard-btn-primary" id="btnPortalSubmitComplaint" style="display:none;">Submit Complaint &#10003;</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- VIEW 3: MY COMPLAINTS -->
                <div id="residentViewList" class="rd-view-pane">
                    <div class="dashboard-panel" style="padding: 24px;">
                        <div class="panel-heading">
                            <div>
                                <h2>My Submitted Complaints</h2>
                                <p>Present your Reference ID if you visit Barangay Tumana Hall in person so staff can link your walk-in follow-up without re-entering data.</p>
                            </div>
                            <div class="panel-heading-actions" style="flex-wrap:wrap;">
                                <button type="button" class="btn-create" data-rd-goto="submit" data-rd-new-complaint="1">+ New Complaint</button>
                            </div>
                        </div>

                        <div class="rd-filter-toolbar">
                            <div class="period-tabs-wrapper">
                                <div class="period-tabs" role="tablist" aria-label="Filter complaints by status">
                                    <button type="button" class="period-tab active" data-rd-filter="all">All</button>
                                    <button type="button" class="period-tab" data-rd-filter="new">New / Filed</button>
                                    <button type="button" class="period-tab" data-rd-filter="ongoing">Ongoing</button>
                                    <button type="button" class="period-tab" data-rd-filter="conciliation">Conciliation</button>
                                    <button type="button" class="period-tab" data-rd-filter="settled">Settled</button>
                                    <button type="button" class="period-tab" data-rd-filter="cancelled">Drafts &amp; Closed</button>
                                </div>
                            </div>
                            <input type="search" id="rdComplaintsSearch" class="rd-search-input" placeholder="Search Reference ID, subject, or respondent...">
                        </div>

                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Reference ID</th>
                                        <th>Subject &amp; Respondent</th>
                                        <th>Channel</th>
                                        <th>Workflow Progress</th>
                                        <th>Filed</th>
                                        <th>Forms</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="myComplaintsTbody">
                                    <tr><td colspan="7" class="empty-state">Loading your submissions...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- VIEW 4: PROFILE & SECURITY -->
                <div id="residentViewProfile" class="rd-view-pane">
                    <div class="rd-profile-grid">
                        <section class="agap-form-card" aria-label="My Resident Profile">
                            <div class="agap-form-card-head">
                                <div class="agap-form-card-title">
                                    <span class="agap-form-card-icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    </span>
                                    <div>
                                        <h2>My Profile</h2>
                                    </div>
                                </div>
                                <span style="background:#eef6f2; color:#1b6553; border:1px solid #cde2d6; font-size:0.75rem; font-weight:700; padding:3px 10px; border-radius:9999px;" id="rdVerificationPill">
                                    <?php echo $isVerifiedResident ? 'Verified' : 'Pending Verification'; ?>
                                </span>
                            </div>

                            <div id="residentProfileAlert" class="alert" style="display:none;"></div>

                            <div class="alert <?php echo $isVerifiedResident ? 'alert-success' : 'alert-info'; ?>" id="rdVerificationGuide" style="display:block; margin-bottom:14px; font-size:0.82rem; line-height:1.45;">
                                <?php if ($isVerifiedResident): ?>
                                    <strong>Verified Resident Account:</strong> Your Email Address, Mobile Number, and Barangay Tumana Address have been verified by Barangay officials.
                                <?php else: ?>
                                    <strong>Verification Status: Pending Review</strong> &mdash; Complete your <strong>Email Address</strong>, <strong>Mobile Number</strong>, <strong>Purok / Area in Tumana</strong>, and <strong>Complete Street Address</strong> below. Barangay Administrator or Secretary will review these details to verify your account.
                                <?php endif; ?>
                            </div>

                            <form id="residentProfileForm">
                                <div class="agap-field-row-2">
                                    <div class="form-group">
                                        <label for="profUsername">Username</label>
                                        <input type="text" id="profUsername" disabled value="<?php echo htmlspecialchars($residentProfile['username']); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="profLang">Preferred Language</label>
                                        <select id="profLang" name="preferred_language">
                                            <option value="fil" <?php echo ($residentProfile['preferred_language'] !== 'en') ? 'selected' : ''; ?>>Filipino / Tagalog</option>
                                            <option value="en" <?php echo ($residentProfile['preferred_language'] === 'en') ? 'selected' : ''; ?>>English</option>
                                        </select>
                                        <div class="agap-segmented-group" data-seg-target="profLang" role="group" aria-label="Preferred Language">
                                            <button type="button" class="agap-seg-btn <?php echo ($residentProfile['preferred_language'] !== 'en') ? 'is-active' : ''; ?>" data-seg-value="fil">Tagalog</button>
                                            <button type="button" class="agap-seg-btn <?php echo ($residentProfile['preferred_language'] === 'en') ? 'is-active' : ''; ?>" data-seg-value="en">English</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="agap-field-row-3">
                                    <div class="form-group">
                                        <label for="profFirstName">First Name <span class="req">*</span></label>
                                        <input type="text" id="profFirstName" name="first_name" required maxlength="100" data-validate="name" value="<?php echo htmlspecialchars($residentProfile['first_name']); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="profMiddleName">Middle Name</label>
                                        <input type="text" id="profMiddleName" name="middle_name" maxlength="100" data-validate="name" value="<?php echo htmlspecialchars($residentProfile['middle_name']); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="profLastName">Last Name <span class="req">*</span></label>
                                        <input type="text" id="profLastName" name="last_name" required maxlength="100" data-validate="name" value="<?php echo htmlspecialchars($residentProfile['last_name']); ?>">
                                    </div>
                                </div>
                                <div class="agap-field-row-2">
                                    <div class="form-group">
                                        <label for="profEmail">Email Address <span class="req">*</span></label>
                                        <input type="email" id="profEmail" name="email" required maxlength="150" data-validate="email" value="<?php echo htmlspecialchars($residentProfile['email']); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="profContactNo">Mobile Number <small style="font-weight:500; color:#64748b;">(For Verification)</small></label>
                                        <input type="tel" id="profContactNo" name="contact_no" maxlength="20" data-validate="phone" placeholder="09XXXXXXXXX or +639XXXXXXXXX" value="<?php echo htmlspecialchars($residentProfile['contact_no']); ?>">
                                    </div>
                                </div>
                                <div class="agap-field-row-2">
                                    <div class="form-group">
                                        <label for="profPurok">Purok / Area in Tumana</label>
                                        <select id="profPurok" name="purok">
                                            <option value="">Select Purok...</option>
                                            <?php foreach ($purokOptions as $p): ?>
                                                <option value="<?php echo htmlspecialchars($p); ?>" <?php echo ($residentProfile['purok'] === $p) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                                            <?php endforeach; ?>
                                            <option value="Non-Resident" <?php echo ($residentProfile['purok'] === 'Non-Resident') ? 'selected' : ''; ?>>Non-Resident / Outside Tumana</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="profAddress">Complete Street Address</label>
                                        <input type="text" id="profAddress" name="address" maxlength="255" value="<?php echo htmlspecialchars($residentProfile['address']); ?>" placeholder="House #, Street, Barangay Tumana">
                                    </div>
                                </div>
                                <div class="agap-form-actions" style="margin-top:8px;">
                                    <button type="submit" class="agap-btn-submit" id="btnSaveResidentProfile">Save Profile Changes</button>
                                </div>
                            </form>
                        </section>

                        <section class="agap-form-card" aria-label="Change Password">
                            <div class="agap-form-card-head">
                                <div class="agap-form-card-title">
                                    <span class="agap-form-card-icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    </span>
                                    <div>
                                        <h2>Change Password</h2>
                                        <p style="margin:2px 0 0; font-size:0.78rem; color:#64748b;">Keep your resident portal account protected with a strong password.</p>
                                    </div>
                                </div>
                            </div>

                            <div id="residentPasswordAlert" class="alert" style="display:none;"></div>

                            <form id="residentPasswordForm">
                                <div class="form-group">
                                    <label for="profCurrentPassword">Current Password <span class="req">*</span></label>
                                    <input type="password" id="profCurrentPassword" name="current_password" required autocomplete="current-password">
                                </div>
                                <div class="form-group">
                                    <label for="profNewPassword">New Password <span class="req">*</span></label>
                                    <input type="password" id="profNewPassword" name="new_password" required minlength="12" maxlength="128" data-validate="password" autocomplete="new-password">
                                    <span class="hint">At least 12 characters with uppercase, lowercase, and a number.</span>
                                </div>
                                <div class="form-group">
                                    <label for="profConfirmPassword">Confirm New Password <span class="req">*</span></label>
                                    <input type="password" id="profConfirmPassword" name="confirm_password" required minlength="12" maxlength="128" data-validate="password-confirm" data-match="profNewPassword" autocomplete="new-password">
                                </div>
                                <div class="agap-form-actions" style="margin-top:8px;">
                                    <button type="submit" class="agap-btn-submit" id="btnSaveResidentPassword" style="width:100%;">Update Password</button>
                                </div>
                            </form>
                        </section>
                    </div>
                </div>

            </section>
        </div>
    </div>

    <!-- Interactive Map Modal for Pinning Incident Location -->
    <div id="rdIncidentMapModal" class="rd-map-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="rdIncidentMapTitle" aria-hidden="true">
        <div class="rd-map-modal">
            <div class="rd-map-modal-head">
                <div>
                    <h3 id="rdIncidentMapTitle">Pin Incident Location on Map</h3>
                    <p>Tap anywhere on the map or drag the pin to mark where the incident happened in Marikina / Barangay Tumana.</p>
                </div>
                <button type="button" class="rd-map-close-btn" id="btnPortalMapClose" aria-label="Close map modal">&times;</button>
            </div>
            <div class="rd-map-modal-body">
                <div class="rd-map-toolbar">
                    <div class="rd-map-toolbar-actions">
                        <button type="button" class="rd-chip-btn" id="btnPortalMapUseGps">Use My GPS</button>
                        <button type="button" class="rd-chip-btn" id="btnPortalMapRecenter">Recenter Tumana</button>
                        <button type="button" class="rd-chip-btn" id="btnPortalMapClear">Clear Pin</button>
                    </div>
                </div>
                <div id="rdIncidentLeafletMap" class="rd-map-canvas" aria-label="Interactive incident location map"></div>
                <div class="rd-map-info-bar">
                    <div id="rdMapStatusText" class="rd-map-status">Tap on the map to place a pin, or click Use My GPS.</div>
                    <div id="rdMapPreviewMeta" class="rd-map-preview-meta">No location pinned yet.</div>
                </div>
            </div>
            <div class="rd-map-modal-foot">
                <button type="button" class="rd-wizard-btn-ghost" id="btnPortalMapCancel">Cancel</button>
                <button type="button" class="rd-wizard-btn-primary" id="btnPortalMapConfirm">Use This Location &#10003;</button>
            </div>
        </div>
    </div>

    <!-- Resident Complaint / Case Details Modal -->
    <div id="rdComplaintDetailsModal" class="rd-map-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="rdDetailModalTitle" aria-hidden="true">
        <div class="rd-detail-modal">
            <div class="rd-map-modal-head">
                <div>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <h3 id="rdDetailModalTitle">Complaint Details</h3>
                        <span id="rdDetailStatusBadge" class="badge badge-amber" style="display:none;"></span>
                    </div>
                    <p id="rdDetailModalSubtitle">Loading case details...</p>
                </div>
                <button type="button" class="rd-map-close-btn" id="btnPortalDetailClose" aria-label="Close details modal">&times;</button>
            </div>
            <div id="rdDetailModalBody" class="rd-detail-modal-body">
                <div class="empty-state">Loading complaint details...</div>
            </div>
            <div class="rd-map-modal-foot">
                <span id="rdDetailFootForms" style="margin-right:auto; font-size:0.82rem; color:#64748b;"></span>
                <button type="button" class="rd-wizard-btn-ghost" id="btnPortalDetailDone">Close</button>
            </div>
        </div>
    </div>

    <!-- Barangay News & Advisories Reader Modal -->
    <div id="rdNewsDetailModal" class="rd-map-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="rdNewsModalTitle" aria-hidden="true">
        <div class="rd-detail-modal">
            <div class="rd-map-modal-head">
                <div>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:4px;">
                        <span id="rdNewsModalCategory" class="badge badge-green">Announcement</span>
                        <span id="rdNewsModalPinned" class="rd-news-pin-pill" style="display:none;">Pinned</span>
                    </div>
                    <h3 id="rdNewsModalTitle">Barangay Advisory</h3>
                    <p id="rdNewsModalMeta">Barangay Tumana Official Update</p>
                </div>
                <button type="button" class="rd-map-close-btn" id="btnPortalNewsClose" aria-label="Close news modal">&times;</button>
            </div>
            <div id="rdNewsModalBody" class="rd-detail-modal-body rd-news-modal-article"></div>
            <div class="rd-map-modal-foot">
                <span id="rdNewsModalAuthor" style="margin-right:auto; font-size:0.8rem; color:#64748b;"></span>
                <button type="button" class="rd-wizard-btn-ghost" id="btnPortalNewsDone">Close</button>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../../layouts/signout-modal.php'; ?>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../../assets/js/news-widget.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/news-widget.js'); ?>"></script>
<?php endif; ?>

<script>
(function () {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
    const originalFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
        const opts = init ? Object.assign({}, init) : {};
        const method = String(opts.method || 'GET').toUpperCase();
        if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method) && csrfToken) {
            const headers = new Headers(opts.headers || {});
            if (!headers.has('X-CSRF-Token')) {
                headers.set('X-CSRF-Token', csrfToken);
            }
            opts.headers = headers;
        }
        return originalFetch(input, opts).then((resp) => {
            if (resp && resp.status === 401 && resp.headers.get('X-Agap-Session') === 'invalid') {
                window.location.href = 'resident-portal.php?error=session_expired';
            }
            return resp;
        });
    };
})();

function escHtml(v) {
    return String(v ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ch]));
}

<?php if (!$isResident): ?>
document.addEventListener('DOMContentLoaded', () => {
    const signInBtn = document.getElementById('tabResidentSignInBtn');
    const regBtn = document.getElementById('tabResidentRegisterBtn');
    const signInPanel = document.getElementById('panelResidentSignIn');
    const regPanel = document.getElementById('panelResidentRegister');
    const authAlert = document.getElementById('residentAuthAlert');
    const authHeading = document.getElementById('residentAuthHeading');
    const authIntro = document.getElementById('residentAuthIntro');
    const regStep1 = document.getElementById('regStep1');
    const regStep2 = document.getElementById('regStep2');
    const regEmailEl = document.getElementById('regEmail');
    const regFirstEl = document.getElementById('regFirstName');
    const regLastEl = document.getElementById('regLastName');
    const regEmailSummary = document.getElementById('regEmailSummary');
    const btnStepNext = document.getElementById('btnRegStepNext');
    const btnStepBack = document.getElementById('btnRegStepBack');
    const btnEditEmail = document.getElementById('btnRegEditEmail');

    function showAuthAlert(msg) {
        if (!authAlert) return;
        if (!msg) {
            authAlert.style.display = 'none';
            authAlert.textContent = '';
            return;
        }
        authAlert.textContent = msg;
        authAlert.style.display = 'block';
    }

    function setRegisterStep(step) {
        const isStep2 = step === 2;
        if (regStep1) regStep1.style.display = isStep2 ? 'none' : 'block';
        if (regStep2) regStep2.style.display = isStep2 ? 'block' : 'none';
        if (authIntro) {
            authIntro.innerHTML = isStep2
                ? 'Step 2 of 2 &middot; Set your username and password.'
                : 'Step 1 of 2 &middot; Start with your email address and name.';
        }
        if (isStep2 && regEmailSummary && regEmailEl) {
            regEmailSummary.textContent = `Email: ${regEmailEl.value.trim()}`;
            document.getElementById('regUsername')?.focus();
        }
    }

    function switchTab(mode) {
        showAuthAlert('');
        const isReg = mode === 'register';
        if (signInPanel) signInPanel.style.display = isReg ? 'none' : 'block';
        if (regPanel) regPanel.style.display = isReg ? 'block' : 'none';
        if (isReg) {
            if (authHeading) authHeading.textContent = 'Create resident account';
            setRegisterStep(1);
            regEmailEl?.focus();
        }
    }

    signInBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        switchTab('signin');
    });
    regBtn?.addEventListener('click', () => switchTab('register'));

    btnEditEmail?.addEventListener('click', () => {
        showAuthAlert('');
        setRegisterStep(1);
        regEmailEl?.focus();
    });
    btnStepBack?.addEventListener('click', () => {
        showAuthAlert('');
        setRegisterStep(1);
    });

    btnStepNext?.addEventListener('click', async () => {
        showAuthAlert('');
        if (!regEmailEl?.reportValidity() || !regFirstEl?.reportValidity() || !regLastEl?.reportValidity()) {
            return;
        }
        btnStepNext.disabled = true;
        try {
            const fd = new FormData();
            fd.append('step', 'check_email');
            fd.append('email', regEmailEl.value.trim());
            fd.append('first_name', regFirstEl.value.trim());
            fd.append('last_name', regLastEl.value.trim());
            const resp = await fetch('../../../backend/api/auth/resident-register.php', {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: fd
            });
            const data = await resp.json();
            if (resp.ok && data.success) {
                setRegisterStep(2);
            } else {
                showAuthAlert(data.message || 'Please check your email address and name.');
            }
        } catch (_) {
            showAuthAlert('Connection error while checking your email.');
        } finally {
            btnStepNext.disabled = false;
        }
    });

    regStep1?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            btnStepNext?.click();
        }
    });

    const regTermsCheckbox = document.getElementById('regAcceptTerms');
    const regTermsBox = document.getElementById('regTermsBox');
    function flagTermsReminder() {
        regTermsBox?.classList.add('is-error');
        showAuthAlert('Reminder: Please read the Terms and Conditions & Data Privacy Policy and check the agreement box before creating your account.');
        regTermsCheckbox?.focus();
    }
    regTermsCheckbox?.addEventListener('invalid', (e) => {
        e.preventDefault();
        flagTermsReminder();
    });
    regTermsCheckbox?.addEventListener('change', () => {
        if (regTermsCheckbox.checked) {
            regTermsBox?.classList.remove('is-error');
            showAuthAlert('');
        }
    });

    document.getElementById('residentRegisterForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.currentTarget;
        const submitBtn = document.getElementById('btnResidentRegister');
        showAuthAlert('');
        regTermsBox?.classList.remove('is-error');
        if (regTermsCheckbox && !regTermsCheckbox.checked) {
            flagTermsReminder();
            return;
        }
        if (submitBtn) submitBtn.disabled = true;
        try {
            const resp = await fetch('../../../backend/api/auth/resident-register.php', {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new FormData(form)
            });
            const data = await resp.json();
            if (data.success) {
                window.location.href = 'resident-portal.php';
            } else {
                showAuthAlert(data.message || 'Unable to register account.');
            }
        } catch (_) {
            showAuthAlert('Connection error while creating account.');
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    });
});
<?php else: ?>
document.addEventListener('DOMContentLoaded', () => {
    // Staff-matched Mobile Sidebar Drawer Toggle
    const sidebarEl = document.getElementById('appSidebar');
    const navToggleBtn = document.querySelector('[data-sidebar-toggle]');
    function closeMobileSidebar() {
        sidebarEl?.classList.remove('is-open');
        navToggleBtn?.setAttribute('aria-expanded', 'false');
        document.querySelector('.sidebar-overlay')?.remove();
    }
    navToggleBtn?.addEventListener('click', () => {
        if (!sidebarEl) return;
        const willOpen = !sidebarEl.classList.contains('is-open');
        if (willOpen) {
            sidebarEl.classList.add('is-open');
            navToggleBtn.setAttribute('aria-expanded', 'true');
            const overlay = document.createElement('div');
            overlay.className = 'sidebar-overlay';
            overlay.addEventListener('click', closeMobileSidebar);
            document.body.appendChild(overlay);
        } else {
            closeMobileSidebar();
        }
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeMobileSidebar();
    });

    const viewMap = {
        home: document.getElementById('residentViewHome'),
        submit: document.getElementById('residentViewSubmit'),
        complaints: document.getElementById('residentViewList'),
        profile: document.getElementById('residentViewProfile'),
    };
    let activeComplaintFilter = 'all';
    let cachedComplaints = [];

    function switchResidentView(targetView, options = {}) {
        const viewKey = Object.prototype.hasOwnProperty.call(viewMap, targetView) ? targetView : 'home';
        Object.entries(viewMap).forEach(([key, pane]) => {
            if (pane) pane.classList.toggle('active', key === viewKey);
        });
        document.querySelectorAll('[data-rd-nav]').forEach((link) => {
            const isActive = link.getAttribute('data-rd-nav') === viewKey;
            link.classList.toggle('is-active', isActive);
            if (isActive) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });
        if (options.filterBucket) {
            setComplaintFilter(options.filterBucket);
        }
        if ((viewKey === 'complaints' || viewKey === 'home') && options.refresh !== false && typeof loadMyComplaints === 'function') {
            loadMyComplaints();
        }
        if (options.updateHash !== false && window.location.hash !== '#' + viewKey) {
            history.replaceState(null, '', '#' + viewKey);
        }
        if (window.innerWidth <= 900) {
            closeMobileSidebar();
        }
        if (options.scrollTop !== false) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }

    document.querySelectorAll('[data-rd-nav]').forEach((link) => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            switchResidentView(link.getAttribute('data-rd-nav'));
        });
    });
    document.addEventListener('click', (e) => {
        const gotoEl = e.target.closest('[data-rd-goto]');
        if (gotoEl) {
            if (gotoEl.tagName === 'A') e.preventDefault();
            if (gotoEl.hasAttribute('data-rd-new-complaint') && typeof resetComplaintWizardToNew === 'function') {
                resetComplaintWizardToNew();
            }
            switchResidentView(gotoEl.getAttribute('data-rd-goto'));
            return;
        }
        const kpiCard = e.target.closest('[data-kpi-filter]');
        if (kpiCard) {
            switchResidentView('complaints', { filterBucket: kpiCard.getAttribute('data-kpi-filter') || 'all' });
        }
    });

    // Global Staff-style Navbar Search wired cleanly to My Complaints
    const globalSearchForm = document.getElementById('rdGlobalNavSearchForm');
    const globalSearchInput = document.getElementById('rdGlobalNavSearchInput');
    const listSearchInput = document.getElementById('rdComplaintsSearch');
    globalSearchForm?.addEventListener('submit', (e) => {
        e.preventDefault();
        if (listSearchInput && globalSearchInput) {
            listSearchInput.value = globalSearchInput.value;
        }
        switchResidentView('complaints');
        renderComplaintsTable();
    });
    globalSearchInput?.addEventListener('input', () => {
        if (listSearchInput) {
            listSearchInput.value = globalSearchInput.value;
            renderComplaintsTable();
        }
    });

    const initialHash = String(window.location.hash || '').replace(/^#/, '').toLowerCase();
    if (Object.prototype.hasOwnProperty.call(viewMap, initialHash)) {
        switchResidentView(initialHash, { updateHash: false, scrollTop: false });
    }

    const dtEl = document.getElementById('portalIncidentDatetime');
    function resetDefaultIncidentDatetime() {
        if (!dtEl) return;
        const now = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        dtEl.value = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
    }
    if (dtEl && !dtEl.value) {
        resetDefaultIncidentDatetime();
    }

    const respFullNameEl = document.getElementById('portalRespFullName');
    const respFirstHidden = document.getElementById('portalRespFirst');
    const respMiddleHidden = document.getElementById('portalRespMiddle');
    const respLastHidden = document.getElementById('portalRespLast');
    function syncRespondentNameParts() {
        const cleaned = String(respFullNameEl?.value || '').trim().replace(/\s+/g, ' ');
        const parts = cleaned ? cleaned.split(' ') : [];
        if (respFirstHidden) respFirstHidden.value = parts[0] || '';
        if (respMiddleHidden) respMiddleHidden.value = parts.length > 2 ? parts.slice(1, -1).join(' ') : '';
        if (respLastHidden) respLastHidden.value = parts.length >= 2 ? parts[parts.length - 1] : '';
        return parts;
    }
    respFullNameEl?.addEventListener('input', syncRespondentNameParts);
    respFullNameEl?.addEventListener('blur', syncRespondentNameParts);

    function selectMatchingPurok(selectEl, value) {
        if (!selectEl) return;
        if (!value) {
            selectEl.value = '';
            return;
        }
        const opt = Array.from(selectEl.options).find((o) => o.value.toLowerCase() === value.toLowerCase());
        if (opt) selectEl.value = opt.value;
    }

    document.getElementById('btnPortalUseMyAddr')?.addEventListener('click', () => {
        const addr = document.getElementById('portalCompAddress')?.value?.trim() || '';
        const purok = document.getElementById('portalCompPurok')?.value?.trim() || '';
        if (addr) document.getElementById('portalIncidentStreet').value = addr;
        selectMatchingPurok(document.getElementById('portalIncidentPurok'), purok);
        document.getElementById('portalLocationHint').textContent = addr ? 'Copied from your profile address.' : 'Enter your address above first.';
    });

    document.getElementById('btnPortalUseRespAddr')?.addEventListener('click', () => {
        const addr = document.getElementById('portalRespAddress')?.value?.trim() || '';
        const purok = document.getElementById('portalRespPurok')?.value?.trim() || '';
        if (addr) document.getElementById('portalIncidentStreet').value = addr;
        selectMatchingPurok(document.getElementById('portalIncidentPurok'), purok);
        document.getElementById('portalLocationHint').textContent = addr ? "Copied from respondent's address." : "Enter respondent's address first.";
    });

    // Interactive Pin-in-Map Modal (Leaflet + Tumana Boundary + GPS + Reverse Geocoding)
    const tumanaBoundary = [[14.6554682,121.0857081],[14.6562612,121.0859908],[14.6557911,121.0865123],[14.6566853,121.0867891],[14.6573361,121.0874608],[14.6566672,121.0882081],[14.6596216,121.0912009],[14.6605249,121.0911456],[14.6609324,121.0914765],[14.6617729,121.0920319],[14.6634173,121.0935248],[14.6639892,121.0936321],[14.6643486,121.0936995],[14.6645004,121.0938826],[14.6649347,121.0948585],[14.6652695,121.0952371],[14.6652424,121.0956829],[14.6648805,121.0961861],[14.664908,121.0963356],[14.6645002,121.0966408],[14.6642299,121.0967374],[14.6637829,121.0977901],[14.6629907,121.0988145],[14.6625832,121.0991486],[14.6625136,121.0996819],[14.6625395,121.100022],[14.6623861,121.1006225],[14.6621551,121.1005299],[14.6614757,121.1023379],[14.6602368,121.1019108],[14.6595948,121.1016599],[14.6589154,121.1019614],[14.6581343,121.1022551],[14.6574788,121.102553],[14.6569962,121.1027094],[14.6566377,121.102703],[14.656158,121.1026733],[14.6559169,121.1026982],[14.6553478,121.1027577],[14.6550262,121.1027938],[14.6548318,121.1027249],[14.6541841,121.1025018],[14.6534713,121.1024013],[14.6532707,121.102373],[14.6510737,121.101336],[14.6508768,121.1007973],[14.6507211,121.0992879],[14.650753,121.0989571],[14.6509538,121.098811],[14.6514718,121.0983827],[14.6521496,121.0978851],[14.6527322,121.0972236],[14.6535364,121.0959443],[14.6539003,121.0955034],[14.654028,121.0953166],[14.6537619,121.0950108],[14.6533329,121.0942299],[14.6530062,121.0934184]];
    function isWithinTumanaPoly(lat, lng) {
        let inside = false;
        for (let i = 0, j = tumanaBoundary.length - 1; i < tumanaBoundary.length; j = i++) {
            const [yi, xi] = tumanaBoundary[i];
            const [yj, xj] = tumanaBoundary[j];
            if ((yi > lat) !== (yj > lat) && lng < ((xj - xi) * (lat - yi) / (yj - yi) + xi)) inside = !inside;
        }
        return inside;
    }

    const mapModalEl = document.getElementById('rdIncidentMapModal');
    const mapStatusEl = document.getElementById('rdMapStatusText');
    const mapMetaEl = document.getElementById('rdMapPreviewMeta');
    const clearMapChipBtn = document.getElementById('btnPortalClearMapChip');
    const locationHintEl = document.getElementById('portalLocationHint');
    let portalMap = null;
    let portalMapMarker = null;
    let portalTumanaBounds = null;
    let mapLookupVersion = 0;
    let pendingPin = null;

    function setModalMapStatus(msg, isError = false) {
        if (!mapStatusEl) return;
        mapStatusEl.textContent = msg;
        mapStatusEl.classList.toggle('is-error', isError);
    }

    function clearPendingPinUI() {
        pendingPin = null;
        if (portalMapMarker && portalMap) {
            portalMap.removeLayer(portalMapMarker);
            portalMapMarker = null;
        }
        setModalMapStatus('Tap anywhere on the map or click Use My GPS to place a pin.');
        if (mapMetaEl) mapMetaEl.textContent = 'No location pinned yet.';
    }

    function clearAppliedMapPin(updateHint = true) {
        const stateEl = document.getElementById('portalMapState');
        const latEl = document.getElementById('portalMapLat');
        const lngEl = document.getElementById('portalMapLng');
        const isEditingExisting = Number(document.getElementById('portalComplaintId')?.value || 0) > 0;
        if (stateEl) stateEl.value = (updateHint && isEditingExisting) ? 'clear' : 'none';
        if (latEl) latEl.value = '';
        if (lngEl) lngEl.value = '';
        if (clearMapChipBtn) clearMapChipBtn.style.display = 'none';
        if (updateHint && locationHintEl) {
            locationHintEl.textContent = 'Map pin cleared. Enter an address or pin on the map again.';
        }
        clearPendingPinUI();
    }

    async function setPendingMapPin(lat, lng, shouldCenter = true) {
        if (!portalMap || !window.L) return;

        if (!portalMapMarker) {
            portalMapMarker = L.marker([lat, lng], { draggable: true }).addTo(portalMap);
            portalMapMarker.on('dragend', (ev) => {
                const pos = ev.target.getLatLng();
                setPendingMapPin(pos.lat, pos.lng, false);
            });
        } else {
            portalMapMarker.setLatLng([lat, lng]);
            if (!portalMap.hasLayer(portalMapMarker)) {
                portalMapMarker.addTo(portalMap);
            }
        }
        if (shouldCenter) {
            portalMap.setView([lat, lng], Math.max(portalMap.getZoom() || 16, 17));
        }

        const withinMarikinaBox = (lat >= 14.610 && lat <= 14.690 && lng >= 121.075 && lng <= 121.155);
        if (!withinMarikinaBox) {
            pendingPin = null;
            setModalMapStatus('Pinned spot is outside Marikina City. Please pin inside Marikina City or Barangay Tumana.', true);
            if (mapMetaEl) mapMetaEl.textContent = `${lat.toFixed(5)}, ${lng.toFixed(5)} (Outside service area)`;
            return;
        }

        const inTumana = isWithinTumanaPoly(lat, lng);
        pendingPin = {
            lat,
            lng,
            street: '',
            inTumana,
            barangay: inTumana ? 'Tumana' : 'Marikina',
        };
        setModalMapStatus('Looking up nearest street / landmark...');
        if (mapMetaEl) {
            mapMetaEl.textContent = `${lat.toFixed(5)}, ${lng.toFixed(5)} (${inTumana ? 'Inside Barangay Tumana' : 'Within Marikina City'})`;
        }

        const ver = ++mapLookupVersion;
        try {
            const resp = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}&zoom=18&addressdetails=1`, {
                headers: { Accept: 'application/json' }
            });
            const geo = await resp.json();
            if (ver !== mapLookupVersion) return;

            const addr = geo.address || {};
            const fullLower = String(geo.display_name || '').toLowerCase();
            const cityCandidates = [addr.city, addr.town, addr.municipality, addr.county, addr.city_district]
                .filter(Boolean)
                .map((c) => String(c).toLowerCase());
            const otherCities = ['quezon city', 'pasig', 'san mateo', 'antipolo', 'cainta', 'rodriguez', 'taguig', 'makati', 'city of manila'];
            const mentionsMarikina = cityCandidates.some((c) => c.includes('marikina')) || fullLower.includes('marikina');
            const mentionsOther = cityCandidates.some((c) => otherCities.some((oc) => c.includes(oc)));

            if (mentionsOther && !mentionsMarikina) {
                pendingPin = null;
                setModalMapStatus('Selected point is outside Marikina City. Move the pin inside Marikina / Tumana.', true);
                if (mapMetaEl) mapMetaEl.textContent = geo.display_name || `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
                return;
            }

            const roadPart = [addr.house_number, addr.road || addr.pedestrian || addr.footway || addr.street].filter(Boolean).join(' ');
            const areaPart = addr.neighbourhood || addr.suburb || addr.quarter || addr.residential || '';
            const previewStreet = [roadPart, areaPart].filter(Boolean).join(', ');
            const detectedBrgy = inTumana
                ? 'Tumana'
                : (String(addr.quarter || addr.suburb || addr.village || 'Tumana').replace(/^Barangay\s+/i, '').trim() || 'Tumana');

            pendingPin = {
                lat,
                lng,
                street: previewStreet,
                inTumana,
                barangay: detectedBrgy,
            };
            setModalMapStatus('Location ready! Drag the pin to fine-tune or click Use This Location.');
            if (mapMetaEl) {
                mapMetaEl.textContent = `${previewStreet || 'Pinned spot'} (${lat.toFixed(5)}, ${lng.toFixed(5)})${inTumana ? ' • Barangay Tumana' : ' • Marikina City'}`;
            }
        } catch (_) {
            if (ver !== mapLookupVersion) return;
            setModalMapStatus('Pin placed! Address lookup unavailable offline, coordinates are ready.');
        }
    }

    function initPortalLeafletMap() {
        const host = document.getElementById('rdIncidentLeafletMap');
        if (!host || !window.L) return false;
        if (!portalMap) {
            portalTumanaBounds = L.latLngBounds(tumanaBoundary);
            portalMap = L.map(host).fitBounds(portalTumanaBounds, { padding: [12, 12] });
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors',
                maxZoom: 19
            }).addTo(portalMap);
            L.polygon(tumanaBoundary, {
                color: '#1b6553',
                weight: 2,
                fillColor: '#34d399',
                fillOpacity: 0.12,
                interactive: false
            }).addTo(portalMap);
            portalMap.on('click', (ev) => {
                setPendingMapPin(ev.latlng.lat, ev.latlng.lng, false);
            });
        }
        return true;
    }

    function openIncidentMapModal() {
        if (!mapModalEl) return;
        mapModalEl.classList.add('is-open');
        mapModalEl.setAttribute('aria-hidden', 'false');

        if (!window.L) {
            setModalMapStatus('Interactive map library failed to load. Check internet connection or type the address directly.', true);
            return;
        }

        initPortalLeafletMap();
        setTimeout(() => {
            if (!portalMap) return;
            portalMap.invalidateSize();
            const existingLat = parseFloat(document.getElementById('portalMapLat')?.value || '');
            const existingLng = parseFloat(document.getElementById('portalMapLng')?.value || '');
            if (Number.isFinite(existingLat) && Number.isFinite(existingLng)) {
                setPendingMapPin(existingLat, existingLng, true);
            } else {
                clearPendingPinUI();
                if (portalTumanaBounds) {
                    portalMap.fitBounds(portalTumanaBounds, { padding: [12, 12] });
                }
            }
        }, 70);
    }

    function closeIncidentMapModal() {
        if (!mapModalEl) return;
        mapModalEl.classList.remove('is-open');
        mapModalEl.setAttribute('aria-hidden', 'true');
    }

    document.getElementById('btnPortalUseGps')?.addEventListener('click', openIncidentMapModal);
    clearMapChipBtn?.addEventListener('click', () => clearAppliedMapPin(true));
    document.getElementById('btnPortalMapClose')?.addEventListener('click', closeIncidentMapModal);
    document.getElementById('btnPortalMapCancel')?.addEventListener('click', closeIncidentMapModal);
    mapModalEl?.addEventListener('click', (ev) => {
        if (ev.target === mapModalEl) closeIncidentMapModal();
    });
    document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape' && mapModalEl?.classList.contains('is-open')) {
            closeIncidentMapModal();
        }
    });

    document.getElementById('btnPortalMapRecenter')?.addEventListener('click', () => {
        if (portalMap && portalTumanaBounds) {
            portalMap.fitBounds(portalTumanaBounds, { padding: [12, 12] });
            portalMap.invalidateSize();
        }
    });

    document.getElementById('btnPortalMapClear')?.addEventListener('click', () => {
        clearAppliedMapPin(true);
        if (portalMap && portalTumanaBounds) {
            portalMap.fitBounds(portalTumanaBounds, { padding: [12, 12] });
        }
    });

    document.getElementById('btnPortalMapUseGps')?.addEventListener('click', () => {
        if (!navigator.geolocation) {
            setModalMapStatus('GPS is unavailable in this browser. Tap on the map instead.', true);
            return;
        }
        setModalMapStatus('Locating your current GPS coordinates...');
        navigator.geolocation.getCurrentPosition((pos) => {
            setPendingMapPin(pos.coords.latitude, pos.coords.longitude, true);
        }, () => {
            setModalMapStatus('Unable to read GPS location. Tap anywhere on the map to pin manually.', true);
        }, { enableHighAccuracy: true, timeout: 10000 });
    });

    document.getElementById('btnPortalMapConfirm')?.addEventListener('click', () => {
        if (!pendingPin) {
            setModalMapStatus('Please click on the map (within Marikina / Tumana) or use GPS before confirming.', true);
            return;
        }
        document.getElementById('portalMapLat').value = pendingPin.lat.toFixed(8);
        document.getElementById('portalMapLng').value = pendingPin.lng.toFixed(8);
        document.getElementById('portalMapState').value = 'selected';

        const brgyHidden = portalForm?.querySelector('[name="incident_barangay"]');
        if (brgyHidden && pendingPin.barangay) {
            brgyHidden.value = pendingPin.barangay;
        }

        const streetEl = document.getElementById('portalIncidentStreet');
        if (streetEl && pendingPin.street) {
            streetEl.value = pendingPin.street;
            if (window.AgapFieldValidator?.validateElement) {
                window.AgapFieldValidator.validateElement(streetEl, false);
            }
        }
        if (clearMapChipBtn) clearMapChipBtn.style.display = 'inline-flex';
        if (locationHintEl) {
            const addrSummary = pendingPin.street ? `${pendingPin.street} ` : '';
            locationHintEl.textContent = `Map pinned: ${addrSummary}(${pendingPin.lat.toFixed(5)}, ${pendingPin.lng.toFixed(5)}).`;
        }
        closeIncidentMapModal();
    });

    function classifyComplaintBucket(row) {
        if (Number(row.is_draft || 0) === 1) {
            return 'cancelled';
        }
        const stageText = `${row.workflow_stage || ''} ${row.lifecycle_status || ''} ${row.case_status || ''} ${row.status || ''}`.toLowerCase();
        if (/dismiss|reject|archived|withdrawn|cancel|repudiat/.test(stageText)) {
            return 'cancelled';
        }
        if (/settled|resolved|resolution \/ closed|\bclosed\b|\bcfa\b/.test(stageText)) {
            return 'settled';
        }
        if (/conciliation|pangkat|\barbitration\b/.test(stageText)) {
            return 'conciliation';
        }
        if (/approved|paanyaya|mediation|accepted|docketed/.test(stageText)) {
            return 'ongoing';
        }
        return 'new';
    }

    function badgeClassForBucket(bucket) {
        return {
            new: 'badge-amber',
            ongoing: 'badge-blue',
            conciliation: 'badge-blue',
            settled: 'badge-green',
            cancelled: 'badge-slate',
        }[bucket] || 'badge-amber';
    }

    function badgeClassForStage(stageLabel, bucket) {
        const s = String(stageLabel || '').toLowerCase();
        if (s === 'approved') return 'badge-teal';
        if (/dismiss|repudiat/.test(s)) return 'badge-red';
        if (/\bcfa\b/.test(s)) return 'badge-purple';
        if (/settled/.test(s)) return 'badge-green';
        if (/paanyaya|mediation|conciliation|arbitration/.test(s)) return 'badge-blue';
        if (/review|needs info/.test(s)) return 'badge-amber';
        if (/draft|archived/.test(s)) return 'badge-slate';
        return badgeClassForBucket(bucket);
    }

    function buildDetailProgressLineHtml(row) {
        const isDraft = Number(row.is_draft || 0) === 1;
        const stageLabel = formatResidentStageLabel(row, isDraft);
        const stageLower = stageLabel.toLowerCase();
        const isRepudiated = /repudiat/.test(stageLower);
        const isDismissed = /dismiss|declin|reject/.test(stageLower);
        const isCfa = /\bcfa\b/.test(stageLower);
        const isArbitrationSettled = /settled\s*\(arbitration\)/.test(stageLower);
        const isSettled = /settled/.test(stageLower);

        let outcomeStepLabel = 'Outcome';
        if (isArbitrationSettled) outcomeStepLabel = 'Settled (Arbitration)';
        else if (isSettled) outcomeStepLabel = 'Settled (Amicable)';
        else if (isRepudiated) outcomeStepLabel = 'Repudiated';
        else if (stageLower === 'for cfa') outcomeStepLabel = 'For CFA';
        else if (isCfa) outcomeStepLabel = 'CFA Issued';
        else if (isDismissed) outcomeStepLabel = 'Dismissed';

        const steps = isDraft
            ? ['Saved Draft', 'For Review', 'Approved', 'Paanyaya', 'Mediation', 'Conciliation', outcomeStepLabel]
            : ['For Review', 'Approved', 'Paanyaya', 'Mediation', 'Conciliation', outcomeStepLabel];

        const offset = isDraft ? 1 : 0;
        let activeIdx = isDraft ? 0 : offset;
        let failedIdx = -1;

        if (!isDraft) {
            if (isDismissed) {
                if (row.case_status === 'Conciliation') failedIdx = offset + 4;
                else if (row.case_id) failedIdx = offset + 3;
                else if (String(row.status || '').toLowerCase().includes('paanyaya')) failedIdx = offset + 2;
                else if (String(row.status || '').toLowerCase() === 'rejected') failedIdx = offset;
                else failedIdx = steps.length - 1;
                activeIdx = failedIdx;
            } else if (isRepudiated) {
                failedIdx = steps.length - 1;
                activeIdx = failedIdx;
            } else if (isSettled || isCfa) {
                activeIdx = steps.length - 1;
            } else if (/conciliation|arbitration/.test(stageLower)) {
                activeIdx = offset + 4;
            } else if (/mediation|docketed/.test(stageLower)) {
                activeIdx = offset + 3;
            } else if (/paanyaya/.test(stageLower)) {
                activeIdx = offset + 2;
            } else if (/approved/.test(stageLower)) {
                activeIdx = offset + 1;
            } else {
                activeIdx = offset;
            }
        }

        const checkSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
        const crossSvg = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

        const parts = [];
        steps.forEach((label, idx) => {
            let stateCls = 'is-pending';
            let nodeInner = '';

            if (failedIdx >= 0 && idx === failedIdx) {
                stateCls = 'is-rejected';
                nodeInner = crossSvg;
            } else if (failedIdx < 0 && (idx < activeIdx || (activeIdx === steps.length - 1 && idx === steps.length - 1))) {
                stateCls = 'is-done';
                nodeInner = checkSvg;
            } else if (idx < activeIdx) {
                stateCls = 'is-done';
                nodeInner = checkSvg;
            } else if (failedIdx < 0 && idx === activeIdx) {
                stateCls = 'is-active';
                nodeInner = '<span class="rd-pl-node-dot"></span>';
            }

            parts.push(`
                <div class="rd-pl-step ${stateCls}">
                    <span class="rd-pl-node">${nodeInner}</span>
                    <span class="rd-pl-label">${escHtml(label)}</span>
                </div>
            `);
            if (idx < steps.length - 1) {
                const connDone = idx < activeIdx;
                parts.push(`<div class="rd-pl-connector ${connDone ? 'is-done' : ''}" aria-hidden="true"></div>`);
            }
        });
        return `<div class="rd-progress-line" role="list" aria-label="Complaint Workflow Progress">${parts.join('')}</div>`;
    }

    function formatResidentStageLabel(row, isDraft) {
        if (isDraft) return 'Saved Draft';
        const rawStage = String(row.workflow_stage || row.lifecycle_status || row.case_status || row.status || 'Under Review').trim();
        if (rawStage === 'Filed' || rawStage === 'Under Review') return 'For Review';
        if (rawStage === 'Accepted') return 'Approved';
        if (rawStage === 'Rejected') return 'Dismissed';
        if (rawStage === 'Settled' || rawStage === 'Resolved at Paanyaya / Closed') return 'Settled (Amicable Settlement)';
        if (rawStage === 'CFA Issued') return 'CFA';
        return rawStage;
    }

    function updateDashboardMetrics(rows) {
        const counts = { new: 0, ongoing: 0, conciliation: 0, settled: 0, cancelled: 0 };
        rows.forEach((row) => {
            counts[classifyComplaintBucket(row)]++;
        });
        const setTxt = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.textContent = String(val);
        };
        setTxt('kpiCountNew', counts.new);
        setTxt('kpiCountOngoing', counts.ongoing);
        setTxt('kpiCountConciliation', counts.conciliation);
        setTxt('kpiCountSettled', counts.settled);
        setTxt('kpiCountCancelled', counts.cancelled);
        setTxt('rdNavComplaintsCount', rows.length);
        setTxt('rdRecentCountBadge', `${rows.length} total`);

        const recentEl = document.getElementById('rdHomeRecentList');
        if (!recentEl) return;
        if (rows.length === 0) {
            recentEl.innerHTML = '<div class="dashboard-list-item"><div class="dashboard-list-content"><h3>No submitted complaints yet</h3><p>You can file a formal complaint online anytime or save a draft before submitting.</p></div><button type="button" class="btn-open-case" data-rd-goto="submit" data-rd-new-complaint="1">File First Complaint</button></div>';
            return;
        }
        recentEl.innerHTML = rows.slice(0, 4).map((row) => {
            const bucket = classifyComplaintBucket(row);
            const isDraft = Number(row.is_draft || 0) === 1;
            const ref = row.case_number || row.complaint_number || (`CMP-${row.complaint_id}`);
            const stage = formatResidentStageLabel(row, isDraft);
            const editDraftBtn = isDraft
                ? `<button type="button" class="btn-secondary" data-edit-draft-id="${Number(row.complaint_id)}" style="padding:7px 12px; font-size:0.8rem;">Edit Draft</button>`
                : '';
            return `
                <div class="dashboard-list-item">
                    <div class="dashboard-list-content">
                        <h3>${escHtml(ref)} &mdash; ${escHtml(row.complaint_title || 'Untitled Complaint')}</h3>
                        <p>vs. ${escHtml(row.respondent_name || 'Respondent')} &bull; Filed ${escHtml((row.created_at || '').slice(0, 10))}</p>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
                        <span class="badge ${badgeClassForStage(stage, bucket)}">${escHtml(stage)}</span>
                        <button type="button" class="btn-open-case" data-view-complaint-id="${Number(row.complaint_id)}">View</button>
                        ${editDraftBtn}
                    </div>
                </div>
            `;
        }).join('');
    }

    function isResidentKp7Ready(row) {
        if (!row || Number(row.is_draft || 0) === 1) return false;
        const statusRaw = String(row.status || '').trim();
        if (/^(Under Review|Needs Information|Rejected)$/i.test(statusRaw)) {
            return false;
        }
        if (row.complaint_source !== 'Walk-in' && /^Filed$/i.test(statusRaw) && !row.case_id && !row.generated_kp7_path) {
            return false;
        }
        return true;
    }

    function renderComplaintsTable() {
        const tbody = document.getElementById('myComplaintsTbody');
        if (!tbody) return;
        const query = (document.getElementById('rdComplaintsSearch')?.value || '').trim().toLowerCase();
        const filtered = cachedComplaints.filter((row) => {
            if (activeComplaintFilter !== 'all' && classifyComplaintBucket(row) !== activeComplaintFilter) {
                return false;
            }
            if (!query) return true;
            const hay = `${row.case_number || ''} ${row.complaint_number || ''} CMP-${row.complaint_id} ${row.complaint_title || ''} ${row.respondent_name || ''} ${row.workflow_stage || ''} ${row.status || ''}`.toLowerCase();
            return hay.includes(query);
        });

        if (cachedComplaints.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="empty-state">No submitted complaints yet. Use Submit Complaint to file your first complaint.</td></tr>';
            return;
        }
        if (filtered.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="empty-state">No complaints match the current filter or search term.</td></tr>';
            return;
        }

        tbody.innerHTML = filtered.map((row) => {
            const bucket = classifyComplaintBucket(row);
            const isDraft = Number(row.is_draft || 0) === 1;
            const ref = row.case_number || row.complaint_number || (`CMP-${row.complaint_id}`);
            const stage = formatResidentStageLabel(row, isDraft);
            const src = row.complaint_source === 'Walk-in' ? 'Walk-in' : 'Online';
            const compIdNum = Number(row.complaint_id);
            let formLinks = '<span style="font-size:0.78rem; color:#64748b;">Pending review</span>';
            if (isDraft) {
                formLinks = '<span class="badge badge-slate">Draft</span>';
            } else if (/^Rejected$/i.test(String(row.status || '').trim())) {
                formLinks = '<span style="font-size:0.78rem; color:#94a3b8;">Not issued</span>';
            } else if (isResidentKp7Ready(row)) {
                formLinks = `<a href="../../../backend/api/complaints/kp7-preview.php?id=${compIdNum}" target="_blank" style="font-size:0.78rem; font-weight:700; color:#1d4ed8; text-decoration:none; margin-right:8px;">KP Form 7</a><a href="../../../backend/api/complaints/kp7-preview.php?id=${compIdNum}&download=1" style="font-size:0.78rem; font-weight:600; color:#475569; text-decoration:none;">PDF</a>`;
            }
            return `
                <tr>
                    <td><strong>${escHtml(ref)}</strong></td>
                    <td>
                        <div style="font-weight:600;">${escHtml(row.complaint_title || 'Untitled')}</div>
                        <div style="font-size:0.78rem; color:#64748b;">vs. ${escHtml(row.respondent_name || 'Respondent')}</div>
                    </td>
                    <td><span class="badge ${src === 'Online' ? 'badge-blue' : 'badge-slate'}">${escHtml(src)}</span></td>
                    <td><span class="badge ${badgeClassForStage(stage, bucket)}">${escHtml(stage)}</span></td>
                    <td style="font-size:0.8rem; color:#475569;">${escHtml((row.created_at || '').slice(0, 10))}</td>
                    <td style="white-space:nowrap;">${formLinks}</td>
                    <td style="white-space:nowrap;">
                        <button type="button" class="btn-open-case" data-view-complaint-id="${compIdNum}">View</button>
                        ${isDraft ? `<button type="button" class="btn-secondary" data-edit-draft-id="${compIdNum}" style="padding:6px 11px; font-size:0.8rem; margin-left:6px;">Edit &amp; Submit</button>` : ''}
                    </td>
                </tr>
            `;
        }).join('');
    }

    const detailModalEl = document.getElementById('rdComplaintDetailsModal');
    const detailTitleEl = document.getElementById('rdDetailModalTitle');
    const detailSubEl = document.getElementById('rdDetailModalSubtitle');
    const detailStatusBadgeEl = document.getElementById('rdDetailStatusBadge');
    const detailBodyEl = document.getElementById('rdDetailModalBody');
    const detailFootFormsEl = document.getElementById('rdDetailFootForms');

    function closeResidentComplaintDetails() {
        if (!detailModalEl) return;
        detailModalEl.classList.remove('is-open');
        detailModalEl.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function formatFriendlyDateTime(dateInput, timeInput = '') {
        const rawDate = String(dateInput || '').trim();
        if (!rawDate) return 'TBA';
        const datePart = rawDate.slice(0, 10);
        const match = datePart.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        let formattedDate = datePart;
        if (match) {
            const dtObj = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
            if (!Number.isNaN(dtObj.getTime())) {
                formattedDate = dtObj.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
            }
        }
        let rawTime = String(timeInput || '').trim();
        if (!rawTime && rawDate.length > 10) {
            rawTime = rawDate.slice(11, 16);
        }
        if (rawTime && /^\d{2}:\d{2}/.test(rawTime)) {
            const [hh, mm] = rawTime.slice(0, 5).split(':').map(Number);
            if (!Number.isNaN(hh) && !Number.isNaN(mm)) {
                const suffix = hh >= 12 ? 'PM' : 'AM';
                const hour12 = ((hh + 11) % 12) + 1;
                return `${formattedDate} at ${hour12}:${String(mm).padStart(2, '0')} ${suffix}`;
            }
        }
        return formattedDate;
    }

    function buildResidentSchedulesHtml(detail, stageLabel, isDraft) {
        const items = [];
        const seenKeys = new Set();

        const pushItem = (item) => {
            const key = `${item.kind}:${item.title}:${item.dateKey}`;
            if (seenKeys.has(key)) return;
            seenKeys.add(key);
            items.push(item);
        };

        const paaSessions = Array.isArray(detail.paanyaya_sessions) ? detail.paanyaya_sessions : [];
        paaSessions.forEach((s) => {
            if (!s?.scheduled_date) return;
            const statusStr = String(s.status || s.session_outcome || s.queue_status || 'Scheduled').replace(/_/g, ' ');
            const isDone = Boolean(s.resolved_at || s.escalated_at || /settled|completed|concluded|escalat/i.test(statusStr));
            pushItem({
                kind: 'paanyaya',
                title: `Paanyaya ${Number(s.attempt_number || 1)} Dialogue`,
                dateStr: formatFriendlyDateTime(s.scheduled_date, s.scheduled_time),
                dateKey: String(s.scheduled_date).slice(0, 10),
                venue: s.venue || 'Tanggapan ng Barangay Tumana',
                status: isDone ? (s.session_outcome ? String(s.session_outcome).replace(/_/g, ' ') : 'Completed') : statusStr,
                isUpcoming: !isDone && !/cancel/i.test(statusStr),
                note: s.outcome_notes || s.session_remarks || ''
            });
        });

        const stg1 = detail.paanyaya_stages?.stage_1?.details;
        const stg2 = detail.paanyaya_stages?.stage_2?.details;
        [
            { label: 'Paanyaya 1 Dialogue', data: stg1, meta: detail.paanyaya_stages?.stage_1 },
            { label: 'Paanyaya 2 Dialogue', data: stg2, meta: detail.paanyaya_stages?.stage_2 }
        ].forEach(({ label, data, meta }) => {
            if (!data?.invitation_date) return;
            const statusStr = meta?.is_completed ? 'Completed' : (data.status && data.status !== 'Not Started' ? data.status : 'Scheduled');
            pushItem({
                kind: 'paanyaya',
                title: label,
                dateStr: formatFriendlyDateTime(data.invitation_date, data.invitation_time),
                dateKey: String(data.invitation_date).slice(0, 10),
                venue: data.venue || 'Tanggapan ng Barangay Tumana',
                status: statusStr,
                isUpcoming: !meta?.is_completed && !/completed|cancel/i.test(statusStr),
                note: meta?.outcome ? `Outcome: ${String(meta.outcome).replace(/_/g, ' ')}` : ''
            });
        });

        if (detail.paanyaya?.scheduled_date) {
            const p = detail.paanyaya;
            const statusStr = String(p.status || p.session_outcome || 'Scheduled').replace(/_/g, ' ');
            pushItem({
                kind: 'paanyaya',
                title: `Paanyaya ${Number(p.attempt_number || 1)} Dialogue`,
                dateStr: formatFriendlyDateTime(p.scheduled_date, p.scheduled_time),
                dateKey: String(p.scheduled_date).slice(0, 10),
                venue: p.venue || 'Tanggapan ng Barangay Tumana',
                status: statusStr,
                isUpcoming: !/completed|resolved|concluded|cancel|escalat/i.test(statusStr),
                note: ''
            });
        }

        const hearings = Array.isArray(detail.case_workspace?.hearings) ? detail.case_workspace.hearings : [];
        hearings.forEach((h) => {
            if (!h?.hearing_date) return;
            const hStatus = String(h.hearing_status || h.hearing_status_code || 'Scheduled');
            const attParts = [];
            if (h.complainant_attendance) attParts.push(`Complainant: ${h.complainant_attendance}`);
            if (h.respondent_attendance) attParts.push(`Respondent: ${h.respondent_attendance}`);
            const noteParts = [];
            if (attParts.length > 0) noteParts.push(attParts.join(' • '));
            if (h.reschedule_reason) noteParts.push(`Reason: ${h.reschedule_reason}`);
            pushItem({
                kind: 'hearing',
                title: `${h.hearing_type || 'Hearing'} Session`,
                dateStr: formatFriendlyDateTime(h.hearing_date),
                dateKey: String(h.hearing_date).slice(0, 16),
                venue: h.venue || 'Tanggapan ng Barangay Tumana',
                status: hStatus,
                isUpcoming: /^scheduled$/i.test(hStatus),
                note: noteParts.join(' | ')
            });
        });

        const ws = detail.case_workspace || {};
        const keyDates = [];
        if (ws.case?.mediation_deadline_date) {
            keyDates.push(`Mediation Period Deadline: <strong>${escHtml(formatFriendlyDateTime(ws.case.mediation_deadline_date))}</strong>`);
        }
        if (ws.settlement?.repudiation_deadline && String(ws.settlement.repudiation_status || '').toLowerCase() !== 'repudiated') {
            keyDates.push(`10-Day Repudiation Deadline: <strong>${escHtml(formatFriendlyDateTime(ws.settlement.repudiation_deadline))}</strong>`);
        }

        let bodyHtml = '';
        if (items.length > 0) {
            const badgeForSched = (st) => {
                if (/scheduled/i.test(st)) return 'badge-blue';
                if (/completed|settled|resolved/i.test(st)) return 'badge-green';
                if (/resched|retry/i.test(st)) return 'badge-amber';
                if (/cancel/i.test(st)) return 'badge-red';
                return 'badge-slate';
            };
            bodyHtml = `<div class="rd-schedule-list">${items.map((it) => `
                <div class="rd-schedule-item ${it.isUpcoming ? 'is-upcoming' : ''}">
                    <div class="rd-schedule-main">
                        <strong>${escHtml(it.title)}</strong>
                        <span>${escHtml(it.dateStr)}</span>
                        <span>Venue: ${escHtml(it.venue)}</span>
                        ${it.note ? `<span style="color:#334155;">${escHtml(it.note)}</span>` : ''}
                    </div>
                    <div class="rd-schedule-meta">
                        <span class="badge ${badgeForSched(it.status)}">${escHtml(it.status)}</span>
                    </div>
                </div>
            `).join('')}</div>`;
        } else {
            let emptyMsg = 'No hearing or Paanyaya session scheduled yet. Please check back for updates from Barangay Tumana.';
            if (isDraft) {
                emptyMsg = 'Saved as draft. Submit your complaint so Barangay Tumana officials can review and schedule your session.';
            } else if (stageLabel === 'Approved') {
                emptyMsg = 'Approved within Barangay jurisdiction. The Barangay Clerk will set your Paanyaya schedule soon — your date, time, and venue will appear here once scheduled.';
            } else if (/review|needs info/i.test(stageLabel)) {
                emptyMsg = 'Your complaint is currently for review. Once approved within jurisdiction and scheduled by the Barangay Clerk, your Paanyaya date, time, and venue will appear here.';
            } else if (/dismiss/i.test(stageLabel)) {
                emptyMsg = 'No upcoming schedules (this complaint/case has been dismissed).';
            }
            bodyHtml = `<div class="rd-schedule-empty">${escHtml(emptyMsg)}</div>`;
        }

        const keyDatesHtml = keyDates.length > 0
            ? `<div style="margin-top:10px; padding:8px 11px; border-radius:8px; background:#f8fafc; border:1px solid #e2e8f0; font-size:0.79rem; color:#334155;">${keyDates.join(' &bull; ')}</div>`
            : '';

        return `
            <div class="rd-detail-section">
                <h4>Your Schedule &amp; Hearings</h4>
                ${bodyHtml}
                ${keyDatesHtml}
            </div>
        `;
    }

    async function openResidentComplaintDetails(complaintId) {
        const compIdNum = Number(complaintId);
        if (!compIdNum || !detailModalEl) return;

        if (detailTitleEl) detailTitleEl.textContent = `Complaint CMP-${compIdNum}`;
        if (detailSubEl) detailSubEl.textContent = 'Loading case record and schedule...';
        if (detailStatusBadgeEl) detailStatusBadgeEl.style.display = 'none';
        if (detailFootFormsEl) detailFootFormsEl.textContent = '';
        if (detailBodyEl) detailBodyEl.innerHTML = '<div class="empty-state">Loading case details and schedule...</div>';

        detailModalEl.classList.add('is-open');
        detailModalEl.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        try {
            const resp = await fetch(`../../../backend/api/complaints/view.php?id=${compIdNum}`, {
                headers: { Accept: 'application/json' },
                cache: 'no-store'
            });
            const detail = await resp.json();
            if (!resp.ok || !detail || detail.success === false) {
                if (detailBodyEl) {
                    detailBodyEl.innerHTML = `<div class="alert alert-error" style="display:block;">${escHtml(detail?.message || 'Unable to load complaint details right now.')}</div>`;
                }
                return;
            }

            const isDraft = Number(detail.is_draft || 0) === 1;
            const ref = detail.case_number || detail.complaint_number || `CMP-${compIdNum}`;
            const stage = formatResidentStageLabel(detail, isDraft);
            const bucket = classifyComplaintBucket(detail);
            const stageBadgeCls = badgeClassForStage(stage, bucket);

            if (detailTitleEl) detailTitleEl.textContent = detail.case_number ? `Case ${detail.case_number} (${detail.complaint_number || ref})` : ref;
            if (detailSubEl) detailSubEl.textContent = detail.complaint_title || 'Case Record';
            if (detailStatusBadgeEl) {
                detailStatusBadgeEl.textContent = stage;
                detailStatusBadgeEl.className = `badge ${stageBadgeCls}`;
                detailStatusBadgeEl.style.display = 'inline-flex';
            }

            const rawCat = String(detail.category_name_display || detail.category_name || '').trim();
            const catDisplay = (!rawCat || /pending barangay liso classification/i.test(rawCat))
                ? 'Pending review by Barangay Lupon / LISO'
                : rawCat;

            const parties = Array.isArray(detail.parties) ? detail.parties : [];
            const partyName = (p) => p.full_name || p.resident_name || [p.first_name, p.middle_name, p.last_name].filter(Boolean).join(' ');
            const complainants = parties.filter((p) => p.party_type === 'Complainant').map(partyName).filter(Boolean);
            const respondents = parties.filter((p) => p.party_type === 'Respondent').map(partyName).filter(Boolean);
            const incDt = [detail.incident_date, detail.incident_time].filter(Boolean).join(' ') || '—';
            const locStr = detail.incident_location || (detail.location && detail.location.address) || '—';

            const ws = detail.case_workspace || {};
            const docketDateStr = ws.case?.docket_date ? String(ws.case.docket_date).slice(0, 10) : 'Not yet docketed';
            const assignments = Array.isArray(ws.assignments) ? ws.assignments : [];
            const assignedHtml = assignments.length > 0
                ? assignments.map((a) => `${a.member_name}${a.assignment_role ? ` (${a.assignment_role})` : ''}`).join(', ')
                : '';

            const cfaRecord = detail.cfa || ws.cfa || null;
            const settleRecord = ws.settlement || null;
            const arbRecord = ws.arbitration || null;
            let outcomeHtml = '';
            if (cfaRecord || /cfa/i.test(stage)) {
                outcomeHtml = `
                    <div class="rd-detail-section" style="background:#f5f3ff; border-color:#ddd6fe;">
                        <h4>Certificate to File Action (CFA)</h4>
                        <dl class="rd-detail-grid">
                            <div><dt>Document Type</dt><dd>${escHtml(cfaRecord?.cfa_type || 'Certificate to File Action')}</dd></div>
                            <div><dt>Date Issued</dt><dd>${escHtml(cfaRecord?.issuance_date || '—')}</dd></div>
                            ${cfaRecord?.reason ? `<div style="grid-column:1 / -1;"><dt>Ground / Reason</dt><dd style="font-weight:500;">${escHtml(cfaRecord.reason)}</dd></div>` : ''}
                        </dl>
                    </div>
                `;
            } else if (/repudiat/i.test(stage) && settleRecord) {
                outcomeHtml = `
                    <div class="rd-detail-section" style="background:#fef2f2; border-color:#fecaca;">
                        <h4>Settlement Repudiation Record</h4>
                        <dl class="rd-detail-grid">
                            <div><dt>Original Settlement Date</dt><dd>${escHtml(settleRecord.settlement_date || '—')}</dd></div>
                            <div><dt>Repudiation Date</dt><dd>${escHtml(settleRecord.repudiation_date || 'Recorded')}</dd></div>
                            ${settleRecord.repudiation_reason ? `<div style="grid-column:1 / -1;"><dt>Repudiation Reason</dt><dd style="font-weight:500;">${escHtml(settleRecord.repudiation_reason)}</dd></div>` : ''}
                        </dl>
                    </div>
                `;
            } else if (arbRecord || /arbitration/i.test(stage)) {
                outcomeHtml = `
                    <div class="rd-detail-section" style="background:#f0fdf4; border-color:#bbf7d0;">
                        <h4>Arbitration Settlement Record</h4>
                        <dl class="rd-detail-grid">
                            <div><dt>Agreement Date</dt><dd>${escHtml(arbRecord?.agreement_date || '—')}</dd></div>
                            <div><dt>Award Date</dt><dd>${escHtml(arbRecord?.award_date || 'Pending Award')}</dd></div>
                            ${arbRecord?.award_details ? `<div style="grid-column:1 / -1;"><dt>Award Summary</dt><dd style="font-weight:500;">${escHtml(arbRecord.award_details)}</dd></div>` : ''}
                        </dl>
                    </div>
                `;
            } else if (settleRecord || /settled/i.test(stage)) {
                outcomeHtml = `
                    <div class="rd-detail-section" style="background:#f0fdf4; border-color:#bbf7d0;">
                        <h4>Amicable Settlement Record</h4>
                        <dl class="rd-detail-grid">
                            <div><dt>Settlement Date</dt><dd>${escHtml(settleRecord?.settlement_date || '—')}</dd></div>
                            <div><dt>Repudiation / Status</dt><dd>${escHtml(settleRecord?.repudiation_status || settleRecord?.compliance_status || 'Settled')}</dd></div>
                            ${settleRecord?.agreement_details ? `<div style="grid-column:1 / -1;"><dt>Agreed Terms Summary</dt><dd style="font-weight:500;">${escHtml(settleRecord.agreement_details)}</dd></div>` : ''}
                        </dl>
                    </div>
                `;
            }

            const reviewNoteHtml = detail.review_notes
                ? `<div class="alert alert-info" style="display:block; margin:0;"><strong>Barangay Review Remarks:</strong> ${escHtml(detail.review_notes)}</div>`
                : '';

            let aiBlockHtml = '';
            const aiSug = detail.ai_suggestion || null;
            if (aiSug && aiSug.resident_viewed && aiSug.primary && aiSug.primary.category_name) {
                const aiSrcLabel = aiSug.source === 'gemini_ai' ? 'Gemini AI' : 'Local Advisory Match';
                const confExplain = getAiConfidenceExplanation(aiSug.primary.confidence || 'Low', storyLang);
                const secAiSug = (aiSug.secondary && aiSug.secondary.category_name) ? aiSug.secondary : null;
                aiBlockHtml = `
                    <div class="rd-detail-section" style="background:#eff6ff; border-color:#bfdbfe;">
                        <h4>AI Suggestion (Read-Only Preview &bull; ${escHtml(aiSrcLabel)})</h4>
                        <dl class="rd-detail-grid">
                            <div><dt>${secAiSug ? 'Suggested Category 1' : 'Suggested Category'}</dt><dd>${escHtml(aiSug.primary.category_name)}</dd></div>
                            <div><dt>Dispute Nature</dt><dd>${escHtml(aiSug.case_type || 'Civil')}${aiSug.primary.confidence ? ` &bull; ${escHtml(aiSug.primary.confidence)} Confidence` : ''}</dd></div>
                            ${secAiSug ? `<div><dt>Suggested Category 2</dt><dd>${escHtml(secAiSug.category_name)}${secAiSug.case_type ? ` (${escHtml(secAiSug.case_type)})` : ''}</dd></div>` : ''}
                            ${aiSug.primary.reason ? `<div style="grid-column:1 / -1;"><dt>AI Reasoning${secAiSug ? ' (Category 1)' : ''}</dt><dd style="font-weight:500;">${escHtml(aiSug.primary.reason)}</dd></div>` : ''}
                            ${(secAiSug && secAiSug.reason) ? `<div style="grid-column:1 / -1;"><dt>AI Reasoning (Category 2)</dt><dd style="font-weight:500;">${escHtml(secAiSug.reason)}</dd></div>` : ''}
                            <div style="grid-column:1 / -1;"><dt>About This Suggestion</dt><dd style="font-weight:500; color:#334155;">${escHtml(confExplain)}</dd></div>
                        </dl>
                    </div>
                `;
            }

            const attachments = Array.isArray(detail.attachments) ? detail.attachments : [];
            const attachHtml = attachments.length > 0
                ? `<div class="rd-detail-section"><h4>Supporting Files (${attachments.length})</h4><div style="display:flex; flex-wrap:wrap; gap:8px;">${attachments.map((a) => `<a href="../../../backend/api/complaints/download-attachment.php?id=${Number(a.attachment_id)}" target="_blank" class="rd-chip-btn" style="text-decoration:none;">${escHtml(a.file_name || 'Attachment')}</a>`).join('')}</div></div>`
                : '';

            if (detailBodyEl) {
                detailBodyEl.innerHTML = `
                    <div class="rd-progress-card">
                        <div class="rd-progress-card-title">Workflow Progress</div>
                        ${buildDetailProgressLineHtml(detail)}
                    </div>
                    ${reviewNoteHtml}
                    ${buildResidentSchedulesHtml(detail, stage, isDraft)}
                    <div class="rd-detail-section">
                        <h4>Case Details &amp; Parties</h4>
                        <dl class="rd-detail-grid">
                            <div><dt>Reference / Case No.</dt><dd>${escHtml(detail.case_number ? `${detail.case_number} (${detail.complaint_number || ref})` : (detail.complaint_number || ref))}</dd></div>
                            <div><dt>Current Stage</dt><dd>${escHtml(stage)}</dd></div>
                            <div><dt>Complainant (Nagsusumbong)</dt><dd>${escHtml(complainants.join(', ') || 'You')}</dd></div>
                            <div><dt>Respondent (Inirereklamo)</dt><dd>${escHtml(respondents.join(', ') || '—')}</dd></div>
                            <div><dt>Dispute Nature &amp; Classification</dt><dd>${escHtml(`${detail.case_type || 'Civil'} • ${catDisplay}`)}</dd></div>
                            <div><dt>Date Filed / Docketed</dt><dd>${escHtml(`${(detail.created_at || '').slice(0, 10) || '—'} / ${docketDateStr}`)}</dd></div>
                            <div><dt>Date &amp; Time of Incident</dt><dd>${escHtml(incDt)}</dd></div>
                            <div><dt>Incident Location</dt><dd>${escHtml(locStr)}</dd></div>
                            ${assignedHtml ? `<div style="grid-column:1 / -1;"><dt>Assigned Barangay Lupon / Panel</dt><dd>${escHtml(assignedHtml)}</dd></div>` : ''}
                        </dl>
                    </div>
                    ${outcomeHtml}
                    <div class="rd-detail-section">
                        <h4>Your Complaint Narrative</h4>
                        <div class="rd-detail-story-box">${escHtml(detail.narrative || 'No narrative provided.')}</div>
                        ${detail.requested_resolution ? `<div style="margin-top:10px;"><strong style="font-size:0.75rem; color:#64748b; text-transform:uppercase;">Requested Resolution</strong><div class="rd-detail-story-box" style="margin-top:4px;">${escHtml(detail.requested_resolution)}</div></div>` : ''}
                        ${detail.additional_details ? `<div style="margin-top:10px;"><strong style="font-size:0.75rem; color:#64748b; text-transform:uppercase;">Additional Notes</strong><div class="rd-detail-story-box" style="margin-top:4px;">${escHtml(detail.additional_details)}</div></div>` : ''}
                    </div>
                    ${aiBlockHtml}
                    ${attachHtml}
                `;
            }

            if (detailFootFormsEl) {
                if (isResidentKp7Ready(detail)) {
                    detailFootFormsEl.innerHTML = `<a href="../../../backend/api/complaints/kp7-preview.php?id=${compIdNum}" target="_blank" style="font-weight:700; color:#1d4ed8; text-decoration:none; margin-right:12px;">View KP Form 7</a><a href="../../../backend/api/complaints/kp7-preview.php?id=${compIdNum}&download=1" style="font-weight:600; color:#475569; text-decoration:none;">Download PDF</a>`;
                } else if (isDraft) {
                    detailFootFormsEl.innerHTML = `<span style="margin-right:10px;">Saved as draft &mdash; not yet submitted for review.</span><button type="button" class="rd-wizard-btn-primary" data-edit-draft-id="${compIdNum}" style="padding:6px 13px; font-size:0.8rem;">Edit &amp; Submit Draft &rarr;</button>`;
                } else if (/^Rejected$/i.test(String(detail.status || ''))) {
                    detailFootFormsEl.textContent = 'Complaint was declined during review; KP Form 7 not issued.';
                } else {
                    detailFootFormsEl.textContent = 'KP Form 7 will be generated once reviewed and accepted.';
                }
            }
        } catch (_) {
            if (detailBodyEl) {
                detailBodyEl.innerHTML = '<div class="alert alert-error" style="display:block;">Connection error while loading complaint details.</div>';
            }
        }
    }

    document.getElementById('btnPortalDetailClose')?.addEventListener('click', closeResidentComplaintDetails);
    document.getElementById('btnPortalDetailDone')?.addEventListener('click', closeResidentComplaintDetails);
    detailModalEl?.addEventListener('click', (e) => {
        if (e.target === detailModalEl) closeResidentComplaintDetails();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && detailModalEl?.classList.contains('is-open')) {
            closeResidentComplaintDetails();
        }
    });
    document.addEventListener('click', (e) => {
        const editDraftTrigger = e.target.closest('[data-edit-draft-id]');
        if (editDraftTrigger) {
            e.preventDefault();
            closeResidentComplaintDetails();
            if (typeof startEditingDraft === 'function') {
                startEditingDraft(editDraftTrigger.getAttribute('data-edit-draft-id'));
            }
            return;
        }
        const viewTrigger = e.target.closest('[data-view-complaint-id]');
        if (viewTrigger) {
            e.preventDefault();
            openResidentComplaintDetails(viewTrigger.getAttribute('data-view-complaint-id'));
        }
    });

    function setComplaintFilter(filterKey) {
        activeComplaintFilter = filterKey || 'all';
        document.querySelectorAll('[data-rd-filter]').forEach((pill) => {
            pill.classList.toggle('active', pill.getAttribute('data-rd-filter') === activeComplaintFilter);
        });
        document.querySelectorAll('.rd-stats-grid .stat-card').forEach((card) => {
            card.classList.toggle('is-active', card.getAttribute('data-kpi-filter') === activeComplaintFilter);
        });
        renderComplaintsTable();
    }

    document.querySelectorAll('[data-rd-filter]').forEach((pill) => {
        pill.addEventListener('click', () => setComplaintFilter(pill.getAttribute('data-rd-filter') || 'all'));
    });
    listSearchInput?.addEventListener('input', () => {
        if (globalSearchInput) globalSearchInput.value = listSearchInput.value;
        renderComplaintsTable();
    });

    async function loadMyComplaints() {
        const tbody = document.getElementById('myComplaintsTbody');
        try {
            const resp = await fetch('../../../backend/api/complaints/list.php', { headers: { Accept: 'application/json' } });
            const rows = await resp.json();
            cachedComplaints = Array.isArray(rows) ? rows : [];
            updateDashboardMetrics(cachedComplaints);
            renderComplaintsTable();
        } catch (_) {
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; color:#b91c1c;">Unable to load complaints right now.</td></tr>';
            }
            const recentEl = document.getElementById('rdHomeRecentList');
            if (recentEl) {
                recentEl.innerHTML = '<div style="padding:14px 0; color:#b91c1c; font-size:0.85rem;">Unable to load recent complaints right now.</div>';
            }
        }
    }

    const portalForm = document.getElementById('residentComplaintForm');
    const draftInput = document.getElementById('portalIsDraft');
    const resultBanner = document.getElementById('portalResultBanner');
    let currentWizardStep = 1;
    const totalWizardSteps = 4;

    const narrativeEl = document.getElementById('portalNarrative');
    const resolutionInputEl = document.getElementById('portalResolution');
    const autoTitleHiddenEl = document.getElementById('portalAutoTitle');
    let lastAiCoverage = null;
    let activeDraftExistingAttachments = [];

    function updateStoryCoverageChips(text) {
        const val = String(text ?? narrativeEl?.value ?? '');
        if (window.AgapNarrativeChecklist) {
            window.AgapNarrativeChecklist.bindAll?.();
            window.AgapNarrativeChecklist.refresh('#portalNarrative', lastAiCoverage || null);
            return;
        }
    }

    function syncGuidedNarrative() {
        const rawText = String(narrativeEl?.value || '').trim();
        if (autoTitleHiddenEl && autoTitleHiddenEl.dataset.fromAi !== '1') {
            const cleanBody = rawText
                .replace(/^(?:Ano ang Nangyari|What Happened)\s*:\s*/i, '')
                .split(/\r?\n/)[0]
                ?.replace(/\s+/g, ' ')
                .trim() || '';
            autoTitleHiddenEl.value = cleanBody
                ? (cleanBody.length > 80 ? `${cleanBody.slice(0, 77).trimEnd()}...` : cleanBody)
                : '';
        }
        updateStoryCoverageChips(rawText);
    }

    narrativeEl?.addEventListener('input', () => {
        lastAiCoverage = null;
        syncGuidedNarrative();
    });
    narrativeEl?.addEventListener('blur', syncGuidedNarrative);

    // Step 3 Voice-to-Text (Tagalog / English) + AI Complaint Type Identifier
    const voiceBtnEl = document.getElementById('btnPortalVoiceDictate');
    const voiceBtnLabelEl = document.getElementById('portalVoiceBtnLabel');
    const voiceLiveTagEl = document.getElementById('portalVoiceLiveTag');
    const identifyBtnEl = document.getElementById('btnPortalIdentifyComplaint');
    const geminiConsentEl = document.getElementById('portalStoryGeminiConsent');
    const storyAiStatusEl = document.getElementById('portalStoryAiStatus');
    const addDetailsEl = document.getElementById('portalAdditionalDetails');

    const portalCatIdEl = document.getElementById('portalCatId');
    const portalOtherCatSpecEl = document.getElementById('portalOtherCatSpec');
    const portalCaseTypeEl = document.getElementById('portalCaseType');
    const portalAiUsedEl = document.getElementById('portalAiUsed');
    const portalAiVerifiedHiddenEl = document.getElementById('portalAiVerifiedHidden');
    const portalAiSugCatIdEl = document.getElementById('portalAiSuggestedCatId');
    const portalAiSugTitleEl = document.getElementById('portalAiSuggestedTitle');
    const portalAiSugPayloadEl = document.getElementById('portalAiSuggestionPayload');
    const portalSuggestBoxEl = document.getElementById('portalIdentifySuggestionBox');
    const portalAiSourceTagEl = document.getElementById('portalAiSourceTag');
    const portalAiReadonlySummaryEl = document.getElementById('portalAiReadonlySummary');
    const portalAiReasonEl = document.getElementById('portalAiReasonText');
    const portalAiExplainEl = document.getElementById('portalAiConfidenceExplain');
    const portalAiJurisdictionEl = document.getElementById('portalAiJurisdictionText');
    let hasClickedAiSuggestion = false;
    let currentReadonlyAiSuggestion = null;

    function getAiConfidenceExplanation(confidence, lang = 'en') {
        const key = String(confidence || '').trim().toLowerCase();
        if (lang === 'tl') {
            if (key === 'high') {
                return 'Kahulugan ng High Confidence: Malinaw na tumutugma ang iyong salaysay sa kategoryang ito. Susuriin pa rin ito ng Barangay Lupon para sa opisyal na kumpirmasyon.';
            }
            if (key === 'medium') {
                return 'Kahulugan ng Medium Confidence: Ilang detalye o salita sa iyong kuwento ang tumugma sa kategoryang ito. Susuriin pa rin ng Barangay Lupon ang buong salaysay.';
            }
            return 'Kahulugan ng Low Confidence: Isang paunang palatandaan (keyword) lang ang awtomatikong nakita sa iyong kuwento. Tanggap pa rin ang iyong reklamo — susuriin at ikukumpirma ng Barangay Lupon ang tamang kategorya.';
        }
        if (key === 'high') {
            return 'What High Confidence means: Your narrative strongly matches this complaint category. Barangay Lupon officials will still review and confirm it officially.';
        }
        if (key === 'medium') {
            return 'What Medium Confidence means: Several details in your story matched this category. Barangay Lupon officials will still verify your full story before finalizing.';
        }
        return 'What Low Confidence means: Only a single or limited keyword clue matched automatically in your story (this does not affect your complaint validity). Barangay Lupon officials will review your full narrative to confirm the exact category.';
    }

    let storyLang = 'tl';
    const SpeechRecCtor = window.SpeechRecognition || window.webkitSpeechRecognition || null;
    let activeSpeechRec = null;
    let activeMediaRec = null;
    let activeMediaStream = null;
    let activeMediaChunks = [];
    let isListeningVoice = false;

    function setStoryAiStatus(msg, tone = '') {
        if (!storyAiStatusEl) return;
        if (!msg) {
            storyAiStatusEl.style.display = 'none';
            storyAiStatusEl.textContent = '';
            storyAiStatusEl.className = 'rd-voice-status';
            return;
        }
        storyAiStatusEl.className = 'rd-voice-status' + (tone ? ` is-${tone}` : '');
        storyAiStatusEl.textContent = msg;
        storyAiStatusEl.style.display = 'block';
    }

    function updateVoiceUiState(listening) {
        isListeningVoice = Boolean(listening);
        if (voiceBtnEl) voiceBtnEl.classList.toggle('is-recording', isListeningVoice);
        if (voiceBtnLabelEl) {
            if (isListeningVoice) {
                voiceBtnLabelEl.textContent = storyLang === 'tl' ? 'Ihinto ang Pag-record' : 'Stop Speaking';
            } else {
                voiceBtnLabelEl.textContent = storyLang === 'tl' ? 'Magsalita (Tagalog)' : 'Speak (English)';
            }
        }
        if (voiceLiveTagEl) {
            voiceLiveTagEl.textContent = storyLang === 'tl' ? 'Nakikinig (Tagalog)...' : 'Listening (English)...';
            voiceLiveTagEl.style.display = isListeningVoice ? 'inline-block' : 'none';
        }
        if (narrativeEl && !isListeningVoice) {
            narrativeEl.placeholder = storyLang === 'tl'
                ? "Pindutin ang 'Magsalita' o i-type dito ang buong kuwento sa Tagalog o English: ano ang ginawa sa iyo, bakit nangyari, sino ang nakakita o epekto, at anong resolusyon ang hiling mo..."
                : "Tap 'Speak' or type your full complaint story in English or Tagalog: what they did, why it started, witnesses/impact, and what resolution you seek...";
        }
    }

    function stopActiveVoiceCapture() {
        if (activeSpeechRec) {
            try { activeSpeechRec.stop(); } catch (_) {}
            activeSpeechRec = null;
        }
        if (activeMediaRec && activeMediaRec.state !== 'inactive') {
            try { activeMediaRec.stop(); } catch (_) {}
        }
        updateVoiceUiState(false);
    }

    document.querySelectorAll('[data-story-lang]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const nextLang = btn.getAttribute('data-story-lang') === 'en' ? 'en' : 'tl';
            if (nextLang !== storyLang && isListeningVoice) {
                stopActiveVoiceCapture();
            }
            storyLang = nextLang;
            document.querySelectorAll('[data-story-lang]').forEach((b) => {
                b.classList.toggle('is-active', b.getAttribute('data-story-lang') === storyLang);
            });
            updateVoiceUiState(false);
        });
    });

    async function startGeminiAudioFallback() {
        if (!geminiConsentEl?.checked) {
            const ok = window.confirm(
                storyLang === 'tl'
                    ? 'Hindi sinusuportahan ng browser ang live speech-to-text. Nais mo bang i-record ang iyong boses at ipasa sa Google Gemini AI upang gawing teksto?'
                    : 'Live browser speech-to-text is unavailable here. Record voice audio and send it to Google Gemini AI for Tagalog/English transcription?'
            );
            if (!ok) {
                setStoryAiStatus('Live browser dictation is unavailable here. You can type your story directly or check Google Gemini consent to record audio.', 'warn');
                return;
            }
            if (geminiConsentEl) geminiConsentEl.checked = true;
        }

        if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
            setStoryAiStatus('Microphone recording is not supported in this browser. Please type your story in the draft box instead.', 'error');
            return;
        }

        try {
            activeMediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            activeMediaChunks = [];
            activeMediaRec = new MediaRecorder(activeMediaStream);
            activeMediaRec.ondataavailable = (ev) => {
                if (ev.data && ev.data.size > 0) activeMediaChunks.push(ev.data);
            };
            activeMediaRec.onstop = async () => {
                activeMediaStream?.getTracks().forEach((t) => t.stop());
                activeMediaStream = null;
                updateVoiceUiState(false);
                if (voiceBtnEl) voiceBtnEl.disabled = true;
                setStoryAiStatus(storyLang === 'tl' ? 'Isinasalin ang boses sa teksto...' : 'Transcribing voice recording...', '');
                try {
                    const mime = activeMediaRec?.mimeType || 'audio/webm';
                    const blob = new Blob(activeMediaChunks, { type: mime });
                    const ext = mime.includes('ogg') ? 'ogg' : (mime.includes('mp4') ? 'm4a' : 'webm');
                    const fd = new FormData();
                    fd.append('audio', blob, `complaint_story.${ext}`);
                    fd.append('external_ai_consent', '1');
                    fd.append('language', storyLang);
                    const resp = await fetch('../../../backend/api/ai/transcribe-audio.php', {
                        method: 'POST',
                        headers: { Accept: 'application/json' },
                        body: fd
                    });
                    const res = await resp.json();
                    const text = String(res.text || res.transcript || '').trim();
                    if (res.success && text && narrativeEl) {
                        const prev = narrativeEl.value.trim();
                        narrativeEl.value = prev ? `${prev}\n${text}` : text;
                        lastAiCoverage = null;
                        syncGuidedNarrative();
                        setStoryAiStatus(
                            storyLang === 'tl'
                                ? 'Nailagay na ang salaysay mula sa boses. Opsyonal na pindutin ang "AI Suggestion" para sa read-only na mungkahi, o isumite agad.'
                                : 'Voice transcript added above. Optionally tap "AI Suggestion" for a read-only preview, or submit directly.',
                            'success'
                        );
                    } else {
                        setStoryAiStatus(res.message || 'Unable to transcribe audio recording. You may type your story directly.', 'warn');
                    }
                } catch (_) {
                    setStoryAiStatus('Connection error while transcribing voice recording.', 'error');
                } finally {
                    activeMediaRec = null;
                    if (voiceBtnEl) voiceBtnEl.disabled = false;
                }
            };
            activeMediaRec.start();
            updateVoiceUiState(true);
            setStoryAiStatus(storyLang === 'tl' ? 'Nagrerecord... Pindutin ang "Ihinto ang Pag-record" kapag tapos na.' : 'Recording audio... Tap "Stop Speaking" when finished.', '');
        } catch (_) {
            updateVoiceUiState(false);
            setStoryAiStatus('Microphone access denied or unavailable.', 'error');
        }
    }

    voiceBtnEl?.addEventListener('click', () => {
        if (isListeningVoice) {
            stopActiveVoiceCapture();
            return;
        }
        setStoryAiStatus('');

        if (!SpeechRecCtor) {
            startGeminiAudioFallback();
            return;
        }

        const baseText = narrativeEl ? narrativeEl.value.trim() : '';
        let committedSpeech = '';

        try {
            const rec = new SpeechRecCtor();
            activeSpeechRec = rec;
            rec.lang = storyLang === 'tl' ? 'fil-PH' : 'en-PH';
            rec.continuous = true;
            rec.interimResults = true;

            rec.onstart = () => {
                updateVoiceUiState(true);
            };
            rec.onresult = (event) => {
                let interimText = '';
                for (let i = event.resultIndex; i < event.results.length; i++) {
                    const piece = String(event.results[i][0]?.transcript || '');
                    if (event.results[i].isFinal) {
                        committedSpeech = (committedSpeech + ' ' + piece).trim();
                    } else {
                        interimText += piece;
                    }
                }
                if (narrativeEl) {
                    const combinedSpeech = [committedSpeech, interimText.trim()].filter(Boolean).join(' ');
                    narrativeEl.value = [baseText, combinedSpeech].filter(Boolean).join('\n');
                    lastAiCoverage = null;
                    syncGuidedNarrative();
                }
            };
            rec.onerror = (ev) => {
                const err = String(ev?.error || '');
                activeSpeechRec = null;
                updateVoiceUiState(false);
                if (err === 'not-allowed' || err === 'permission-denied') {
                    setStoryAiStatus(
                        storyLang === 'tl'
                            ? 'Walang pahintulot sa mikropono. Payagan ang microphone sa browser o i-type ang kuwento.'
                            : 'Microphone permission denied. Allow microphone access in your browser or type your story directly.',
                        'error'
                    );
                } else if (err === 'service-not-allowed' || err === 'language-not-supported' || err === 'network') {
                    startGeminiAudioFallback();
                } else if (err && err !== 'aborted' && err !== 'no-speech') {
                    setStoryAiStatus(`Speech recognition stopped (${err}). You can type or tap Magsalita again.`, 'warn');
                }
            };
            rec.onend = () => {
                if (activeSpeechRec === rec) {
                    activeSpeechRec = null;
                }
                updateVoiceUiState(false);
                if (narrativeEl && narrativeEl.value.trim().length >= 10) {
                    setStoryAiStatus(
                        storyLang === 'tl'
                            ? 'Tapos na ang pag-record. Opsyonal na pindutin ang "AI Suggestion" para sa read-only na mungkahi, o isumite agad.'
                            : 'Speech captured. Optionally tap "AI Suggestion" for a read-only preview, or submit directly.',
                        'success'
                    );
                }
            };
            rec.start();
        } catch (_) {
            activeSpeechRec = null;
            updateVoiceUiState(false);
            startGeminiAudioFallback();
        }
    });

    function resetAiSuggestionSelection(hideCard = true) {
        hasClickedAiSuggestion = false;
        currentReadonlyAiSuggestion = null;
        if (portalCatIdEl) portalCatIdEl.value = '';
        if (portalOtherCatSpecEl) portalOtherCatSpecEl.value = '';
        if (portalCaseTypeEl) portalCaseTypeEl.value = 'Civil';
        if (portalAiUsedEl) portalAiUsedEl.value = '0';
        if (portalAiVerifiedHiddenEl) portalAiVerifiedHiddenEl.value = '0';
        if (portalAiSugCatIdEl) portalAiSugCatIdEl.value = '';
        if (portalAiSugTitleEl) portalAiSugTitleEl.value = '';
        if (portalAiSugPayloadEl) portalAiSugPayloadEl.value = '';
        if (portalSuggestBoxEl && hideCard) portalSuggestBoxEl.style.display = 'none';
        if (portalAiReadonlySummaryEl && hideCard) portalAiReadonlySummaryEl.innerHTML = '';
        if (portalAiReasonEl && hideCard) portalAiReasonEl.textContent = '';
        if (portalAiExplainEl && hideCard) {
            portalAiExplainEl.style.display = 'none';
            portalAiExplainEl.textContent = '';
        }
        if (portalAiJurisdictionEl && hideCard) {
            portalAiJurisdictionEl.style.display = 'none';
            portalAiJurisdictionEl.textContent = '';
        }
        if (autoTitleHiddenEl) autoTitleHiddenEl.dataset.fromAi = '';
        syncGuidedNarrative();
    }

    identifyBtnEl?.addEventListener('click', async () => {
        if (isListeningVoice) {
            stopActiveVoiceCapture();
        }
        const narrativeText = narrativeEl ? narrativeEl.value.trim() : '';
        if (narrativeText.length < 10) {
            setStoryAiStatus(
                storyLang === 'tl'
                    ? 'Magkuwento o mag-type muna ng kahit 10 letra sa Your Complaint Narrative bago pindutin ang AI Suggestion.'
                    : 'Please speak or enter at least 10 characters in Your Complaint Narrative first.',
                'warn'
            );
            narrativeEl?.focus();
            return;
        }

        const useGemini = Boolean(geminiConsentEl?.checked);
        identifyBtnEl.disabled = true;
        setStoryAiStatus(
            storyLang === 'tl' ? 'Sinusuri ang iyong salaysay para sa AI suggestion...' : 'Analyzing your story for a read-only AI suggestion...',
            ''
        );

        try {
            const resp = await fetch('../../../backend/api/ai/classify-complaint.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    context: 'resident_portal',
                    processing_mode: useGemini ? 'gemini' : 'local_only',
                    external_ai_consent: useGemini ? '1' : '0',
                    narrative: narrativeText,
                    requested_resolution: resolutionInputEl?.value?.trim() || ''
                })
            });
            const data = await resp.json();
            if (!resp.ok || !data.success || !data.suggestion || !data.suggestion.primary) {
                setStoryAiStatus(
                    data.message || 'Unable to generate an AI suggestion right now. Uncheck Gemini to use instant keyword matching.',
                    resp.status === 429 ? 'warn' : 'error'
                );
                return;
            }

            const sug = data.suggestion;
            const primary = sug.primary;
            const defaultCaseType = (sug.case_type === 'Criminal' || sug.case_type === 'Civil') ? sug.case_type : 'Civil';
            const cleanPayload = {
                primary: {
                    category_id: Number(primary.category_id),
                    category_name: String(primary.category_name || ''),
                    confidence: String(primary.confidence || 'Medium'),
                    reason: String(primary.reason || ''),
                },
                case_type: defaultCaseType,
                suggested_title: String(sug.suggested_title || ''),
                alternatives: Array.isArray(sug.alternatives) ? sug.alternatives : [],
                secondary: sug.secondary && sug.secondary.category_id ? sug.secondary : null,
                jurisdiction_advisory: String(sug.jurisdiction_advisory || ''),
                source: String(data.source || 'keyword_fallback'),
            };

            renderReadonlyAiSuggestion(cleanPayload);
            const matchedCatsLabel = cleanPayload.secondary?.category_name
                ? `${cleanPayload.primary.category_name} + ${cleanPayload.secondary.category_name}`
                : cleanPayload.primary.category_name;
            setStoryAiStatus(
                storyLang === 'tl'
                    ? `Ipinakita ang read-only AI Suggestion (${matchedCatsLabel}). Susuriin ito ng Lupon kapag isinumite mo.`
                    : `Read-only AI suggestion shown below (${matchedCatsLabel}). Lupon officials will review it when submitted.`,
                'success'
            );
        } catch (_) {
            setStoryAiStatus('Connection error while generating AI suggestion.', 'error');
        } finally {
            identifyBtnEl.disabled = false;
        }
    });

    function renderReadonlyAiSuggestion(cleanPayload) {
        if (!cleanPayload || !cleanPayload.primary || !cleanPayload.primary.category_id) return;
        const primary = cleanPayload.primary;
        const secondary = (cleanPayload.secondary && cleanPayload.secondary.category_id && cleanPayload.secondary.category_name)
            ? cleanPayload.secondary
            : null;
        const defaultCaseType = (cleanPayload.case_type === 'Criminal' || cleanPayload.case_type === 'Civil') ? cleanPayload.case_type : 'Civil';
        hasClickedAiSuggestion = true;
        currentReadonlyAiSuggestion = cleanPayload;
        if (portalAiSugCatIdEl) portalAiSugCatIdEl.value = String(primary.category_id || '');
        if (portalAiSugTitleEl) portalAiSugTitleEl.value = String(cleanPayload.suggested_title || '');
        if (portalAiSugPayloadEl) portalAiSugPayloadEl.value = JSON.stringify(cleanPayload);
        if (portalAiSourceTagEl) {
            portalAiSourceTagEl.textContent = cleanPayload.source === 'gemini_ai' ? 'Source: Gemini AI' : 'Source: Local Advisory Match';
        }

        if (portalAiReadonlySummaryEl) {
            const secCaseType = secondary?.case_type || defaultCaseType;
            const secConfidence = secondary?.confidence || primary.confidence || 'Low';
            const secHtml = secondary
                ? `<div class="rd-ai-readonly-row" style="margin-top:6px; padding-top:6px; border-top:1px dashed #cbd5e1;">
                        <span style="font-size:0.74rem; font-weight:700; color:#475569; text-transform:uppercase;">2nd Category:</span>
                        <strong style="font-size:0.88rem; color:#102b2d;">${escHtml(secondary.category_name)}</strong>
                        <span class="rd-ai-cand-meta">${escHtml(secCaseType)}</span>
                        <span class="rd-ai-cand-meta">${escHtml(secConfidence)} Confidence</span>
                   </div>`
                : '';
            const subjHtml = cleanPayload.suggested_title
                ? `<div class="rd-ai-readonly-row"><span style="font-size:0.78rem; color:#475569;">Suggested Subject:</span> <span style="font-size:0.82rem; font-weight:600; color:#1e293b;">${escHtml(cleanPayload.suggested_title)}</span></div>`
                : '';
            portalAiReadonlySummaryEl.innerHTML = `
                <div class="rd-ai-readonly-row">
                    ${secondary ? '<span style="font-size:0.74rem; font-weight:700; color:#475569; text-transform:uppercase;">1st Category:</span>' : ''}
                    <strong style="font-size:0.9rem; color:#102b2d;">${escHtml(primary.category_name)}</strong>
                    <span class="rd-ai-cand-meta">${escHtml(defaultCaseType)}</span>
                    <span class="rd-ai-cand-meta">${escHtml(primary.confidence || 'Medium')} Confidence</span>
                </div>
                ${secHtml}
                ${subjHtml}
            `;
        }

        if (portalAiReasonEl) {
            const primaryReason = primary.reason
                ? `AI Reasoning${secondary ? ' (1st Category)' : ''}: ${primary.reason}`
                : 'Advisory category identified from the details in your story.';
            const secReason = (secondary && secondary.reason)
                ? ` | 2nd Category Reasoning: ${secondary.reason}`
                : '';
            portalAiReasonEl.textContent = primaryReason + secReason;
        }

        if (portalAiExplainEl) {
            portalAiExplainEl.textContent = getAiConfidenceExplanation(primary.confidence, storyLang);
            portalAiExplainEl.style.display = 'block';
        }

        if (portalAiJurisdictionEl) {
            if (cleanPayload.jurisdiction_advisory) {
                portalAiJurisdictionEl.textContent = `Advisory Note (For Barangay Review): ${cleanPayload.jurisdiction_advisory}`;
                portalAiJurisdictionEl.style.display = 'block';
            } else {
                portalAiJurisdictionEl.style.display = 'none';
            }
        }

        if (portalSuggestBoxEl) portalSuggestBoxEl.style.display = 'block';
    }

    function validateWizardPane(stepNum) {
        const pane = document.querySelector(`[data-wizard-pane="${stepNum}"]`);
        if (!pane) return true;
        const fields = Array.from(pane.querySelectorAll('input, select, textarea'));
        let firstInvalid = null;

        for (const el of fields) {
            if (el.disabled || el.type === 'hidden') continue;
            // Validate native rules + AgapFieldValidator even before focusing
            let isOk = true;
            if (window.AgapFieldValidator && typeof window.AgapFieldValidator.validateElement === 'function') {
                isOk = window.AgapFieldValidator.validateElement(el, true);
            } else {
                isOk = el.checkValidity();
                if (!isOk) el.reportValidity();
            }
            if (!isOk && !firstInvalid) {
                firstInvalid = el;
            }
        }

        if (stepNum === 2 && respFullNameEl) {
            const parts = syncRespondentNameParts();
            if (parts.length < 2) {
                const msg = "Please enter the respondent's full name (first and last name) for barangay summons.";
                if (window.AgapFieldValidator?.renderFieldError) {
                    window.AgapFieldValidator.renderFieldError(respFullNameEl, msg);
                } else {
                    respFullNameEl.setCustomValidity(msg);
                    respFullNameEl.reportValidity();
                }
                if (!firstInvalid) firstInvalid = respFullNameEl;
            }
        }

        if (firstInvalid) {
            firstInvalid.focus({ preventScroll: false });
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }
        return true;
    }

    function renderComplaintReviewSummary() {
        const host = document.getElementById('rdWizardReviewContent');
        if (!host) return;
        syncGuidedNarrative();
        const dtRaw = document.getElementById('portalIncidentDatetime')?.value || '';
        const dtFormatted = dtRaw ? dtRaw.replace('T', ' ') : '—';
        const filesCount = document.getElementById('portalEvidence')?.files?.length || 0;
        const savedFilesCount = Array.isArray(activeDraftExistingAttachments) ? activeDraftExistingAttachments.length : 0;
        let attachedFilesLabel = 'None';
        if (savedFilesCount > 0 && filesCount > 0) {
            attachedFilesLabel = `${savedFilesCount} saved + ${filesCount} new file(s)`;
        } else if (savedFilesCount > 0) {
            attachedFilesLabel = `${savedFilesCount} saved file(s) from draft`;
        } else if (filesCount > 0) {
            attachedFilesLabel = `${filesCount} file(s) attached`;
        }
        const claimAmt = document.getElementById('portalFineMax')?.value?.trim() || '';
        const extraNotes = document.getElementById('portalAdditionalDetails')?.value?.trim() || '';

        const aiSecSug = currentReadonlyAiSuggestion?.secondary?.category_name ? currentReadonlyAiSuggestion.secondary : null;
        const aiSugSummary = (hasClickedAiSuggestion && currentReadonlyAiSuggestion?.primary?.category_name)
            ? `${currentReadonlyAiSuggestion.primary.category_name} (${currentReadonlyAiSuggestion.case_type || 'Civil'})${aiSecSug ? ` + ${aiSecSug.category_name} (${aiSecSug.case_type || currentReadonlyAiSuggestion.case_type || 'Civil'})` : ''}`
            : '';
        const aiReviewRowHtml = aiSugSummary
            ? `<div class="rd-review-full"><dt>AI Suggestion (Read-Only)</dt><dd>${escHtml(aiSugSummary)} &mdash; Advisory only; final classification confirmed by Barangay Lupon / LISO</dd></div>`
            : `<div class="rd-review-full"><dt>Complaint Classification</dt><dd>To be classified by Barangay Tumana Lupon / LISO (For Review)</dd></div>`;

        host.innerHTML = `
            <div class="rd-review-box">
                <div class="rd-review-box-head">
                    <strong>1. Complainant Details</strong>
                    <button type="button" class="rd-review-edit-btn" data-wizard-goto="1">Edit</button>
                </div>
                <dl class="rd-review-dl">
                    <div><dt>Full Name</dt><dd>${escHtml(document.getElementById('portalCompFullName')?.value || '—')}</dd></div>
                    <div><dt>Contact Number</dt><dd>${escHtml(document.getElementById('portalCompContact')?.value || '—')}</dd></div>
                    <div class="rd-review-full"><dt>Address &amp; Purok</dt><dd>${escHtml([document.getElementById('portalCompAddress')?.value?.trim(), document.getElementById('portalCompPurok')?.value].filter(Boolean).join(' • ') || '—')}</dd></div>
                </dl>
            </div>
            <div class="rd-review-box">
                <div class="rd-review-box-head">
                    <strong>2. Respondent Details (For Summons)</strong>
                    <button type="button" class="rd-review-edit-btn" data-wizard-goto="2">Edit</button>
                </div>
                <dl class="rd-review-dl">
                    <div><dt>Respondent Full Name</dt><dd>${escHtml(respFullNameEl?.value || '—')}</dd></div>
                    <div><dt>Contact Number</dt><dd>${escHtml(document.getElementById('portalRespContact')?.value?.trim() || 'Not provided')}</dd></div>
                    <div class="rd-review-full"><dt>Actual Summons Address</dt><dd>${escHtml([document.getElementById('portalRespAddress')?.value?.trim(), document.getElementById('portalRespPurok')?.value].filter(Boolean).join(' • ') || '—')}</dd></div>
                </dl>
            </div>
            <div class="rd-review-box">
                <div class="rd-review-box-head">
                    <strong>3. Complaint Narrative</strong>
                    <button type="button" class="rd-review-edit-btn" data-wizard-goto="3">Edit</button>
                </div>
                <dl class="rd-review-dl">
                    <div><dt>When It Happened</dt><dd>${escHtml(dtFormatted)}</dd></div>
                    <div><dt>Where It Happened</dt><dd>${escHtml([document.getElementById('portalIncidentStreet')?.value?.trim(), document.getElementById('portalIncidentPurok')?.value].filter(Boolean).join(' • ') || '—')}</dd></div>
                    ${aiReviewRowHtml}
                    <div class="rd-review-full"><dt>Your Complaint Narrative</dt><dd style="white-space:pre-line;">${escHtml(narrativeEl?.value?.trim() || '—')}</dd></div>
                    <div><dt>Attached Files</dt><dd>${escHtml(attachedFilesLabel)}</dd></div>
                    <div><dt>Estimated Claim</dt><dd>${claimAmt ? `PHP ${escHtml(claimAmt)}` : 'None specified'}</dd></div>
                    ${extraNotes ? `<div class="rd-review-full"><dt>Additional Optional Notes</dt><dd style="white-space:pre-line;">${escHtml(extraNotes)}</dd></div>` : ''}
                </dl>
            </div>
        `;
    }

    function goToWizardStep(stepNum, options = {}) {
        const nextStep = Math.max(1, Math.min(totalWizardSteps, Number(stepNum) || 1));
        if (options.validateCurrent && nextStep > currentWizardStep) {
            for (let s = currentWizardStep; s < nextStep; s++) {
                if (!validateWizardPane(s)) {
                    if (s !== currentWizardStep) {
                        goToWizardStep(s, { validateCurrent: false, scroll: false });
                        validateWizardPane(s);
                    }
                    return false;
                }
            }
        }
        if (nextStep !== 3 && isListeningVoice) {
            stopActiveVoiceCapture();
        }
        currentWizardStep = nextStep;
        if (currentWizardStep === 4) {
            renderComplaintReviewSummary();
        }
        document.querySelectorAll('[data-wizard-pane]').forEach((pane) => {
            pane.classList.toggle('active', Number(pane.getAttribute('data-wizard-pane')) === currentWizardStep);
        });
        document.querySelectorAll('[data-wizard-step-btn]').forEach((btn) => {
            const n = Number(btn.getAttribute('data-wizard-step-btn'));
            btn.classList.toggle('is-active', n === currentWizardStep);
            btn.classList.toggle('is-done', n < currentWizardStep);
            const badge = btn.querySelector('[data-step-badge]');
            if (badge) badge.textContent = n < currentWizardStep ? '✓' : String(n);
        });

        const stepTextEl = document.getElementById('rdWizardStepText');
        const progFillEl = document.getElementById('rdWizardProgressFill');
        if (stepTextEl) stepTextEl.textContent = `Step ${currentWizardStep} of ${totalWizardSteps}`;
        if (progFillEl) progFillEl.style.width = `${(currentWizardStep / totalWizardSteps) * 100}%`;

        const prevBtn = document.getElementById('btnWizardPrev');
        const nextBtn = document.getElementById('btnWizardNext');
        const submitBtn = document.getElementById('btnPortalSubmitComplaint');
        if (prevBtn) prevBtn.style.display = currentWizardStep > 1 ? 'inline-flex' : 'none';
        if (nextBtn) nextBtn.style.display = currentWizardStep < totalWizardSteps ? 'inline-flex' : 'none';
        if (submitBtn) submitBtn.style.display = currentWizardStep === totalWizardSteps ? 'inline-flex' : 'none';

        if (options.scroll !== false) {
            document.querySelector('.rd-wizard-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        return true;
    }

    document.getElementById('btnWizardNext')?.addEventListener('click', () => {
        goToWizardStep(currentWizardStep + 1, { validateCurrent: true });
    });
    document.getElementById('btnWizardPrev')?.addEventListener('click', () => {
        goToWizardStep(currentWizardStep - 1, { validateCurrent: false });
    });
    document.querySelectorAll('[data-wizard-step-btn]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const target = Number(btn.getAttribute('data-wizard-step-btn'));
            goToWizardStep(target, { validateCurrent: target > currentWizardStep });
        });
    });
    document.getElementById('rdWizardReviewContent')?.addEventListener('click', (e) => {
        const editBtn = e.target.closest('[data-wizard-goto]');
        if (editBtn) {
            goToWizardStep(Number(editBtn.getAttribute('data-wizard-goto')), { validateCurrent: false });
        }
    });

    document.getElementById('btnPortalSaveDraft')?.addEventListener('click', () => {
        if (draftInput) draftInput.value = '1';
    });
    document.getElementById('btnPortalSubmitComplaint')?.addEventListener('click', () => {
        if (draftInput) draftInput.value = '0';
    });

    const complaintIdEl = document.getElementById('portalComplaintId');
    const editingDraftBannerEl = document.getElementById('portalEditingDraftBanner');
    const editingDraftRefEl = document.getElementById('portalEditingDraftRef');
    const wizardHeadingEl = document.getElementById('portalWizardHeading');
    const wizardSubheadingEl = document.getElementById('portalWizardSubheading');
    const optionalDetailsBoxEl = document.getElementById('portalOptionalDetailsBox');
    const existingAttachNoticeEl = document.getElementById('portalExistingAttachmentsNotice');

    function resetComplaintWizardToNew(options = {}) {
        if (portalForm) portalForm.reset();
        if (complaintIdEl) complaintIdEl.value = '';
        if (draftInput) draftInput.value = '0';
        activeDraftExistingAttachments = [];
        if (existingAttachNoticeEl) {
            existingAttachNoticeEl.style.display = 'none';
            existingAttachNoticeEl.innerHTML = '';
        }
        if (optionalDetailsBoxEl) optionalDetailsBoxEl.open = false;
        if (editingDraftBannerEl) editingDraftBannerEl.style.display = 'none';
        if (editingDraftRefEl) editingDraftRefEl.textContent = '';
        if (wizardHeadingEl) wizardHeadingEl.textContent = 'File a Complaint';
        if (wizardSubheadingEl) wizardSubheadingEl.textContent = 'Complete the steps below to submit a formal complaint to Barangay Tumana.';
        if (!options.keepResultBanner && resultBanner) {
            resultBanner.style.display = 'none';
        }
        stopActiveVoiceCapture();
        lastAiCoverage = null;
        resetAiSuggestionSelection(true);
        setStoryAiStatus('');
        syncRespondentNameParts();
        syncGuidedNarrative();
        resetDefaultIncidentDatetime();
        clearAppliedMapPin(false);
        if (locationHintEl) locationHintEl.textContent = '';
        const brgyHidden = portalForm?.querySelector('[name="incident_barangay"]');
        if (brgyHidden) brgyHidden.value = 'Tumana';
        goToWizardStep(1, { validateCurrent: false, scroll: false });
    }

    document.getElementById('btnPortalCancelDraftEdit')?.addEventListener('click', () => {
        resetComplaintWizardToNew();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    async function startEditingDraft(complaintId) {
        const compIdNum = Number(complaintId);
        if (!compIdNum || !portalForm) return;

        try {
            const resp = await fetch(`../../../backend/api/complaints/view.php?id=${compIdNum}`, {
                headers: { Accept: 'application/json' },
                cache: 'no-store'
            });
            const detail = await resp.json();
            if (!resp.ok || !detail || !detail.complaint_id) {
                alert(detail?.message || 'Unable to load the saved draft for editing.');
                return;
            }
            if (Number(detail.is_draft || 0) !== 1) {
                alert('Only saved drafts can be edited here.');
                return;
            }
            const statusStr = String(detail.status || 'Filed').trim();
            if (statusStr !== 'Filed' && statusStr !== 'Needs Information') {
                alert('This complaint can no longer be edited as a draft.');
                return;
            }

            resetComplaintWizardToNew();

            const ref = detail.complaint_number || `CMP-${compIdNum}`;
            if (complaintIdEl) complaintIdEl.value = String(compIdNum);
            if (editingDraftRefEl) editingDraftRefEl.textContent = ref;
            if (editingDraftBannerEl) editingDraftBannerEl.style.display = 'flex';
            if (wizardHeadingEl) wizardHeadingEl.textContent = `Edit Draft (${ref})`;
            if (wizardSubheadingEl) wizardSubheadingEl.textContent = 'Update your saved draft details below, save changes, or submit when ready.';

            const parties = Array.isArray(detail.parties) ? detail.parties : [];
            const compParty = parties.find((p) => p.party_type === 'Complainant') || {};
            const respParty = parties.find((p) => p.party_type === 'Respondent') || {};

            const compContactEl = document.getElementById('portalCompContact');
            const compPurokEl = document.getElementById('portalCompPurok');
            const compAddrEl = document.getElementById('portalCompAddress');
            if (compContactEl && (compParty.resident_contact || compParty.preferred_contact)) {
                compContactEl.value = compParty.resident_contact || compParty.preferred_contact;
            }
            if (compPurokEl && compParty.purok) {
                selectMatchingPurok(compPurokEl, compParty.purok);
            }
            if (compAddrEl && compParty.address) {
                compAddrEl.value = compParty.address;
            }

            const respFullName = [respParty.first_name, respParty.middle_name, respParty.last_name, respParty.suffix]
                .filter(Boolean)
                .join(' ')
                .replace(/\s+/g, ' ')
                .trim() || respParty.resident_name || '';
            if (respFullNameEl) respFullNameEl.value = respFullName;
            syncRespondentNameParts();
            const respAddrEl = document.getElementById('portalRespAddress');
            const respPurokEl = document.getElementById('portalRespPurok');
            const respContactEl = document.getElementById('portalRespContact');
            if (respAddrEl) respAddrEl.value = respParty.address || '';
            if (respPurokEl) selectMatchingPurok(respPurokEl, respParty.purok || '');
            if (respContactEl) respContactEl.value = respParty.resident_contact || respParty.preferred_contact || '';

            if (dtEl && detail.incident_date) {
                const datePart = String(detail.incident_date).slice(0, 10);
                const timePart = detail.incident_time ? String(detail.incident_time).slice(0, 5) : '00:00';
                dtEl.value = `${datePart}T${timePart}`;
            }
            const incPurokEl = document.getElementById('portalIncidentPurok');
            const incStreetEl = document.getElementById('portalIncidentStreet');
            if (incPurokEl) selectMatchingPurok(incPurokEl, detail.incident_purok || '');
            if (incStreetEl) incStreetEl.value = detail.incident_street || '';
            const brgyHidden = portalForm.querySelector('[name="incident_barangay"]');
            if (brgyHidden) brgyHidden.value = detail.incident_barangay || 'Tumana';

            const latNum = parseFloat(detail.location?.latitude);
            const lngNum = parseFloat(detail.location?.longitude);
            if (detail.location && Number.isFinite(latNum) && Number.isFinite(lngNum)) {
                const latEl = document.getElementById('portalMapLat');
                const lngEl = document.getElementById('portalMapLng');
                const stateEl = document.getElementById('portalMapState');
                if (latEl) latEl.value = latNum.toFixed(8);
                if (lngEl) lngEl.value = lngNum.toFixed(8);
                if (stateEl) stateEl.value = 'unchanged';
                if (clearMapChipBtn) clearMapChipBtn.style.display = 'inline-flex';
                if (locationHintEl) locationHintEl.textContent = 'Saved map pin loaded (click Pin in Map to update or Clear Pin to remove).';
            }

            if (narrativeEl) narrativeEl.value = detail.narrative || '';
            if (resolutionInputEl) resolutionInputEl.value = detail.requested_resolution || '';
            if (autoTitleHiddenEl) {
                autoTitleHiddenEl.dataset.fromAi = '';
                autoTitleHiddenEl.value = detail.complaint_title || '';
            }
            syncGuidedNarrative();

            const fineEl = document.getElementById('portalFineMax');
            const fineVal = Number(detail.intake_screening?.offense_fine_max || 0);
            if (fineEl) fineEl.value = fineVal > 0 ? String(fineVal) : '';
            if (addDetailsEl) addDetailsEl.value = detail.additional_details || '';

            activeDraftExistingAttachments = Array.isArray(detail.attachments) ? detail.attachments : [];
            if (existingAttachNoticeEl && activeDraftExistingAttachments.length > 0) {
                const fileNames = activeDraftExistingAttachments.map((a) => escHtml(a.file_name || 'Attachment')).join(', ');
                existingAttachNoticeEl.innerHTML = `<strong>Saved files already attached (${activeDraftExistingAttachments.length}):</strong> ${fileNames}. <span style="color:#64748b;">Any new files chosen below will be added to your draft.</span>`;
                existingAttachNoticeEl.style.display = 'block';
            }
            if (optionalDetailsBoxEl) {
                optionalDetailsBoxEl.open = Boolean(fineVal > 0 || (detail.additional_details || '').trim() || activeDraftExistingAttachments.length > 0);
            }

            if (detail.ai_suggestion && detail.ai_suggestion.resident_viewed && detail.ai_suggestion.primary?.category_id) {
                renderReadonlyAiSuggestion(detail.ai_suggestion);
            }

            switchResidentView('submit', { scrollTop: true, refresh: false });
            goToWizardStep(1, { validateCurrent: false, scroll: false });
        } catch (_) {
            alert('Connection error while loading the saved draft.');
        }
    }

    portalForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (resultBanner) resultBanner.style.display = 'none';
        syncGuidedNarrative();

        for (let s = 1; s <= 3; s++) {
            const pane = document.querySelector(`[data-wizard-pane="${s}"]`);
            if (!pane) continue;
            const fields = Array.from(pane.querySelectorAll('input, select, textarea')).filter((el) => !el.disabled && el.type !== 'hidden');
            const needsNameSplit = (s === 2 && syncRespondentNameParts().length < 2);
            const hasFieldErr = fields.some((el) => (
                window.AgapFieldValidator?.validateElement
                    ? !window.AgapFieldValidator.validateElement(el, false)
                    : !el.checkValidity()
            ));
            if (hasFieldErr || needsNameSplit) {
                goToWizardStep(s, { validateCurrent: false, scroll: false });
                validateWizardPane(s);
                if (resultBanner) {
                    resultBanner.className = 'alert alert-error';
                    resultBanner.textContent = `Please complete the required fields in Step ${s} before continuing.`;
                    resultBanner.style.display = 'block';
                }
                return;
            }
        }

        const editingDraftId = Number(complaintIdEl?.value || 0);
        const targetEndpoint = editingDraftId > 0
            ? '../../../backend/api/complaints/save-complaint.php'
            : '../../../backend/api/complaints/create.php';
        const submitBtn = document.getElementById('btnPortalSubmitComplaint');
        const draftBtn = document.getElementById('btnPortalSaveDraft');
        if (submitBtn) submitBtn.disabled = true;
        if (draftBtn) draftBtn.disabled = true;

        try {
            const resp = await fetch(targetEndpoint, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new FormData(portalForm)
            });
            const data = await resp.json();
            if (resultBanner) {
                resultBanner.className = 'alert ' + (data.success ? 'alert-success' : 'alert-error');
                resultBanner.style.display = 'block';
                if (data.success) {
                    const ref = data.complaint_number || (`CMP-${data.complaint_id || ''}`);
                    const kpLinks = (!data.is_draft && data.status !== 'Under Review' && (data.kp7_preview_url || data.kp7_download_url) && data.complaint_id)
                        ? ` <a href="../../../backend/api/complaints/kp7-preview.php?id=${Number(data.complaint_id)}" target="_blank" style="font-weight:700; color:#1d4ed8; margin-left:8px;">View KP Form 7</a> &bull; <a href="../../../backend/api/complaints/kp7-preview.php?id=${Number(data.complaint_id)}&download=1" style="font-weight:700; color:#1d4ed8;">Download PDF</a>`
                        : '';
                    const draftTitle = editingDraftId > 0 ? 'Draft updated!' : 'Draft saved!';
                    resultBanner.innerHTML = data.is_draft
                        ? `<strong>${draftTitle}</strong> Your Reference ID is <code>${escHtml(ref)}</code>. You can continue editing or submit it anytime from My Complaints. <button type="button" class="btn-secondary" data-rd-goto="complaints" style="margin-left:8px; padding:4px 10px; font-size:0.78rem;">View My Complaints &rarr;</button>`
                        : `<strong>Complaint submitted &mdash; For Review!</strong> Your complaint (Reference ID: <code>${escHtml(ref)}</code>) has been received and is now <strong>for review</strong> by Barangay Tumana officials. KP Form 7 will be generated once your complaint is accepted. Track updates under My Complaints.${kpLinks} <button type="button" class="btn-secondary" data-rd-goto="complaints" style="margin-left:8px; padding:4px 10px; font-size:0.78rem;">View My Complaints &rarr;</button>`;
                    resetComplaintWizardToNew({ keepResultBanner: true });
                    loadMyComplaints();
                } else {
                    resultBanner.textContent = data.message || 'Unable to submit online complaint.';
                }
                resultBanner.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        } catch (_) {
            if (resultBanner) {
                resultBanner.className = 'alert alert-error';
                resultBanner.textContent = 'Connection error while submitting your complaint.';
                resultBanner.style.display = 'block';
            }
        } finally {
            if (submitBtn) submitBtn.disabled = false;
            if (draftBtn) draftBtn.disabled = false;
        }
    });

    // Profile Update Handler
    const profForm = document.getElementById('residentProfileForm');
    const profAlert = document.getElementById('residentProfileAlert');
    profForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (profAlert) profAlert.style.display = 'none';
        const saveBtn = document.getElementById('btnSaveResidentProfile');
        if (saveBtn) saveBtn.disabled = true;

        const payload = {
            first_name: document.getElementById('profFirstName')?.value?.trim() || '',
            middle_name: document.getElementById('profMiddleName')?.value?.trim() || '',
            last_name: document.getElementById('profLastName')?.value?.trim() || '',
            email: document.getElementById('profEmail')?.value?.trim() || '',
            contact_no: document.getElementById('profContactNo')?.value?.trim() || '',
            purok: document.getElementById('profPurok')?.value || '',
            address: document.getElementById('profAddress')?.value?.trim() || '',
            preferred_language: document.getElementById('profLang')?.value || 'fil',
        };

        try {
            const resp = await fetch('../../../backend/api/settings/profile.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await resp.json();
            if (profAlert) {
                profAlert.className = 'alert ' + (data.success ? 'alert-success' : 'alert-error');
                profAlert.textContent = data.message || (data.success ? 'Profile updated.' : 'Unable to save profile.');
                profAlert.style.display = 'block';
            }
            if (data.success) {
                const fullName = `${payload.first_name} ${payload.last_name}`.trim();
                const initials = ((payload.first_name.charAt(0) || 'R') + (payload.last_name.charAt(0) || '')).toUpperCase();
                const navNameEl = document.getElementById('rdHeaderName');
                const navAvatarEl = document.getElementById('rdHeaderAvatar');
                const subNameEl = document.getElementById('rdSubFullName');
                const subEmailEl = document.getElementById('rdSubEmail');
                if (navNameEl && fullName) navNameEl.textContent = fullName;
                if (navAvatarEl && initials) navAvatarEl.textContent = initials;
                if (subNameEl && fullName) subNameEl.textContent = fullName;
                if (subEmailEl && payload.email) subEmailEl.textContent = payload.email;

                // Sync auto-filled complainant fields + defaults for future form resets
                const syncVal = (id, val) => {
                    const el = document.getElementById(id);
                    if (el) {
                        el.value = val;
                        if ('defaultValue' in el) el.defaultValue = val;
                    }
                };
                const compFullName = [payload.first_name, payload.middle_name, payload.last_name].filter(Boolean).join(' ');
                syncVal('portalCompFullName', compFullName);
                syncVal('portalCompFirst', payload.first_name);
                syncVal('portalCompMiddle', payload.middle_name);
                syncVal('portalCompLast', payload.last_name);
                syncVal('portalCompContact', payload.contact_no);
                syncVal('portalCompAddress', payload.address);
                syncVal('portalCompEmailHidden', payload.email);
                const portalCompPurokEl = document.getElementById('portalCompPurok');
                selectMatchingPurok(portalCompPurokEl, payload.purok);
                if (portalCompPurokEl) {
                    Array.from(portalCompPurokEl.options).forEach((o) => {
                        o.defaultSelected = (o.value === portalCompPurokEl.value);
                    });
                }

                if (typeof window.setAppLanguage === 'function') {
                    window.setAppLanguage(payload.preferred_language === 'en' ? 'en' : 'tl');
                }
                const verGuide = document.getElementById('rdVerificationGuide');
                const verPill = document.getElementById('rdVerificationPill');
                if (verGuide && verPill && verPill.textContent.trim() !== 'Verified') {
                    const ready = Boolean(payload.email && payload.contact_no && (payload.address || payload.purok));
                    verGuide.innerHTML = ready
                        ? '<strong>Ready for Verification:</strong> Your Email, Mobile Number, and Barangay Address are saved and awaiting verification by the Barangay Administrator or Secretary.'
                        : '<strong>Verification Status: Pending Review</strong> &mdash; Complete your <strong>Email Address</strong>, <strong>Mobile Number</strong>, <strong>Purok / Area in Tumana</strong>, and <strong>Complete Street Address</strong> below. Barangay Administrator or Secretary will review these details to verify your account.';
                }
            }
        } catch (_) {
            if (profAlert) {
                profAlert.className = 'alert alert-error';
                profAlert.textContent = 'Connection error while updating your profile.';
                profAlert.style.display = 'block';
            }
        } finally {
            if (saveBtn) saveBtn.disabled = false;
        }
    });

    // Change Password Handler
    const pwForm = document.getElementById('residentPasswordForm');
    const pwAlert = document.getElementById('residentPasswordAlert');
    pwForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (pwAlert) pwAlert.style.display = 'none';
        const saveBtn = document.getElementById('btnSaveResidentPassword');
        if (saveBtn) saveBtn.disabled = true;

        try {
            const resp = await fetch('../../../backend/api/settings/change-password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    current_password: document.getElementById('profCurrentPassword')?.value || '',
                    new_password: document.getElementById('profNewPassword')?.value || '',
                    confirm_password: document.getElementById('profConfirmPassword')?.value || '',
                })
            });
            const data = await resp.json();
            if (pwAlert) {
                pwAlert.className = 'alert ' + (data.success ? 'alert-success' : 'alert-error');
                pwAlert.textContent = data.message || (data.success ? 'Password updated successfully.' : 'Unable to update password.');
                pwAlert.style.display = 'block';
            }
            if (data.success) {
                pwForm.reset();
            }
        } catch (_) {
            if (pwAlert) {
                pwAlert.className = 'alert alert-error';
                pwAlert.textContent = 'Connection error while updating password.';
                pwAlert.style.display = 'block';
            }
        } finally {
            if (saveBtn) saveBtn.disabled = false;
        }
    });

    document.getElementById('btnRefreshMyComplaints')?.addEventListener('click', loadMyComplaints);
    loadMyComplaints();
    window.AgapNewsWidget?.loadAndRenderResidentNews();
});
<?php endif; ?>
</script>
</body>
</html>
