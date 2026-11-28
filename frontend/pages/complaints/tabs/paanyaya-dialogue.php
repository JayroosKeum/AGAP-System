<?php
$dialogueEndpoint = '../../../backend/api/paanyaya/dialogue.php';
$dialogueStatus = $preparation['complaint_status'];
$canRecordPreparedDialogue = in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2], true);

$getPartyInitials = static function (string $name): string {
    $clean = trim(preg_replace('/[^A-Za-z0-9\s]/', ' ', $name) ?? '');
    $parts = array_values(array_filter(preg_split('/\s+/', $clean) ?: []));
    if (empty($parts)) {
        return 'PT';
    }
    if (count($parts) === 1) {
        return strtoupper(substr($parts[0], 0, 2));
    }
    return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
};

$compDisplaySafe = $compDisplay ?? 'Nagpaanyaya (Complainant)';
$respDisplaySafe = $respDisplay ?? 'Inanyayahan (Respondent)';
$compInitials = $getPartyInitials($compDisplaySafe);
$respInitials = $getPartyInitials($respDisplaySafe);

$scheduledIso = (string) ($savedAppointment ?? ($preparation['appointment_at'] ?? ''));
$scheduledTs = $scheduledIso !== '' ? strtotime($scheduledIso) : false;
$scheduledDisplay = $scheduledTs ? date('M j, Y g:i A', $scheduledTs) : 'Pending schedule';
$isFutureSchedule = $scheduledTs !== false && $scheduledTs > time();

$deliveredAtRaw = ($latestService['recorded_at'] ?? null) && ($serviceOutcome ?? '') === 'Delivered'
    ? (string) $latestService['recorded_at']
    : '';
$deliveredMinAttr = $deliveredAtRaw !== '' ? date('Y-m-d\TH:i', strtotime($deliveredAtRaw)) : '';

$medDaysArr = !empty($allowedMedDays) && is_array($allowedMedDays) ? $allowedMedDays : ['Tue'];
$medDayNums = !empty($allowedMedDayNumbers) && is_array($allowedMedDayNumbers) ? $allowedMedDayNumbers : [2];
$medStartVal = (string) ($medHoursStart ?? '13:00');
$medEndVal = (string) ($medHoursEnd ?? '16:30');
$medStartLabel = date('g:i A', strtotime($medStartVal));
$medRuleBadge = implode('/', $medDaysArr) . ' ' . $medStartLabel . '+ Rule';

// Compute next allowed 1st Mediation date strictly after max(today, Paanyaya scheduled date)
require_once __DIR__ . '/../../../../backend/services/MediationDeadlineService.php';
$baseAnchorDate = date('Y-m-d');
if ($scheduledTs !== false) {
    $schedDayStr = date('Y-m-d', $scheduledTs);
    if ($schedDayStr > $baseAnchorDate) {
        $baseAnchorDate = $schedDayStr;
    }
}
$medCursor = new DateTimeImmutable($baseAnchorDate . ' +1 day');
$defaultMedDate = $medCursor->format('Y-m-d');
for ($i = 0; $i < 45; $i++) {
    $dow = (int) $medCursor->format('w');
    if (in_array($dow, $medDayNums, true) && !MediationDeadlineService::isNonWorkingDay($medCursor)) {
        $defaultMedDate = $medCursor->format('Y-m-d');
        break;
    }
    $medCursor = $medCursor->modify('+1 day');
}

$pendingMedSess = $_SESSION['paanyaya_pending_mediation'][$complaintId] ?? [];
$initMedDate = (string) ($formValues['mediation_date'] ?? ($pendingMedSess['mediation_date'] ?? $defaultMedDate));
$initMedTime = (string) ($formValues['mediation_time'] ?? ($pendingMedSess['mediation_time'] ?? substr($medStartVal, 0, 5)));
$initCompAtt = in_array($formValues['complainant_attended'] ?? '', ['Present', 'Absent'], true)
    ? $formValues['complainant_attended']
    : 'Present';
$initRespAtt = in_array($formValues['respondent_attended'] ?? '', ['Present', 'Absent'], true)
    ? $formValues['respondent_attended']
    : 'Present';
$initOutcome = (string) ($formValues['outcome'] ?? '');
$initEarlyEsc = !empty($formValues['allow_immediate_escalation']);
?>
<div class="paanyaya-dialogue" id="paanyaya-dialogue-<?= $invitationNumber ?>">
    <?php if (!empty($preparationFlash)): ?>
        <p role="<?= $preparationFlash['type'] === 'error' ? 'alert' : 'status' ?>"
           style="margin: 10px 0 14px; padding: 12px 14px; border-radius: 8px; font-weight: 600; font-size: 0.88rem; <?= $preparationFlash['type'] === 'error' ? 'background:#fef2f2; color:#991b1b; border:1px solid #fecaca;' : 'background:#f0fdf4; color:#166534; border:1px solid #bbf7d0;' ?>">
            <?= $escapePrep($preparationFlash['message']) ?>
        </p>
    <?php endif; ?>

    <?php if ($paanyayaMinutes): ?>
        <div class="att-dialogue-card" style="background: #f0fdf4; border-color: #86efac; color: #14532d; margin-top: 10px;">
            <div class="att-top-row" style="margin-bottom: 8px;">
                <span class="att-overline" style="color: #166534;">DIALOGUE RECORDED</span>
                <div class="att-meta-pills">
                    <span class="att-pill is-attempt"><?= $invitationNumber === 2 ? '2nd Paanyaya' : '1st Paanyaya' ?></span>
                    <span class="att-pill" style="background:#dcfce7; border:1px solid #86efac; color:#166534;">
                        <?= $escapePrep(match ($paanyayaMinutes['outcome']) {
                            'Settled' => 'Agreement Reached (Settled)',
                            'Dismissed' => 'Dismissed at Paanyaya',
                            'Party_Absent' => 'Respondent Absent',
                            default => 'No Agreement',
                        }) ?>
                    </span>
                </div>
            </div>
            <p style="margin: 0 0 6px; font-size: 0.88rem; font-weight: 600;">
                Dialogue Date: <?= $escapePrep($paanyayaMinutes['dialogue_date']) ?> · Complainant <?= $escapePrep(strtolower($paanyayaMinutes['complainant_attended'])) ?> · Respondent <?= $escapePrep(strtolower($paanyayaMinutes['respondent_attended'])) ?>
            </p>
            <p style="margin: 0; font-size: 0.88rem; line-height: 1.5;"><?= nl2br($escapePrep($paanyayaMinutes['discussion_summary'])) ?></p>
            <?php if ($paanyayaMinutes['agreed_terms']): ?>
                <h4 style="margin: 10px 0 4px; font-size: 0.84rem; font-weight: 700;">Agreed terms</h4>
                <p style="margin: 0; font-size: 0.88rem; line-height: 1.5;"><?= nl2br($escapePrep($paanyayaMinutes['agreed_terms'])) ?></p>
            <?php endif; ?>
            <?php if (in_array($paanyayaMinutes['outcome'], ['Settled', 'Dismissed'], true)): ?>
                <div style="margin-top: 12px;">
                    <a class="btn-case-primary" href="complaint-list.php" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">← Back to Complaints List</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($paanyayaMinutes['complainant_choice'] === 'Request_Paanyaya_2' && $dialogueStatus === 'Pre-Docketed (Paanyaya)'): ?>
            <div style="margin-top: 12px;">
                <a class="btn-case-primary" href="#paanyaya-invitation-2" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">Continue to the second invitation →</a>
            </div>
        <?php elseif ($canRecordPreparedDialogue && $paanyayaMinutes['complainant_choice'] === 'Escalate_Mediation' && $dialogueStatus === 'Pre-Docketed (Paanyaya)'): ?>
            <?php
                require_once __DIR__ . '/../../../../backend/services/FilingFeeService.php';
                $dlgFeeStatus = (new FilingFeeService($preparationDb))->getStatus($complaintId);
                $dlgFeePaid = in_array($dlgFeeStatus['filing_fee_status'], ['Paid', 'Exempt / Indigent'], true);
            ?>
            <form method="post" action="<?= $dialogueEndpoint ?>" style="margin-top: 12px;"
                  onsubmit="if(this.dataset.submitting==='1'){event.preventDefault();return false;}this.dataset.submitting='1';const b=this.querySelector('button[type=submit]');if(b){b.disabled=true;b.textContent='Creating formal docket...';}">
                <input type="hidden" name="csrf_token" value="<?= $prepCsrf ?>">
                <input type="hidden" name="complaint_id" value="<?= $complaintId ?>">
                <input type="hidden" name="invitation_number" value="<?= $invitationNumber ?>">
                <input type="hidden" name="action" value="docket">
                <input type="hidden" name="enforce_filing_fee" value="1">

                <div class="att-esc-drawer" style="margin-top: 0; margin-bottom: 12px;">
                    <div class="att-esc-drawer-head">
                        <span class="att-esc-drawer-title">Step 5 &middot; Official KP Form No. 7 Filing Fee</span>
                        <span class="att-esc-rule-pill"><?= $dlgFeePaid ? $escapePrep($dlgFeeStatus['filing_fee_status']) : ('Fee: PHP ' . number_format((float) $dlgFeeStatus['default_fee_amount'], 2)) ?></span>
                    </div>
                    <?php if ($dlgFeePaid): ?>
                        <p style="margin:0; font-size:0.84rem; color:#166534; font-weight:600;">
                            <?= $dlgFeeStatus['filing_fee_status'] === 'Paid'
                                ? ('Official Receipt Recorded: ' . $escapePrep((string) $dlgFeeStatus['filing_fee_or_number']) . ' (PHP ' . number_format((float) $dlgFeeStatus['filing_fee_amount'], 2) . ')')
                                : ('Filing Fee Exempted: ' . $escapePrep((string) $dlgFeeStatus['filing_fee_waiver_reason'])) ?>
                        </p>
                    <?php else: ?>
                        <div style="display:grid; gap:10px;" data-fee-group="<?= $invitationNumber ?>">
                            <div style="display:flex; gap:14px; flex-wrap:wrap; font-size:0.82rem; font-weight:600; color:#0f172a;">
                                <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                                    <input type="radio" name="filing_fee_status" value="Paid" checked onchange="const g=this.closest('[data-fee-group]');const p=g.querySelector('[data-fee-paid]');const w=g.querySelector('[data-fee-waiver]');p.style.display='grid';w.style.display='none';p.querySelectorAll('input').forEach(i=>i.disabled=false);w.querySelectorAll('input').forEach(i=>i.disabled=true);">
                                    <span>Official Receipt Paid (KP Form No. 7)</span>
                                </label>
                                <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                                    <input type="radio" name="filing_fee_status" value="Exempt / Indigent" onchange="const g=this.closest('[data-fee-group]');const p=g.querySelector('[data-fee-paid]');const w=g.querySelector('[data-fee-waiver]');p.style.display='none';w.style.display='block';p.querySelectorAll('input').forEach(i=>i.disabled=true);w.querySelectorAll('input').forEach(i=>i.disabled=false);">
                                    <span>Indigent / Fee Exempt</span>
                                </label>
                            </div>
                            <div class="att-form-grid" style="margin-bottom:0;" data-fee-paid="1">
                                <label class="att-field-group">
                                    <span class="att-field-label" style="font-size:0.76rem;">Official Receipt (OR) No. *</span>
                                    <input type="text" name="filing_fee_or_number" class="att-clean-input" maxlength="64" required placeholder="e.g. OR-2026-00142">
                                </label>
                                <label class="att-field-group">
                                    <span class="att-field-label" style="font-size:0.76rem;">Amount Paid (PHP) *</span>
                                    <input type="number" step="0.01" min="0" name="filing_fee_amount" class="att-clean-input" required value="<?= number_format((float) $dlgFeeStatus['default_fee_amount'], 2, '.', '') ?>">
                                </label>
                            </div>
                            <div style="display:none;" data-fee-waiver="1">
                                <label class="att-field-group">
                                    <span class="att-field-label" style="font-size:0.76rem;">Indigency / Exemption Basis *</span>
                                    <input type="text" name="filing_fee_waiver_reason" class="att-clean-input" maxlength="255" disabled required placeholder="e.g. Barangay Indigency Certificate No. 2026-088">
                                </label>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="att-esc-drawer" style="margin-top: 0; margin-bottom: 12px;">
                    <div class="att-esc-drawer-head">
                        <span class="att-esc-drawer-title">1st Formal Mediation Schedule (Punong Barangay)</span>
                        <span class="att-esc-rule-pill"><?= $escapePrep($medRuleBadge) ?></span>
                    </div>
                    <div class="att-form-grid" style="margin-bottom: 0;">
                        <label class="att-field-group">
                            <span class="att-field-label" style="font-size: 0.76rem;">1st Mediation Date</span>
                            <input type="date" name="mediation_date" class="att-clean-input"
                                   min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                                   value="<?= $escapePrep($initMedDate) ?>" required>
                        </label>
                        <label class="att-field-group">
                            <span class="att-field-label" style="font-size: 0.76rem;">Target Call Time</span>
                            <input type="time" name="mediation_time" class="att-clean-input"
                                   min="<?= $escapePrep(substr($medStartVal, 0, 5)) ?>"
                                   max="<?= $escapePrep(substr($medEndVal, 0, 5)) ?>"
                                   value="<?= $escapePrep($initMedTime) ?>" required>
                        </label>
                    </div>
                </div>

                <button type="submit" class="att-submit-btn">Create formal docket and continue to mediation</button>
            </form>
        <?php elseif ($paanyayaMinutes['complainant_choice'] === 'Escalate_Mediation' && $dialogueStatus === 'Pre-Docketed (Paanyaya)'): ?>
            <p style="margin: 8px 0 0; font-size: 0.85rem; color: #475569;">Escalation requested — awaiting Lupon Clerk formal docketing for Mediation.</p>
        <?php elseif ($paanyayaMinutes['complainant_choice'] === 'Escalate_Mediation' && $preparation['case_id']): ?>
            <?php
            $caseIsClosed = in_array((string) ($dialogueStatus ?? ''), ['Settled', 'Amicable Settlement', 'Arbitration Award', 'Closed', 'Dismissed', 'Resolved at Paanyaya / Closed'], true)
                || in_array((string) ($preparation['case_status'] ?? ''), ['Settled', 'Amicable Settlement', 'Arbitration Award', 'Closed', 'Dismissed'], true);
            ?>
            <?php if (!$caseIsClosed): ?>
                <div style="margin-top: 12px;">
                    <a class="btn-case-primary" href="?id=<?= $complaintId ?>&amp;tab=mediation" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">Open mediation on this complaint →</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php elseif ($canRecordPreparedDialogue && $dialogueStatus === 'Pre-Docketed (Paanyaya)'): ?>
        <form method="post" action="<?= $dialogueEndpoint ?>" class="att-dialogue-card att-dialogue-form"
              data-pny-dialogue-form="1"
              data-attempt="<?= $invitationNumber ?>"
              data-is-future="<?= $isFutureSchedule ? '1' : '0' ?>"
              data-init-outcome="<?= $escapePrep($initOutcome) ?>">
            <input type="hidden" name="csrf_token" value="<?= $prepCsrf ?>">
            <input type="hidden" name="complaint_id" value="<?= $complaintId ?>">
            <input type="hidden" name="invitation_number" value="<?= $invitationNumber ?>">
            <input type="hidden" name="action" value="minutes">
            <input type="hidden" name="document_version" value="<?= $escapePrep($preparation['document_version']) ?>">

            <div class="att-top-row">
                <span class="att-overline">DIALOGUE DETAILS</span>
                <div class="att-meta-pills">
                    <span class="att-pill is-attempt"><?= $invitationNumber === 2 ? '2nd Paanyaya' : '1st Paanyaya' ?></span>
                    <span class="att-pill is-status"><?= $escapePrep($serviceOutcome ?: 'Delivered') ?></span>
                </div>
            </div>

            <!-- Side-by-Side Attendance Cards -->
            <div class="att-form-grid">
                <div class="att-field-group">
                    <label class="att-field-label">Nagpaanyaya (Complainant)</label>
                    <div class="att-input-shell">
                        <div class="att-party-chip">
                            <span class="att-party-avatar"><?= $escapePrep($compInitials) ?></span>
                            <span class="att-party-name" title="<?= $escapePrep($compDisplaySafe) ?>"><?= $escapePrep($compDisplaySafe) ?></span>
                        </div>
                        <div class="att-seg-group" role="radiogroup" aria-label="Complainant attendance">
                            <label class="att-seg-pill">
                                <input type="radio" name="complainant_attended" value="Present" <?= $initCompAtt === 'Present' ? 'checked' : '' ?> required>
                                <span class="att-seg-text">Present</span>
                            </label>
                            <label class="att-seg-pill">
                                <input type="radio" name="complainant_attended" value="Absent" <?= $initCompAtt === 'Absent' ? 'checked' : '' ?> required>
                                <span class="att-seg-text">Absent</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="att-field-group">
                    <label class="att-field-label">Inanyayahan (Respondent)</label>
                    <div class="att-input-shell">
                        <div class="att-party-chip">
                            <span class="att-party-avatar is-respondent"><?= $escapePrep($respInitials) ?></span>
                            <span class="att-party-name" title="<?= $escapePrep($respDisplaySafe) ?>"><?= $escapePrep($respDisplaySafe) ?></span>
                        </div>
                        <div class="att-seg-group" role="radiogroup" aria-label="Respondent attendance">
                            <label class="att-seg-pill">
                                <input type="radio" name="respondent_attended" value="Present" <?= $initRespAtt === 'Present' ? 'checked' : '' ?> required>
                                <span class="att-seg-text">Present</span>
                            </label>
                            <label class="att-seg-pill">
                                <input type="radio" name="respondent_attended" value="Absent" <?= $initRespAtt === 'Absent' ? 'checked' : '' ?> required>
                                <span class="att-seg-text">Absent</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Schedule Date & Time + Actual Dialogue Date & Time -->
            <div class="att-form-grid">
                <div class="att-field-group">
                    <label class="att-field-label">Schedule Date &amp; Time</label>
                    <div class="att-input-shell is-readonly">
                        <span aria-hidden="true" style="color: #64748b;">📅</span>
                        <span><?= $escapePrep($scheduledDisplay) ?></span>
                    </div>
                </div>

                <div class="att-field-group">
                    <label class="att-field-label" for="att-dialogue-date-<?= $invitationNumber ?>">
                        <span>Actual Dialogue Date &amp; Time</span>
                    </label>
                    <div class="att-input-shell">
                        <input id="att-dialogue-date-<?= $invitationNumber ?>"
                               type="datetime-local"
                               name="dialogue_date"
                               <?= $deliveredMinAttr !== '' ? 'min="' . $escapePrep($deliveredMinAttr) . '"' : '' ?>
                               value="<?= $escapePrep($formValues['dialogue_date'] ?? date('Y-m-d\TH:i')) ?>"
                               required>
                    </div>
                </div>
            </div>

            <!-- Initial Salaysay (Read-Only Reference) -->
            <div class="att-field-group" style="margin-bottom: 14px;">
                <div class="att-field-label">
                    <span>Initial Salaysay (Statement of Complaint)</span>
                    <span class="att-meta-pills">
                        <span style="font-size: 0.72rem; font-weight: 700; color: <?= ($prepCaseType ?? 'Civil') === 'Criminal' ? '#b91c1c' : '#1d4ed8' ?>;">
                            <?= $escapePrep($prepCaseType ?? 'Civil') ?>
                        </span>
                        <?php if (!empty($prepCategoryText)): ?>
                            <span style="font-size: 0.72rem; font-weight: 600; color: #475569;"><?= $escapePrep($prepCategoryText) ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="att-salaysay-readonly"><?= $escapePrep(($prepNarrative ?? '') !== '' ? $prepNarrative : 'No Salaysay narrative recorded.') ?></div>
            </div>

            <!-- Session-Specific Dialogue Minutes -->
            <div class="att-field-group" style="margin-bottom: 14px;">
                <label class="att-field-label" for="att-notes-<?= $invitationNumber ?>">
                    <span>Dialogue Minutes &amp; Attendance Notes</span>
                    <span class="att-field-hint">Session-specific minutes (Salaysay transfers separately if escalated)</span>
                </label>
                <textarea id="att-notes-<?= $invitationNumber ?>"
                          name="discussion_summary"
                          class="att-clean-textarea"
                          rows="4"
                          maxlength="10000"
                          required
                          placeholder="Record dialogue minutes, agreements, party explanations, or attendance remarks..."><?= $escapePrep($formValues['discussion_summary'] ?? '') ?></textarea>
            </div>

            <!-- Outcome & Conditional Drawers -->
            <div class="att-outcome-section">
                <div class="att-field-group" style="margin-bottom: 0;">
                    <label class="att-field-label" for="att-outcome-<?= $invitationNumber ?>">
                        <span>Outcome</span>
                        <span class="att-outcome-rule-badge" data-pny-rule-badge>Checking attendance rule...</span>
                    </label>
                    <select id="att-outcome-<?= $invitationNumber ?>" name="outcome" class="att-clean-select" required>
                        <option value="Settled" <?= $initOutcome === 'Settled' ? 'selected' : '' ?>>Agreement reached (Settled)</option>
                        <?php if ($invitationNumber === 1): ?>
                            <option value="Request_Paanyaya_2" <?= $initOutcome === 'Request_Paanyaya_2' ? 'selected' : '' ?>>Schedule 2nd Paanyaya</option>
                        <?php endif; ?>
                        <option value="Escalate_Mediation" <?= $initOutcome === 'Escalate_Mediation' ? 'selected' : '' ?>>Escalate to Mediation</option>
                        <option value="Dismissed" <?= $initOutcome === 'Dismissed' ? 'selected' : '' ?>>Dismissed — Complainant Absent (RA 7160 Sec. 410(b))</option>
                    </select>
                </div>

                <!-- Agreed Terms Drawer (Visible when Settled) -->
                <div class="att-terms-drawer" data-pny-terms-drawer style="display: none;">
                    <label class="att-field-group">
                        <span class="att-field-label" for="att-terms-<?= $invitationNumber ?>" style="color: #166534;">
                            <span>Agreed Terms (Required for a Settlement)</span>
                        </span>
                        <textarea id="att-terms-<?= $invitationNumber ?>"
                                  name="agreed_terms"
                                  class="att-clean-textarea"
                                  rows="3"
                                  maxlength="10000"
                                  placeholder="Enter the specific settlement agreement reached by both parties..."><?= $escapePrep($formValues['agreed_terms'] ?? '') ?></textarea>
                    </label>
                </div>

                <!-- Escalate to Mediation Drawer (Visible when Escalate_Mediation) -->
                <div data-pny-escalate-wrap style="display: none;">
                    <label class="att-early-check">
                        <input type="checkbox" name="allow_immediate_escalation" value="1" <?= $initEarlyEsc ? 'checked' : '' ?>>
                        <span>Allow early escalation / record immediately (even if scheduled date is in the future)</span>
                    </label>

                    <div class="att-esc-drawer">
                        <div class="att-esc-drawer-head">
                            <span class="att-esc-drawer-title">1st Formal Mediation Schedule (Punong Barangay)</span>
                            <span class="att-esc-rule-pill"><?= $escapePrep($medRuleBadge) ?></span>
                        </div>
                        <div class="att-form-grid" style="margin-bottom: 0;">
                            <label class="att-field-group">
                                <span class="att-field-label" style="font-size: 0.76rem;">1st Mediation Date</span>
                                <input type="date" name="mediation_date" class="att-clean-input"
                                       min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                                       value="<?= $escapePrep($initMedDate) ?>">
                            </label>
                            <label class="att-field-group">
                                <span class="att-field-label" style="font-size: 0.76rem;">Target Call Time</span>
                                <input type="time" name="mediation_time" class="att-clean-input"
                                       min="<?= $escapePrep(substr($medStartVal, 0, 5)) ?>"
                                       max="<?= $escapePrep(substr($medEndVal, 0, 5)) ?>"
                                       value="<?= $escapePrep($initMedTime) ?>">
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="att-actions-foot">
                <button type="submit" class="att-submit-btn" data-pny-submit-btn>Save Dialogue Record</button>
            </div>
        </form>
    <?php elseif ($dialogueStatus === 'Pre-Docketed (Paanyaya)'): ?>
        <p style="margin: 8px 0 0; font-size: 0.86rem; color: #475569;">Dialogue minutes will be recorded by the Lupon Clerk/Admin using the Initial Salaysay above.</p>
    <?php endif; ?>
</div>

<?php if (!defined('AGAP_PAANYAYA_DIALOGUE_SCRIPT_LOADED')): define('AGAP_PAANYAYA_DIALOGUE_SCRIPT_LOADED', true); ?>
<script>
(function () {
    function initPaanyayaDialogueForm(form) {
        if (!form || form.dataset.pnyBound === '1') return;
        form.dataset.pnyBound = '1';

        const attemptNum = parseInt(form.dataset.attempt || '1', 10) === 2 ? 2 : 1;
        const isSecond = attemptNum === 2;
        const isFutureSchedule = form.dataset.isFuture === '1';

        const compRadios = form.querySelectorAll('input[name="complainant_attended"]');
        const respRadios = form.querySelectorAll('input[name="respondent_attended"]');
        const outcomeSelect = form.querySelector('select[name="outcome"]');
        const ruleBadge = form.querySelector('[data-pny-rule-badge]');
        const termsDrawer = form.querySelector('[data-pny-terms-drawer]');
        const termsInput = form.querySelector('textarea[name="agreed_terms"]');
        const escalateWrap = form.querySelector('[data-pny-escalate-wrap]');
        const earlyEscCheck = form.querySelector('input[name="allow_immediate_escalation"]');
        const submitBtn = form.querySelector('[data-pny-submit-btn]');

        const getRadioVal = (nodes) => {
            for (const n of nodes) {
                if (n.checked) return n.value;
            }
            return 'Present';
        };

        const computeSpec = (compAtt, respAtt) => {
            const compPresent = compAtt === 'Present';
            const respPresent = respAtt === 'Present';
            const prefix = isSecond ? '2nd Paanyaya' : '1st Paanyaya';

            if (!compPresent) {
                return {
                    allowed: ['Dismissed'],
                    defaultVal: 'Dismissed',
                    hint: `${prefix} · Complainant Absent — Dismissed (RA 7160 Sec. 410(b))`,
                };
            }
            if (!respPresent) {
                if (isSecond) {
                    return {
                        allowed: ['Escalate_Mediation'],
                        defaultVal: 'Escalate_Mediation',
                        hint: '2nd Paanyaya · Respondent Absent — Escalate to Mediation',
                    };
                }
                return {
                    allowed: ['Request_Paanyaya_2', 'Escalate_Mediation'],
                    defaultVal: 'Request_Paanyaya_2',
                    hint: '1st Paanyaya · Respondent Absent — Schedule 2nd Paanyaya or Escalate',
                };
            }
            if (isSecond) {
                return {
                    allowed: ['Settled', 'Escalate_Mediation'],
                    defaultVal: 'Settled',
                    hint: '2nd Paanyaya · Both Present — Agreement or Escalate to Mediation',
                };
            }
            return {
                allowed: ['Settled', 'Request_Paanyaya_2', 'Escalate_Mediation'],
                defaultVal: 'Settled',
                hint: '1st Paanyaya · Both Present — Agreement, 2nd Paanyaya, or Escalate',
            };
        };

        const syncOutcomeUi = (preserveSelection) => {
            if (!outcomeSelect) return;
            const compAtt = getRadioVal(compRadios);
            const respAtt = getRadioVal(respRadios);
            const spec = computeSpec(compAtt, respAtt);

            Array.from(outcomeSelect.options).forEach((opt) => {
                const ok = spec.allowed.includes(opt.value);
                opt.hidden = !ok;
                opt.disabled = !ok;
            });

            const current = preserveSelection ? outcomeSelect.value : '';
            if (current && spec.allowed.includes(current)) {
                outcomeSelect.value = current;
            } else {
                outcomeSelect.value = spec.defaultVal;
            }

            if (ruleBadge) {
                ruleBadge.textContent = spec.hint;
            }

            syncDrawers();
        };

        const syncDrawers = () => {
            if (!outcomeSelect) return;
            const val = outcomeSelect.value;

            if (termsDrawer && termsInput) {
                const isSettled = val === 'Settled';
                termsDrawer.style.display = isSettled ? 'block' : 'none';
                termsInput.required = isSettled;
            }

            if (escalateWrap) {
                escalateWrap.style.display = val === 'Escalate_Mediation' ? 'block' : 'none';
            }

            if (submitBtn) {
                submitBtn.classList.remove('is-blue', 'is-danger');
                if (val === 'Escalate_Mediation') {
                    submitBtn.textContent = 'Escalate to Mediation';
                } else if (val === 'Request_Paanyaya_2') {
                    submitBtn.textContent = 'Schedule 2nd Paanyaya';
                    submitBtn.classList.add('is-blue');
                } else if (val === 'Dismissed') {
                    submitBtn.textContent = 'Record Dismissal';
                    submitBtn.classList.add('is-danger');
                } else {
                    submitBtn.textContent = 'Confirm Settlement (Settled)';
                }
            }
        };

        compRadios.forEach((r) => r.addEventListener('change', () => syncOutcomeUi(false)));
        respRadios.forEach((r) => r.addEventListener('change', () => syncOutcomeUi(false)));
        outcomeSelect?.addEventListener('change', syncDrawers);

        form.addEventListener('submit', function (event) {
            const out = outcomeSelect?.value || '';
            if (out === 'Settled' && termsInput && !termsInput.value.trim()) {
                event.preventDefault();
                termsInput.required = true;
                termsInput.focus();
                window.agapNotify?.('Enter the Agreed terms before saving an Agreement reached (Settled) outcome.', 'error', 'Agreed Terms Required');
                return false;
            }
            if (out === 'Escalate_Mediation' && isFutureSchedule && earlyEscCheck && !earlyEscCheck.checked) {
                event.preventDefault();
                earlyEscCheck.focus();
                window.agapNotify?.('The scheduled Paanyaya appointment is still in the future. Check "Allow early escalation / record immediately" to proceed now.', 'warning', 'Early Escalation Confirmation');
                return false;
            }
            if (form.dataset.submitting === '1') {
                event.preventDefault();
                return false;
            }
            form.dataset.submitting = '1';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Saving dialogue record...';
            }
        });

        const initOutcome = form.dataset.initOutcome || '';
        syncOutcomeUi(Boolean(initOutcome));
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-pny-dialogue-form="1"]').forEach(initPaanyayaDialogueForm);
    });
    if (document.readyState !== 'loading') {
        document.querySelectorAll('[data-pny-dialogue-form="1"]').forEach(initPaanyayaDialogueForm);
    }
})();
</script>
<?php endif; ?>
