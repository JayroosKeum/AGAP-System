<?php 
session_start(); 
if (($_SESSION['role_id'] ?? 0) != 1) die('Access Denied'); 
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
            <button class="btn-create" onclick="openUserModal()">Add User</button>
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
            <button class="close-btn" onclick="closeUserModal()">&times;</button>
        </div>
        
        <form id="userForm" method="POST" action="../../../backend/api/users/create.php">
            <input type="hidden" id="userId" name="user_id">
            <div id="userMessage" role="alert"></div>
            
            <div class="grid">
                <div class="form-group">
                    <label>First Name</label>
                    <input id="firstName" name="first_name" required>
                </div>
                <div class="form-group">
                    <label>Last Name</label>
                    <input id="lastName" name="last_name" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Username</label>
                <input id="username" name="username" required>
            </div>
            
            <div class="form-group">
                <label>Email</label>
                <input id="email" type="email" name="email" required>
            </div>

            <div class="form-group">
                <label>Contact Number</label>
                <input id="contactNo" type="tel" name="contact_no" maxlength="20" pattern="[0-9+() .-]{7,20}" inputmode="tel" aria-describedby="contactNoHelp">
                <small id="contactNoHelp">Optional. Use 7 to 20 digits and phone symbols.</small>
            </div>
            
            <div class="form-group">
                <label>Password <small id="passwordHint">(required)</small></label>
                <input id="password" type="password" name="password">
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
