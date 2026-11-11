<?php

declare(strict_types=1);

// Module 2: Landing Page & Public Accessibility
// Zero module dependencies: No session required, no database connection, no authenticated controllers.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AGAP - Barangay Tumana | Katarungang Pambarangay</title>
    <meta name="description" content="Automated Grievance Assistance Platform (AGAP) for Katarungang Pambarangay of Barangay Tumana, Marikina City.">
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

            <!-- Section Jump Links (Desktop) -->
            <div class="landing-nav-links">
                <a href="#process" data-i18n="nav.process">Complaint Process</a>
                <a href="#mission-vision" data-i18n="nav.mission_vision">Mission &amp; Vision</a>
                <a href="#faq" data-i18n="nav.faq">FAQs</a>
            </div>

            <!-- Header Actions: Language Switcher & Sign In -->
            <div class="landing-nav-actions">
                <!-- Accessible Language Switcher Group -->
                <div class="lang-switcher" role="group" aria-label="Pumili ng Wika / Select Language">
                    <button type="button"
                            id="langToggleTl"
                            class="lang-btn"
                            aria-pressed="false"
                            aria-label="Piliin ang Tagalog">
                        Tagalog
                    </button>
                    <button type="button"
                            id="langToggleEn"
                            class="lang-btn active"
                            aria-pressed="true"
                            aria-label="Select English">
                        English
                    </button>
                </div>

                <!-- Sign In Link -->
                <a href="../auth/login.php" class="landing-login">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" y1="12" x2="3" y2="12"></line></svg>
                    <span data-i18n="nav.sign_in">Sign In</span>
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
                    Katarungang Pambarangay · Marikina City
                </p>
                <h1 id="landing-title">
                    <span data-i18n="hero.title_main">Better coordination.</span><br>
                    <span data-i18n="hero.title_sub">Stronger communities.</span>
                </h1>
                <p class="hero-description" data-i18n="hero.description">
                    A clear, connected public platform for managing community grievances, preliminary invitations, Lupon hearings, and amicable case resolutions in Barangay Tumana.
                </p>
                <div class="hero-actions">
                    <a href="../complaints/resident-portal.php?tab=register" class="landing-cta" data-i18n="hero.cta_create_account">Create Account</a>
                    <a href="../auth/login.php" class="landing-cta landing-cta-secondary" data-i18n="hero.cta_sign_in">Sign In</a>
                    <span class="hero-note" data-i18n="hero.for_residents">For the residents of Barangay Tumana</span>
                </div>
            </div>

            <!-- Service Overview Card -->
            <aside class="hero-card" aria-label="Overview of Barangay Tumana Justice Process">
                <div class="hero-card-top">
                    <img src="../../assets/images/tumana_logo.jpg" alt="Barangay Tumana Seal">
                    <div>
                        <span class="card-eyebrow" data-i18n="hero.overview_eyebrow">Barangay Tumana</span>
                        <strong data-i18n="hero.overview_title">Community Justice Services</strong>
                    </div>
                    <span class="status-dot" aria-label="System status online"></span>
                </div>
                <div class="hero-card-rule"></div>
                <p class="card-caption" data-i18n="hero.overview_caption">One Connected Process</p>
                <ol class="service-list">
                    <li>
                        <span class="service-number">01</span>
                        <span>
                            <strong data-i18n="hero.step1_title">Receive</strong>
                            <small data-i18n="hero.step1_desc">Organize complaints, statements, and parties involved</small>
                        </span>
                    </li>
                    <li>
                        <span class="service-number">02</span>
                        <span>
                            <strong data-i18n="hero.step2_title">Resolve</strong>
                            <small data-i18n="hero.step2_desc">Coordinate informal Paanyaya and formal mediation hearings</small>
                        </span>
                    </li>
                    <li>
                        <span class="service-number">03</span>
                        <span>
                            <strong data-i18n="hero.step3_title">Record</strong>
                            <small data-i18n="hero.step3_desc">Document amicable settlements and legal KP records</small>
                        </span>
                    </li>
                </ol>
                <div class="card-footnote">
                    <span aria-hidden="true">&#10003;</span> <span data-i18n="hero.footnote">Designed for transparent and accountable public service</span>
                </div>
            </aside>
            <div class="hero-orb hero-orb-one" aria-hidden="true"></div>
            <div class="hero-orb hero-orb-two" aria-hidden="true"></div>
        </section>

        <!-- ========================================================================= -->
        <!-- COMPLAINT HANDLING PROCESS (FILING -> PAANYAYA -> MEDIATION -> RESOLUTION) -->
        <!-- ========================================================================= -->
        <section id="process" class="landing-section landing-process" aria-labelledby="process-heading">
            <div class="section-heading">
                <div>
                    <p class="landing-kicker" data-i18n="process.kicker">Katarungang Pambarangay Workflow · RA 7160</p>
                    <h2 id="process-heading" data-i18n="process.title">How Barangay Tumana Handles Complaints</h2>
                </div>
                <p data-i18n="process.subtitle">From submitting your grievance to final resolution, track every official stage transparently under Republic Act No. 7160.</p>
            </div>

            <div class="process-pipeline" aria-label="Complaint lifecycle stages">
                <span class="pipeline-pill"><span class="pipeline-pill-num">1</span> <span data-i18n="process.flow_1">Filing</span></span>
                <span class="pipeline-arrow" aria-hidden="true">&#8594;</span>
                <span class="pipeline-pill"><span class="pipeline-pill-num">2</span> <span data-i18n="process.flow_2">Review &amp; Approval</span></span>
                <span class="pipeline-arrow" aria-hidden="true">&#8594;</span>
                <span class="pipeline-pill"><span class="pipeline-pill-num">3</span> <span data-i18n="process.flow_3">Paanyaya</span></span>
                <span class="pipeline-arrow" aria-hidden="true">&#8594;</span>
                <span class="pipeline-pill"><span class="pipeline-pill-num">4</span> <span data-i18n="process.flow_4">Mediation</span></span>
                <span class="pipeline-arrow" aria-hidden="true">&#8594;</span>
                <span class="pipeline-pill"><span class="pipeline-pill-num">5</span> <span data-i18n="process.flow_5">Conciliation / Arbitration</span></span>
                <span class="pipeline-arrow" aria-hidden="true">&#8594;</span>
                <span class="pipeline-pill"><span class="pipeline-pill-num">6</span> <span data-i18n="process.flow_6">Resolution / Closure</span></span>
            </div>

            <div class="process-grid">
                <article class="process-card">
                    <div class="process-card-head">
                        <span class="process-step-tag" data-i18n="process.step1_badge">Step 01 · Filing</span>
                        <span class="process-status-pill" data-i18n="process.step1_status">For Review / Saved Draft</span>
                    </div>
                    <h3 data-i18n="process.step1_title">Online or Walk-In Filing</h3>
                    <p data-i18n="process.step1_desc">Create a resident account or walk in at Barangay Tumana Hall to file your complaint with respondent details, incident story, up to 2 AI-suggested categories (read-only preview), and optional supporting files—or save a draft first.</p>
                </article>

                <article class="process-card">
                    <div class="process-card-head">
                        <span class="process-step-tag" data-i18n="process.step2_badge">Step 02 · LISO Screening</span>
                        <span class="process-status-pill" data-i18n="process.step2_status">Approved / Needs Information</span>
                    </div>
                    <h3 data-i18n="process.step2_title">Jurisdiction Check &amp; Approval</h3>
                    <p data-i18n="process.step2_desc">The Lupon Intake &amp; Screening Officer (LISO) reviews whether the dispute falls under Barangay Tumana KP jurisdiction, verifies party information, confirms up to 2 complaint categories, and marks valid filings Approved (or asks for missing details).</p>
                </article>

                <article class="process-card">
                    <div class="process-card-head">
                        <span class="process-step-tag" data-i18n="process.step3_badge">Step 03 · Stage 0 Dialogue</span>
                        <span class="process-status-pill" data-i18n="process.step3_status">Paanyaya</span>
                    </div>
                    <h3 data-i18n="process.step3_title">Informal Paanyaya</h3>
                    <p data-i18n="process.step3_desc">Before formal docketing, the Barangay Secretary or Clerk schedules a polite informal invitation (Paanyaya) giving both parties a chance for private, constructive dialogue and early voluntary settlement.</p>
                </article>

                <article class="process-card">
                    <div class="process-card-head">
                        <span class="process-step-tag" data-i18n="process.step4_badge">Step 04 · Punong Barangay</span>
                        <span class="process-status-pill" data-i18n="process.step4_status">Mediation</span>
                    </div>
                    <h3 data-i18n="process.step4_title">Formal Mediation (KP Form 9)</h3>
                    <p data-i18n="process.step4_desc">If unresolved during Paanyaya or a party fails to appear, the case is officially docketed and formal Summons (KP Form 9) are served for Mediation presided over by the Punong Barangay within the statutory 15-day period.</p>
                </article>

                <article class="process-card">
                    <div class="process-card-head">
                        <span class="process-step-tag" data-i18n="process.step5_badge">Step 05 · Lupon Panel</span>
                        <span class="process-status-pill" data-i18n="process.step5_status">Conciliation / Arbitration</span>
                    </div>
                    <h3 data-i18n="process.step5_title">Pangkat Conciliation or Arbitration</h3>
                    <p data-i18n="process.step5_desc">If Mediation does not settle the dispute, a three-member Pangkat Tagapagkasundo is constituted from the Lupon to hold formal Conciliation hearings (15 days, extendible up to 15 more days), or conduct Arbitration upon written mutual agreement.</p>
                </article>

                <article class="process-card process-card-outcomes">
                    <div class="process-card-head">
                        <span class="process-step-tag" data-i18n="process.step6_badge">Step 06 · Final Outcomes</span>
                        <span class="process-status-pill" data-i18n="process.step6_status">Case Closure</span>
                    </div>
                    <h3 data-i18n="process.step6_title">Resolution, Repudiation, CFA, or Dismissal</h3>
                    <p data-i18n="process.step6_desc">Depending on hearing results and statutory rules, the case concludes under one of these official dispositions:</p>
                    <div class="process-outcomes-list">
                        <div class="process-outcome-item">
                            <strong data-i18n="process.outcome_settled_label">Settled (Amicable Settlement / Arbitration)</strong>
                            <span data-i18n="process.outcome_settled_desc">Signed KP Form 16 or Arbitration Award carrying the force of a final court judgment after 10 days.</span>
                        </div>
                        <div class="process-outcome-item">
                            <strong data-i18n="process.outcome_repudiated_label">Repudiation</strong>
                            <span data-i18n="process.outcome_repudiated_desc">Sworn statement filed within 10 days only when consent was vitiated by fraud, violence, or intimidation.</span>
                        </div>
                        <div class="process-outcome-item">
                            <strong data-i18n="process.outcome_cfa_label">CFA (Certificate to File Action)</strong>
                            <span data-i18n="process.outcome_cfa_desc">Issued if no settlement is reached after mandatory KP stages (or willful default), allowing filing in court.</span>
                        </div>
                        <div class="process-outcome-item">
                            <strong data-i18n="process.outcome_dismissed_label">Dismissed</strong>
                            <span data-i18n="process.outcome_dismissed_desc">Closed when outside KP jurisdiction, withdrawn, barred, or dismissed due to complainant non-appearance.</span>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <!-- ========================================================================= -->
        <!-- BARANGAY TUMANA LUPONG TAGAPAMAYAPA — VISION & MISSION -->
        <!-- ========================================================================= -->
        <section id="mission-vision" class="landing-section landing-mv" aria-labelledby="mv-heading">
            <div class="section-heading">
                <div>
                    <p class="landing-kicker" data-i18n="mv.kicker">Barangay Tumana · Lupong Tagapamayapa</p>
                    <h2 id="mv-heading" data-i18n="mv.title">Our Vision &amp; Mission</h2>
                </div>
                <p data-i18n="mv.subtitle">Guided by the official charter of the Katarungang Pambarangay — Lupong Tagapamayapa of Barangay Tumana, Marikina City under RA 7160.</p>
            </div>

            <div class="mv-grid">
                <article class="mv-card">
                    <div class="mv-card-header">
                        <span class="mv-icon" aria-hidden="true">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                        </span>
                        <div>
                            <span class="mv-eyebrow" data-i18n="mv.vision_eyebrow">Vision · Bisyon</span>
                            <h3 data-i18n="mv.vision_heading">An Orderly, Peaceful, Joyful, and United Barangay Tumana</h3>
                        </div>
                    </div>
                    <p class="mv-body" data-i18n="mv.vision_body">Now and in the future, the Lupong Tagapamayapa, through resolving community matters with integrity and wisdom, strives to achieve an orderly, peaceful, joyful, and united Barangay Tumana.</p>
                    <div class="mv-official-quote">
                        <small data-i18n="mv.vision_official_label">Official Filipino Charter (Lupong Tagapamayapa):</small>
                        <p data-i18n="mv.vision_official_text">“Ngayon at sa hinaharap, ang Lupong Tagapamayapa, sa pamamagitan ng may integridad at talinong pagresolba sa mga usaping pang-komunidad; nagsusumikap na makamit ang isang maayos, mapayapa, masaya at nagkakaisang Barangay Tumana.”</p>
                    </div>
                </article>

                <article class="mv-card mv-card-mission">
                    <div class="mv-card-header">
                        <span class="mv-icon" aria-hidden="true">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
                        </span>
                        <div>
                            <span class="mv-eyebrow" data-i18n="mv.mission_eyebrow">Mission · Misyon</span>
                            <h3 data-i18n="mv.mission_heading">Prompt, Fair, and Integrity-Driven Community Justice</h3>
                        </div>
                    </div>
                    <p class="mv-body" data-i18n="mv.mission_body">It is the mission of the Lupong Tagapamayapa of Barangay Tumana to provide prompt and peaceful settlement of community challenges through an open, fair, and integrity-driven service, and to be an effective partner of the Courts and Homeowners' Associations (HOA) in de-escalating cases in accordance with the mandate of Republic Act 7160, Local Government Code of 1991.</p>
                    <div class="mv-official-quote">
                        <small data-i18n="mv.mission_official_label">Official Filipino Charter (Lupong Tagapamayapa):</small>
                        <p data-i18n="mv.mission_official_text">“Misyon ng Lupong Tagapamayapa ng Barangay Tumana na makapagbigay ng isang maagap at mapayapang pag-aayos ng mga hamong pang-komunidad sa pamamagitan ng isang bukas, patas at may integridad na serbisyo at maging epektibong katuwang ng Korte at ng HOA sa pagpapababa ng kaso nang naaayon sa mandato ng Republic Act 7160, Local Government Code of 1991.”</p>
                    </div>
                </article>
            </div>
        </section>

        <!-- ========================================================================= -->
        <!-- FREQUENTLY ASKED QUESTIONS -->
        <!-- ========================================================================= -->
        <section id="faq" class="landing-section landing-faq" aria-labelledby="faq-heading">
            <div class="faq-layout">
                <div class="faq-sidebar">
                    <p class="landing-kicker" data-i18n="faq.kicker">Frequently Asked Questions</p>
                    <h2 id="faq-heading" data-i18n="faq.title">Answers About Filing &amp; Barangay Hearings</h2>
                    <p class="faq-intro" data-i18n="faq.subtitle">Helpful information for Barangay Tumana residents before submitting a complaint or attending a Lupon session.</p>

                    <div class="faq-cta-box">
                        <strong data-i18n="faq.cta_title">Ready to file or check your case?</strong>
                        <p data-i18n="faq.cta_desc">Register with your email address or sign in to track your complaint status and hearing schedule.</p>
                        <div class="faq-cta-actions">
                            <a href="../complaints/resident-portal.php?tab=register" class="landing-cta" data-i18n="faq.cta_btn_register">Create Account</a>
                            <a href="../auth/login.php" class="landing-cta landing-cta-secondary" data-i18n="faq.cta_btn_signin">Sign In</a>
                        </div>
                    </div>
                </div>

                <div class="faq-list" role="region" aria-label="Frequently asked questions accordion">
                    <div class="faq-item is-open">
                        <button type="button" class="faq-trigger" aria-expanded="true" aria-controls="faq-panel-1">
                            <span data-i18n="faq.q1">How do I create an account and file a complaint online?</span>
                            <svg class="faq-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="faq-panel" id="faq-panel-1" data-i18n="faq.a1">
                            Click <strong>Create Account</strong> and register using your full name, username, email address, and password. After signing in, open your <strong>Profile</strong> inside the Resident Portal to enter your Barangay Tumana address and mobile number so the Barangay Secretary or Admin can verify your residency. Even while filing, you can also save a draft anytime and edit it before final submission.
                        </div>
                    </div>

                    <div class="faq-item">
                        <button type="button" class="faq-trigger" aria-expanded="false" aria-controls="faq-panel-2">
                            <span data-i18n="faq.q2">What disputes are covered under Katarungang Pambarangay (RA 7160)?</span>
                            <svg class="faq-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="faq-panel" id="faq-panel-2" hidden data-i18n="faq.a2">
                            Generally, disputes between individuals residing in Marikina City (following Barangay Tumana territorial jurisdiction rules under Section 409 of RA 7160) involving civil matters or minor criminal offenses punishable by not more than one (1) year imprisonment or a fine not exceeding &#8369;5,000 are handled here. During review, LISO screens jurisdiction—disputes involving non-negotiable criminal cases, urgent court relief, or excluded parties may be referred or closed accordingly.
                        </div>
                    </div>

                    <div class="faq-item">
                        <button type="button" class="faq-trigger" aria-expanded="false" aria-controls="faq-panel-3">
                            <span data-i18n="faq.q3">What is the difference between Paanyaya, Mediation, and Conciliation?</span>
                            <svg class="faq-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="faq-panel" id="faq-panel-3" hidden data-i18n="faq.a3">
                            <strong>Paanyaya</strong> is an informal pre-docket dialogue scheduled by the Barangay Secretary/Clerk to help both parties settle early without formal summons. If unresolved, the case moves to <strong>Mediation</strong> presided over by the Punong Barangay with official Summons (KP Form 9) for up to 15 days. If still unsettled, a three-member <strong>Pangkat Tagapagkasundo</strong> leads formal <strong>Conciliation</strong> hearings for 15 days (extendible up to 15 more days), or <strong>Arbitration</strong> if both parties agree in writing.
                        </div>
                    </div>

                    <div class="faq-item">
                        <button type="button" class="faq-trigger" aria-expanded="false" aria-controls="faq-panel-4">
                            <span data-i18n="faq.q4">What is the AI Suggestion feature during online filing, and can I edit it?</span>
                            <svg class="faq-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="faq-panel" id="faq-panel-4" hidden data-i18n="faq.a4">
                            When typing your complaint narrative, you may click <strong>AI Suggestion</strong> to see a <strong>read-only advisory preview</strong> of up to two (2) possible complaint categories based on your story. Residents do not select or alter the official classification—Barangay LISO/Lupon staff always review your narrative and confirm up to two final categories.
                        </div>
                    </div>

                    <div class="faq-item">
                        <button type="button" class="faq-trigger" aria-expanded="false" aria-controls="faq-panel-5">
                            <span data-i18n="faq.q5">How do I know my current complaint stage and hearing schedule?</span>
                            <svg class="faq-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="faq-panel" id="faq-panel-5" hidden data-i18n="faq.a5">
                            Sign in to your <strong>Resident Portal</strong> using your username or email address. Your dashboard and case record show your exact live stage (<strong>For Review</strong>, <strong>Approved</strong>, <strong>Paanyaya</strong>, <strong>Mediation</strong>, <strong>Conciliation</strong>, <strong>Arbitration</strong>, <strong>Settled</strong>, <strong>Repudiated</strong>, <strong>For CFA</strong>, <strong>CFA</strong>, or <strong>Dismissed</strong>) along with your assigned Paanyaya or Lupon hearing schedules.
                        </div>
                    </div>

                    <div class="faq-item">
                        <button type="button" class="faq-trigger" aria-expanded="false" aria-controls="faq-panel-6">
                            <span data-i18n="faq.q6">What happens if the respondent ignores Paanyaya or if we still do not settle?</span>
                            <svg class="faq-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="faq-panel" id="faq-panel-6" hidden data-i18n="faq.a6">
                            If a party does not attend informal Paanyaya, the Lupon proceeds with formal docketing and official Summons (KP Form 9) for Punong Barangay Mediation. If no agreement is reached through Mediation and Pangkat Conciliation—or if a settlement is validly repudiated within 10 days—the Lupon issues a <strong>Certificate to File Action (CFA)</strong> so the complainant can bring the case to court or the prosecutor's office.
                        </div>
                    </div>

                    <div class="faq-item">
                        <button type="button" class="faq-trigger" aria-expanded="false" aria-controls="faq-panel-7">
                            <span data-i18n="faq.q7">Can I still file a complaint if I prefer walk-in assistance?</span>
                            <svg class="faq-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="faq-panel" id="faq-panel-7" hidden data-i18n="faq.a7">
                            Yes. Residents without internet access or preferring personal assistance may file directly at the <strong>Office of the Punong Barangay / Lupon Secretariat in Barangay Tumana Hall</strong>. Walk-in complaints are also recorded in AGAP so your case details and hearing notices remain organized.
                        </div>
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
        <span data-i18n="footer.address">Office of the Punong Barangay · Barangay Tumana, Marikina City</span>
        <span data-i18n="footer.rights">© <?= date('Y') ?> Barangay Tumana. All rights reserved.</span>
    </footer>

    <!-- Load isolated I18n manager -->
    <script src="../../assets/js/modules/i18n.js?v=<?= file_exists(__DIR__ . '/../../assets/js/modules/i18n.js') ? filemtime(__DIR__ . '/../../assets/js/modules/i18n.js') : '1' ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const items = document.querySelectorAll('.faq-item');
            items.forEach((item) => {
                const trigger = item.querySelector('.faq-trigger');
                const panel = item.querySelector('.faq-panel');
                if (!trigger || !panel) return;

                trigger.addEventListener('click', () => {
                    const willOpen = trigger.getAttribute('aria-expanded') !== 'true';
                    items.forEach((other) => {
                        const t = other.querySelector('.faq-trigger');
                        const p = other.querySelector('.faq-panel');
                        if (!t || !p) return;
                        other.classList.remove('is-open');
                        t.setAttribute('aria-expanded', 'false');
                        p.setAttribute('hidden', '');
                    });
                    if (willOpen) {
                        item.classList.add('is-open');
                        trigger.setAttribute('aria-expanded', 'true');
                        panel.removeAttribute('hidden');
                    }
                });
            });
        });
    </script>
</body>
</html>
