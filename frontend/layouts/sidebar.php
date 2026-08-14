<?php
$roleId = (int) ($_SESSION['role_id'] ?? 0);
$currentPage = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
$isActive = static function (array $pages) use ($currentPage): string {
    return in_array($currentPage, $pages, true) ? ' class="is-active" aria-current="page"' : '';
};
$dashboardLink = [1 => '../dashboard/admin-dashboard.php', 2 => '../dashboard/clerk-dashboard.php', 3 => '../dashboard/lupon-dashboard.php', 4 => '../dashboard/server-dashboard.php'][$roleId] ?? '../auth/login.php';
?>
<aside class="sidebar" id="appSidebar">
    <div class="sidebar-brand"><span class="brand-mark">A</span><div><strong>AGAP</strong><span>Case Management</span></div></div>
    <nav class="sidebar-nav" aria-label="Main navigation">
        <p class="nav-label">Workspace</p>
        <a href="<?php echo $dashboardLink; ?>"<?php echo $isActive(['admin-dashboard.php', 'clerk-dashboard.php', 'lupon-dashboard.php', 'server-dashboard.php']); ?>>Dashboard</a>
        <a href="../notifications/inbox.php"<?php echo $isActive(['inbox.php']); ?>>Notifications</a>

        <?php if (in_array($roleId, [1, 2], true)): ?>
            <p class="nav-label">Case management</p>
            <a href="../residents/resident-list.php"<?php echo $isActive(['resident-list.php']); ?>>Residents</a>
            <a href="../complaints/complaint-list.php"<?php echo $isActive(['complaint-list.php', 'complaint-create.php', 'complaint-details.php']); ?>>Complaints</a>
            <a href="../cases/case-list.php"<?php echo $isActive(['case-list.php', 'create-case.php', 'case-details.php']); ?>>Cases</a>
            <a href="../assignments/case-assignment.php"<?php echo $isActive(['case-assignment.php']); ?>>Case assignments</a>
            <a href="../pangkat/pangkat-list.php"<?php echo $isActive(['pangkat-list.php']); ?>>Pangkat</a>
            <p class="nav-label">Operations</p>
            <a href="../hearings/schedules.php"<?php echo $isActive(['schedules.php', 'calendar.php']); ?>>Hearings and deadlines</a>
            <a href="../documents/document-center.php"<?php echo $isActive(['document-center.php', 'summons.php', 'settlements.php', 'cfa.php', 'kp-form-9.php']); ?>>KP documents</a>
            <a href="../gps/incident-map.php"<?php echo $isActive(['incident-map.php']); ?>>Incident locations</a>
            <a href="../gps/proof-service.php"<?php echo $isActive(['proof-service.php']); ?>>Proof of service</a>
            <a href="../search/records.php"<?php echo $isActive(['records.php']); ?>>Records search</a>
            <a href="../reports/report-list.php"<?php echo $isActive(['report-list.php']); ?>>Reports and export</a>
        <?php endif; ?>

        <?php if ($roleId === 3): ?>
            <p class="nav-label">Case work</p>
            <a href="../hearings/schedules.php"<?php echo $isActive(['schedules.php', 'calendar.php']); ?>>Hearings and deadlines</a>
            <a href="../pangkat/pangkat-list.php"<?php echo $isActive(['pangkat-list.php']); ?>>Pangkat</a>
            <a href="../gps/incident-map.php"<?php echo $isActive(['incident-map.php']); ?>>Incident locations</a>
        <?php endif; ?>

        <?php if ($roleId === 4): ?>
            <p class="nav-label">Field service</p>
            <a href="../gps/proof-service.php"<?php echo $isActive(['proof-service.php']); ?>>Proof of service</a>
            <a href="../gps/incident-map.php"<?php echo $isActive(['incident-map.php']); ?>>Incident locations</a>
        <?php endif; ?>

        <?php if ($roleId === 1): ?>
            <p class="nav-label">Administration</p>
            <a href="../users/user-list.php"<?php echo $isActive(['user-list.php', 'roles.php']); ?>>Users and roles</a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-footer"><span>AGAP</span><small>Barangay Tumana</small></div>
</aside>
