<?php
session_start();

require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/middleware/rbac.php';

if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    header('Location: ../auth/login.php?error=login_required');
    exit;
}

$rbacDb = (new Database())->connect();
if (!agapHasRole(['Administrator', 'Secretary', 'Lupon Clerk'], $rbacDb)) {
    $roleId = (int) ($_SESSION['role_id'] ?? 0);
    $redirect = match ($roleId) {
        3 => '../dashboard/lupon-dashboard.php',
        4 => '../dashboard/server-dashboard.php',
        default => '../complaints/complaint-list.php',
    };
    header('Location: ' . $redirect);
    exit;
}

$roleId = (int) ($_SESSION['role_id'] ?? 0);
$roleName = agapGetRoleName($rbacDb);
$isAdmin = ($roleId === 1 || $roleName === 'Administrator');
$requestedTab = (string) ($_GET['tab'] ?? 'staff');
$initialTab = ($isAdmin && $requestedTab !== 'residents') ? 'staff' : 'residents';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/users.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/users.css'); ?>">

<div class="dashboard-layout" id="usersModuleRoot" data-is-admin="<?php echo $isAdmin ? '1' : '0'; ?>" data-initial-tab="<?php echo htmlspecialchars($initialTab, ENT_QUOTES, 'UTF-8'); ?>">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content users-ambient-bg">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="users-page-header">
            <div class="users-page-heading">
                <h1 id="usersPageTitle"><?php echo $isAdmin ? 'Users &amp; Resident Verification' : 'Resident Account Verification'; ?></h1>
                <p id="usersPageSubtitle">
                    <?php if ($isAdmin): ?>
                        Manage barangay staff accounts, roles, and resident portal residency verification.
                    <?php else: ?>
                        Review Email, Mobile Number, and Barangay Tumana Address submitted in the Resident Portal before verifying accounts.
                    <?php endif; ?>
                </p>

                <?php if ($isAdmin): ?>
                    <div class="users-segmented-tabs" role="tablist" aria-label="Users and Residents sections">
                        <button
                            type="button"
                            class="users-seg-tab <?php echo $initialTab === 'staff' ? 'active' : ''; ?>"
                            data-module-tab="staff"
                            role="tab"
                            aria-selected="<?php echo $initialTab === 'staff' ? 'true' : 'false'; ?>">
                            Staff Accounts &amp; Roles
                            <span class="tab-count-pill" id="staffTabCountBadge" hidden>0</span>
                        </button>
                        <button
                            type="button"
                            class="users-seg-tab <?php echo $initialTab === 'residents' ? 'active' : ''; ?>"
                            data-module-tab="residents"
                            role="tab"
                            aria-selected="<?php echo $initialTab === 'residents' ? 'true' : 'false'; ?>">
                            Resident Verification
                            <span class="tab-count-pill is-warning" id="rvTabPendingBadge" hidden>0</span>
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($isAdmin): ?>
                <div class="users-header-actions">
                    <button type="button" class="btn-pill-dark" id="btnAddStaffUser" onclick="openUserModal()"<?php echo $initialTab === 'staff' ? '' : ' hidden'; ?>>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        <span>Add Staff User</span>
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($isAdmin): ?>
            <section id="staffPanel" class="users-tab-panel" role="tabpanel"<?php echo $initialTab === 'staff' ? '' : ' hidden'; ?>>
                <div class="kpi-cards-grid is-six-cards">
                    <article class="kpi-stat-card is-clickable" data-staff-card-filter="all" role="button" tabindex="0">
                        <div class="kpi-card-top">
                            <span class="kpi-icon-box" aria-hidden="true">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            </span>
                            <svg class="kpi-sparkline" viewBox="0 0 88 28" fill="none" aria-hidden="true">
                                <path d="M2 16 C14 8, 24 8, 34 17 C44 25, 56 26, 66 15 C73 8, 80 12, 84 11" stroke="#e11d48" stroke-width="1.6" stroke-linecap="round" opacity="0.65"/>
                                <circle cx="84" cy="11" r="3" fill="#ffffff" stroke="#cbd5e1" stroke-width="1.5"/>
                            </svg>
                        </div>
                        <div class="kpi-card-label">Total staff</div>
                        <div class="kpi-card-metric-row">
                            <span class="kpi-card-value" id="staffTotalCount">0</span>
                            <span class="kpi-delta-pill is-emerald" id="staffActiveTotalPill">0 active</span>
                        </div>
                    </article>

                    <article class="kpi-stat-card is-clickable" data-staff-card-filter="secretary" role="button" tabindex="0">
                        <div class="kpi-card-top">
                            <span class="kpi-icon-box" aria-hidden="true">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                            </span>
                            <svg class="kpi-sparkline" viewBox="0 0 88 28" fill="none" aria-hidden="true">
                                <path d="M2 15 C14 8, 24 9, 34 18 C45 26, 56 24, 68 14 C75 8, 80 11, 84 10" stroke="#9333ea" stroke-width="1.6" stroke-linecap="round" opacity="0.65"/>
                                <circle cx="84" cy="10" r="3" fill="#ffffff" stroke="#cbd5e1" stroke-width="1.5"/>
                            </svg>
                        </div>
                        <div class="kpi-card-label">Barangay Secretary</div>
                        <div class="kpi-card-metric-row">
                            <span class="kpi-card-value" id="staffSecretaryCount">0</span>
                            <span class="kpi-delta-pill is-indigo" id="staffSecretaryShare">0%</span>
                        </div>
                    </article>

                    <article class="kpi-stat-card is-clickable" data-staff-card-filter="clerk" role="button" tabindex="0">
                        <div class="kpi-card-top">
                            <span class="kpi-icon-box" aria-hidden="true">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"></rect><line x1="7" y1="9" x2="17" y2="9"></line><line x1="7" y1="13" x2="13" y2="13"></line></svg>
                            </span>
                            <svg class="kpi-sparkline" viewBox="0 0 88 28" fill="none" aria-hidden="true">
                                <path d="M2 16 C12 9, 22 10, 34 18 C46 25, 58 24, 68 13 C75 7, 80 9, 84 9" stroke="#2563eb" stroke-width="1.6" stroke-linecap="round" opacity="0.65"/>
                                <circle cx="84" cy="9" r="3" fill="#ffffff" stroke="#cbd5e1" stroke-width="1.5"/>
                            </svg>
                        </div>
                        <div class="kpi-card-label">Lupon Clerk</div>
                        <div class="kpi-card-metric-row">
                            <span class="kpi-card-value" id="staffClerkCount">0</span>
                            <span class="kpi-delta-pill is-emerald" id="staffClerkShare">0%</span>
                        </div>
                    </article>

                    <article class="kpi-stat-card is-clickable" data-staff-card-filter="member" role="button" tabindex="0">
                        <div class="kpi-card-top">
                            <span class="kpi-icon-box" aria-hidden="true">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"></path><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"></path><path d="M7 21h10"></path><path d="M12 3v18"></path></svg>
                            </span>
                            <svg class="kpi-sparkline" viewBox="0 0 88 28" fill="none" aria-hidden="true">
                                <path d="M2 15 C13 7, 24 10, 35 17 C45 24, 57 22, 68 13 C75 8, 80 10, 84 10" stroke="#0d9488" stroke-width="1.6" stroke-linecap="round" opacity="0.65"/>
                                <circle cx="84" cy="10" r="3" fill="#ffffff" stroke="#cbd5e1" stroke-width="1.5"/>
                            </svg>
                        </div>
                        <div class="kpi-card-label">Lupon Members</div>
                        <div class="kpi-card-metric-row">
                            <span class="kpi-card-value" id="staffMemberCount">0</span>
                            <span class="kpi-delta-pill is-emerald" id="staffMemberShare">0%</span>
                        </div>
                    </article>

                    <article class="kpi-stat-card is-clickable" data-staff-card-filter="server" role="button" tabindex="0">
                        <div class="kpi-card-top">
                            <span class="kpi-icon-box" aria-hidden="true">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s-8-4.5-8-11.8A8 8 0 0 1 12 2a8 8 0 0 1 8 8.2c0 7.3-8 11.8-8 11.8z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                            </span>
                            <svg class="kpi-sparkline" viewBox="0 0 88 28" fill="none" aria-hidden="true">
                                <path d="M2 16 C12 9, 22 10, 34 18 C46 25, 58 24, 68 13 C75 7, 80 9, 84 9" stroke="#4f46e5" stroke-width="1.6" stroke-linecap="round" opacity="0.65"/>
                                <circle cx="84" cy="9" r="3" fill="#ffffff" stroke="#cbd5e1" stroke-width="1.5"/>
                            </svg>
                        </div>
                        <div class="kpi-card-label">Summon Server</div>
                        <div class="kpi-card-metric-row">
                            <span class="kpi-card-value" id="staffServerCount">0</span>
                            <span class="kpi-delta-pill is-indigo" id="staffServerShare">0%</span>
                        </div>
                    </article>

                    <article class="kpi-stat-card is-clickable" data-staff-card-filter="disabled" role="button" tabindex="0">
                        <div class="kpi-card-top">
                            <span class="kpi-icon-box" aria-hidden="true">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>
                            </span>
                            <svg class="kpi-sparkline" viewBox="0 0 88 28" fill="none" aria-hidden="true">
                                <path d="M2 14 C14 7, 25 9, 36 18 C47 26, 58 25, 68 15 C75 9, 80 12, 84 12" stroke="#f43f5e" stroke-width="1.6" stroke-linecap="round" opacity="0.65"/>
                                <circle cx="84" cy="12" r="3" fill="#ffffff" stroke="#cbd5e1" stroke-width="1.5"/>
                            </svg>
                        </div>
                        <div class="kpi-card-label">Disabled</div>
                        <div class="kpi-card-metric-row">
                            <span class="kpi-card-value" id="staffDisabledCount">0</span>
                            <span class="kpi-delta-pill is-rose" id="staffDisabledShare">0%</span>
                        </div>
                    </article>
                </div>

                <div class="users-table-card">
                    <div class="users-card-toolbar">
                        <div class="users-card-title-group">
                            <h2>All staff accounts</h2>
                            <span class="users-shown-meta" id="staffShownMeta">0 of 0 shown</span>
                        </div>

                        <div class="users-card-controls">
                            <div class="users-pill-search">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                <input type="search" id="staffSearchInput" autocomplete="off" placeholder="Search by name, username, or email...">
                            </div>
                        </div>
                    </div>

                    <div class="users-card-table-wrap">
                        <table class="users-modern-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Email</th>
                                    <th>Contact Number</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th class="col-actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="userTable">
                                <tr><td colspan="6" class="table-state-cell">Loading staff accounts...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section id="residentsPanel" class="users-tab-panel" role="tabpanel"<?php echo $initialTab === 'residents' ? '' : ' hidden'; ?>>
            <div class="kpi-cards-grid">
                <article class="kpi-stat-card is-clickable" data-rv-card-filter="all" role="button" tabindex="0">
                    <div class="kpi-card-top">
                        <span class="kpi-icon-box" aria-hidden="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        </span>
                        <svg class="kpi-sparkline" viewBox="0 0 88 28" fill="none" aria-hidden="true">
                            <path d="M2 16 C14 8, 24 8, 34 17 C44 25, 56 26, 66 15 C73 8, 80 12, 84 11" stroke="#e11d48" stroke-width="1.6" stroke-linecap="round" opacity="0.65"/>
                            <circle cx="84" cy="11" r="3" fill="#ffffff" stroke="#cbd5e1" stroke-width="1.5"/>
                        </svg>
                    </div>
                    <div class="kpi-card-label">All residents</div>
                    <div class="kpi-card-metric-row">
                        <span class="kpi-card-value" id="rvTotalCount">0</span>
                        <span class="kpi-delta-pill is-emerald" id="rvTotalShare">100%</span>
                    </div>
                </article>

                <article class="kpi-stat-card is-clickable" data-rv-card-filter="verified" role="button" tabindex="0">
                    <div class="kpi-card-top">
                        <span class="kpi-icon-box" aria-hidden="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"></path><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        </span>
                        <svg class="kpi-sparkline" viewBox="0 0 88 28" fill="none" aria-hidden="true">
                            <path d="M2 15 C14 8, 24 9, 34 18 C45 26, 56 24, 68 14 C75 8, 80 11, 84 10" stroke="#9333ea" stroke-width="1.6" stroke-linecap="round" opacity="0.65"/>
                            <circle cx="84" cy="10" r="3" fill="#ffffff" stroke="#cbd5e1" stroke-width="1.5"/>
                        </svg>
                    </div>
                    <div class="kpi-card-label">Verified residents</div>
                    <div class="kpi-card-metric-row">
                        <span class="kpi-card-value" id="rvVerifiedCount">0</span>
                        <span class="kpi-delta-pill is-emerald" id="rvVerifiedShare">0%</span>
                    </div>
                </article>

                <article class="kpi-stat-card is-clickable" data-rv-card-filter="pending" role="button" tabindex="0">
                    <div class="kpi-card-top">
                        <span class="kpi-icon-box" aria-hidden="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        </span>
                        <svg class="kpi-sparkline" viewBox="0 0 88 28" fill="none" aria-hidden="true">
                            <path d="M2 16 C12 9, 22 10, 34 18 C46 25, 58 24, 68 13 C75 7, 80 9, 84 9" stroke="#4f46e5" stroke-width="1.6" stroke-linecap="round" opacity="0.65"/>
                            <circle cx="84" cy="9" r="3" fill="#ffffff" stroke="#cbd5e1" stroke-width="1.5"/>
                        </svg>
                    </div>
                    <div class="kpi-card-label">Pending verification</div>
                    <div class="kpi-card-metric-row">
                        <span class="kpi-card-value" id="rvPendingCount">0</span>
                        <span class="kpi-delta-pill is-indigo" id="rvPendingShare">0%</span>
                    </div>
                </article>

                <article class="kpi-stat-card is-clickable" data-rv-card-filter="incomplete" role="button" tabindex="0">
                    <div class="kpi-card-top">
                        <span class="kpi-icon-box" aria-hidden="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                        </span>
                        <svg class="kpi-sparkline" viewBox="0 0 88 28" fill="none" aria-hidden="true">
                            <path d="M2 14 C14 7, 25 9, 36 18 C47 26, 58 25, 68 15 C75 9, 80 12, 84 12" stroke="#f43f5e" stroke-width="1.6" stroke-linecap="round" opacity="0.65"/>
                            <circle cx="84" cy="12" r="3" fill="#ffffff" stroke="#cbd5e1" stroke-width="1.5"/>
                        </svg>
                    </div>
                    <div class="kpi-card-label">Incomplete profile</div>
                    <div class="kpi-card-metric-row">
                        <span class="kpi-card-value" id="rvIncompleteCount">0</span>
                        <span class="kpi-delta-pill is-rose" id="rvIncompleteShare">0%</span>
                    </div>
                </article>
            </div>

            <div class="users-table-card">
                <div class="users-card-toolbar">
                    <div class="users-card-title-group">
                        <h2>All residents</h2>
                        <span class="users-shown-meta" id="rvShownMeta">0 of 0 shown</span>
                    </div>

                    <div class="users-card-controls">
                        <div class="users-pill-search">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            <input type="search" id="rvSearchInput" autocomplete="off" placeholder="Search by name, email, mobile, or address...">
                        </div>
                        <select id="rvFilterSelect" class="users-pill-select" autocomplete="off" aria-label="Filter resident accounts">
                            <option value="all">All statuses</option>
                            <option value="pending">Pending verification</option>
                            <option value="verified">Verified</option>
                            <option value="incomplete">Missing mobile / address</option>
                        </select>
                    </div>
                </div>

                <div class="users-card-table-wrap">
                    <table class="users-modern-table">
                        <thead>
                            <tr>
                                <th>Resident</th>
                                <th>Email</th>
                                <th>Mobile Number</th>
                                <th>Purok &amp; Complete Address</th>
                                <th>Status</th>
                                <th class="col-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="residentVerificationTbody">
                            <tr><td colspan="6" class="table-state-cell">Loading resident accounts...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- Floating row action menu (positioned via JS so table overflow never clips it) -->
<div id="rowActionPopover" class="row-action-popover" role="menu" hidden></div>

<?php if ($isAdmin): ?>
<div id="userModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="userModalTitle">Add Staff User</h2>
            <button type="button" class="close-btn" onclick="closeUserModal()">&times;</button>
        </div>

        <form id="userForm" method="POST" action="../../../backend/api/users/create.php">
            <input type="hidden" id="userId" name="user_id">
            <div id="userMessage" role="alert" style="display: none;"></div>

            <div class="grid">
                <div class="form-group">
                    <label>First Name</label>
                    <input id="firstName" name="first_name" maxlength="100" required>
                </div>
                <div class="form-group">
                    <label>Last Name</label>
                    <input id="lastName" name="last_name" maxlength="100" required>
                </div>
            </div>

            <div class="form-group">
                <label>Username</label>
                <input id="username" name="username" minlength="3" maxlength="100" pattern="[A-Za-z0-9_.-]{3,100}" required>
            </div>

            <div class="form-group">
                <label>Email</label>
                <input id="email" type="email" name="email" maxlength="150" required>
            </div>

            <div class="form-group">
                <label for="contactNo">Contact Number</label>
                <input id="contactNo" type="tel" name="contact_no" maxlength="20" pattern="[0-9+() .-]{10,20}" inputmode="tel" autocomplete="tel" placeholder="09XXXXXXXXX or +639XXXXXXXXX" aria-describedby="contactNoHelp" required>
                <small id="contactNoHelp">Required. Enter a valid Philippine mobile or telephone number.</small>
            </div>

            <div class="form-group" id="passwordGroup">
                <label>Password <small id="passwordHint">(optional; leave blank to send verification email)</small></label>
                <input id="password" type="password" name="password" minlength="12" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{12,}" title="Use at least 12 characters with uppercase, lowercase, and a number.">
            </div>
            <div class="form-group" id="passwordConfirmationGroup">
                <label for="passwordConfirmation">Confirm Password</label>
                <input id="passwordConfirmation" type="password" name="password_confirmation" minlength="12" autocomplete="new-password" title="Re-enter the password exactly.">
                <small>Required only when an initial password is entered.</small>
            </div>

            <div class="form-group">
                <label>Role</label>
                <select id="roleId" name="role_id">
                    <option value="1">Administrator</option>
                    <option value="2">Lupon Clerk</option>
                    <option value="3">Lupon Member</option>
                    <option value="4">Summons Server</option>
                </select>
            </div>

            <button class="btn-create btn-pill-dark" style="width:100%; justify-content:center;">Save User</button>
        </form>
    </div>
</div>
<?php endif; ?>

<script src="../../assets/js/users.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/users.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
