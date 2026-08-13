<?php include '../../layouts/header.php'; ?>
<link rel="stylesheet" href="../../assets/css/landing.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/landing.css'); ?>">

<main class="landing-page">
    <nav class="landing-nav"><a class="landing-logo" href="index.php"><span>A</span> AGAP</a><a href="../auth/login.php" class="landing-login">Sign in</a></nav>
    <section class="landing-hero"><div><p class="landing-kicker">Barangay Tumana</p><h1>Better case management for peaceful communities.</h1><p>AGAP brings complaints, mediation, hearings, documents, and reporting into one accountable Katarungang Pambarangay workspace.</p><a href="../auth/login.php" class="landing-cta">Access the workspace <span aria-hidden="true">→</span></a></div><aside class="landing-panel"><span>AGAP WORKSPACE</span><strong>One source of truth for every case.</strong><ul><li>Organized complaint records</li><li>Hearing and deadline tracking</li><li>KP documents and reports</li></ul></aside></section>
    <section class="landing-features"><header><p class="landing-kicker">Built for day-to-day operations</p><h2>From filing to final resolution.</h2></header><div class="feature-grid"><article><b>01</b><h3>Manage cases</h3><p>Keep resident, complaint, and case records connected and searchable.</p></article><article><b>02</b><h3>Run proceedings</h3><p>Coordinate Lupon assignments, Pangkat formation, hearings, and deadlines.</p></article><article><b>03</b><h3>Document outcomes</h3><p>Generate KP documents and maintain auditable case histories.</p></article><article><b>04</b><h3>Stay informed</h3><p>Use notifications and reports to keep operations on track.</p></article></div></section>
</main>
<footer class="landing-footer">AGAP · Automated Grievance Assistance Platform · © <?php echo date('Y'); ?></footer>
<?php include '../../layouts/footer.php'; ?>
