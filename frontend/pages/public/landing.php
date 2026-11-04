<?php

declare(strict_types=1);

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

        /* FAQ Accordion Section */
        .faq-section {
            max-width: 1240px;
            margin: 40px auto 60px;
            padding: 0 32px;
        }

        .faq-accordion {
            margin-top: 24px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .faq-item {
            background: #ffffff;
            border: 1px solid #dfe8e3;
            border-radius: 10px;
            overflow: hidden;
            transition: border-color 0.2s ease;
        }

        .faq-item:focus-within {
            border-color: #2b6b5e;
        }

        .faq-trigger {
            width: 100%;
            text-align: left;
            background: transparent;
            border: none;
            padding: 18px 24px;
            font-size: 1rem;
            font-weight: 700;
            color: #102b2d;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            font-family: inherit;
        }

        .faq-trigger svg {
            transition: transform 0.25s ease;
            flex-shrink: 0;
            color: #2b6b5e;
        }

        .faq-trigger[aria-expanded="true"] svg {
            transform: rotate(180deg);
        }

        .faq-panel {
            padding: 0 24px 18px;
            font-size: 0.92rem;
            color: #617475;
            line-height: 1.6;
            display: none;
        }

        .faq-panel.is-open {
            display: block;
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
            <a class="landing-brand" href="landing.php" aria-label="AGAP Home">
                <img src="../../assets/images/tumana_logo.jpg" alt="Barangay Tumana Seal">
                <span>
                    <strong data-i18n="nav.brand_title">AGAP</strong>
                    <small data-i18n="nav.brand_sub">Barangay Tumana, Marikina City</small>
                </span>
            </a>

            <!-- Quick Navigation Links -->
            <div class="landing-nav-links">
                <a href="#process" class="landing-nav-link" data-i18n="nav.process">Hakbang sa Pagdulog</a>
                <a href="#services" class="landing-nav-link" data-i18n="nav.services">Mga Serbisyo</a>
                <a href="#faq" class="landing-nav-link" data-i18n="nav.faq">Mga Tanong (FAQ)</a>
            </div>

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
                    <a href="#process" class="landing-cta-guide" data-i18n="hero.cta_guide">Gabay sa Pagdulog</a>
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

        <!-- ========================================================================= -->
        <!-- PUBLIC GRIEVANCE INSTRUCTIONS (HAKBANG SA PAGDULOG) -->
        <!-- ========================================================================= -->
        <section id="process" class="grievance-guide-section" aria-labelledby="guide-title">
            <div class="section-heading">
                <div>
                    <p class="landing-kicker" data-i18n="guide.kicker">Alituntunin at Pamamaraan</p>
                    <h2 id="guide-title" data-i18n="guide.title">Paano Magdulog ng Sumbong o Reklamo?</h2>
                </div>
                <p data-i18n="guide.subtitle">
                    Sundin ang apat na mahahalagang yugto ng Katarungang Pambarangay para sa mapayapang pagkakaayos:
                </p>
            </div>

            <div class="guide-grid">
                <article class="guide-card">
                    <span class="guide-badge" data-i18n="guide.step1_badge">Yugto 0 · Paunang Pag-uusap</span>
                    <h3 data-i18n="guide.step1_title">1. Paanyaya (Informal Dialogue)</h3>
                    <p data-i18n="guide.step1_desc">
                        Bago pormal na mag-isyu ng summons, nagpapadala muna ang Barangay ng Paanyaya para sa magalang at pribadong pag-uusap ng magkabilang panig sa Tanggapan ng Punong Barangay.
                    </p>
                </article>

                <article class="guide-card">
                    <span class="guide-badge" data-i18n="guide.step2_badge">Yugto 1 · Pagpapasya ng Punong Barangay</span>
                    <h3 data-i18n="guide.step2_title">2. Pormal na Mediation</h3>
                    <p data-i18n="guide.step2_desc">
                        Kung hindi naayos sa paanyaya o hindi sumipot ang inirereklamo, pormal na idodoket ang kaso at mag-iisyu ng Pormal na Patawag (Summons - KP Form 9) para sa pagdinig sa ilalim ng Punong Barangay.
                    </p>
                </article>

                <article class="guide-card">
                    <span class="guide-badge" data-i18n="guide.step3_badge">Yugto 2 · Pangkat Tagapagkasundo</span>
                    <h3 data-i18n="guide.step3_title">3. Conciliation ng Pangkat</h3>
                    <p data-i18n="guide.step3_desc">
                        Kapag hindi nagkasundo sa Mediation sa loob ng 15 araw, bubuuin ang tatlong (3) kasapi ng Pangkat Tagapagkasundo mula sa Lupon upang ipagpatuloy ang pag-uusap.
                    </p>
                </article>

                <article class="guide-card">
                    <span class="guide-badge" data-i18n="guide.step4_badge">Yugto 3 · Pinal na Desisyon</span>
                    <h3 data-i18n="guide.step4_title">4. Kasunduan o Sertipikasyon (CFA)</h3>
                    <p data-i18n="guide.step4_desc">
                        Kapag nagkasundo, pipirma sa Kasunduan (Amicable Settlement - KP Form 16) na may bisa ng hatol ng hukuman. Kung mabigo matapos ang lahat ng yugto, mag-iisyu ng Certificate to File Action (CFA).
                    </p>
                </article>
            </div>
        </section>

        <!-- ========================================================================= -->
        <!-- CORE SERVICES & LUPON CAPABILITIES -->
        <!-- ========================================================================= -->
        <section id="services" class="landing-features" aria-labelledby="features-title">
            <div class="section-heading">
                <div>
                    <p class="landing-kicker" data-i18n="services.kicker">Mga Kakayahan ng Plataporma</p>
                    <h2 id="features-title" data-i18n="services.title">Maayos na Sistema sa Bawat Hakbang</h2>
                </div>
                <p data-i18n="services.subtitle">
                    Pinag-uugnay ang buong daloy ng Katarungang Pambarangay mula sa pagtanggap ng salaysay hanggang sa pinal na kasulatan.
                </p>
            </div>
            <div class="feature-grid">
                <article class="feature-card">
                    <span class="feature-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M7 3.75h7l4.25 4.5v12H7a2 2 0 0 1-2-2v-12a2.5 2.5 0 0 1 2-2.5Z"/><path d="M14 4v5h4M8.5 13h7M8.5 16.5h7"/></svg>
                    </span>
                    <span class="feature-number" data-i18n="services.card1_num">01 / PAGTANGGAP NG SUMBONG</span>
                    <h3 data-i18n="services.card1_title">Maagap na Case Intake</h3>
                    <p data-i18n="services.card1_desc">Mabilis at organisadong pagtatala ng mga detalye ng reklamo, lokasyon ng insidente, at pagkakakilanlan ng mga mamamayan.</p>
                </article>
                <article class="feature-card">
                    <span class="feature-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M12 21s7-3.5 7-9V5l-7-2-7 2v7c0 5.5 7 9 7 9Z"/><path d="m9 12 2 2 4-4"/></svg>
                    </span>
                    <span class="feature-number" data-i18n="services.card2_num">02 / PAANYAYA AT MEDIATION</span>
                    <h3 data-i18n="services.card2_title">Maayos na Pagdinig</h3>
                    <p data-i18n="services.card2_desc">Pagtatakda ng mga sesyon, pagbabantay sa mga statutory deadline, at pagpapadala ng opisyal na paanyaya at summons sa tulong ng mga kawani.</p>
                </article>
                <article class="feature-card">
                    <span class="feature-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M6 3.75h8l4 4v12.5H6a2 2 0 0 1-2-2v-12.5a2 2 0 0 1 2-2Z"/><path d="M14 4v4h4M8 13h8M8 16.5h5"/></svg>
                    </span>
                    <span class="feature-number" data-i18n="services.card3_num">03 / DOKUMENTASYON NG KP</span>
                    <h3 data-i18n="services.card3_title">Opisyal na KP Forms</h3>
                    <p data-i18n="services.card3_desc">Awtomatikong paggawa at pag-iingat ng mga legal na dokumento alinsunod sa Local Government Code (RA 7160).</p>
                </article>
                <article class="feature-card">
                    <span class="feature-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M4 19.5V12M10 19.5V5M16 19.5V9M22 19.5V3"/></svg>
                    </span>
                    <span class="feature-number" data-i18n="services.card4_num">04 / TRANSPARENCY AT ULAT</span>
                    <h3 data-i18n="services.card4_title">Ulat at Pananagutan</h3>
                    <p data-i18n="services.card4_desc">Malinaw na pagsubaybay sa kalagayan ng bawat kaso upang matiyak na bawat mamamayan ay nabibigyan ng patas at agarang aksyon.</p>
                </article>
            </div>
        </section>

        <!-- ========================================================================= -->
        <!-- FREQUENTLY ASKED QUESTIONS (FAQ) -->
        <!-- ========================================================================= -->
        <section id="faq" class="faq-section" aria-labelledby="faq-title">
            <div class="section-heading">
                <div>
                    <p class="landing-kicker" data-i18n="faq.kicker">Katanungan ng Mamamayan</p>
                    <h2 id="faq-title" data-i18n="faq.title">Mga Karaniwang Tanong ukol sa Barangay Justice</h2>
                </div>
            </div>

            <div class="faq-accordion">
                <!-- Q1 -->
                <div class="faq-item">
                    <button type="button" class="faq-trigger" aria-expanded="false" onclick="toggleFaq(this)">
                        <span data-i18n="faq.q1">Ano ang saklaw ng Katarungang Pambarangay sa Barangay Tumana?</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </button>
                    <div class="faq-panel">
                        <p data-i18n="faq.a1">
                            Saklaw nito ang mga alitan, hindi pagkakaunawaan, utang, pagkasira ng ari-arian, at mga minor na paglabag sa pagitan ng mga residenteng naninirahan sa iisang lungsod o magkatabing barangay kung saan ang parusa ay hindi lalampas sa isang taong pagkabilanggo o multang hindi hihigit sa ₱5,000.
                        </p>
                    </div>
                </div>

                <!-- Q2 -->
                <div class="faq-item">
                    <button type="button" class="faq-trigger" aria-expanded="false" onclick="toggleFaq(this)">
                        <span data-i18n="faq.q2">Ano ang pagkakaiba ng Paanyaya sa Pormal na Summons?</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </button>
                    <div class="faq-panel">
                        <p data-i18n="faq.a2">
                            Ang Paanyaya (Stage 0) ay isang magalang at impormal na imbitasyon upang mag-usap nang pribado bago pormal na magbukas ng docketed case record. Samantala, ang Pormal na Summons (KP Form 9) ay may legal na kapangyarihan sa ilalim ng batas na nag-aatas sa inirereklamo na humarap sa pagdinig.
                        </p>
                    </div>
                </div>

                <!-- Q3 -->
                <div class="faq-item">
                    <button type="button" class="faq-trigger" aria-expanded="false" onclick="toggleFaq(this)">
                        <span data-i18n="faq.q3">May bayad ba ang pagdulog o paghingi ng tulong sa Lupon?</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </button>
                    <div class="faq-panel">
                        <p data-i18n="faq.a3">
                            Wala pong bayad. Ang lahat ng serbisyo ng Katarungang Pambarangay at pamamagitan ng Lupon Tagapamayapa ay libre para sa lahat ng mamamayan ng Barangay Tumana.
                        </p>
                    </div>
                </div>

                <!-- Q4 -->
                <div class="faq-item">
                    <button type="button" class="faq-trigger" aria-expanded="false" onclick="toggleFaq(this)">
                        <span data-i18n="faq.q4">Ano ang mangyayari kung hindi sumipot ang inirereklamo sa pagdinig?</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </button>
                    <div class="faq-panel">
                        <p data-i18n="faq.a4">
                            Kung ang inirereklamo ay sadyang hindi sumipot matapos tumanggap ng summons nang walang makatwirang dahilan, mawawalan siya ng karapatang magharap ng counterclaim at maaaring mag-isyu ang Lupon ng Certificate to File Action o iendorso ang kaso sa hukuman.
                        </p>
                    </div>
                </div>

                <!-- Q5 -->
                <div class="faq-item">
                    <button type="button" class="faq-trigger" aria-expanded="false" onclick="toggleFaq(this)">
                        <span data-i18n="faq.q5">Paano kapag ang nagrereklamo (complainant) ang hindi sumipot?</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </button>
                    <div class="faq-panel">
                        <p data-i18n="faq.a5">
                            Kung ang nagsumbong ang hindi sumipot nang walang balidong dahilan, ang reklamo ay ididismis at hindi siya mabibigyan ng Certificate to File Action.
                        </p>
                    </div>
                </div>
            </div>
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

    <!-- Interactive FAQ Script -->
    <script>
        function toggleFaq(btn) {
            const isExpanded = btn.getAttribute('aria-expanded') === 'true';
            const panel = btn.nextElementSibling;

            // Close other panels
            document.querySelectorAll('.faq-trigger').forEach(trigger => {
                trigger.setAttribute('aria-expanded', 'false');
                if (trigger.nextElementSibling) {
                    trigger.nextElementSibling.classList.remove('is-open');
                }
            });

            if (!isExpanded) {
                btn.setAttribute('aria-expanded', 'true');
                panel.classList.add('is-open');
            }
        }
    </script>

    <!-- Load isolated I18n manager -->
    <script src="../../assets/js/modules/i18n.js?v=<?= file_exists(__DIR__ . '/../../assets/js/modules/i18n.js') ? filemtime(__DIR__ . '/../../assets/js/modules/i18n.js') : '1' ?>"></script>
</body>
</html>
