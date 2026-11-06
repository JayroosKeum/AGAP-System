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

<div id="userModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="userModalTitle">Add User</h2>
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
            
            <button class="btn-create">Save User</button>
        </form>
    </div>
</div>

<script src="../../assets/js/users.js?v=<?php echo filemtime(__DIR__.'/../../assets/js/users.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
