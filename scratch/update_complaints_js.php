<?php

$filePath = __DIR__ . '/../frontend/assets/js/complaints.js';
$js = file_get_contents($filePath);
// Normalize line endings to \n
$js = str_replace("\r\n", "\n", $js);

// 1. New renderPaanyayaTab and helpers to replace old renderPaanyayaTab
$newRenderPaanyayaTab = <<<'JAVASCRIPT'
function populatePaanyayaOfficersSelect(officers) {
    if (!officers || !Array.isArray(officers)) return;
    const select1 = document.getElementById('issueStageOfficer');
    const select2 = document.getElementById('rorServingOfficer');
    const optionsHtml = '<option value="">-- Select Serving Officer --</option>' +
        officers.map(o => `<option value="${o.user_id}">${escapeHtml(o.full_name)} (${escapeHtml(o.role_name)})</option>`).join('');

    if (select1 && select1.options.length <= 1) {
        select1.innerHTML = optionsHtml;
    }
    if (select2 && select2.options.length <= 1) {
        select2.innerHTML = optionsHtml;
    }
}

function renderPaanyayaStageCard(stageNum, stageData, caseNum, compNames, respNames, respAddr) {
    const isP2 = stageNum === '2';
    const cardId = isP2 ? 'paanyaya2Card' : 'paanyaya1Card';
    const cardEl = document.getElementById(cardId);
    if (!cardEl) return;

    const details = stageData?.details || {};
    const attempts = stageData?.attempts || [];
    const count = stageData?.attempts_count || 0;
    const latest = stageData?.latest_attempt || null;
    const status = details.status || (isP2 && !stageData?.is_eligible ? 'Locked' : 'Not Started');

    if (isP2) {
        const lockedNotice = document.getElementById('p2LockedNotice');
        const metricGrid = document.getElementById('p2MetricGrid');
        const stepperWrap = document.getElementById('p2StepperWrap');
        const partiesBox = document.getElementById('p2PartiesBox');
        const btnIssue = document.getElementById('btnIssueP2');

        if (!stageData?.is_eligible) {
            if (lockedNotice) lockedNotice.style.display = 'flex';
            if (metricGrid) metricGrid.style.display = 'none';
            if (stepperWrap) stepperWrap.style.display = 'none';
            if (partiesBox) partiesBox.style.display = 'none';
            const badge = document.getElementById('p2StatusBadge');
            if (badge) {
                badge.textContent = 'Locked';
                badge.className = 'paanyaya-status-badge status-locked';
            }
            if (btnIssue) {
                btnIssue.style.display = 'inline-flex';
                btnIssue.disabled = true;
                btnIssue.style.opacity = '0.55';
                btnIssue.style.cursor = 'not-allowed';
            }
            const btnRecord = document.getElementById('btnRecordReturnP2');
            const btnPreview = document.getElementById('btnPreviewP2');
            const btnReview = document.getElementById('btnReviewP2');
            if (btnRecord) btnRecord.style.display = 'none';
            if (btnPreview) btnPreview.style.display = 'none';
            if (btnReview) btnReview.style.display = 'none';
            return;
        } else {
            if (lockedNotice) lockedNotice.style.display = 'none';
            if (metricGrid) metricGrid.style.display = 'grid';
            if (stepperWrap) stepperWrap.style.display = 'block';
            if (partiesBox) partiesBox.style.display = 'block';
        }
    }

    // Status Badge
    const badgeId = isP2 ? 'p2StatusBadge' : 'p1StatusBadge';
    const badge = document.getElementById(badgeId);
    if (badge) {
        badge.textContent = status;
        let badgeClass = 'status-not-started';
        if (status === 'In Progress') badgeClass = 'status-in-progress';
        else if (status === 'Successfully Delivered') badgeClass = 'status-delivered';
        else if (status === 'Attempts Exhausted – For Review') badgeClass = 'status-exhausted';
        else if (status === 'Completed') badgeClass = 'status-completed';
        else if (status === 'Locked') badgeClass = 'status-locked';
        badge.className = 'paanyaya-status-badge ' + badgeClass;
    }

    // Metric Grid
    const attemptTextId = isP2 ? 'p2AttemptText' : 'p1AttemptText';
    const serviceStatusId = isP2 ? 'p2ServiceStatus' : 'p1ServiceStatus';
    const officerId = isP2 ? 'p2ServingOfficer' : 'p1ServingOfficer';
    const dateInitId = isP2 ? 'p2DateInitiated' : 'p1DateInitiated';

    setText(attemptTextId, `${count} of 6 used`);
    const currentServiceOutcome = latest ? latest.delivery_outcome : (status === 'Not Started' ? 'Not Initiated' : 'Pending Service');
    setText(serviceStatusId, currentServiceOutcome);
    setText(officerId, details.assigned_officer_name || 'Unassigned');
    setText(dateInitId, details.invitation_date ? formatDateReadable(details.invitation_date) : (details.created_at ? formatDateReadable(details.created_at) : '—'));

    // Stepper Count & Dots
    const stepperCountId = isP2 ? 'p2StepperCountText' : 'p1StepperCountText';
    const stepperDotsId = isP2 ? 'p2StepperDots' : 'p1StepperDots';
    setText(stepperCountId, `${count} / 6 Attempts`);

    const stepperDots = document.getElementById(stepperDotsId);
    if (stepperDots) {
        const dots = stepperDots.querySelectorAll('.stepper-dot');
        dots.forEach((dot, idx) => {
            dot.className = 'stepper-dot';
            if (idx < count) {
                const att = attempts[idx];
                if (att && att.delivery_outcome === 'Successfully delivered') {
                    dot.classList.add('dot-served');
                    dot.title = `Attempt ${idx + 1}: Delivered (${att.service_datetime || ''})`;
                } else {
                    dot.classList.add('dot-unserved');
                    dot.title = `Attempt ${idx + 1}: Unsuccessful - ${att?.delivery_outcome || 'Unserved'}`;
                }
            } else if (idx === count && stageData?.can_record_attempt) {
                dot.classList.add('dot-current');
                dot.title = `Attempt ${idx + 1}: Ready to Record`;
            } else {
                dot.title = `Attempt ${idx + 1}: Not recorded`;
            }
        });
    }

    // Parties & Info Box
    const caseNumId = isP2 ? 'p2CaseNumber' : 'p1CaseNumber';
    const compNameId = isP2 ? 'p2ComplainantName' : 'p1ComplainantName';
    const respNameId = isP2 ? 'p2RespondentName' : 'p1RespondentName';
    const respAddrId = isP2 ? 'p2RespondentAddr' : 'p1RespondentAddr';

    setText(caseNumId, caseNum || '—');
    setText(compNameId, compNames || '—');
    setText(respNameId, respNames || '—');
    setText(respAddrId, respAddr || 'Barangay Tumana');

    // Latest Outcome Row
    const latestRowId = isP2 ? 'p2LatestOutcomeRow' : 'p1LatestOutcomeRow';
    const latestBadgeId = isP2 ? 'p2LatestOutcomeBadge' : 'p1LatestOutcomeBadge';
    const latestRemarksId = isP2 ? 'p2LatestRemarks' : 'p1LatestRemarks';
    const latestRow = document.getElementById(latestRowId);
    const latestBadge = document.getElementById(latestBadgeId);
    const latestRemarks = document.getElementById(latestRemarksId);

    if (latestRow && latestBadge && latestRemarks) {
        if (latest) {
            latestRow.style.display = 'block';
            let outClass = 'outcome-other';
            if (latest.delivery_outcome === 'Successfully delivered') outClass = 'outcome-delivered';
            else if (latest.delivery_outcome === 'Recipient unavailable') outClass = 'outcome-unavailable';
            else if (latest.delivery_outcome === 'Recipient refused to receive') outClass = 'outcome-refused';
            else if (latest.delivery_outcome === 'Respondent not found at the address') outClass = 'outcome-not-found';
            else if (latest.delivery_outcome === 'Incorrect or incomplete address') outClass = 'outcome-incorrect-addr';

            latestBadge.innerHTML = `<span class="outcome-badge ${outClass}">${escapeHtml(latest.delivery_outcome)}</span>`;
            const remText = latest.remarks || latest.unsuccessful_reason || '';
            latestRemarks.textContent = remText ? `“${remText}”` : '';
        } else {
            latestRow.style.display = 'none';
        }
    }

    // Action Buttons
    const btnIssueId = isP2 ? 'btnIssueP2' : 'btnIssueP1';
    const btnRecordId = isP2 ? 'btnRecordReturnP2' : 'btnRecordReturnP1';
    const btnPreviewId = isP2 ? 'btnPreviewP2' : 'btnPreviewP1';
    const btnReviewId = isP2 ? 'btnReviewP2' : 'btnReviewP1';

    const btnIssue = document.getElementById(btnIssueId);
    const btnRecord = document.getElementById(btnRecordId);
    const btnPreview = document.getElementById(btnPreviewId);
    const btnReview = document.getElementById(btnReviewId);

    if (btnIssue) {
        btnIssue.style.display = stageData?.can_issue ? 'inline-flex' : 'none';
        btnIssue.disabled = false;
        btnIssue.style.opacity = '';
        btnIssue.style.cursor = '';
    }
    if (btnRecord) {
        btnRecord.style.display = stageData?.can_record_attempt ? 'inline-flex' : 'none';
    }
    if (btnPreview) {
        btnPreview.style.display = (details.status && details.status !== 'Not Started') ? 'inline-flex' : 'none';
    }
    if (btnReview) {
        btnReview.style.display = stageData?.needs_review ? 'inline-flex' : 'none';
    }
}

function renderPaanyayaTab(data) {
    if (!data) return;
    const stages = data.paanyaya_stages || {};
    window.paanyayaStagesData = stages;

    const stage1 = stages.stage_1 || {};
    const stage2 = stages.stage_2 || {};
    const servingOfficers = stages.serving_officers || [];

    const esc = (s) => String(s ?? '').replace(/[&<>'"]/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    }[c] || c));

    // Populate Serving Officers dropdown in modals
    populatePaanyayaOfficersSelect(servingOfficers);

    // 1. Stage 0 Banner Text and Badges
    const stage0Badge = document.getElementById('stage0StatusText');
    const stage0Icon = document.getElementById('stage0Icon');
    if (stage0Badge) {
        if (stage2.is_delivered || stage1.is_delivered) {
            stage0Badge.textContent = 'Successfully Delivered';
            stage0Badge.style.background = '#dcfce7';
            stage0Badge.style.color = '#15803d';
            if (stage0Icon) stage0Icon.textContent = '✓';
        } else if (stage1.details?.status === 'Attempts Exhausted – For Review' || stage2.details?.status === 'Attempts Exhausted – For Review') {
            stage0Badge.textContent = 'Attempts Exhausted – For Review';
            stage0Badge.style.background = '#fee2e2';
            stage0Badge.style.color = '#991b1b';
            if (stage0Icon) stage0Icon.textContent = '⚠️';
        } else if (stage1.details?.status === 'In Progress' || stage2.details?.status === 'In Progress') {
            stage0Badge.textContent = 'Paanyaya In Progress';
            stage0Badge.style.background = '#fef08a';
            stage0Badge.style.color = '#854d0e';
            if (stage0Icon) stage0Icon.textContent = '✉️';
        } else {
            stage0Badge.textContent = 'Paanyaya Preliminary Stage';
            stage0Badge.style.background = '#fef08a';
            stage0Badge.style.color = '#854d0e';
            if (stage0Icon) stage0Icon.textContent = '✉️';
        }
    }

    // 2. Nagpaanyaya (Complainants) & Inanyayahan (Respondents)
    const complainants = data.parties ? data.parties.filter(x => x.party_type === 'Complainant') : [];
    const respondents = data.parties ? data.parties.filter(x => x.party_type === 'Respondent') : [];

    const compBadge = document.getElementById('tabPaaComplainantsCountBadge');
    if (compBadge) compBadge.textContent = `${complainants.length} Party(ies)`;
    const respBadge = document.getElementById('tabPaaRespondentsCountBadge');
    if (respBadge) respBadge.textContent = `${respondents.length} Party(ies)`;

    const renderPartyRows = (parties) => {
        if (!parties || parties.length === 0) {
            return '<p style="color: #64748b; font-size: 0.85rem; margin: 0; padding: 6px 0;">No party recorded on file.</p>';
        }
        return parties.map(pt => {
            const name = pt.full_name || `${pt.first_name || ''} ${pt.last_name || ''}`.trim() || 'Unknown';
            const addr = pt.address || pt.purok || 'Barangay Tumana';
            const phone = pt.contact_no || pt.phone || '';
            return `
                <div style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px dashed #e2e8f0;">
                    <div style="font-weight: 700; color: #0f172a; font-size: 0.92rem;">${esc(name)}</div>
                    <div style="font-size: 0.8rem; color: #64748b; margin-top: 2px;">
                        📍 ${esc(addr)}
                        ${phone ? ` &bull; 📞 ${esc(phone)}` : ''}
                    </div>
                </div>
            `;
        }).join('');
    };

    const compList = document.getElementById('tabPaaComplainantsList');
    if (compList) compList.innerHTML = renderPartyRows(complainants);
    const respList = document.getElementById('tabPaaRespondentsList');
    if (respList) respList.innerHTML = renderPartyRows(respondents);

    const compNames = complainants.map(c => c.full_name || `${c.first_name || ''} ${c.last_name || ''}`.trim()).filter(Boolean).join(', ') || 'Complainant';
    const respNames = respondents.map(r => r.full_name || `${r.first_name || ''} ${r.last_name || ''}`.trim()).filter(Boolean).join(', ') || 'Respondent';
    const respAddr = respondents[0]?.address || respondents[0]?.purok || 'Barangay Tumana';

    // 3. Render CARD 1: PAANYAYA 1
    renderPaanyayaStageCard('1', stage1, data.complaint_number, compNames, respNames, respAddr);

    // 4. Render CARD 2: PAANYAYA 2
    renderPaanyayaStageCard('2', stage2, data.complaint_number, compNames, respNames, respAddr);

    // 5. Render DELIVERY HISTORY TABLE
    if (!window.activePaanyayaHistoryStage) {
        window.activePaanyayaHistoryStage = 'Paanyaya 1';
    }
    renderPaanyayaDeliveryHistoryTable(window.activePaanyayaHistoryStage);
}
JAVASCRIPT;

// Replace from "function renderPaanyayaTab(data) {" up to "function renderMediationCard("
$posStart = strpos($js, "function renderPaanyayaTab(data) {");
$posEnd = strpos($js, "function renderMediationCard(", $posStart);

if ($posStart === false || $posEnd === false) {
    die("Error: Could not locate renderPaanyayaTab in complaints.js\n");
}

$js = substr($js, 0, $posStart) . $newRenderPaanyayaTab . "\n\n" . substr($js, $posEnd);
echo "Replaced renderPaanyayaTab successfully.\n";

// 2. Protect handleIssueSummonClick() and openIssueSummonModal() with summons prerequisite check
$prereqCheckSnippet = <<<'JAVASCRIPT'
    // Guard against issuing summons before completing Paanyaya prerequisites
    const paaPrereq = window.complaintData?.paanyaya_stages?.summons_prerequisite;
    if (paaPrereq && !paaPrereq.allowed) {
        window.agapNotify?.(
            paaPrereq.reason || 'Complete the required Paanyaya process before issuing the first formal summons.',
            'warning',
            'Paanyaya Prerequisite Required'
        );
        if (typeof switchCaseTab === 'function') {
            switchCaseTab('tab-paanyaya');
        }
        return;
    }
JAVASCRIPT;

// Inject in openIssueSummonModal
$posOpenSummon = strpos($js, "function openIssueSummonModal() {");
if ($posOpenSummon !== false) {
    $searchTarget = "function openIssueSummonModal() {\n";
    $replacement = "function openIssueSummonModal() {\n" . $prereqCheckSnippet . "\n";
    if (strpos($js, "paaPrereq = window.complaintData?.paanyaya_stages?.summons_prerequisite") === false) {
        $js = str_replace($searchTarget, $replacement, $js);
        echo "Injected prerequisite check into openIssueSummonModal.\n";
    }
}

// Inject in handleIssueSummonClick
$posHandleClick = strpos($js, "function handleIssueSummonClick() {");
if ($posHandleClick !== false) {
    $searchTarget = "function handleIssueSummonClick() {\n";
    $replacement = "function handleIssueSummonClick() {\n" . $prereqCheckSnippet . "\n";
    // Avoid double injection
    if (substr_count($js, "paaPrereq = window.complaintData?.paanyaya_stages?.summons_prerequisite") < 2) {
        $js = str_replace($searchTarget, $replacement, $js);
        echo "Injected prerequisite check into handleIssueSummonClick.\n";
    }
}

// 3. Append the Paanyaya interactive workflow functions at the end of complaints.js
$newPaanyayaFunctions = <<<'JAVASCRIPT'

// ===================================================================
// PAANYAYA 1 & 2 WORKFLOW & OFFICER'S RETURN INTERACTIVE HANDLERS
// ===================================================================

window.switchDeliveryHistoryView = function(stageType) {
    window.activePaanyayaHistoryStage = stageType;
    renderPaanyayaDeliveryHistoryTable(stageType);
};

window.renderPaanyayaDeliveryHistoryTable = function(stageType) {
    const stages = window.paanyayaStagesData || window.complaintData?.paanyaya_stages || {};
    const stage1 = stages.stage_1 || {};
    const stage2 = stages.stage_2 || {};

    const tab1Btn = document.getElementById('btnHistTabP1');
    const tab2Btn = document.getElementById('btnHistTabP2');
    if (tab1Btn) tab1Btn.classList.toggle('active', stageType === 'Paanyaya 1');
    if (tab2Btn) tab2Btn.classList.toggle('active', stageType === 'Paanyaya 2');

    const p1CountEl = document.getElementById('p1HistBadgeCount');
    const p2CountEl = document.getElementById('p2HistBadgeCount');
    if (p1CountEl) p1CountEl.textContent = stage1.attempts_count || 0;
    if (p2CountEl) p2CountEl.textContent = stage2.attempts_count || 0;

    const targetStage = (stageType === 'Paanyaya 2') ? stage2 : stage1;
    const attempts = targetStage.attempts || [];

    const table = document.getElementById('paanyayaDeliveryHistoryTable');
    const tbody = document.getElementById('paanyayaDeliveryHistoryTbody');
    const emptyState = document.getElementById('paanyayaDeliveryHistoryEmpty');

    if (!tbody) return;

    if (!attempts || attempts.length === 0) {
        if (table) table.style.display = 'none';
        if (emptyState) {
            emptyState.style.display = 'block';
            emptyState.innerHTML = `<p style="margin: 0; font-size: 0.9rem;">No delivery attempts recorded yet for <strong>${escapeHtml(stageType)}</strong>.</p>`;
        }
        tbody.innerHTML = '';
        return;
    }

    if (table) table.style.display = 'table';
    if (emptyState) emptyState.style.display = 'none';

    const esc = (s) => escapeHtml(s ?? '');

    tbody.innerHTML = attempts.map(att => {
        let outClass = 'outcome-other';
        if (att.delivery_outcome === 'Successfully delivered') outClass = 'outcome-delivered';
        else if (att.delivery_outcome === 'Recipient unavailable') outClass = 'outcome-unavailable';
        else if (att.delivery_outcome === 'Recipient refused to receive') outClass = 'outcome-refused';
        else if (att.delivery_outcome === 'Respondent not found at the address') outClass = 'outcome-not-found';
        else if (att.delivery_outcome === 'Incorrect or incomplete address') outClass = 'outcome-incorrect-addr';

        const rawDate = att.service_datetime ? new Date(att.service_datetime.replace(' ', 'T')) : null;
        const dtFormatted = rawDate && !isNaN(rawDate.getTime()) ?
            rawDate.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' +
            rawDate.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', hour12: true }) :
            esc(att.service_datetime || '—');

        const proofBtn = att.proof_file_path ?
            `<button type="button" class="btn-case-outline" style="padding: 3px 8px; font-size: 0.76rem;" onclick="openPaanyayaProofLightbox('${esc(att.proof_file_path)}')">📷 View</button>` :
            `<span style="color: #94a3b8; font-size: 0.78rem;">None</span>`;

        const remarksText = att.remarks || att.unsuccessful_reason || '—';

        return `
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 10px 12px; font-weight: 700; color: #0f172a;">
                    <span class="badge-attempt">Attempt ${att.attempt_number} of 6</span>
                </td>
                <td style="padding: 10px 12px; white-space: nowrap; color: #334155; font-size: 0.82rem;">
                    ${dtFormatted}
                </td>
                <td style="padding: 10px 12px; color: #0f172a; font-weight: 600;">
                    ${esc(att.serving_officer_name || 'Serving Officer')}
                </td>
                <td style="padding: 10px 12px; color: #475569; max-width: 180px; font-size: 0.82rem;">
                    ${esc(att.service_address || '—')}
                </td>
                <td style="padding: 10px 12px;">
                    <span class="outcome-badge ${outClass}">${esc(att.delivery_outcome)}</span>
                </td>
                <td style="padding: 10px 12px; color: #475569; max-width: 180px; font-size: 0.82rem;">
                    ${esc(remarksText)}
                </td>
                <td style="padding: 10px 12px; text-align: center;">
                    ${proofBtn}
                </td>
                <td style="padding: 10px 12px; text-align: center;">
                    <button type="button" class="btn-case-outline" style="padding: 3px 8px; font-size: 0.76rem;" onclick="viewOfficersReturnDetails(${att.id})">
                        📄 Return
                    </button>
                </td>
                <td style="padding: 10px 12px; color: #64748b; font-size: 0.78rem; white-space: nowrap;">
                    ${esc(att.recorded_by_name || 'Staff')}
                </td>
                <td style="padding: 10px 12px; text-align: right; white-space: nowrap;">
                    <button type="button" class="btn-case-outline" style="padding: 3px 8px; font-size: 0.76rem;" onclick="openEditOfficersReturnModal(${att.id})" title="Permitted administrative correction">
                        ✏️ Edit
                    </button>
                </td>
            </tr>
        `;
    }).join('');
};

window.openIssuePaanyayaModal = function(stageType) {
    const modal = document.getElementById('issuePaanyayaStageModal');
    if (!modal) return;

    const inputType = document.getElementById('issueStageType');
    const heading = document.getElementById('issueStageModalHeading');
    const sub = document.getElementById('issueStageModalSub');
    const dateInput = document.getElementById('issueStageDate');
    const timeInput = document.getElementById('issueStageTime');
    const venueInput = document.getElementById('issueStageVenue');
    const salaysayInput = document.getElementById('issueStageSalaysay');
    const errBox = document.getElementById('issueStageError');

    if (inputType) inputType.value = stageType;
    if (heading) heading.textContent = `Issue ${stageType}`;
    if (sub) sub.textContent = stageType === 'Paanyaya 1' ? 'First Preliminary Invitation (Informal Dialogue)' : 'Second Preliminary Invitation (Informal Dialogue)';

    // Compute default date: 3 weekdays ahead
    if (dateInput) {
        const d = new Date();
        let added = 0;
        while (added < 3) {
            d.setDate(d.getDate() + 1);
            if (d.getDay() !== 0 && d.getDay() !== 6) {
                added++;
            }
        }
        dateInput.value = d.toISOString().slice(0, 10);
        dateInput.min = new Date().toISOString().slice(0, 10);
    }
    if (timeInput) timeInput.value = '14:00';
    if (venueInput) venueInput.value = 'Tanggapan ng Barangay Tumana';
    if (salaysayInput) salaysayInput.value = window.complaintData?.narrative || '';
    if (errBox) errBox.style.display = 'none';

    modal.style.display = 'flex';
};

window.closeIssuePaanyayaStageModal = function() {
    const modal = document.getElementById('issuePaanyayaStageModal');
    if (modal) modal.style.display = 'none';
};

window.submitIssuePaanyayaStage = async function(event) {
    event.preventDefault();
    const form = event.target;
    const btn = document.getElementById('btnSubmitIssueStage');
    const errBox = document.getElementById('issueStageError');

    const formData = new FormData(form);
    const payload = {
        complaint_id: parseInt(formData.get('complaint_id'), 10),
        stage_type: formData.get('stage_type'),
        invitation_date: formData.get('invitation_date'),
        invitation_time: formData.get('invitation_time'),
        assigned_officer_id: formData.get('assigned_officer_id') ? parseInt(formData.get('assigned_officer_id'), 10) : null,
        venue: formData.get('venue'),
        salaysay: formData.get('salaysay'),
        has_e_signature: form.querySelector('input[name="has_e_signature"]').checked ? 1 : 0
    };

    try {
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Issuing Invitation...';
        }
        if (errBox) errBox.style.display = 'none';

        const res = await complaintApi('../../../backend/api/paanyaya/issue-stage.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        if (res.success) {
            window.agapNotify?.(res.message || 'Invitation issued successfully!', 'success');
            closeIssuePaanyayaStageModal();
            await loadComplaintDetails();
            previewPaanyayaStageLetter(payload.stage_type);
        } else {
            throw new Error(res.message || 'Failed to issue invitation.');
        }
    } catch (err) {
        if (errBox) {
            errBox.textContent = err.message || 'Error issuing invitation.';
            errBox.style.display = 'block';
        } else {
            alert(err.message || 'Error issuing invitation.');
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Issue Invitation & Generate Letter';
        }
    }
};

window.openRecordReturnModal = function(stageType) {
    const modal = document.getElementById('recordOfficersReturnModal');
    if (!modal) return;

    const stages = window.paanyayaStagesData || window.complaintData?.paanyaya_stages || {};
    const targetStage = (stageType === 'Paanyaya 2') ? stages.stage_2 : stages.stage_1;
    const nextAttempt = targetStage?.next_attempt_number || (targetStage?.attempts_count ? targetStage.attempts_count + 1 : 1);

    const stageInput = document.getElementById('rorStageType');
    const stageBadge = document.getElementById('rorStageBadge');
    const attemptBadge = document.getElementById('rorAttemptBadge');
    const dtInput = document.getElementById('rorServiceDatetime');
    const officerSelect = document.getElementById('rorServingOfficer');
    const recipientInput = document.getElementById('rorRecipientName');
    const addressInput = document.getElementById('rorServiceAddress');
    const outcomeSelect = document.getElementById('rorDeliveryOutcome');
    const reasonWrap = document.getElementById('rorUnsuccessfulReasonWrap');
    const reasonInput = document.getElementById('rorUnsuccessfulReason');
    const remarksInput = document.getElementById('rorRemarks');
    const proofFile = document.getElementById('rorProofFile');
    const latInput = document.getElementById('rorLatitude');
    const lngInput = document.getElementById('rorLongitude');
    const gpsStatus = document.getElementById('rorGpsStatus');
    const errBox = document.getElementById('rorErrorBox');

    if (stageInput) stageInput.value = stageType;
    if (stageBadge) stageBadge.textContent = stageType;
    if (attemptBadge) attemptBadge.textContent = `Attempt ${nextAttempt} of 6`;

    // Default datetime to now (local timezone)
    if (dtInput) {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        dtInput.value = `${year}-${month}-${day}T${hours}:${minutes}`;
    }

    // Pre-fill officer from stage
    if (officerSelect && targetStage?.details?.assigned_officer_id) {
        officerSelect.value = targetStage.details.assigned_officer_id;
    }

    // Pre-fill recipient & address from complaint parties
    const respondents = window.complaintData?.parties ? window.complaintData.parties.filter(x => x.party_type === 'Respondent') : [];
    if (recipientInput) {
        const respName = respondents.map(r => r.full_name || `${r.first_name || ''} ${r.last_name || ''}`.trim()).filter(Boolean).join(', ');
        recipientInput.value = respName || '';
    }
    if (addressInput) {
        addressInput.value = respondents[0]?.address || respondents[0]?.purok || 'Barangay Tumana';
    }

    if (outcomeSelect) outcomeSelect.value = '';
    if (reasonWrap) reasonWrap.style.display = 'none';
    if (reasonInput) {
        reasonInput.value = '';
        reasonInput.required = false;
    }
    if (remarksInput) remarksInput.value = '';
    if (proofFile) proofFile.value = '';
    if (latInput) latInput.value = '';
    if (lngInput) lngInput.value = '';
    if (gpsStatus) gpsStatus.textContent = '';
    if (errBox) errBox.style.display = 'none';

    modal.style.display = 'flex';
};

window.closeRecordOfficersReturnModal = function() {
    const modal = document.getElementById('recordOfficersReturnModal');
    if (modal) modal.style.display = 'none';
};

window.handleRorOutcomeChange = function() {
    const outcomeSelect = document.getElementById('rorDeliveryOutcome');
    const reasonWrap = document.getElementById('rorUnsuccessfulReasonWrap');
    const reasonInput = document.getElementById('rorUnsuccessfulReason');
    if (!outcomeSelect || !reasonWrap || !reasonInput) return;

    if (outcomeSelect.value && outcomeSelect.value !== 'Successfully delivered') {
        reasonWrap.style.display = 'block';
        reasonInput.required = true;
    } else {
        reasonWrap.style.display = 'none';
        reasonInput.required = false;
    }
};

window.captureReturnGpsLocation = function() {
    const latInput = document.getElementById('rorLatitude');
    const lngInput = document.getElementById('rorLongitude');
    const gpsStatus = document.getElementById('rorGpsStatus');

    if (!navigator.geolocation) {
        if (gpsStatus) gpsStatus.textContent = 'Geolocation is not supported by your browser.';
        return;
    }

    if (gpsStatus) gpsStatus.textContent = 'Acquiring GPS coordinates...';

    navigator.geolocation.getCurrentPosition(
        (pos) => {
            if (latInput) latInput.value = pos.coords.latitude.toFixed(6);
            if (lngInput) lngInput.value = pos.coords.longitude.toFixed(6);
            if (gpsStatus) {
                gpsStatus.textContent = `📍 Captured: ${pos.coords.latitude.toFixed(6)}, ${pos.coords.longitude.toFixed(6)} (Accuracy ±${Math.round(pos.coords.accuracy)}m)`;
                gpsStatus.style.color = '#15803d';
            }
        },
        (err) => {
            if (gpsStatus) {
                gpsStatus.textContent = 'Unable to retrieve location: ' + err.message;
                gpsStatus.style.color = '#dc2626';
            }
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
};

window.submitRecordOfficersReturn = async function(event) {
    event.preventDefault();
    const form = event.target;
    const btn = document.getElementById('btnSubmitRecordReturn');
    const errBox = document.getElementById('rorErrorBox');

    const formData = new FormData(form);

    try {
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Saving Return...';
        }
        if (errBox) errBox.style.display = 'none';

        const res = await fetch('../../../backend/api/paanyaya/record-return.php', {
            method: 'POST',
            body: formData
        });

        const data = await res.json().catch(() => ({}));

        if (!res.ok || !data.success) {
            throw new Error(data.message || 'Failed to record Officer’s Return.');
        }

        window.agapNotify?.(data.message || 'Officer’s Return recorded successfully!', 'success');
        closeRecordOfficersReturnModal();
        await loadComplaintDetails();
    } catch (err) {
        if (errBox) {
            errBox.textContent = err.message || 'Error recording return.';
            errBox.style.display = 'block';
        } else {
            alert(err.message || 'Error recording return.');
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Save Officer\'s Return';
        }
    }
};

window.viewOfficersReturnDetails = async function(returnId) {
    const modal = document.getElementById('viewOfficersReturnModal');
    const body = document.getElementById('vorContentBody');
    const editBtn = document.getElementById('btnVorEditCorrection');
    if (!modal || !body) return;

    body.innerHTML = '<div style="padding: 24px; text-align: center; color: #64748b;">Loading return details...</div>';
    modal.style.display = 'flex';

    try {
        const res = await fetch(`../../../backend/api/paanyaya/return-details.php?id=${encodeURIComponent(returnId)}`);
        const json = await res.json();

        if (!json.success || !json.data) {
            throw new Error(json.message || 'Unable to load return details.');
        }

        const d = json.data;
        const esc = (s) => escapeHtml(s ?? '');

        let outClass = 'outcome-other';
        if (d.delivery_outcome === 'Successfully delivered') outClass = 'outcome-delivered';
        else if (d.delivery_outcome === 'Recipient unavailable') outClass = 'outcome-unavailable';
        else if (d.delivery_outcome === 'Recipient refused to receive') outClass = 'outcome-refused';
        else if (d.delivery_outcome === 'Respondent not found at the address') outClass = 'outcome-not-found';
        else if (d.delivery_outcome === 'Incorrect or incomplete address') outClass = 'outcome-incorrect-addr';

        const dtFormatted = d.service_datetime ?
            new Date(d.service_datetime.replace(' ', 'T')).toLocaleString('en-PH', { month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true }) :
            esc(d.service_datetime || '—');

        const proofSection = d.proof_file_path ? `
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-top: 6px;">
                <strong style="font-size: 0.82rem; color: #475569; display: block; margin-bottom: 6px;">Proof of Service Attachment:</strong>
                <button type="button" class="btn-create" style="padding: 6px 14px; font-size: 0.82rem;" onclick="openPaanyayaProofLightbox('${esc(d.proof_file_path)}')">
                    📷 View Attached Proof Document
                </button>
            </div>
        ` : '<span style="color: #94a3b8; font-size: 0.84rem;">No proof file attached</span>';

        const gpsSection = (d.latitude && d.longitude) ? `
            <span>📍 ${esc(d.latitude)}, ${esc(d.longitude)} &bull; <a href="https://www.google.com/maps?q=${esc(d.latitude)},${esc(d.longitude)}" target="_blank" style="color: #2563eb; text-decoration: underline;">View on Map</a></span>
        ` : '<span style="color: #94a3b8;">No GPS coordinates recorded</span>';

        let auditHtml = '';
        if (d.corrections && d.corrections.length > 0) {
            auditHtml = `
                <div style="background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; padding: 12px; margin-top: 8px;">
                    <strong style="color: #92400e; font-size: 0.84rem; display: block; margin-bottom: 6px;">Correction Audit Trail:</strong>
                    ${d.corrections.map(c => `
                        <div style="font-size: 0.78rem; color: #78350f; border-bottom: 1px dashed #fde68a; padding: 4px 0;">
                            <strong>By ${esc(c.user_name || 'Staff')} on ${esc(c.created_at)}:</strong> ${esc(c.justification || c.details || 'Correction applied')}
                        </div>
                    `).join('')}
                </div>
            `;
        }

        body.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; padding: 12px 14px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div>
                    <span style="font-size: 0.82rem; font-weight: 700; color: #64748b; text-transform: uppercase;">${esc(d.stage_type)}</span>
                    <h3 style="margin: 2px 0 0; font-size: 1.1rem; color: #0f172a; font-weight: 800;">Attempt ${d.attempt_number} of 6</h3>
                </div>
                <span class="outcome-badge ${outClass}" style="font-size: 0.86rem; padding: 4px 12px;">${esc(d.delivery_outcome)}</span>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 0.86rem;">
                <div><strong>Date & Time of Service:</strong><br><span style="color: #334155;">${dtFormatted}</span></div>
                <div><strong>Assigned Serving Officer:</strong><br><span style="color: #334155;">${esc(d.serving_officer_name || 'Unassigned')}</span></div>
                <div><strong>Intended Recipient:</strong><br><span style="color: #334155;">${esc(d.recipient_name || '—')}</span></div>
                <div><strong>Address Visited:</strong><br><span style="color: #334155;">${esc(d.service_address || '—')}</span></div>
            </div>

            ${d.unsuccessful_reason ? `
                <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 10px 14px; font-size: 0.85rem; color: #991b1b;">
                    <strong>Reason for Unsuccessful Delivery:</strong>
                    <p style="margin: 4px 0 0;">${esc(d.unsuccessful_reason)}</p>
                </div>
            ` : ''}

            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-size: 0.85rem;">
                <strong style="color: #475569;">Remarks / Detailed Explanation:</strong>
                <p style="margin: 6px 0 0; color: #1e293b; line-height: 1.5;">${esc(d.remarks || 'No detailed remarks recorded.')}</p>
            </div>

            <div style="font-size: 0.85rem;">
                <strong>GPS Coordinates:</strong> ${gpsSection}
            </div>

            <div>${proofSection}</div>

            <div style="font-size: 0.8rem; color: #64748b; padding-top: 8px; border-top: 1px solid #f1f5f9;">
                <strong>Recorded By:</strong> ${esc(d.recorded_by_name || 'Authorized Staff')} &bull; 
                <strong>Recorded At:</strong> ${esc(d.created_at || '—')}
            </div>

            ${auditHtml}
        `;

        if (editBtn) {
            editBtn.style.display = 'inline-flex';
            editBtn.setAttribute('data-return-id', returnId);
        }
    } catch (err) {
        body.innerHTML = `<div class="alert alert-danger" style="margin: 0;">${escapeHtml(err.message)}</div>`;
        if (editBtn) editBtn.style.display = 'none';
    }
};

window.closeViewOfficersReturnModal = function() {
    const modal = document.getElementById('viewOfficersReturnModal');
    if (modal) modal.style.display = 'none';
};

window.openEditFromViewReturn = function() {
    const editBtn = document.getElementById('btnVorEditCorrection');
    const returnId = editBtn?.getAttribute('data-return-id');
    closeViewOfficersReturnModal();
    if (returnId) openEditOfficersReturnModal(returnId);
};

window.openEditOfficersReturnModal = async function(returnId) {
    const modal = document.getElementById('editOfficersReturnModal');
    if (!modal) return;

    const idInput = document.getElementById('eorReturnId');
    const addrInput = document.getElementById('eorServiceAddress');
    const reasonInput = document.getElementById('eorUnsuccessfulReason');
    const remarksInput = document.getElementById('eorRemarks');
    const notesInput = document.getElementById('eorCorrectionNotes');
    const errBox = document.getElementById('eorErrorBox');

    if (idInput) idInput.value = returnId;
    if (notesInput) notesInput.value = '';
    if (errBox) errBox.style.display = 'none';

    try {
        const res = await fetch(`../../../backend/api/paanyaya/return-details.php?id=${encodeURIComponent(returnId)}`);
        const json = await res.json();
        if (json.success && json.data) {
            const d = json.data;
            if (addrInput) addrInput.value = d.service_address || '';
            if (reasonInput) reasonInput.value = d.unsuccessful_reason || '';
            if (remarksInput) remarksInput.value = d.remarks || '';
        }
    } catch (_) {}

    modal.style.display = 'flex';
};

window.closeEditOfficersReturnModal = function() {
    const modal = document.getElementById('editOfficersReturnModal');
    if (modal) modal.style.display = 'none';
};

window.submitEditOfficersReturn = async function(event) {
    event.preventDefault();
    const form = event.target;
    const btn = document.getElementById('btnSubmitEditReturn');
    const errBox = document.getElementById('eorErrorBox');

    const formData = new FormData(form);
    const payload = {
        return_id: parseInt(formData.get('return_id'), 10),
        service_address: formData.get('service_address'),
        unsuccessful_reason: formData.get('unsuccessful_reason'),
        remarks: formData.get('remarks'),
        correction_notes: formData.get('correction_notes')
    };

    if (!payload.correction_notes || !payload.correction_notes.trim()) {
        if (errBox) {
            errBox.textContent = 'Correction justification is required for the audit trail.';
            errBox.style.display = 'block';
        }
        return;
    }

    try {
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Saving Correction...';
        }
        if (errBox) errBox.style.display = 'none';

        const res = await complaintApi('../../../backend/api/paanyaya/update-return.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        if (res.success) {
            window.agapNotify?.(res.message || 'Correction saved successfully with audit record!', 'success');
            closeEditOfficersReturnModal();
            await loadComplaintDetails();
        } else {
            throw new Error(res.message || 'Failed to save correction.');
        }
    } catch (err) {
        if (errBox) {
            errBox.textContent = err.message || 'Error saving correction.';
            errBox.style.display = 'block';
        } else {
            alert(err.message || 'Error saving correction.');
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Save Correction';
        }
    }
};

window.openPaanyayaReviewModal = function(stageType) {
    const modal = document.getElementById('reviewPaanyayaModal');
    if (!modal) return;

    const inputType = document.getElementById('rpmStageType');
    const sub = document.getElementById('rpmModalSub');
    const decisionSelect = document.getElementById('rpmDecision');
    const notesInput = document.getElementById('rpmReviewNotes');
    const errBox = document.getElementById('rpmErrorBox');

    if (inputType) inputType.value = stageType;
    if (sub) sub.textContent = `Review for ${stageType}`;
    if (decisionSelect) decisionSelect.value = '';
    if (notesInput) notesInput.value = '';
    if (errBox) errBox.style.display = 'none';

    modal.style.display = 'flex';
};

window.closePaanyayaReviewModal = function() {
    const modal = document.getElementById('reviewPaanyayaModal');
    if (modal) modal.style.display = 'none';
};

window.submitPaanyayaReview = async function(event) {
    event.preventDefault();
    const form = event.target;
    const btn = document.getElementById('btnSubmitReview');
    const errBox = document.getElementById('rpmErrorBox');

    const formData = new FormData(form);
    const payload = {
        complaint_id: parseInt(formData.get('complaint_id'), 10),
        stage_type: formData.get('stage_type'),
        decision: formData.get('decision'),
        review_notes: formData.get('review_notes')
    };

    try {
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Confirming Decision...';
        }
        if (errBox) errBox.style.display = 'none';

        const res = await complaintApi('../../../backend/api/paanyaya/review-stage.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        if (res.success) {
            window.agapNotify?.(res.message || 'Lupon review decision recorded successfully!', 'success');
            closePaanyayaReviewModal();
            await loadComplaintDetails();
        } else {
            throw new Error(res.message || 'Failed to record review decision.');
        }
    } catch (err) {
        if (errBox) {
            errBox.textContent = err.message || 'Error recording review decision.';
            errBox.style.display = 'block';
        } else {
            alert(err.message || 'Error recording review decision.');
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Confirm Decision';
        }
    }
};

window.openPaanyayaProofLightbox = function(filePath) {
    const modal = document.getElementById('paanyayaProofLightboxModal');
    const img = document.getElementById('plbImage');
    const pdfWrap = document.getElementById('plbPdfContainer');
    const pdfLink = document.getElementById('plbPdfLink');
    const dlBtn = document.getElementById('plbDownloadBtn');
    if (!modal) return;

    const fullUrl = '../../../' + filePath.replace(/^\/+/, '');
    const isPdf = filePath.toLowerCase().endsWith('.pdf');

    if (isPdf) {
        if (img) img.style.display = 'none';
        if (pdfWrap) pdfWrap.style.display = 'block';
        if (pdfLink) pdfLink.href = fullUrl;
    } else {
        if (img) {
            img.style.display = 'inline-block';
            img.src = fullUrl;
        }
        if (pdfWrap) pdfWrap.style.display = 'none';
    }

    if (dlBtn) dlBtn.href = fullUrl;
    modal.style.display = 'flex';
};

window.closePaanyayaProofLightbox = function() {
    const modal = document.getElementById('paanyayaProofLightboxModal');
    if (modal) modal.style.display = 'none';
    const img = document.getElementById('plbImage');
    if (img) img.src = '';
};

window.previewPaanyayaStageLetter = function(stageType) {
    const complaintId = window.complaintData?.complaint_id || new URLSearchParams(window.location.search).get('id');
    if (!complaintId) return;

    const iframe = document.getElementById('paanyayaPreviewIframe');
    if (iframe) {
        iframe.src = `../../../backend/api/paanyaya/preview.php?id=${encodeURIComponent(complaintId)}&stage_type=${encodeURIComponent(stageType)}&format=html&_t=${Date.now()}`;
    }
    const dlBtn = document.getElementById('btnModalDownloadPaanyaya');
    if (dlBtn) {
        dlBtn.href = `../../../backend/api/paanyaya/preview.php?id=${encodeURIComponent(complaintId)}&stage_type=${encodeURIComponent(stageType)}&download=1`;
    }

    const modal = document.getElementById('paanyayaPreviewModal');
    if (modal) modal.style.display = 'flex';
};
JAVASCRIPT;

// Only append if not already appended
if (strpos($js, "window.renderPaanyayaDeliveryHistoryTable") === false) {
    $js .= "\n" . $newPaanyayaFunctions . "\n";
    echo "Appended new Paanyaya interactive handlers successfully.\n";
} else {
    echo "Paanyaya interactive handlers already present.\n";
}

file_put_contents($filePath, $js);
echo "complaints.js updated successfully!\n";
