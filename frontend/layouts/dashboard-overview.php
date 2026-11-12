<section class="dashboard-overview pb-dashboard-overview" aria-label="Punong Barangay Executive Overview">
    <!-- Period Tabs & Inline Selector + Captain Quick Shortcuts -->
    <div class="pb-subtoolbar">
        <div class="pb-timeframe-unified" role="region" aria-label="Dashboard timeframe selector">
            <div class="period-tabs-wrapper">
                <nav class="period-tabs pb-period-tabs" role="tablist" aria-label="Dashboard timeframe">
                    <button type="button" role="tab" class="period-tab active" data-period="all" aria-selected="true" id="tab-all">All Time</button>
                    <button type="button" role="tab" class="period-tab" data-period="weekly" aria-selected="false" id="tab-weekly">Weekly</button>
                    <button type="button" role="tab" class="period-tab" data-period="monthly" aria-selected="false" id="tab-monthly">Monthly</button>
                    <button type="button" role="tab" class="period-tab" data-period="quarterly" aria-selected="false" id="tab-quarterly">Quarterly</button>
                    <button type="button" role="tab" class="period-tab" data-period="annual" aria-selected="false" id="tab-annual">Annual</button>
                </nav>
            </div>

            <div class="pb-period-inline-picker" id="periodNavButtons" style="display:none;">
                <button type="button" id="prevPeriodBtn" class="pb-period-step-btn" title="Previous period" aria-label="Previous period">&lsaquo;</button>
                <select id="periodPresetSelect" class="pb-period-select" aria-label="Choose specific period"></select>
                <button type="button" id="nextPeriodBtn" class="pb-period-step-btn" title="Next period" aria-label="Next period">&rsaquo;</button>
                <button type="button" id="currentPeriodBtn" class="pb-period-today-btn" title="Jump to current period" style="display:none;">
                    <span id="currentPeriodText">This Period</span>
                </button>
            </div>

            <span class="pb-period-summary-chip" id="periodDateRangeDisplay">All recorded data</span>
        </div>
    </div>

    <p id="dashboardMessage" class="dashboard-message" role="status"></p>
    <section class="liso-availability-card" aria-labelledby="activeLisoTitle">
        <div>
            <p class="liso-availability-eyebrow">Walk-in intake screening</p>
            <h2 id="activeLisoTitle">LISOs on duty</h2>
        </div>
        <div id="activeLisoList" class="active-liso-list" aria-live="polite">Checking available Lupon Members…</div>
    </section>

    <!-- Legacy hidden metric spans kept in sync for compatibility -->
    <span id="totalCases" hidden aria-hidden="true">0</span>
    <span id="pendingReview" hidden aria-hidden="true">0</span>
    <span id="hearingsToday" hidden aria-hidden="true">0</span>
    <span id="hearingsThisWeek" hidden aria-hidden="true">0</span>
    <span id="paanyayaComplaints" hidden aria-hidden="true">0</span>
    <span id="activeMediations" hidden aria-hidden="true">0</span>
    <span id="settledCases" hidden aria-hidden="true">0</span>
    <span id="archivedCases" hidden aria-hidden="true">0</span>
    <span id="cfaCases" hidden aria-hidden="true">0</span>
    <span id="deadlinesDue" hidden aria-hidden="true">0</span>

    <div class="pb-customize-banner" id="pbCustomizeBanner" role="region" aria-label="Widget customization mode" hidden>
        <div class="pb-customize-banner-text">
            <strong>Customize Dashboard Widgets</strong>
            <span>Drag any widget's <b>right, bottom, or corner handle</b> to resize freely, drag the top bar to <b>reorder</b>, use <b>&uarr; / &darr;</b> for quick moves, or click <b>&times;</b> to hide.</span>
        </div>
        <div class="pb-customize-banner-actions">
            <button type="button" class="pb-btn-outline-sm" id="btnBannerAddWidget">+ Add widget</button>
            <button type="button" class="pb-btn-outline-sm" id="btnResetLayoutBanner">Reset layout</button>
            <button type="button" class="pb-btn-primary-sm" id="btnDoneCustomizeLayout">Done</button>
        </div>
    </div>

    <!-- All Dashboard Widgets (Unified Customizable Grid) -->
    <div class="pb-widgets-grid" id="captainModularWidgets">
        <!-- Widget 0: 4 Executive KPI Cards -->
        <section class="pb-card pb-widget-card pb-kpi-widget-card pb-widget-span-12" data-widget-id="widget-kpis" id="dashboardKpisPanel" aria-label="Executive KPI Summary">
            <div class="pb-kpi-widget-topbar">
                <span class="pb-kpi-widget-title">Executive KPI Summary</span>
                <button type="button" class="pb-widget-hide-btn" data-hide-widget="widget-kpis" title="Hide widget">&times;</button>
            </div>
            <div class="pb-kpi-grid" id="statsGrid" aria-live="polite">
                <article class="pb-kpi-card" data-target-stat="all-cases" role="button" tabindex="0" title="Click to view all cases in Case Register">
            <div class="pb-kpi-head">
                <span class="pb-kpi-label" id="totalDisputesLabel">Total Disputes</span>
                <span class="pb-kpi-icon is-blue" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                </span>
            </div>
            <div class="pb-kpi-metric-row">
                <h2 class="pb-kpi-value" id="kpiTotalDisputes">-</h2>
                <span class="pb-delta-pill is-neutral" id="kpiDisputesDelta">—</span>
            </div>
            <p class="pb-kpi-sub" id="kpiTotalDisputesSub">Loading dispute records...</p>
        </article>

        <article class="pb-kpi-card" data-target-stat="active-mediation" role="button" tabindex="0" title="Click to filter active PB Mediation cases">
            <div class="pb-kpi-head">
                <span class="pb-kpi-label">PB Mediation Docket</span>
                <span class="pb-kpi-icon is-indigo" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"></path><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"></path><path d="M7 21h10"></path><path d="M12 3v18"></path><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"></path></svg>
                </span>
            </div>
            <div class="pb-kpi-metric-row">
                <h2 class="pb-kpi-value" id="kpiPbMediation">-</h2>
                <span class="pb-delta-pill is-neutral" id="kpiMediationRiskPill">On track</span>
            </div>
            <p class="pb-kpi-sub" id="kpiPbMediationSub">15-day statutory window (R.A. 7160)</p>
        </article>

        <article class="pb-kpi-card" data-target-stat="captain-actions" role="button" tabindex="0" title="Click to open Captain's Priority Action Queue">
            <div class="pb-kpi-head">
                <span class="pb-kpi-label">Needs Captain's Action</span>
                <span class="pb-kpi-icon is-amber" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                </span>
            </div>
            <div class="pb-kpi-metric-row">
                <h2 class="pb-kpi-value" id="kpiCaptainActions">-</h2>
                <span class="pb-delta-pill is-amber" id="kpiCaptainActionsPill">Oversight</span>
            </div>
            <p class="pb-kpi-sub" id="kpiCaptainActionsSub">Review · Verify · Attest · Transmittals</p>
        </article>

        <article class="pb-kpi-card" data-target-stat="settled-cases" role="button" tabindex="0" title="Click to inspect Settlements &amp; LTIA savings">
            <div class="pb-kpi-head">
                <span class="pb-kpi-label">Amicable Resolutions</span>
                <span class="pb-kpi-icon is-emerald" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                </span>
            </div>
            <div class="pb-kpi-metric-row">
                <h2 class="pb-kpi-value" id="kpiResolvedTotal">-</h2>
                <span class="pb-delta-pill is-positive" id="kpiLtiaSavingsPill">₱0 saved</span>
            </div>
            <p class="pb-kpi-sub" id="kpiResolvedSub">Formal settled + Paanyaya resolved</p>
        </article>
            </div>
        </section>

        <!-- Widget 1: Case Intake & Resolution Overview + LTIA Form 06 Carousel -->
        <section class="pb-card pb-widget-card pb-overview-card pb-overview-carousel-card pb-widget-span-6" data-widget-id="widget-overview" id="dashboardOverviewCarouselPanel" aria-label="Case Intake, Resolution Overview, and LTIA Form 06 Carousel">
            <div class="pb-overview-carousel-topbar">
                <div class="pb-overview-carousel-tabs" role="tablist" aria-label="Executive overview views">
                    <button type="button" role="tab" class="pb-carousel-tab is-active" id="btnOverviewSlide0" data-overview-slide="0" aria-selected="true" aria-controls="overviewCarouselSlide0">
                        Case Intake &amp; Resolution Overview
                    </button>
                    <button type="button" role="tab" class="pb-carousel-tab" id="btnOverviewSlide1" data-overview-slide="1" aria-selected="false" aria-controls="overviewCarouselSlide1">
                        LTIA Form 06 &mdash; Summary of Cases
                    </button>
                </div>
                <div class="pb-overview-carousel-nav" aria-label="Carousel navigation">
                    <span class="pb-carousel-counter" id="overviewCarouselCounter">1 / 2</span>
                    <button type="button" class="pb-carousel-arrow" id="overviewCarouselPrev" title="Previous slide" aria-label="Previous slide">&lsaquo;</button>
                    <button type="button" class="pb-carousel-arrow" id="overviewCarouselNext" title="Next slide" aria-label="Next slide">&rsaquo;</button>
                    <button type="button" class="pb-widget-hide-btn" data-hide-widget="widget-overview" title="Hide widget">&times;</button>
                </div>
            </div>

                <div class="pb-overview-carousel-viewport" id="overviewCarouselViewport">
                    <div class="pb-overview-carousel-track" id="overviewCarouselTrack">
                        <!-- Slide 1: Case Intake & Resolution Overview -->
                        <div class="pb-overview-carousel-slide is-active" id="overviewCarouselSlide0" role="tabpanel" aria-labelledby="btnOverviewSlide0">
                            <div class="pb-card-head">
                                <div>
                                    <h2 class="pb-card-title">Case Intake &amp; Resolution Overview</h2>
                                    <p class="pb-card-sub" id="totalCasesSub">Filed complaints, resolutions, and LTIA court-fee savings</p>
                                </div>
                                <div class="pb-chart-legend" aria-label="Chart legend">
                                    <span class="pb-legend-item"><i class="pb-legend-line is-solid"></i>Filed</span>
                                    <span class="pb-legend-item"><i class="pb-legend-line is-green"></i>Resolved</span>
                                    <span class="pb-legend-item"><i class="pb-legend-line is-dashed"></i>Prior period</span>
                                </div>
                            </div>

                            <div class="pb-overview-hero-split">
                                <div class="pb-overview-stat-col">
                                    <div class="pb-overview-big-value" id="overviewLtiaHeadline">₱0</div>
                                    <div class="pb-overview-delta-row">
                                        <span class="pb-delta-pill is-neutral" id="overviewDeltaBadge">—</span>
                                        <span class="pb-overview-delta-label">vs. last period</span>
                                    </div>
                                    <div class="pb-overview-mini-metrics">
                                        <div>
                                            <small>Hearings Today</small>
                                            <strong id="overviewHearingsToday">0</strong>
                                        </div>
                                        <div>
                                            <small>Next 7 Days</small>
                                            <strong id="overviewHearingsWeek">0</strong>
                                        </div>
                                        <div>
                                            <small>Summons SLA</small>
                                            <strong id="overviewSummonsSla">100%</strong>
                                        </div>
                                    </div>
                                </div>

                                <div class="pb-trend-chart-wrap" id="captainTrendChartWrap">
                                    <svg id="captainTrendChartSvg" class="pb-trend-svg" viewBox="0 0 540 168" role="img" aria-label="Dispute intake and resolution trend chart"></svg>
                                    <div class="pb-trend-tooltip" id="captainTrendTooltip" style="display:none;"></div>
                                </div>
                            </div>

                            <!-- Bottom Segmented Stage Breakdown -->
                            <div class="pb-stage-strip-head">
                                <span>Case Pipeline Stages</span>
                                <span class="pb-stage-extra-chip" id="overviewExtraOutcomesChip">0 CFA · 0 Archived</span>
                            </div>
                            <div class="pb-stage-strip">
                                <div class="pb-stage-col">
                                    <div class="pb-stage-top">
                                        <span class="pb-stage-icon is-indigo" aria-hidden="true">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                                        </span>
                                        <strong id="stageCountPaanyaya">0</strong>
                                    </div>
                                    <span class="pb-stage-name">Paanyaya Stage 0</span>
                                    <div class="pb-stage-bar-track"><div class="pb-stage-bar is-indigo" id="stageBarPaanyaya" style="width:20%;"></div></div>
                                </div>

                                <div class="pb-stage-col">
                                    <div class="pb-stage-top">
                                        <span class="pb-stage-icon is-blue" aria-hidden="true">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                        </span>
                                        <strong id="stageCountMediation">0</strong>
                                    </div>
                                    <span class="pb-stage-name">PB Mediation</span>
                                    <div class="pb-stage-bar-track"><div class="pb-stage-bar is-blue" id="stageBarMediation" style="width:30%;"></div></div>
                                </div>

                                <div class="pb-stage-col">
                                    <div class="pb-stage-top">
                                        <span class="pb-stage-icon is-teal" aria-hidden="true">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                                        </span>
                                        <strong id="stageCountConciliation">0</strong>
                                    </div>
                                    <span class="pb-stage-name">Pangkat Conciliation</span>
                                    <div class="pb-stage-bar-track"><div class="pb-stage-bar is-teal" id="stageBarConciliation" style="width:20%;"></div></div>
                                </div>

                                <div class="pb-stage-col">
                                    <div class="pb-stage-top">
                                        <span class="pb-stage-icon is-green" aria-hidden="true">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                        </span>
                                        <strong id="stageCountSettled">0</strong>
                                    </div>
                                    <span class="pb-stage-name">Settled &amp; Resolved</span>
                                    <div class="pb-stage-bar-track"><div class="pb-stage-bar is-green" id="stageBarSettled" style="width:30%;"></div></div>
                                </div>
                            </div>
                        </div>

                        <!-- Slide 2: LTIA Form 06 - Summary of Cases -->
                        <div class="pb-overview-carousel-slide" id="overviewCarouselSlide1" role="tabpanel" aria-labelledby="btnOverviewSlide1">
                            <div class="pb-card-head pb-ltia-slide-head">
                                <div>
                                    <h2 class="pb-card-title">LTIA Form 06 &mdash; Summary of Cases</h2>
                                    <p class="pb-card-sub">Monthly Lupong Tagapamayapa case nature, settlements, and pending dispositions</p>
                                </div>
                                <div class="pb-widget-actions">
                                    <label class="pb-ltia-year-label" for="ltiaForm6YearSelect">
                                        <span>CY</span>
                                        <select id="ltiaForm6YearSelect" class="pb-ltia-year-select" aria-label="Select LTIA Form 06 Calendar Year"></select>
                                    </label>
                                    <a href="../../../backend/api/reports/official.php?type=form6" target="_blank" rel="noopener" id="ltiaForm6PrintLink" class="mini-cal-link" style="font-size: 0.82rem; font-weight: 700;">Print Form 06 &rarr;</a>
                                </div>
                            </div>

                            <div class="pb-ltia-form6-sheet">
                                <div class="pb-ltia-sheet-header">
                                    <h4 class="pb-ltia-sheet-title" id="ltiaForm6Heading">Barangay Tumana Lupong Tagapamayapa CY —</h4>
                                    <p class="pb-ltia-sheet-subtitle" id="ltiaForm6Subheading">Brgy. Tumana, Marikina City, Metro Manila NCR</p>
                                    <div class="pb-ltia-sheet-formline">LTIA &nbsp;&nbsp; Form 06 - SUMMARY OF CASES</div>
                                </div>

                                <div class="pb-ltia-meta-grid">
                                    <div class="pb-ltia-meta-col">
                                        <div class="pb-ltia-meta-row"><span class="pb-ltia-meta-key">Punong Barangay:</span><span class="pb-ltia-meta-val" id="ltiaMetaCaptain">—</span></div>
                                        <div class="pb-ltia-meta-row"><span class="pb-ltia-meta-key">City Mayor:</span><span class="pb-ltia-meta-val" id="ltiaMetaMayor">—</span></div>
                                        <div class="pb-ltia-meta-row"><span class="pb-ltia-meta-key">Brgy. Tumana IRA:</span><span class="pb-ltia-meta-val" id="ltiaMetaIra">—</span></div>
                                    </div>
                                    <div class="pb-ltia-meta-col">
                                        <div class="pb-ltia-meta-row"><span class="pb-ltia-meta-key">Population:</span><span class="pb-ltia-meta-val" id="ltiaMetaPopulation">—</span></div>
                                        <div class="pb-ltia-meta-row"><span class="pb-ltia-meta-key">Land Area:</span><span class="pb-ltia-meta-val" id="ltiaMetaLandArea">—</span></div>
                                        <div class="pb-ltia-meta-row"><span class="pb-ltia-meta-key">Total No. of Cases:</span><span class="pb-ltia-meta-val is-strong" id="ltiaMetaTotalCases">0</span></div>
                                    </div>
                                </div>

                                <div class="pb-ltia-table-wrap">
                                    <table class="pb-ltia-table" aria-label="LTIA Form 06 Summary of Cases">
                                        <thead>
                                            <tr>
                                                <th rowspan="3" class="col-month">MONTH</th>
                                                <th colspan="4" rowspan="2" class="hdr-group">NATURE OF CASES (1)</th>
                                                <th colspan="10" class="hdr-group">ACTION TAKEN</th>
                                                <th rowspan="3" class="col-total-filed">TOTAL<br>CASES<br>FILED</th>
                                            </tr>
                                            <tr>
                                                <th colspan="4" class="hdr-subgroup">SETTLED (2)</th>
                                                <th colspan="6" class="hdr-subgroup">NOT SETTLED (3)</th>
                                            </tr>
                                            <tr class="hdr-cols">
                                                <th class="shade-nature-crim" title="Criminal Cases (1a)">CRIM.<br>(1a)</th>
                                                <th class="shade-nature-civ" title="Civil Cases (1b)">CIVIL<br>(1b)</th>
                                                <th title="Other Cases (1c)">OTHERS<br>(1c)</th>
                                                <th class="shade-nature-tot" title="Total Nature of Cases (1D)">TOTAL<br>(1D)</th>
                                                <th class="shade-settled" title="Settled via Mediation (2a)">MED.<br>(2a)</th>
                                                <th class="shade-settled" title="Settled via Conciliation (2b)">CONCIL.<br>(2b)</th>
                                                <th title="Settled via Arbitration (2c)">ARBIT.<br>(2c)</th>
                                                <th class="shade-settled-tot" title="Total Settled Cases (2D)">TOTAL<br>(2D)</th>
                                                <th class="shade-unsettled" title="Pending Cases (3a)">PEND.<br>(3a)</th>
                                                <th class="shade-unsettled" title="Dismissed Cases (3b)">DISM.<br>(3b)</th>
                                                <th title="Repudiated Settlements (3c)">REPUD.<br>(3c)</th>
                                                <th class="shade-cfa" title="Certified to File Action in Court (3d)">CFA<br>(3d)</th>
                                                <th title="Dropped / Withdrawn / Referred (3e)">DROP/<br>WITHDR<br>(3e)</th>
                                                <th class="shade-unsettled-tot" title="Total Not Settled (3F)">TOTAL<br>(3F)</th>
                                            </tr>
                                        </thead>
                                        <tbody id="ltiaForm6TableBody">
                                            <tr><td colspan="16" class="pb-table-empty">Loading LTIA Form 06 summary...</td></tr>
                                        </tbody>
                                        <tfoot id="ltiaForm6TableFoot"></tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pb-overview-carousel-dots" aria-label="Slide indicators">
                    <button type="button" class="pb-carousel-dot is-active" data-overview-slide="0" aria-label="View Case Intake and Resolution Overview"></button>
                    <button type="button" class="pb-carousel-dot" data-overview-slide="1" aria-label="View LTIA Form 06 Summary of Cases"></button>
                </div>
            </section>

            <!-- Widget 2: Captain's Docket & Oversight Queue -->
            <section class="pb-card pb-widget-card pb-table-card pb-widget-span-6" data-widget-id="widget-docket" id="dashboardCasesPanel" aria-label="Barangay Docket and Priority Action Table">
                <div class="pb-table-card-header">
                    <div>
                        <h2 class="pb-card-title" id="casesPanelHeaderTitle">Captain's Docket &amp; Oversight Queue</h2>

                        <p class="pb-card-sub" id="casesPeriodDescription">Priority approvals, 15-day clocks, and full case register</p>
                    </div>
                    <div class="pb-table-controls">
                        <div class="pb-table-search">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
                            <input type="search" id="captainDocketSearchInput" placeholder="Search case #, party, purok..." aria-label="Filter docket table">
                        </div>
                        <button type="button" class="pb-shortcut-pill is-warning" id="btnPbUnavailableTop" onclick="triggerPbUnavailableBatch()" title="Batch reschedule today's hearings if Punong Barangay is unavailable">
                            PB Unavailable
                        </button>
                        <button type="button" id="btnResetCaseFilter" class="pb-btn-reset-filter" style="display:none;">Show All</button>
                        <button type="button" class="pb-widget-hide-btn" data-hide-widget="widget-docket" title="Hide widget">&times;</button>
                    </div>
                </div>

                <div class="pb-docket-tabs" role="tablist" aria-label="Docket views">
                    <button type="button" class="pb-docket-tab active" data-docket-tab="priority" role="tab" aria-selected="true">
                        Priority Actions <span class="pb-tab-count" id="tabCountPriority">0</span>
                    </button>
                    <button type="button" class="pb-docket-tab" data-docket-tab="cases" role="tab" aria-selected="false">
                        All Cases <span class="pb-tab-count" id="tabCountCases">0</span>
                    </button>
                    <button type="button" class="pb-docket-tab" data-docket-tab="screening" role="tab" aria-selected="false">
                        Pending Review <span class="pb-tab-count" id="tabCountScreening">0</span>
                    </button>
                    <button type="button" class="pb-docket-tab" data-docket-tab="residents" role="tab" aria-selected="false">
                        Resident Verification <span class="pb-tab-count" id="tabCountResidents">0</span>
                    </button>
                    <button type="button" class="pb-docket-tab" data-docket-tab="transmittals" role="tab" aria-selected="false">
                        Settlements &amp; Transmittals <span class="pb-tab-count" id="tabCountTransmittals">0</span>
                    </button>
                </div>

                <div class="pb-table-scroll">
                    <table class="pb-executive-table" id="captainDocketTable">
                        <thead id="captainDocketThead">
                            <tr>
                                <th>Urgency</th>
                                <th>Item / Reference</th>
                                <th>Details / Purok</th>
                                <th>Deadline / Date</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="periodCasesTableBody">
                            <tr><td colspan="5" class="pb-table-empty">Loading barangay docket...</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="dashboard-table-pagination pb-table-pagination" id="casesTablePagination" style="display:none;">
                    <div class="pagination-info" id="casesPaginationInfo">Showing 1 to 10</div>
                    <div class="pagination-buttons" id="casesPaginationButtons">
                        <button type="button" class="btn-page-nav" id="casesPrevPageBtn" disabled>&laquo;</button>
                        <span id="casesPageNumbers" class="pb-page-numbers"></span>
                        <button type="button" class="btn-page-nav" id="casesNextPageBtn">&raquo;</button>
                    </div>
                </div>
            </section>

        <!-- Widget 3: Today's Hearings & Mediation Queue -->
        <section class="pb-card pb-widget-card pb-widget-span-8" data-widget-id="widget-hearings" id="dashboardHearingsPanel">
            <div class="pb-widget-head">
                <div class="pb-widget-title-group">
                    <span class="pb-widget-icon is-blue" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"></path><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"></path><path d="M7 21h10"></path><path d="M12 3v18"></path><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"></path></svg>
                    </span>
                    <div>
                        <h3 class="pb-card-title" id="hearingsPanelHeading">Today's Hearings</h3>
                        <p class="pb-card-sub" id="hearingsPanelSub">Scheduled formal sessions &amp; Lupon Court Room queue (drag to reorder)</p>
                    </div>
                </div>
                <div class="pb-widget-actions">
                    <button type="button" class="btn-pb-unavailable-batch" id="btnPbUnavailableBatchAdmin" onclick="triggerPbUnavailableBatch()" title="Punong Barangay Unavailable: Batch reschedule all hearings for today to next Tuesday">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                        <span>Punong Barangay Unavailable</span>
                    </button>
                    <span id="periodHearingCountBadge" class="panel-count-badge" style="display:none;">0</span>
                    <button type="button" class="pb-widget-hide-btn" data-hide-widget="widget-hearings" title="Hide widget">&times;</button>
                </div>
            </div>
            <div id="todayHearings" class="dash-hearings-stream" aria-live="polite">
                <div class="pb-table-empty">Loading today's hearing sessions...</div>
            </div>
            <div class="dashboard-table-pagination" id="hearingsTablePagination" style="display:none; padding-top:10px;">
                <div class="pagination-info" id="hearingsPaginationInfo" style="font-size: 11px;">Showing 1-10</div>
                <div class="pagination-buttons" id="hearingsPaginationButtons">
                    <button type="button" class="btn-page-nav" id="hearingsPrevPageBtn" disabled>&laquo;</button>
                    <span id="hearingsPageNumbers" class="pb-page-numbers"></span>
                    <button type="button" class="btn-page-nav" id="hearingsNextPageBtn">&raquo;</button>
                </div>
            </div>
        </section>

        <!-- Widget 2: Hearing Calendar -->
        <section class="pb-card pb-widget-card pb-widget-span-4" data-widget-id="widget-calendar" id="dashboardCalendarCard">
            <div class="pb-widget-head">
                <div class="pb-widget-title-group">
                    <span class="pb-widget-icon is-teal" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    </span>
                    <div>
                        <h3 class="pb-card-title">Session Calendar</h3>
                        <p class="pb-card-sub">Click a date to filter scheduled sessions</p>
                    </div>
                </div>
                <div class="pb-widget-actions">
                    <div class="mini-cal-nav">
                        <button type="button" id="dashCalPrevBtn" class="mini-cal-btn" title="Previous Month" aria-label="Previous Month">&lt;</button>
                        <span id="dashCalMonthDisplay" class="mini-cal-month-text">Nov 2026</span>
                        <button type="button" id="dashCalNextBtn" class="mini-cal-btn" title="Next Month" aria-label="Next Month">&gt;</button>
                    </div>
                    <button type="button" class="pb-widget-hide-btn" data-hide-widget="widget-calendar" title="Hide widget">&times;</button>
                </div>
            </div>
            <div class="mini-cal-body" id="miniCalendarCard">
                <div class="mini-cal-days-grid" id="dashboardCalendarGrid" aria-label="Hearing Calendar"></div>
            </div>
            <div class="mini-cal-footer">
                <button type="button" id="dashCalFullBtn" class="mini-cal-link" style="border:0; background:transparent; cursor:pointer; padding:0;">Full calendar &rarr;</button>
                <button type="button" class="mini-cal-toggle-btn" id="dashCalTodayBtn">Today</button>
            </div>
        </section>

        <!-- Widget 3: Most Complained Issues & Purok Hotspots -->
        <section class="pb-card pb-widget-card pb-widget-span-8" data-widget-id="widget-analytics">
            <div class="pb-widget-head">
                <div class="pb-widget-title-group">
                    <span class="pb-widget-icon is-amber" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                    </span>
                    <div>
                        <h3 class="pb-card-title">Community Peace &amp; Order Analytics</h3>
                        <p class="pb-card-sub">Top recurring dispute categories &amp; Purok hotspots in Barangay Tumana</p>
                    </div>
                </div>
                <button type="button" class="pb-widget-hide-btn" data-hide-widget="widget-analytics" title="Hide widget">&times;</button>
            </div>
            <div class="pb-analytics-split">
                <div class="pb-subanalytics-box">
                    <h4>Most Complained Issues</h4>
                    <div id="analyticsTopCategories" class="analytics-bars-container">
                        <div class="analytics-loading">Loading dispute statistics...</div>
                    </div>
                </div>
                <div class="pb-subanalytics-box">
                    <h4>Purok Dispute Hotspots</h4>
                    <div id="analyticsPurokHotspots" class="analytics-bars-container">
                        <div class="analytics-loading">Loading area hotspots...</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Widget 4: Participant Demographics -->
        <section class="pb-card pb-widget-card pb-widget-span-4" data-widget-id="widget-demographics">
            <div class="pb-widget-head">
                <div class="pb-widget-title-group">
                    <span class="pb-widget-icon is-indigo" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path></svg>
                    </span>
                    <div>
                        <h3 class="pb-card-title">Participant Demographics</h3>
                        <p class="pb-card-sub">Gender &amp; age brackets of dispute parties</p>
                    </div>
                </div>
                <button type="button" class="pb-widget-hide-btn" data-hide-widget="widget-demographics" title="Hide widget">&times;</button>
            </div>
            <div class="pb-demographics-stack">
                <div>
                    <h4 class="pb-sublabel">Gender Distribution</h4>
                    <div id="analyticsGenderBreakdown" class="analytics-demographics-wrap">
                        <div class="analytics-loading">Loading gender data...</div>
                    </div>
                </div>
                <div>
                    <h4 class="pb-sublabel">Age Group Brackets</h4>
                    <div id="analyticsAgeDistribution" class="analytics-demographics-wrap">
                        <div class="analytics-loading">Loading age demographics...</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Widget 5: Paanyaya Stage 0 Invitations -->
        <section class="pb-card pb-widget-card pb-widget-span-8" data-widget-id="widget-paanyaya" id="dashboardPaanyayaPanel">
            <div class="pb-widget-head">
                <div class="pb-widget-title-group">
                    <span class="pb-widget-icon is-indigo" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    </span>
                    <div>
                        <h3 class="pb-card-title">Paanyaya Stage 0 Invitations</h3>
                        <p class="pb-card-sub">Pre-docketed informal dialogues preserving LTIA settlement efficiency</p>
                    </div>
                </div>
                <div class="pb-widget-actions">
                    <a href="../paanyaya/index.php" class="mini-cal-link" style="font-size: 0.82rem; font-weight: 700;">View all &rarr;</a>
                    <button type="button" class="pb-widget-hide-btn" data-hide-widget="widget-paanyaya" title="Hide widget">&times;</button>
                </div>
            </div>
            <div id="todayPaanyayas" class="dash-hearings-stream" aria-live="polite">
                <div class="pb-table-empty">Loading Paanyaya invitations...</div>
            </div>
        </section>

        <!-- Widget 6: Announcements & Barangay Bulletin -->
        <section class="pb-card pb-widget-card pb-widget-span-4" data-widget-id="widget-bulletin">
            <div class="pb-widget-head">
                <div class="pb-widget-title-group">
                    <span class="pb-widget-icon is-emerald" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                    </span>
                    <div>
                        <h3 class="pb-card-title">Announcements &amp; News</h3>
                        <p class="pb-card-sub">Barangay Tumana bulletin</p>
                    </div>
                </div>
                <div class="pb-widget-actions">
                    <?php if (in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2], true) || in_array(trim((string) ($_SESSION['role_name'] ?? '')), ['Administrator', 'Secretary', 'Lupon Clerk'], true)): ?>
                        <button type="button" id="btnOpenStaffNewsManager" class="staff-news-manage-btn">Manage News</button>
                    <?php endif; ?>
                    <button type="button" class="pb-widget-hide-btn" data-hide-widget="widget-bulletin" title="Hide widget">&times;</button>
                </div>
            </div>
            <div class="pb-bulletin-body">
                <div class="dash-aside-bulletin">
                    <strong>Monthly Council Meeting</strong>
                    <p>Katarungang Pambarangay monthly case presentation takes place on the 1st Monday of every month.</p>
                </div>
                <div id="staffBulletinNewsList" class="dash-aside-news-feed"></div>
            </div>
        </section>

        <!-- Widget 7: Security & Governance Audit Trail -->
        <section class="pb-card pb-widget-card pb-widget-span-12" data-widget-id="widget-audit">
            <div class="pb-widget-head">
                <div class="pb-widget-title-group">
                    <span class="pb-widget-icon is-slate" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </span>
                    <div>
                        <h3 class="pb-card-title">Security &amp; Governance Audit Trail</h3>
                        <p class="pb-card-sub">Recent staff actions, resident verifications, and case updates</p>
                    </div>
                </div>
                <div class="pb-widget-actions">
                    <a href="../users/user-list.php" class="mini-cal-link" style="font-size: 0.82rem; font-weight: 700;">Manage Users &rarr;</a>
                    <button type="button" class="pb-widget-hide-btn" data-hide-widget="widget-audit" title="Hide widget">&times;</button>
                </div>
            </div>
            <div class="pb-audit-grid" id="captainAuditFeedList">
                <div class="pb-table-empty">Loading audit activity...</div>
            </div>
        </section>
    </div>

    <!-- Add Widget Slide-Over Drawer (Mirrors Reference Design Top Panel) -->
    <div id="addWidgetDrawerOverlay" class="pb-drawer-overlay" style="display:none;" aria-hidden="true">
        <aside id="addWidgetDrawer" class="pb-widget-drawer" role="dialog" aria-modal="true" aria-labelledby="addWidgetDrawerTitle">
            <header class="pb-drawer-header">
                <div>
                    <h2 id="addWidgetDrawerTitle">Add Widget</h2>
                    <p>Customize which oversight modules appear on the Captain's dashboard.</p>
                </div>
                <button type="button" id="btnCloseWidgetDrawer" class="pb-drawer-close" aria-label="Close Add Widget drawer">&times;</button>
            </header>
            <div class="pb-drawer-body" id="addWidgetCatalogList">
                <!-- Populated dynamically by dashboard.js -->
            </div>
            <footer class="pb-drawer-footer">
                <button type="button" id="btnResetWidgetsDefault" class="pb-btn-outline-sm">Reset defaults</button>
                <button type="button" id="btnDoneWidgetDrawer" class="pb-btn-primary-sm">Done</button>
            </footer>
        </aside>
    </div>

    <!-- Settlement & LTIA Details Modal -->
    <div id="settlementDetailsModal" class="report-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="settlementDetailsTitle">
        <div class="report-modal-container" style="max-width:680px;">
            <header class="report-modal-header">
                <div class="modal-header-info">
                    <span class="report-modal-badge">LTIA &amp; Resolution Efficiency</span>
                    <h2 id="settlementDetailsTitle" class="report-modal-title">Amicable Settlement Breakdown</h2>
                    <p class="report-modal-sub">Formal KP settlements vs. Pre-Docket Paanyaya diversions (₱9,500 est. court fee saved per case)</p>
                </div>
                <button type="button" id="settlementDetailsClose" class="btn-modal-close" aria-label="Close">&times;</button>
            </header>
            <div id="settlementDetailsBody" class="report-modal-body"></div>
            <footer class="report-modal-footer">
                <button type="button" id="settlementDetailsCloseFooter" class="btn-modal-close-footer">Close</button>
            </footer>
        </div>
    </div>

    <!-- Full hearing calendar: summary only; it reads the dashboard payload and never changes schedules. -->
    <div id="dashboardFullCalendarModal" class="report-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="dashboardFullCalendarTitle">
        <div class="report-modal-container" style="max-width:760px;">
            <header class="report-modal-header">
                <div class="modal-header-info">
                    <span class="report-modal-badge">Session Schedule</span>
                    <h2 id="dashboardFullCalendarTitle" class="report-modal-title">Full Calendar</h2>
                    <p id="dashboardFullCalendarSubtitle" class="report-modal-sub">Scheduled hearings and Paanyaya sessions</p>
                </div>
                <button type="button" id="dashboardFullCalendarClose" class="btn-modal-close" aria-label="Close calendar">&times;</button>
            </header>
            <div id="dashboardFullCalendarContent" class="report-modal-body" style="max-height:65vh; overflow-y:auto;"></div>
            <footer class="report-modal-footer">
                <button type="button" id="dashboardFullCalendarCloseFooter" class="btn-modal-close-footer">Close</button>
            </footer>
        </div>
    </div>

    <?php if (in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2], true)): ?>
    <!-- Report Preview Modal Overlay -->
    <div id="reportPreviewModal" class="report-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="modalReportTitle">
        <div class="report-modal-container">
            <header class="report-modal-header">
                <div class="modal-header-info">
                    <span class="report-modal-badge" id="modalPeriodBadge">Monthly</span>
                    <h2 id="modalReportTitle" class="report-modal-title">Report Preview</h2>
                    <p id="modalReportSubtitle" class="report-modal-sub">Period details</p>
                </div>
                <div class="report-modal-actions">
                    <button type="button" id="modalPrintBtn" class="btn-modal-action btn-modal-print" title="Print this report">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                        <span>Print</span>
                    </button>
                    <button type="button" id="modalCloseBtn" class="btn-modal-close" aria-label="Close modal">&times;</button>
                </div>
            </header>

            <div class="report-modal-body" id="modalReportBody">
                <div id="modalLoading" class="report-modal-loading" style="display:none;">
                    <div class="dash-spinner"></div>
                    <span>Generating report preview...</span>
                </div>
                <div id="modalContent">
                    <div class="report-summary" id="modalSummaryCards"></div>
                    <div class="report-grid">
                        <div class="report-card">
                            <h3>Case status</h3>
                            <div id="modalStatusTotals"></div>
                        </div>
                        <div class="report-card">
                            <h3>Complaint categories</h3>
                            <div id="modalCategoryTotals"></div>
                        </div>
                    </div>
                </div>
            </div>
            <footer class="report-modal-footer">
                <button type="button" class="btn-modal-close-footer" id="modalCloseFooterBtn">Close</button>
            </footer>
        </div>
    </div>

    <!-- Period selector for the centralized official report menu. -->
    <div id="officialReportModal" class="report-modal-overlay official-report-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="officialReportModalTitle">
        <form id="officialReportForm" class="report-modal-container official-report-form">
            <header class="report-modal-header"><div class="modal-header-info"><span class="report-modal-badge">Official KP Report</span><h2 id="officialReportModalTitle" class="report-modal-title"></h2><p id="officialReportDescription" class="report-modal-sub"></p></div><button type="button" id="officialReportClose" class="btn-modal-close" aria-label="Close">&times;</button></header>
            <div class="report-modal-body official-report-body"><input type="hidden" id="officialReportType" name="report_type"><div class="official-report-grid"><label>Month<select id="officialReportMonth" name="month" required></select></label><label>Year<input id="officialReportYear" name="year" type="number" min="2000" max="2100" required></label></div><div id="officialMonitoringFields" hidden><div class="official-report-grid"><label>Reporting Quarter<select id="officialReportQuarter" name="quarter"><option value="1">Quarter 1</option><option value="2">Quarter 2</option><option value="3">Quarter 3</option><option value="4">Quarter 4</option></select></label><label>Date Accomplished<input id="officialReportDateAccomplished" name="date_accomplished" type="date"></label></div><fieldset><legend>Covering Months</legend><div id="officialReportMonths" class="official-month-checks"></div></fieldset></div></div>
            <footer class="report-modal-footer"><button type="button" id="officialReportCancel" class="btn-modal-close-footer">Cancel</button><button type="submit" class="btn-modal-action btn-modal-print">Generate Report</button></footer>
        </form>
    </div>
    <?php endif; ?>

    <!-- Quick Attendance / Check-In Modal -->
    <div id="quickDashboardAttendanceModal" class="report-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="quickAttendanceModalTitle">
        <div class="report-modal-container" style="max-width: 640px;">
            <header class="report-modal-header" style="padding: 16px 20px; border-bottom: 1px solid #e2e8f0; background: #f8fafc;">
                <div class="modal-header-info">
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                        <span class="report-modal-badge" style="background:#e0e7ff; color:#3730a3; font-weight:700;">Lupon Court Session</span>
                        <span id="quickAttCaseNumBadge" style="font-size:11.5px; font-weight:700; color:#475569; background:#f1f5f9; padding:2px 8px; border-radius:4px;">Case #</span>
                    </div>
                    <h2 class="report-modal-title" id="quickAttendanceModalTitle" style="font-size:1.15rem; margin:0; color:#0f172a;">Quick Attendance / Check-In</h2>
                    <p class="report-modal-sub" id="quickAttendanceModalSub" style="margin:2px 0 0; font-size:0.8rem; color:#64748b;">Itala ang pagdalo ng bawat panig bago simulan ang sesyon</p>
                </div>
                <button type="button" class="btn-modal-close" id="quickAttCloseBtn" aria-label="Close modal" style="font-size:1.4rem; cursor:pointer; background:none; border:none; color:#64748b;">&times;</button>
            </header>

            <div class="report-modal-body" style="padding: 20px; max-height: 70vh; overflow-y: auto;">
                <input type="hidden" id="quickAttHearingId" value="">
                <div style="background: #f1f5f9; border-radius: 8px; padding: 10px 14px; margin-bottom: 18px; font-size: 0.83rem; color: #334155; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                    <div><strong id="quickAttHearingType">Mediation Hearing</strong> · <span id="quickAttHearingTime">1:00 PM</span></div>
                    <div style="color: #64748b; font-size: 0.8rem;"><span id="quickAttHearingVenue">Lupon Court Room</span></div>
                </div>
                <div id="quickAttPartiesContainer" style="display: flex; flex-direction: column; gap: 12px;"></div>
                <div id="quickCompAbsentNotice" style="display: none; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 6px; padding: 8px 12px; margin-top: 10px; font-size: 0.82rem; color: #991b1b;">
                    <strong>Paalala: May Complainant na Hindi Dumalo</strong> &bull; Maaaring maging batayan ito para sa pag-dismiss ng reklamo alinsunod sa KP rules sa katitikan ng pagdinig.
                </div>
                <div style="margin-top: 12px;">
                    <label for="quickAttRemarks" style="display: block; font-size: 0.82rem; font-weight: 700; color: #0f172a; margin-bottom: 6px;">
                        Mga Tala ukol sa Pagkaantala / Delay Remarks (Opsyonal)
                    </label>
                    <textarea id="quickAttRemarks" rows="2" placeholder="Maglagay ng dahilan kung nahuli o naantala ang pagsisimula..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.85rem; box-sizing: border-box; font-family: inherit; resize: vertical;"></textarea>
                </div>
            </div>

            <footer class="report-modal-footer" style="padding: 14px 20px; display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e2e8f0; background: #f8fafc;">
                <button type="button" class="btn-modal-close-footer" id="quickAttCancelBtn" style="padding: 7px 16px; font-size: 0.85rem; font-weight: 600; border: 1px solid #cbd5e1; background: #fff; border-radius: 6px; cursor: pointer; color: #475569;">Kanselahin</button>
                <button type="button" id="quickAttSaveBtn" style="padding: 7px 18px; font-size: 0.85rem; font-weight: 600; border: none; background: #0f172a; color: #fff; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                    I-save ang Attendance
                </button>
            </footer>
        </div>
    </div>

    <?php
    if (in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2], true) || in_array(trim((string) ($_SESSION['role_name'] ?? '')), ['Administrator', 'Secretary', 'Lupon Clerk'], true)) {
        include __DIR__ . '/news-manager-modal.php';
    }
    ?>
    <script src="../../assets/js/news-widget.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/news-widget.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            window.AgapNewsWidget?.initStaffBulletin();
        });
    </script>
</section>
