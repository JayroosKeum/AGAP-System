<?php
$escapePrep = static fn (?string $value): string => htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
$prepEndpoint = '../../../backend/api/paanyaya/preparation.php';
$savedAppointment = $preparation['appointment_at'] ?? '';
$formValues = $preparationFlash['old'] ?? [];
$prepCsrf = $escapePrep(CsrfService::token());
?>
<section class="paanyaya-preparation" id="paanyaya-preparation" aria-labelledby="paanyaya-prep-title">
    <h2 id="paanyaya-prep-title">Paanyaya Preparation</h2>

    <?php if ($preparationFlash): ?>
        <p role="<?= $preparationFlash['type'] === 'error' ? 'alert' : 'status' ?>">
            <?= $escapePrep($preparationFlash['message']) ?>
        </p>
    <?php endif; ?>

    <?php if ($preparation): ?>
        <p class="paanyaya-prep-status">
            <?= $preparation['ready_at'] ? 'Ready for Release' : ($preparation['prepared_at'] ? 'Invitation generated' : 'Awaiting preparation') ?>
        </p>
        <?php if (!empty($preparation['review_notes'])): ?>
            <p><strong>LISO screening notes</strong> <?= $escapePrep($preparation['review_notes']) ?></p>
        <?php endif; ?>

        <h3><?= $preparation['ready_at'] ? 'Verified parties' : 'Verify the parties' ?></h3>
        <?php if (!$preparationParties): ?>
            <p>Add the complainant and respondent to this intake before generating the invitation.</p>
        <?php else: ?>
            <ul class="paanyaya-prep-list">
                <?php foreach ($preparationParties as $party): ?>
                    <li>
                        <span><strong><?= $escapePrep($party['party_type']) ?></strong> <?= $escapePrep($party['full_name']) ?></span>
                        <span><?= $escapePrep($party['address'] ?: 'Address missing') ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($preparation['eligible'] && !$preparation['ready_at']): ?>
            <form method="post" action="<?= $prepEndpoint ?>">
                <input type="hidden" name="csrf_token" value="<?= $prepCsrf ?>">
                <input type="hidden" name="complaint_id" value="<?= $complaintId ?>">
                <input type="hidden" name="action" value="generate">
                <input type="hidden" name="party_version" value="<?= PaanyayaPreparationService::partyVersion($preparationParties) ?>">
                <div class="paanyaya-prep-fields">
                    <label>Appointment date
                        <input type="date" name="appointment_date" min="<?= date('Y-m-d') ?>"
                               value="<?= $escapePrep($formValues['appointment_date'] ?? substr($savedAppointment, 0, 10)) ?>" required>
                    </label>
                    <label>Appointment time
                        <input type="time" name="appointment_time"
                               value="<?= $escapePrep($formValues['appointment_time'] ?? substr($savedAppointment, 11, 5)) ?>" required>
                    </label>
                    <label>Venue
                        <input type="text" name="venue" maxlength="255"
                               value="<?= $escapePrep($formValues['venue'] ?? $preparation['venue'] ?? 'Tanggapan ng Barangay Tumana') ?>" required>
                    </label>
                </div>
                <label class="paanyaya-prep-check">
                    <input type="checkbox" name="verify_parties" value="1" required>
                    I verified the complainant and respondent names and addresses for this invitation.
                </label>
                <button type="submit" class="btn-case-primary"><?= $preparation['prepared_at'] ? 'Regenerate invitation PDF' : 'Generate invitation PDF' ?></button>
            </form>
        <?php endif; ?>

        <?php if ($preparation['prepared_at']): ?>
            <?php $pdfUrl = $prepEndpoint . '?complaint_id=' . $complaintId . '&version=' . $preparation['document_version']; ?>
            <p><strong>Appointment</strong> <?= $escapePrep(date('M j, Y, g:i A', strtotime($savedAppointment))) ?> · <?= $escapePrep($preparation['venue']) ?></p>
            <p><strong>Generated</strong> <?= $escapePrep($preparation['prepared_at']) ?> · <?= $escapePrep($preparation['prepared_by_name'] ?: 'Staff account') ?></p>
            <details class="paanyaya-prep-preview">
                <summary>Preview and print invitation</summary>
                <p>Use the PDF viewer’s print control. <a href="<?= $escapePrep($pdfUrl) ?>" target="_blank" rel="noopener">Open PDF separately</a></p>
                <iframe title="Prepared Paanyaya invitation PDF" src="<?= $escapePrep($pdfUrl) ?>" loading="lazy"></iframe>
            </details>
            <?php if ($preparation['ready_at']): ?>
                <p><strong>Marked ready</strong> <?= $escapePrep($preparation['ready_at']) ?> · <?= $escapePrep($preparation['ready_by_name'] ?: 'Staff account') ?></p>
            <?php elseif ($preparation['eligible']): ?>
                <form method="post" action="<?= $prepEndpoint ?>">
                    <input type="hidden" name="csrf_token" value="<?= $prepCsrf ?>">
                    <input type="hidden" name="complaint_id" value="<?= $complaintId ?>">
                    <input type="hidden" name="action" value="ready">
                    <input type="hidden" name="document_version" value="<?= $escapePrep($preparation['document_version']) ?>">
                    <button type="submit" class="btn-case-primary">Mark Ready for Release</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>
