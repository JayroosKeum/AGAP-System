<?php

// 1. Patch complaints.js
$complaintsPath = __DIR__ . '/../frontend/assets/js/complaints.js';
$complaintsJs = file_get_contents($complaintsPath);

$targetComplaintsProceed = <<<'JS'
window.proceedToOfficersReturnFromPreview = function() {
    closePaanyayaPreviewModal();
    if (typeof switchCaseTab === 'function') {
        switchCaseTab('tab-paanyaya');
    }
    setTimeout(() => {
        const stages = window.paanyayaStagesData || window.complaintData?.paanyaya_stages || {};
        const stage1 = stages.stage_1 || {};
        if (stage1.can_record_attempt) {
            openRecordReturnModal('Paanyaya 1');
        } else if (stage1.can_issue) {
            openIssuePaanyayaModal('Paanyaya 1');
        } else {
            const card = document.getElementById('paanyaya1Card');
            if (card) card.scrollIntoView({ behavior: 'smooth' });
        }
    }, 250);
};
JS;

$replaceComplaintsProceed = <<<'JS'
window.proceedToOfficersReturnFromPreview = function() {
    if (typeof closePaanyayaPreviewModal === 'function') {
        closePaanyayaPreviewModal();
    }
    const params = new URLSearchParams(window.location.search);
    const complaintId = params.get('id') || params.get('complaint_id') || window.complaintData?.complaint_id;
    window.location.href = `../gps/proof-service.php?complaint_id=${encodeURIComponent(complaintId)}&stage=Paanyaya+1&open_return=1`;
};
JS;

$targetOpenRecord = 'window.openRecordReturnModal = function(stageType) {';
$replaceOpenRecord = <<<'JS'
window.openRecordReturnModal = function(stageType) {
    const params = new URLSearchParams(window.location.search);
    const complaintId = params.get('id') || params.get('complaint_id') || window.complaintData?.complaint_id;
    const stage = stageType || 'Paanyaya 1';
    window.location.href = `../gps/proof-service.php?complaint_id=${encodeURIComponent(complaintId)}&stage=${encodeURIComponent(stage)}&open_return=1`;
    return;
    // Legacy local modal fallback below:
JS;

$complaintsJs = str_replace(str_replace("\r\n", "\n", $targetComplaintsProceed), str_replace("\r\n", "\n", $replaceComplaintsProceed), str_replace("\r\n", "\n", $complaintsJs));
if (strpos($complaintsJs, 'proof-service.php?complaint_id=') === false || strpos($complaintsJs, 'window.openRecordReturnModal = function(stageType) {') !== false && strpos($complaintsJs, 'window.location.href = `../gps/proof-service.php?complaint_id=') === false) {
    $complaintsJs = str_replace(str_replace("\r\n", "\n", $targetOpenRecord), str_replace("\r\n", "\n", $replaceOpenRecord), str_replace("\r\n", "\n", $complaintsJs));
}
file_put_contents($complaintsPath, $complaintsJs);
echo "complaints.js navigation patched.\n";

// 2. Patch gps.js for expanded Paanyaya view and DOMContentLoaded
$gpsPath = __DIR__ . '/../frontend/assets/js/gps.js';
$gpsJs = file_get_contents($gpsPath);

// Patch activeHearing storage and isPaanyayaCase in renderExpandedCaseView
$targetExpandStart = 'const activeHearing = data.hearings[0];';
$replaceExpandStart = <<<'JS'
const activeHearing = data.hearings[0];
        window.activeExpandedHearingData = activeHearing;
        const currentCaseItem = allLoadedSummonCases.find(c => Number(c.case_id) === Number(caseId)) || {};
        const isPaanyayaCase = Boolean(currentCaseItem.is_paanyaya || activeHearing.is_paanyaya);
JS;

if (strpos($gpsJs, 'window.activeExpandedHearingData = activeHearing;') === false) {
    $gpsJs = str_replace(str_replace("\r\n", "\n", $targetExpandStart), str_replace("\r\n", "\n", $replaceExpandStart), str_replace("\r\n", "\n", $gpsJs));
    echo "gps.js activeHearing and isPaanyayaCase flag added.\n";
}

// In renderCfaStepperNode: hide when isPaanyayaCase
$targetCfaRender = 'const renderCfaStepperNode = () => {';
$replaceCfaRender = <<<'JS'
const renderCfaStepperNode = () => {
            if (isPaanyayaCase) return '';
JS;
if (strpos($gpsJs, 'if (isPaanyayaCase) return \'\';') === false) {
    $gpsJs = str_replace(str_replace("\r\n", "\n", $targetCfaRender), str_replace("\r\n", "\n", $replaceCfaRender), str_replace("\r\n", "\n", $gpsJs));
    echo "gps.js renderCfaStepperNode updated.\n";
}

// In grid: hide complainant card when isPaanyayaCase
$targetGrid = '<div class="expanded-columns-grid">';
$replaceGrid = '<div class="expanded-columns-grid" ${isPaanyayaCase ? \'style="grid-template-columns: 1fr;"\' : \'\'}>';
if (strpos($gpsJs, '${isPaanyayaCase ? \'style="grid-template-columns: 1fr;"\' : \'\'}') === false) {
    $gpsJs = str_replace($targetGrid, $replaceGrid, $gpsJs);
    echo "gps.js grid updated for 1fr.\n";
}

$targetCompCard = '<div class="delivery-card" style="margin: 0; display: flex; flex-direction: column; justify-content: space-between;">';
$replaceCompCard = '<div class="delivery-card" style="margin: 0; display: ${isPaanyayaCase ? \'none\' : \'flex\'}; flex-direction: column; justify-content: space-between;">';
if (strpos($gpsJs, 'display: ${isPaanyayaCase ? \'none\' : \'flex\'}') === false) {
    $gpsJs = str_replace($targetCompCard, $replaceCompCard, $gpsJs);
    echo "gps.js complainant card hidden for Paanyaya.\n";
}

// In respondent card: update role and form type when isPaanyayaCase
$targetRespRole = '<span class="delivery-party-role" style="color: #ea580c;">Respondent</span>';
$replaceRespRole = '<span class="delivery-party-role" style="color: #ea580c;">Respondent ${isPaanyayaCase ? \'(Intended Recipient Visited)\' : \'\'}</span>';
if (strpos($gpsJs, '${isPaanyayaCase ? \'(Intended Recipient Visited)\' : \'\'}') === false) {
    $gpsJs = str_replace($targetRespRole, $replaceRespRole, $gpsJs);
}

$targetFormType = '<span class="delivery-form-type">KP Form No. 9 – Summons Delivery Tracking</span>';
$replaceFormType = '<span class="delivery-form-type">${isPaanyayaCase ? `${escapeGpsHtml(activeHearing.hearing_type || \'Paanyaya\')} – Preliminary Invitation Tracking (6 Attempts Max)` : \'KP Form No. 9 – Summons Delivery Tracking\'}</span>';
if (strpos($gpsJs, 'Preliminary Invitation Tracking (6 Attempts Max)') === false) {
    $gpsJs = str_replace($targetFormType, $replaceFormType, $gpsJs);
}

// In openOfficerReturnModal: populate is_paanyaya, stage_type, and complaint_id
$targetReturnModalBadges = "const caseBadge = document.getElementById('drawerCaseBadge');";
$replaceReturnModalBadges = <<<'JS'
const isPaanyaya = Boolean(caseItem.is_paanyaya || window.activeExpandedHearingData?.is_paanyaya);
    const paaFlagEl = document.getElementById('returnIsPaanyaya');
    if (paaFlagEl) paaFlagEl.value = isPaanyaya ? '1' : '0';
    const paaStageEl = document.getElementById('returnStageType');
    if (paaStageEl) paaStageEl.value = caseItem.paanyaya_stage_type || window.activeExpandedHearingData?.hearing_type || 'Paanyaya 1';
    const paaCompIdEl = document.getElementById('returnComplaintId');
    if (paaCompIdEl) paaCompIdEl.value = caseItem.complaint_id || caseId;

    const caseBadge = document.getElementById('drawerCaseBadge');
JS;

if (strpos($gpsJs, 'const paaFlagEl = document.getElementById(\'returnIsPaanyaya\');') === false) {
    $gpsJs = str_replace($targetReturnModalBadges, $replaceReturnModalBadges, $gpsJs);
    echo "gps.js openOfficerReturnModal badges updated.\n";
}

// In openOfficerReturnModal docBadge text
$targetDocBadgeText = "docBadge.textContent = partyType === 'Respondent' ? 'KP Form No. 9 – Summons' : 'KP Form No. 8 – Notice';";
$replaceDocBadgeText = <<<'JS'
docBadge.textContent = isPaanyaya 
            ? `${caseItem.paanyaya_stage_type || window.activeExpandedHearingData?.hearing_type || 'Paanyaya'} Invitation` 
            : (partyType === 'Respondent' ? 'KP Form No. 9 – Summons' : 'KP Form No. 8 – Notice');
JS;
if (strpos($gpsJs, 'Invitation`') === false) {
    $gpsJs = str_replace($targetDocBadgeText, $replaceDocBadgeText, $gpsJs);
}

// In DOMContentLoaded: support complaint_id and open_return
$targetDomInit = <<<'JS'
    const requestedCaseId = new URLSearchParams(window.location.search).get('case_id') || '';

    try {
        await loadCases(requestedCaseId);
    } catch (error) {
        resetCaseView();
        setGpsMessage(error.message || 'Unable to load available cases.');
    }
JS;

$replaceDomInit = <<<'JS'
    const urlParams = new URLSearchParams(window.location.search);
    const requestedCaseId = urlParams.get('case_id') || '';
    const requestedComplaintId = urlParams.get('complaint_id') || '';
    const shouldOpenReturn = urlParams.get('open_return') === '1';

    try {
        await loadCases(requestedCaseId);

        let targetCase = null;
        if (requestedCaseId) {
            targetCase = allLoadedSummonCases.find(c => Number(c.case_id) === Number(requestedCaseId));
        }
        if (!targetCase && requestedComplaintId) {
            targetCase = allLoadedSummonCases.find(c => Number(c.complaint_id) === Number(requestedComplaintId) || Number(c.case_id) === Number(requestedComplaintId));
        }

        if (targetCase) {
            // Setup Back Link
            const backLink = document.getElementById('backLink');
            const backLinkLabel = document.getElementById('backLinkLabel');
            if (backLink) {
                backLink.href = `../complaints/complaint-details.php?id=${encodeURIComponent(targetCase.complaint_id || targetCase.case_id)}&tab=paanyaya`;
                if (backLinkLabel) {
                    backLinkLabel.textContent = `Back to Case Details (${targetCase.case_number || targetCase.complaint_number || 'Paanyaya'})`;
                }
                backLink.hidden = false;
            }

            if (String(activeExpandedCaseId) !== String(targetCase.case_id)) {
                await toggleExpandCase(targetCase.case_id);
            }

            if (shouldOpenReturn) {
                setTimeout(() => {
                    const activeHearing = window.activeExpandedHearingData || {};
                    const respAttempts = activeHearing.respondent_attempts || [];
                    const pendingAttempt = respAttempts.find(a => a.is_pending) || null;
                    const nextAttemptNum = pendingAttempt ? pendingAttempt.attempt_number : (respAttempts.length + 1);
                    const respResidentId = Number(activeHearing.deliveries?.find(d => d.party_type === 'Respondent')?.resident_id || 0);
                    const respName = activeHearing.deliveries?.find(d => d.party_type === 'Respondent')?.recipient_name || targetCase.respondent_names || 'Respondent';
                    openOfficerReturnModal(
                        'Respondent',
                        respResidentId,
                        activeHearing.hearing_id,
                        targetCase.case_id,
                        'Pending',
                        respName,
                        '',
                        '',
                        '',
                        nextAttemptNum,
                        null
                    );
                }, 350);
            }
        }
    } catch (error) {
        resetCaseView();
        setGpsMessage(error.message || 'Unable to load available cases.');
    }
JS;

$gpsJs = str_replace(str_replace("\r\n", "\n", $targetDomInit), str_replace("\r\n", "\n", $replaceDomInit), str_replace("\r\n", "\n", $gpsJs));

file_put_contents($gpsPath, $gpsJs);
echo "gps.js patched successfully.\n";
