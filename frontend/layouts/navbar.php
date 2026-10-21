<?php
$roleNames = [1 => 'Administrator', 2 => 'Lupon Clerk', 3 => 'Lupon Member', 4 => 'Summons Server'];
$roleName = $roleNames[(int) ($_SESSION['role_id'] ?? 0)] ?? 'Staff Member';
?>
<nav class="navbar">
    <button class="nav-menu-toggle" type="button" aria-label="Open navigation" aria-expanded="false" data-sidebar-toggle>Menu</button>
    <div class="navbar-context"><span class="navbar-eyebrow">Barangay Tumana</span><span class="navbar-title">Katarungang Pambarangay</span></div>
    <div class="navbar-right">
        <a class="notification-link" href="../notifications/inbox.php" aria-label="Open notifications">Notifications</a>
        <div class="user-summary"><strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'User', ENT_QUOTES, 'UTF-8'); ?></strong><span><?php echo $roleName; ?></span></div>
        <a class="notification-link" href="../settings/index.php<?php echo in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2], true) ? '' : '?tab=profile'; ?>">Settings</a>
        <a class="notification-link" href="../auth/change-password.php">Change password</a>
        <form method="post" action="../../../backend/api/auth/logout.php" style="display: inline;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CsrfService::token(), ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="logout-btn">Sign out</button>
        </form>
    </div>
</nav>
