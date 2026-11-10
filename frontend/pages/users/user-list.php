<?php 
session_start(); 
$roleId = (int) ($_SESSION['role_id'] ?? 0);
if ($roleId !== 1) {
    $redirect = match ($roleId) {
        2 => '../dashboard/clerk-dashboard.php',
        3 => '../dashboard/lupon-dashboard.php',
        4 => '../dashboard/server-dashboard.php',
        default => '../auth/login.php',
    };
    header('Location: ' . $redirect);
    exit;
} 
require_once __DIR__ . '/../../../backend/config/database.php';
$db = (new Database())->connect();
$availableRoles = $db->query("SELECT role_id, role_name FROM roles WHERE role_name IN ('Administrator', 'Lupon Clerk', 'Lupon Member', 'Summons Server', 'Resident') ORDER BY role_id")->fetchAll(PDO::FETCH_ASSOC);
include '../../layouts/header.php'; 
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css">
<link rel="stylesheet" href="../../assets/css/users.css?v=<?php echo filemtime(__DIR__.'/../../assets/css/users.css'); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>
        
        <div class="page-header">
            <div>
                <h1>User Management</h1>
                <p>Create accounts and assign system roles.</p>
            </div>
            <button type="button" class="btn-create" onclick="openUserModal()">Add User</button>
        </div>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Contact Number</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="userTable"></tbody>
            </table>
        </div>
    </div>
</div>

<div id="userModal" class="modal" role="presentation" aria-hidden="true">
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="userModalTitle" tabindex="-1">
        <div class="modal-header">
            <h2 id="userModalTitle">Add User</h2>
            <button type="button" class="close-btn" onclick="closeUserModal()" aria-label="Close user form">&times;</button>
        </div>
        
        <form id="userForm" method="POST" action="../../../backend/api/users/create.php">
            <input type="hidden" id="userId" name="user_id">
            <div id="userMessage" role="alert" style="display: none;"></div>
            
            <div class="grid">
                <div class="form-group">
                    <label for="firstName">First Name</label>
                    <input id="firstName" name="first_name" maxlength="100" required>
                </div>
                <div class="form-group">
                    <label for="lastName">Last Name</label>
                    <input id="lastName" name="last_name" maxlength="100" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="username">Username</label>
                <input id="username" name="username" minlength="3" maxlength="100" pattern="[A-Za-z0-9_.-]{3,100}" required>
            </div>
            
            <div class="form-group">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" maxlength="150" required>
            </div>

            <div class="form-group">
                <label for="contactNo">Contact Number</label>
                <input id="contactNo" type="tel" name="contact_no" maxlength="20" pattern="[0-9+() .-]{10,20}" inputmode="tel" autocomplete="tel" placeholder="09XXXXXXXXX or +639XXXXXXXXX" aria-describedby="contactNoHelp" required>
                <small id="contactNoHelp">Required. Enter a valid Philippine mobile or telephone number.</small>
            </div>
            
            <div class="form-group" id="passwordGroup">
                <label for="password">Password <small id="passwordHint">(optional; leave blank to send verification email)</small></label>
                <div class="user-password-field">
                    <input id="password" type="password" name="password" minlength="12" autocomplete="new-password" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{12,}" title="Use at least 12 characters with uppercase, lowercase, and a number." aria-describedby="passwordRequirements">
                    <button type="button" class="user-password-toggle" data-password-toggle="password" aria-controls="password" aria-label="Show password" aria-pressed="false">Show</button>
                </div>
                <ul id="passwordRequirements" class="password-requirements" aria-label="Password requirements">
                    <li data-password-rule="length">At least 12 characters</li>
                    <li data-password-rule="lowercase">One lowercase letter</li>
                    <li data-password-rule="uppercase">One uppercase letter</li>
                    <li data-password-rule="number">One number</li>
                </ul>
            </div>
            <div class="form-group" id="passwordConfirmationGroup">
                <label for="passwordConfirmation">Confirm Password</label>
                <div class="user-password-field">
                    <input id="passwordConfirmation" type="password" name="password_confirmation" minlength="12" autocomplete="new-password" title="Re-enter the password exactly.">
                    <button type="button" class="user-password-toggle" data-password-toggle="passwordConfirmation" aria-controls="passwordConfirmation" aria-label="Show confirmation password" aria-pressed="false">Show</button>
                </div>
                <small>Required only when an initial password is entered.</small>
            </div>
            
            <div class="form-group">
                <label for="roleId">Role</label>
                <select id="roleId" name="role_id">
                    <?php foreach ($availableRoles as $role): ?>
                        <option value="<?= (int) $role['role_id'] ?>"><?= htmlspecialchars($role['role_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <button type="submit" class="btn-create">Save User</button>
        </form>
    </div>
</div>

<script src="../../assets/js/users.js?v=<?php echo filemtime(__DIR__.'/../../assets/js/users.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
