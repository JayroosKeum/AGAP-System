<?php include '../../layouts/header.php'; ?>
<link rel="stylesheet" href="../../assets/css/landing.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/landing.css'); ?>">

<main class="landing-page">
    <nav class="landing-nav" aria-label="Main navigation">
        <a class="landing-brand" href="index.php" aria-label="AGAP home">
            <img src="../../assets/images/tumana_logo.jpg" alt="">
            <span><strong>AGAP</strong><small>Barangay Tumana</small></span>
        </a>
    </nav>

    <section class="landing-hero" aria-labelledby="landing-title">
        <div class="hero-copy">
            <p class="landing-kicker"><span></span> Katarungang Pambarangay · Marikina City</p>
            <h1 id="landing-title">Better coordination.<br><span>Stronger communities.</span></h1>
            <p class="hero-description">A clear, connected workspace for managing barangay complaints, mediation, hearings, and case outcomes—with accountability at every step.</p>
            <div class="hero-actions">
                <a href="../auth/login.php" class="landing-cta">Staff sign in</a>
                <span class="hero-note">For authorized barangay personnel</span>
            </div>
        </div>

        <aside class="hero-card" aria-label="AGAP service overview">
            <div class="hero-card-top">
                <img src="../../assets/images/tumana_logo.jpg" alt="Barangay Tumana seal">
                <div><span class="card-eyebrow">Barangay Tumana</span><strong>Community case services</strong></div>
                <span class="status-dot" aria-label="Service platform"></span>
            </div>
            <div class="hero-card-rule"></div>
            <p class="card-caption">One connected process</p>
            <ol class="service-list">
                <li><span class="service-number">01</span><span><strong>Receive</strong><small>Organize complaints and resident records</small></span></li>
                <li><span class="service-number">02</span><span><strong>Resolve</strong><small>Coordinate mediation and hearings</small></span></li>
                <li><span class="service-number">03</span><span><strong>Record</strong><small>Document decisions and case progress</small></span></li>
            </ol>
            <div class="card-footnote"><span aria-hidden="true">✓</span> Designed for accountable public service</div>
        </aside>
        <div class="hero-orb hero-orb-one" aria-hidden="true"></div>
        <div class="hero-orb hero-orb-two" aria-hidden="true"></div>
    </section>

    <section class="landing-features" aria-labelledby="features-title">
        <div class="section-heading">
            <div>
                <p class="landing-kicker">A more connected workflow</p>
                <h2 id="features-title">Everything in its right place.</h2>
            </div>
            <p>Keep the work moving and the record clear—from the first complaint to its documented resolution.</p>
        </div>
        <div class="feature-grid">
            <article class="feature-card">
                <span class="feature-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M7 3.75h7l4.25 4.5v12H7a2 2 0 0 1-2-2v-12a2.5 2.5 0 0 1 2-2.5Z"/><path d="M14 4v5h4M8.5 13h7M8.5 16.5h7"/></svg>
                </span>
                <span class="feature-number">01 / CASE INTAKE</span>
                <h3>Manage cases</h3>
                <p>Connect complaints, parties, and case records in one organized place.</p>
            </article>
            <article class="feature-card">
                <span class="feature-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M12 21s7-3.5 7-9V5l-7-2-7 2v7c0 5.5 7 9 7 9Z"/><path d="m9 12 2 2 4-4"/></svg>
                </span>
                <span class="feature-number">02 / PROCEEDINGS</span>
                <h3>Run proceedings</h3>
                <p>Coordinate Lupon assignments, mediation, hearings, and deadlines.</p>
            </article>
            <article class="feature-card">
                <span class="feature-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M6 3.75h8l4 4v12.5H6a2 2 0 0 1-2-2v-12.5a2 2 0 0 1 2-2Z"/><path d="M14 4v4h4M8 13h8M8 16.5h5"/></svg>
                </span>
                <span class="feature-number">03 / CASE RECORD</span>
                <h3>Document outcomes</h3>
                <p>Prepare KP documents and preserve a reliable history of each case.</p>
            </article>
            <article class="feature-card">
                <span class="feature-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M4 19.5V12M10 19.5V5M16 19.5V9M22 19.5V3"/></svg>
                </span>
                <span class="feature-number">04 / OVERSIGHT</span>
                <h3>Stay informed</h3>
                <p>Follow deadlines, notifications, and reports to support timely action.</p>
            </article>
        </div>
    </section>

    <section class="landing-bottom">
        <div class="bottom-mark"><img src="../../assets/images/marikina_logo.jpg" alt="City of Marikina seal"></div>
        <div><p class="landing-kicker">Public service, with clarity</p><h2>Supporting fair and orderly community resolution.</h2></div>
    </section>
</main>

<footer class="landing-footer">
    <span>AGAP <span aria-hidden="true">·</span> Automated Grievance Assistance Platform</span>
    <span>Barangay Tumana, Marikina City <span aria-hidden="true">·</span> © <?php echo date('Y'); ?></span>
</footer>
<?php include '../../layouts/footer.php'; ?>
