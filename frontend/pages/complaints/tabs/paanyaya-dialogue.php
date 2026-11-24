<?php
$dialogueEndpoint = '../../../backend/api/paanyaya/dialogue.php';
$dialogueStatus = $preparation['complaint_status'];
$canRecordPreparedDialogue = in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2], true);
?>
<div class="paanyaya-dialogue" id="paanyaya-dialogue-<?= $invitationNumber ?>">
    <h3>Dialogue minutes</h3>
    <?php if (!empty($preparationFlash)): ?>
        <p role="<?= $preparationFlash['type'] === 'error' ? 'alert' : 'status' ?>"
           style="margin: 10px 0 14px; padding: 12px 14px; border-radius: 8px; font-weight: 600; font-size: 0.88rem; <?= $preparationFlash['type'] === 'error' ? 'background:#fef2f2; color:#991b1b; border:1px solid #fecaca;' : 'background:#f0fdf4; color:#166534; border:1px solid #bbf7d0;' ?>">
            <?= $escapePrep($preparationFlash['message']) ?>
        </p>
    <?php endif; ?>
    <?php if ($paanyayaMinutes): ?>
        <div style="margin: 10px 0 14px; padding: 14px 16px; border-radius: 10px; background: #f0fdf4; border: 1.5px solid #86efac; color: #14532d;">
            <p style="margin: 0 0 6px; font-size: 0.95rem;"><strong><?= $escapePrep(match ($paanyayaMinutes['outcome']) {
                'Settled' => '✓ Agreement Reached (Settled at Paanyaya)',
                'Dismissed' => 'Dismissed at Paanyaya',
                'Party_Absent' => 'Respondent absent',
                default => 'No agreement',
            }) ?></strong> · <?= $escapePrep($paanyayaMinutes['dialogue_date']) ?></p>
            <p style="margin: 0 0 6px; font-size: 0.85rem;">Complainant <?= $escapePrep(strtolower($paanyayaMinutes['complainant_attended'])) ?> · Respondent <?= $escapePrep(strtolower($paanyayaMinutes['respondent_attended'])) ?></p>
            <p style="margin: 0; font-size: 0.88rem;"><?= nl2br($escapePrep($paanyayaMinutes['discussion_summary'])) ?></p>
            <?php if ($paanyayaMinutes['agreed_terms']): ?>
                <h4 style="margin: 10px 0 4px; font-size: 0.86rem;">Agreed terms</h4>
                <p style="margin: 0; font-size: 0.88rem;"><?= nl2br($escapePrep($paanyayaMinutes['agreed_terms'])) ?></p>
            <?php endif; ?>
            <?php if (in_array($paanyayaMinutes['outcome'], ['Settled', 'Dismissed'], true)): ?>
                <div style="margin-top: 12px;">
                    <a class="btn-case-primary" href="complaint-list.php" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">← Back to Complaints List</a>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($paanyayaMinutes['complainant_choice'] === 'Request_Paanyaya_2' && $dialogueStatus === 'Pre-Docketed (Paanyaya)'): ?>
            <a href="#paanyaya-invitation-2">Continue to the second invitation</a>
        <?php elseif ($canRecordPreparedDialogue && $paanyayaMinutes['complainant_choice'] === 'Escalate_Mediation' && $dialogueStatus === 'Pre-Docketed (Paanyaya)'): ?>
            <form method="post" action="<?= $dialogueEndpoint ?>" onsubmit="if(this.dataset.submitting==='1'){event.preventDefault();return false;}this.dataset.submitting='1';const b=this.querySelector('button[type=submit]');if(b){b.disabled=true;b.textContent='Creating formal docket...';}">
                <input type="hidden" name="csrf_token" value="<?= $prepCsrf ?>">
                <input type="hidden" name="complaint_id" value="<?= $complaintId ?>">
                <input type="hidden" name="invitation_number" value="<?= $invitationNumber ?>">
                <input type="hidden" name="action" value="docket">
                <button type="submit" class="btn-case-primary">Create formal docket and continue to mediation</button>
            </form>
        <?php elseif ($paanyayaMinutes['complainant_choice'] === 'Escalate_Mediation' && $dialogueStatus === 'Pre-Docketed (Paanyaya)'): ?>
            <p style="margin: 8px 0 0; font-size: 0.85rem; color: #475569;">Escalation requested — awaiting Lupon Clerk formal docketing for Mediation.</p>
        <?php elseif ($paanyayaMinutes['complainant_choice'] === 'Escalate_Mediation' && $preparation['case_id']): ?>
            <?php
            $caseIsClosed = in_array((string) ($dialogueStatus ?? ''), ['Settled', 'Amicable Settlement', 'Arbitration Award', 'Closed', 'Dismissed', 'Resolved at Paanyaya / Closed'], true)
                || in_array((string) ($preparation['case_status'] ?? ''), ['Settled', 'Amicable Settlement', 'Arbitration Award', 'Closed', 'Dismissed'], true);
            ?>
            <?php if (!$caseIsClosed): ?>
                <a class="btn-case-primary" href="?id=<?= $complaintId ?>&amp;tab=mediation">Open mediation on this complaint</a>
            <?php endif; ?>
        <?php endif; ?>
    <?php elseif ($canRecordPreparedDialogue && $dialogueStatus === 'Pre-Docketed (Paanyaya)'): ?>
        <form method="post" action="<?= $dialogueEndpoint ?>" onsubmit="const out=this.querySelector('[name=outcome]')?.value;const terms=this.querySelector('[name=agreed_terms]');if(out==='Settled'&&terms&&!terms.value.trim()){event.preventDefault();terms.required=true;terms.focus();window.agapNotify?.('Enter the Agreed terms before saving an Agreement reached (Settled) outcome.','error','Agreed Terms Required');return false;}if(this.dataset.submitting==='1'){event.preventDefault();return false;}this.dataset.submitting='1';const b=this.querySelector('button[type=submit]');if(b){b.disabled=true;b.textContent='Saving dialogue minutes...';}">
            <input type="hidden" name="csrf_token" value="<?= $prepCsrf ?>">
            <input type="hidden" name="complaint_id" value="<?= $complaintId ?>">
            <input type="hidden" name="invitation_number" value="<?= $invitationNumber ?>">
            <input type="hidden" name="action" value="minutes">
            <input type="hidden" name="document_version" value="<?= $escapePrep($preparation['document_version']) ?>">
            <div class="paanyaya-prep-fields">
                <label>Actual date and time
                    <input type="datetime-local" name="dialogue_date" required
                        value="<?= $escapePrep($formValues['dialogue_date'] ?? date('Y-m-d\TH:i')) ?>">
                </label>
                <?php foreach (['complainant' => 'Complainant', 'respondent' => 'Respondent'] as $partyKey => $partyLabel): ?>
                    <?php $field = $partyKey . '_attended'; ?>
                    <label><?= $partyLabel ?> attendance
                        <select name="<?= $field ?>" required>
                            <option value="">Select attendance</option>
                            <option <?= ($formValues[$field] ?? '') === 'Present' ? 'selected' : '' ?>>Present</option>
                            <option <?= ($formValues[$field] ?? '') === 'Absent' ? 'selected' : '' ?>>Absent</option>
                        </select>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="paanyaya-prep-fields">
                <label>Minutes or attendance notes
                    <textarea name="discussion_summary" rows="4" maxlength="10000" required><?= $escapePrep($formValues['discussion_summary'] ?? '') ?></textarea>
                </label>
            </div>
            <div class="paanyaya-prep-fields">
                <label>Outcome
                    <select name="outcome" required onchange="const t=this.form?.querySelector('[name=agreed_terms]');if(t){t.required=(this.value==='Settled');}">
                        <option value="">Select an outcome</option>
                        <?php
                        $choices = ['Settled' => 'Agreement reached'];
                        if ($invitationNumber === 1) {
                            $choices['Request_Paanyaya_2'] = 'Request a second Paanyaya';
                        }
                        $choices['Escalate_Mediation'] = 'Proceed to formal mediation';
                        $choices['Dismissed'] = 'Dismissed — complainant absent';
                        foreach ($choices as $value => $label):
                        ?>
                            <option value="<?= $value ?>" <?= ($formValues['outcome'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Agreed terms (required for a settlement)
                    <textarea name="agreed_terms" rows="3" maxlength="10000" placeholder="Enter the specific settlement agreement reached by both parties..." <?= ($formValues['outcome'] ?? '') === 'Settled' ? 'required' : '' ?>><?= $escapePrep($formValues['agreed_terms'] ?? '') ?></textarea>
                </label>
            </div>
            <button type="submit" class="btn-case-primary">Save dialogue minutes</button>
        </form>
    <?php elseif ($dialogueStatus === 'Pre-Docketed (Paanyaya)'): ?>
        <p style="margin: 8px 0 0; font-size: 0.86rem; color: #475569;">Dialogue minutes will be recorded by the Lupon Clerk/Admin using the Initial Salaysay above.</p>
    <?php endif; ?>
</div>
