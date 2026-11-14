<?php
$dialogueEndpoint = '../../../backend/api/paanyaya/dialogue.php';
$dialogueStatus = $preparation['complaint_status'];
?>
<div class="paanyaya-dialogue" id="paanyaya-dialogue-<?= $invitationNumber ?>">
    <h3>Dialogue minutes</h3>
    <?php if ($paanyayaMinutes): ?>
        <p><strong><?= $escapePrep(match ($paanyayaMinutes['outcome']) {
            'Settled' => 'Settled',
            'Dismissed' => 'Dismissed',
            'Party_Absent' => 'Respondent absent',
            default => 'No agreement',
        }) ?></strong> · <?= $escapePrep($paanyayaMinutes['dialogue_date']) ?></p>
        <p>Complainant <?= $escapePrep(strtolower($paanyayaMinutes['complainant_attended'])) ?> · Respondent <?= $escapePrep(strtolower($paanyayaMinutes['respondent_attended'])) ?></p>
        <p><?= nl2br($escapePrep($paanyayaMinutes['discussion_summary'])) ?></p>
        <?php if ($paanyayaMinutes['agreed_terms']): ?>
            <h4>Agreed terms</h4>
            <p><?= nl2br($escapePrep($paanyayaMinutes['agreed_terms'])) ?></p>
        <?php endif; ?>
        <?php if ($paanyayaMinutes['complainant_choice'] === 'Request_Paanyaya_2' && $dialogueStatus === 'Pre-Docketed (Paanyaya)'): ?>
            <a href="#paanyaya-invitation-2">Continue to the second invitation</a>
        <?php elseif ($paanyayaMinutes['complainant_choice'] === 'Escalate_Mediation' && $dialogueStatus === 'Pre-Docketed (Paanyaya)'): ?>
            <form method="post" action="<?= $dialogueEndpoint ?>">
                <input type="hidden" name="csrf_token" value="<?= $prepCsrf ?>">
                <input type="hidden" name="complaint_id" value="<?= $complaintId ?>">
                <input type="hidden" name="invitation_number" value="<?= $invitationNumber ?>">
                <input type="hidden" name="action" value="docket">
                <button type="submit" class="btn-case-primary">Create formal docket and continue to mediation</button>
            </form>
        <?php elseif ($paanyayaMinutes['complainant_choice'] === 'Escalate_Mediation' && $preparation['case_id']): ?>
            <a class="btn-case-primary" href="?id=<?= $complaintId ?>&amp;tab=mediation">Open mediation on this complaint</a>
        <?php endif; ?>
    <?php elseif ($dialogueStatus === 'Pre-Docketed (Paanyaya)'): ?>
        <form method="post" action="<?= $dialogueEndpoint ?>">
            <input type="hidden" name="csrf_token" value="<?= $prepCsrf ?>">
            <input type="hidden" name="complaint_id" value="<?= $complaintId ?>">
            <input type="hidden" name="invitation_number" value="<?= $invitationNumber ?>">
            <input type="hidden" name="action" value="minutes">
            <input type="hidden" name="document_version" value="<?= $escapePrep($preparation['document_version']) ?>">
            <div class="paanyaya-prep-fields">
                <label>Actual date and time
                    <input type="datetime-local" name="dialogue_date" required max="<?= date('Y-m-d\TH:i') ?>"
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
                    <select name="outcome" required>
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
                    <textarea name="agreed_terms" rows="3" maxlength="10000"><?= $escapePrep($formValues['agreed_terms'] ?? '') ?></textarea>
                </label>
            </div>
            <button type="submit" class="btn-case-primary">Save dialogue minutes</button>
        </form>
    <?php endif; ?>
</div>
