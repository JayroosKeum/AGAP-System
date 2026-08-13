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
        <a href="../../../backend/api/auth/logout.php" class="logout-btn">Sign out</a>
    </div>
</nav>
