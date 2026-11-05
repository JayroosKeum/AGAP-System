<?php

declare(strict_types=1);

// Module 2: Landing Page & Public Accessibility
// Zero module dependencies: No session required, no database connection, no authenticated controllers.
?>
<!DOCTYPE html>
<html lang="tl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AGAP - Barangay Tumana | Katarungang Pambarangay</title>
    <meta name="description" content="Automated Grievance Assistance Platform (AGAP) para sa Katarungang Pambarangay ng Barangay Tumana, Lungsod ng Marikina.">
    <link rel="icon" type="image/jpeg" href="../../assets/images/tumana_logo.jpg">
    <link rel="stylesheet" href="../../assets/css/app.css?v=<?= file_exists(__DIR__ . '/../../assets/css/app.css') ? filemtime(__DIR__ . '/../../assets/css/app.css') : '1' ?>">
    <link rel="stylesheet" href="../../assets/css/landing.css?v=<?= file_exists(__DIR__ . '/../../assets/css/landing.css') ? filemtime(__DIR__ . '/../../assets/css/landing.css') : '1' ?>">
    <style>
        /* Accessible Header & Language Switcher Styles */
        .landing-nav-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .lang-switcher {
            display: inline-flex;
            align-items: center;
            background: #ffffff;
            border: 1.5px solid #d7e1db;
            border-radius: 9999px;
            padding: 3px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .lang-btn {
            background: transparent;
            border: none;
            padding: 5px 12px;
            border-radius: 9999px;
            font-size: 0.82rem;
            font-weight: 700;
            color: #617475;
            cursor: pointer;
            transition: all 0.2s ease;
            user-select: none;
        }

        .lang-btn:hover {
            color: #102b2d;
        }

        .lang-btn.active {
            background: #2b6b5e;
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(43, 107, 94, 0.3);
        }

        .landing-nav-links {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .landing-nav-link {
            text-decoration: none;
            color: #173638;
            font-size: 0.88rem;
            font-weight: 600;
            transition: color 0.15s ease;
        }

        .landing-nav-link:hover {
            color: #2b6b5e;
        }

        @media (max-width: 860px) {
            .landing-nav-links {
                display: none;
            }
        }

        /* Public Grievance Guide Section */
        .grievance-guide-section {
            max-width: 1240px;
            margin: 40px auto;
            padding: 40px 32px;
            background: #ffffff;
            border: 1px solid #dfe8e3;
            border-radius: 16px;
        }

        .guide-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 20px;
            margin-top: 28px;
        }

        .guide-card {
            background: #fbfcfa;
            border: 1px solid #dfe8e3;
            border-radius: 12px;
            padding: 22px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .guide-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.06);
            border-color: #2b6b5e;
        }

        .guide-badge {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 3px 8px;
            border-radius: 4px;
            background: #eaf2ed;
            color: #2b6b5e;
            margin-bottom: 12px;
            width: fit-content;
        }

        .guide-card h3 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #102b2d;
            margin-bottom: 8px;
        }

        .guide-card p {
            font-size: 0.88rem;
            color: #617475;
            line-height: 1.55;
        }

        .hero-actions .landing-cta-guide {
            display: inline-flex;
            align-items: center;
            background: #ffffff;
            color: #2b6b5e;
            border: 1.5px solid #2b6b5e;
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: 700;
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }

        .hero-actions .landing-cta-guide:hover {
            background: #2b6b5e;
            color: #ffffff;
        }
    </style>
</head>
<body class="landing-page">

    <!-- ========================================================================= -->
    <!-- NAVIGATION WITH ACCESSIBLE LANGUAGE SWITCHER -->
    <!-- ========================================================================= -->
    <header>
        <nav class="landing-nav" aria-label="Main public navigation">
            <a class="landing-brand" href="index.php" aria-label="AGAP Home">
                <img src="../../assets/images/tumana_logo.jpg" alt="Barangay Tumana Seal">
                <span>
                    <strong data-i18n="nav.brand_title">AGAP</strong>
                    <small data-i18n="nav.brand_sub">Barangay Tumana, Marikina City</small>
                </span>
            </a>

            <!-- Header Actions: Language Switcher & Staff Sign-in -->
            <div class="landing-nav-actions">
                <!-- Accessible Language Switcher Group -->
                <div class="lang-switcher" role="group" aria-label="Pumili ng Wika / Select Language">
                    <button type="button"
                            id="langToggleTl"
                            class="lang-btn active"
                            aria-pressed="true"
                            aria-label="Piliin ang Tagalog">
                        Tagalog
                    </button>
                    <button type="button"
                            id="langToggleEn"
                            class="lang-btn"
                            aria-pressed="false"
                            aria-label="Select English">
                        English
                    </button>
                </div>

                <!-- Staff Sign In Link -->
                <a href="../auth/login.php" class="landing-login" data-i18n="nav.staff_login">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" y1="12" x2="3" y2="12"></line></svg>
                    <span>Mag-sign in (Kawani)</span>
                </a>
            </div>
        </nav>
    </header>

    <main>
        <!-- ========================================================================= -->
        <!-- HERO SECTION -->
        <!-- ========================================================================= -->
        <section class="landing-hero" aria-labelledby="landing-title">
            <div class="hero-copy">
                <p class="landing-kicker" data-i18n="hero.kicker">
                    <span></span> Katarungang Pambarangay · Lungsod ng Marikina
                </p>
                <h1 id="landing-title">
                    <span data-i18n="hero.title_main">Mas Maayos na Ugnayan.</span><br>
                    <span data-i18n="hero.title_sub">Mas Mapayapang Pamayanan.</span>
                </h1>
                <p class="hero-description" data-i18n="hero.description">
                    Isang malinaw at maasahang pampublikong plataporma para sa pamamahala ng mga idinudulog na sumbong, paunang paanyaya, pagdinig ng Lupon, at mapayapang pagkakasundo sa Barangay Tumana.
                </p>
                <div class="hero-actions">
                    <a href="../auth/login.php" class="landing-cta" data-i18n="hero.cta_login">Pag-login ng Kawani</a>
                    <span class="hero-note" data-i18n="hero.for_residents">Para sa mga mamamayan ng Barangay Tumana</span>
                </div>
            </div>

            <!-- Service Overview Card -->
            <aside class="hero-card" aria-label="Overview of Barangay Tumana Justice Process">
                <div class="hero-card-top">
                    <img src="../../assets/images/tumana_logo.jpg" alt="Barangay Tumana Seal">
                    <div>
                        <span class="card-eyebrow" data-i18n="hero.overview_eyebrow">Barangay Tumana</span>
                        <strong data-i18n="hero.overview_title">Serbisyo sa Katarungang Pambarangay</strong>
                    </div>
                    <span class="status-dot" aria-label="System status online"></span>
                </div>
                <div class="hero-card-rule"></div>
                <p class="card-caption" data-i18n="hero.overview_caption">Tuloy-tuloy at Maayos na Proseso</p>
                <ol class="service-list">
                    <li>
                        <span class="service-number">01</span>
                        <span>
                            <strong data-i18n="hero.step1_title">Tanggapin (Intake)</strong>
                            <small data-i18n="hero.step1_desc">Pagtatala ng idinudulog na salaysay at mga partidong sangkot</small>
                        </span>
                    </li>
                    <li>
                        <span class="service-number">02</span>
                        <span>
                            <strong data-i18n="hero.step2_title">Ayusin (Resolve)</strong>
                            <small data-i18n="hero.step2_desc">Impormal na Paanyaya at Pormal na Pagdinig ng Lupon Tagapamayapa</small>
                        </span>
                    </li>
                    <li>
                        <span class="service-number">03</span>
                        <span>
                            <strong data-i18n="hero.step3_title">Itala (Record)</strong>
                            <small data-i18n="hero.step3_desc">Pagsasaayos ng Kasulatan ng Kasunduan at opisyal na dokumento</small>
                        </span>
                    </li>
                </ol>
                <div class="card-footnote">
                    <span aria-hidden="true">&#10003;</span> <span data-i18n="hero.footnote">Dinisenyo para sa tapat at makatarungang paglilingkod-bayan</span>
                </div>
            </aside>
            <div class="hero-orb hero-orb-one" aria-hidden="true"></div>
            <div class="hero-orb hero-orb-two" aria-hidden="true"></div>
        </section>

        <!-- Bottom City of Marikina Seal Strip -->
        <section class="landing-bottom">
            <div class="bottom-mark">
                <img src="../../assets/images/marikina_logo.jpg" alt="City of Marikina Seal">
            </div>
            <div>
                <p class="landing-kicker">Lungsod ng Marikina · Disiplina, Husay, at Katapatan</p>
                <h2>Naglilingkod para sa maayos at mapayapang pamayanan ng Tumana.</h2>
            </div>
        </section>
    </main>

    <!-- ========================================================================= -->
    <!-- PUBLIC FOOTER -->
    <!-- ========================================================================= -->
    <footer class="landing-footer">
        <span data-i18n="footer.platform_name">AGAP · Automated Grievance Assistance Platform</span>
        <span data-i18n="footer.address">Tanggapan ng Punong Barangay · Barangay Tumana, Marikina City</span>
        <span data-i18n="footer.rights">© <?= date('Y') ?> Barangay Tumana. Lahat ng karapatan ay nakalaan.</span>
    </footer>

    <!-- Load isolated I18n manager -->
    <script src="../../assets/js/modules/i18n.js?v=<?= file_exists(__DIR__ . '/../../assets/js/modules/i18n.js') ? filemtime(__DIR__ . '/../../assets/js/modules/i18n.js') : '1' ?>"></script>
</body>
</html>
