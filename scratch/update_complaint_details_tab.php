<?php
$file = __DIR__ . '/../frontend/pages/complaints/complaint-details.php';
$content = file_get_contents($file);

// 1. Target for Paanyaya tab pane
$tabStart = '<div id="tab-paanyaya"';
$tabEnd = '<!-- ===================================================================' . "\n" . '             TAB PANE 2: MEDIATION';

$pos1 = strpos($content, $tabStart);
$pos2 = strpos($content, $tabEnd);

if ($pos1 === false || $pos2 === false) {
    die("Error: Could not locate tab-paanyaya boundaries in complaint-details.php\n");
}

$newTabPane = <<<'HTML'
<div id="tab-paanyaya" class="case-tab-pane <?php echo $activeTab === 'tab-paanyaya' ? 'active' : ''; ?>">
            <!-- Stage 0 Banner: Paanyaya Preliminary Process -->
            <div style="margin: 0 30px 20px;">
                <div id="stage0Card" class="stage-card-item active" style="background: linear-gradient(135deg, #15803d, #166534); border-color: #86efac; color: #ffffff;">
                    <div class="stage-card-top">
                        <span class="stage-label-num" style="background: rgba(255,255,255,0.2); color: #ffffff;">PRELIMINARY STAGE &bull; PAANYAYA PROCESS (BEFORE MEDIATION)</span>
                        <span id="stage0Icon" style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: rgba(255,255,255,0.25); font-size: 0.75rem;">✉️</span>
                    </div>
                    <h3 class="stage-card-title" style="font-size: 1.15rem; margin: 4px 0 4px; color: #ffffff;">Paanyaya (Preliminary Invitations &amp; Officer's Return Tracking)</h3>
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <span id="stage0StatusText" class="stage-sub-status" style="font-weight: 700; background: #fef08a; color: #854d0e; padding: 3px 10px; border-radius: 9999px;">Paanyaya In Progress</span>
                        <span style="font-size: 0.78rem; color: #dcfce7;">Two Independent Stages (Paanyaya 1 &amp; Paanyaya 2) &bull; Up to 6 Delivery Attempts Each with Officer's Return Documentation</span>
                    </div>
                </div>
            </div>

            <!-- LTIA Guidance Banner -->
            <div class="ltia-banner" style="margin: 0 30px 24px; background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%); border: 1.5px solid #86efac; border-radius: 10px; padding: 16px 20px; display: flex; align-items: flex-start; gap: 14px;">
                <div style="font-size: 1.7rem; line-height: 1;">🏛️</div>
                <div style="flex: 1;">
                    <h4 style="margin: 0 0 4px; font-size: 0.95rem; font-weight: 800; color: #14532d;">
                        Barangay Tumana Rule: Mandatory Preliminary Paanyaya Process
                    </h4>
                    <p style="margin: 0; font-size: 0.83rem; color: #166534; line-height: 1.45;">
                        Before issuing formal court-annexed summons (KP Form 8 &amp; 9), the Lupong Tagapamayapa provides informal preliminary invitation dialogue opportunities. Each stage allows up to <strong>six (6) recorded service attempts</strong> with documented <strong>Officer’s Returns</strong> before authorized case escalation.
                    </p>
                </div>
            </div>

            <!-- Side-by-Side Context Grid: Nagpaanyaya & Inanyayahan -->
            <div class="case-context-grid" style="margin-bottom: 24px;">
                <!-- LEFT COLUMN: Nagpaanyaya (Complainant / s) -->
                <div class="context-box-card">
                    <div class="context-box-header">
                        <h3 style="display: flex; align-items: center; gap: 6px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle></svg>
                            <span>NAGPAANYAYA (COMPLAINANT / S)</span>
                        </h3>
                        <span class="context-subtitle" id="tabPaaComplainantsCountBadge">0 Party(ies)</span>
                    </div>
                    <div id="tabPaaComplainantsList" style="margin-top: 10px;">
                        <p class="empty-detail-state" style="padding: 12px; margin: 0; font-size: 0.85rem;">Loading complainants...</p>
                    </div>
                </div>

                <!-- RIGHT COLUMN: Inanyayahan (Respondent / s) -->
                <div class="context-box-card">
                    <div class="context-box-header">
                        <h3 style="display: flex; align-items: center; gap: 6px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            <span>INANYAYAHAN (RESPONDENT / S)</span>
                        </h3>
                        <span class="context-subtitle" id="tabPaaRespondentsCountBadge">0 Party(ies)</span>
                    </div>
                    <div id="tabPaaRespondentsList" style="margin-top: 10px;">
                        <p class="empty-detail-state" style="padding: 12px; margin: 0; font-size: 0.85rem;">Loading respondents...</p>
                    </div>
                </div>
            </div>

            <!-- TWO PAANYAYA STAGES: SIDE BY SIDE SUMMARY CARDS -->
            <div class="paanyaya-stages-grid">
                <!-- CARD 1: PAANYAYA 1 (First Preliminary Invitation) -->
                <div class="paanyaya-card" id="paanyaya1Card">
                    <div>
                        <div class="paanyaya-card-header">
                            <div class="paanyaya-stage-title-wrap">
                                <span class="paanyaya-stage-tag">Stage 0.1 &bull; Initial Preliminary</span>
                                <h3 class="paanyaya-card-title">Paanyaya 1 – First Preliminary Invitation</h3>
                            </div>
                            <span id="p1StatusBadge" class="paanyaya-status-badge status-not-started">Not Started</span>
                        </div>

                        <!-- 4 Metric KPI Box -->
                        <div class="paanyaya-metric-grid">
                            <div class="paanyaya-metric-item">
                                <span class="paanyaya-metric-label">Delivery Attempts</span>
                                <span id="p1AttemptText" class="paanyaya-metric-val">0 of 6 used</span>
                            </div>
                            <div class="paanyaya-metric-item">
                                <span class="paanyaya-metric-label">Latest Service Status</span>
                                <span id="p1ServiceStatus" class="paanyaya-metric-val">—</span>
                            </div>
                            <div class="paanyaya-metric-item">
                                <span class="paanyaya-metric-label">Assigned Serving Officer</span>
                                <span id="p1ServingOfficer" class="paanyaya-metric-val">Unassigned</span>
                            </div>
                            <div class="paanyaya-metric-item">
                                <span class="paanyaya-metric-label">Date Initiated</span>
                                <span id="p1DateInitiated" class="paanyaya-metric-val">—</span>
                            </div>
                        </div>

                        <!-- 6-Attempt Progress Stepper Indicator -->
                        <div class="paanyaya-attempts-stepper">
                            <div class="paanyaya-stepper-label">
                                <span>Service Attempts Tracking (Max 6)</span>
                                <span id="p1StepperCountText">0 / 6 Attempts</span>
                            </div>
                            <div class="paanyaya-stepper-dots" id="p1StepperDots">
                                <div class="stepper-dot" title="Attempt 1"></div>
                                <div class="stepper-dot" title="Attempt 2"></div>
                                <div class="stepper-dot" title="Attempt 3"></div>
                                <div class="stepper-dot" title="Attempt 4"></div>
                                <div class="stepper-dot" title="Attempt 5"></div>
                                <div class="stepper-dot" title="Attempt 6"></div>
                            </div>
                        </div>

                        <!-- Case Details & Intended Recipient Info -->
                        <div class="paanyaya-parties-box">
                            <div class="party-row"><strong>Case / Tracking:</strong> <span id="p1CaseNumber">—</span></div>
                            <div class="party-row"><strong>Complainant:</strong> <span id="p1ComplainantName">—</span></div>
                            <div class="party-row"><strong>Respondent:</strong> <span id="p1RespondentName">—</span></div>
                            <div class="party-row"><strong>Service Address:</strong> <span id="p1RespondentAddr">—</span></div>
                            <div class="party-row" id="p1LatestOutcomeRow" style="display:none; margin-top: 6px; padding-top: 6px; border-top: 1px dashed #e2e8f0;">
                                <strong>Latest Outcome:</strong> <span id="p1LatestOutcomeBadge">None</span>
                                <div id="p1LatestRemarks" style="font-size: 0.78rem; color: #64748b; margin-top: 2px;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="paanyaya-card-actions">
                        <button type="button" class="btn-create" id="btnIssueP1" onclick="openIssuePaanyayaModal('Paanyaya 1')">
                            ✉️ Issue Paanyaya 1
                        </button>
                        <button type="button" class="btn-create" id="btnRecordReturnP1" onclick="openRecordReturnModal('Paanyaya 1')" style="background: #2563eb; border-color: #2563eb; display: none;">
                            📝 Record Officer's Return
                        </button>
                        <button type="button" class="btn-sess-indigo" id="btnPreviewP1" onclick="previewPaanyayaStageLetter('Paanyaya 1')" style="display: none;">
                            👁️ Preview Letter
                        </button>
                        <button type="button" class="btn-create" id="btnReviewP1" onclick="openPaanyayaReviewModal('Paanyaya 1')" style="background: #dc2626; border-color: #dc2626; display: none;">
                            ⚖️ Review Case
                        </button>
                        <button type="button" class="btn-sess-white" onclick="switchDeliveryHistoryView('Paanyaya 1')">
                            📜 View Delivery History
                        </button>
                    </div>
                </div>

                <!-- CARD 2: PAANYAYA 2 (Second Preliminary Invitation) -->
                <div class="paanyaya-card" id="paanyaya2Card">
                    <div>
                        <div class="paanyaya-card-header">
                            <div class="paanyaya-stage-title-wrap">
                                <span class="paanyaya-stage-tag tag-p2">Stage 0.2 &bull; Second Preliminary</span>
                                <h3 class="paanyaya-card-title">Paanyaya 2 – Second Preliminary Invitation</h3>
                            </div>
                            <span id="p2StatusBadge" class="paanyaya-status-badge status-locked">Locked</span>
                        </div>

                        <!-- Locked State Alert Banner (when Paanyaya 1 is still incomplete) -->
                        <div id="p2LockedNotice" style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 14px; margin-bottom: 16px; font-size: 0.85rem; color: #64748b; display: flex; align-items: center; gap: 10px;">
                            <span style="font-size: 1.3rem;">🔒</span>
                            <span>Paanyaya 2 is locked. It unlocks only after Paanyaya 1 reaches completion condition or authorized review.</span>
                        </div>

                        <!-- 4 Metric KPI Box -->
                        <div class="paanyaya-metric-grid" id="p2MetricGrid" style="display: none;">
                            <div class="paanyaya-metric-item">
                                <span class="paanyaya-metric-label">Delivery Attempts</span>
                                <span id="p2AttemptText" class="paanyaya-metric-val">0 of 6 used</span>
                            </div>
                            <div class="paanyaya-metric-item">
                                <span class="paanyaya-metric-label">Latest Service Status</span>
                                <span id="p2ServiceStatus" class="paanyaya-metric-val">—</span>
                            </div>
                            <div class="paanyaya-metric-item">
                                <span class="paanyaya-metric-label">Assigned Serving Officer</span>
                                <span id="p2ServingOfficer" class="paanyaya-metric-val">Unassigned</span>
                            </div>
                            <div class="paanyaya-metric-item">
                                <span class="paanyaya-metric-label">Date Initiated</span>
                                <span id="p2DateInitiated" class="paanyaya-metric-val">—</span>
                            </div>
                        </div>

                        <!-- 6-Attempt Progress Stepper Indicator (Paanyaya 2) -->
                        <div class="paanyaya-attempts-stepper" id="p2StepperWrap" style="display: none;">
                            <div class="paanyaya-stepper-label">
                                <span>Independent Service Attempts Tracking (Max 6)</span>
                                <span id="p2StepperCountText">0 / 6 Attempts</span>
                            </div>
                            <div class="paanyaya-stepper-dots" id="p2StepperDots">
                                <div class="stepper-dot" title="Attempt 1"></div>
                                <div class="stepper-dot" title="Attempt 2"></div>
                                <div class="stepper-dot" title="Attempt 3"></div>
                                <div class="stepper-dot" title="Attempt 4"></div>
                                <div class="stepper-dot" title="Attempt 5"></div>
                                <div class="stepper-dot" title="Attempt 6"></div>
                            </div>
                        </div>

                        <!-- Case Details & Party Info (Paanyaya 2) -->
                        <div class="paanyaya-parties-box" id="p2PartiesBox" style="display: none;">
                            <div class="party-row"><strong>Case / Tracking:</strong> <span id="p2CaseNumber">—</span></div>
                            <div class="party-row"><strong>Complainant:</strong> <span id="p2ComplainantName">—</span></div>
                            <div class="party-row"><strong>Respondent:</strong> <span id="p2RespondentName">—</span></div>
                            <div class="party-row"><strong>Service Address:</strong> <span id="p2RespondentAddr">—</span></div>
                            <div class="party-row" id="p2LatestOutcomeRow" style="display:none; margin-top: 6px; padding-top: 6px; border-top: 1px dashed #e2e8f0;">
                                <strong>Latest Outcome:</strong> <span id="p2LatestOutcomeBadge">None</span>
                                <div id="p2LatestRemarks" style="font-size: 0.78rem; color: #64748b; margin-top: 2px;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="paanyaya-card-actions">
                        <button type="button" class="btn-create" id="btnIssueP2" onclick="openIssuePaanyayaModal('Paanyaya 2')" disabled style="opacity: 0.55; cursor: not-allowed;">
                            ✉️ Issue Paanyaya 2
                        </button>
                        <button type="button" class="btn-create" id="btnRecordReturnP2" onclick="openRecordReturnModal('Paanyaya 2')" style="background: #2563eb; border-color: #2563eb; display: none;">
                            📝 Record Officer's Return
                        </button>
                        <button type="button" class="btn-sess-indigo" id="btnPreviewP2" onclick="previewPaanyayaStageLetter('Paanyaya 2')" style="display: none;">
                            👁️ Preview Letter
                        </button>
                        <button type="button" class="btn-create" id="btnReviewP2" onclick="openPaanyayaReviewModal('Paanyaya 2')" style="background: #dc2626; border-color: #dc2626; display: none;">
                            ⚖️ Review Case
                        </button>
                        <button type="button" class="btn-sess-white" onclick="switchDeliveryHistoryView('Paanyaya 2')">
                            📜 View Delivery History
                        </button>
                    </div>
                </div>
            </div>

            <!-- PAANYAYA DELIVERY HISTORY & OFFICER'S RETURN TABLE -->
            <div class="hearings-schedule-card" id="paanyayaHistoryCard" style="margin: 0 30px 24px;">
                <div class="operational-card-header" style="flex-wrap: wrap; gap: 12px;">
                    <div>
                        <h3 style="font-size: 1rem; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                            <span>Paanyaya Delivery History &amp; Officer's Return Records</span>
                        </h3>
                        <span class="card-subtitle">Chronological record of service attempts. Max 6 attempts tracked independently per stage.</span>
                    </div>

                    <!-- Stage Selector Pills -->
                    <div class="paanyaya-history-tabs-row">
                        <button type="button" class="hist-tab-btn active" id="btnHistTabP1" onclick="switchDeliveryHistoryView('Paanyaya 1')">
                            Paanyaya 1 Delivery History (<span id="p1HistBadgeCount">0</span>/6)
                        </button>
                        <button type="button" class="hist-tab-btn" id="btnHistTabP2" onclick="switchDeliveryHistoryView('Paanyaya 2')">
                            Paanyaya 2 Delivery History (<span id="p2HistBadgeCount">0</span>/6)
                        </button>
                    </div>
                </div>

                <!-- History Table Container -->
                <div style="overflow-x: auto; padding: 14px 20px 20px;">
                    <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;" id="paanyayaDeliveryHistoryTable">
                        <thead>
                            <tr style="border-bottom: 2px solid #e2e8f0; background: #f8fafc; text-align: left;">
                                <th style="padding: 10px 12px; font-weight: 700; color: #475569;">Attempt</th>
                                <th style="padding: 10px 12px; font-weight: 700; color: #475569;">Date &amp; Time</th>
                                <th style="padding: 10px 12px; font-weight: 700; color: #475569;">Serving Officer</th>
                                <th style="padding: 10px 12px; font-weight: 700; color: #475569;">Address Visited</th>
                                <th style="padding: 10px 12px; font-weight: 700; color: #475569;">Outcome</th>
                                <th style="padding: 10px 12px; font-weight: 700; color: #475569;">Remarks</th>
                                <th style="padding: 10px 12px; font-weight: 700; color: #475569; text-align: center;">Proof</th>
                                <th style="padding: 10px 12px; font-weight: 700; color: #475569; text-align: center;">Officer's Return</th>
                                <th style="padding: 10px 12px; font-weight: 700; color: #475569;">Recorded By</th>
                                <th style="padding: 10px 12px; font-weight: 700; color: #475569; text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="paanyayaDeliveryHistoryTbody">
                            <!-- Populated dynamically by complaints.js -->
                        </tbody>
                    </table>

                    <!-- Empty State -->
                    <div id="paanyayaDeliveryHistoryEmpty" class="empty-detail-state" style="padding: 24px; text-align: center; color: #64748b;">
                        <p style="margin: 0; font-size: 0.9rem;">No delivery attempts recorded yet for this Paanyaya stage.</p>
                    </div>
                </div>
            </div>

            <!-- Procedural Safeguards Card -->
            <div class="operational-card" style="margin: 0 30px 24px;">
                <div class="operational-card-header">
                    <div>
                        <h3 style="font-size: 0.95rem; font-weight: 800; color: #0f172a; margin: 0;">Barangay Tumana Procedural Safeguards &amp; Workflow Rules</h3>
                        <span class="card-subtitle">Requirements for advancing from Preliminary Paanyaya to Formal Summons</span>
                    </div>
                </div>
                <div style="padding: 16px 20px; font-size: 0.86rem; color: #475569; line-height: 1.6;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px;">
                        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 14px;">
                            <strong style="color: #15803d; display: block; margin-bottom: 4px;">1. Successful Delivery Unlocks Flow</strong>
                            <span style="font-size: 0.82rem; color: #166534;">When an invitation is successfully delivered, the stage records the outcome and unlocks formal mediation summons if settlement is not informally reached.</span>
                        </div>
                        <div style="background: #fefce8; border: 1px solid #fef08a; border-radius: 8px; padding: 12px 14px;">
                            <strong style="color: #854d0e; display: block; margin-bottom: 4px;">2. Up to 6 Attempts Per Stage</strong>
                            <span style="font-size: 0.82rem; color: #713f12;">Serving personnel may record up to 6 separate delivery attempts per stage. Each attempt requires date, time, location, and specific outcome remarks.</span>
                        </div>
                        <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 14px;">
                            <strong style="color: #1d4ed8; display: block; margin-bottom: 4px;">3. Independent Attempt Counters</strong>
                            <span style="font-size: 0.82rem; color: #1e40af;">Paanyaya 2 maintains its own 1-to-6 attempt counter and does not inherit attempts or status from Paanyaya 1.</span>
                        </div>
                        <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 14px;">
                            <strong style="color: #b91c1c; display: block; margin-bottom: 4px;">4. Exhausted Attempts Require Review</strong>
                            <span style="font-size: 0.82rem; color: #991b1b;">If all 6 attempts are exhausted without delivery, the system will NOT automatically advance to formal summons or CFA. An authorized Lupon review is required.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

HTML;

$updated = substr($content, 0, $pos1) . $newTabPane . "\n        " . substr($content, $pos2);
file_put_contents($file, $updated);
echo "Paanyaya tab pane replaced successfully in complaint-details.php!\n";
