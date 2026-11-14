<?php
$escapePrep = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$prepEndpoint = '../../../backend/api/paanyaya/preparation.php';
$savedAppointment = $preparation['appointment_at'] ?? '';
$formValues = $preparationFlash['old'] ?? [];
$prepCsrf = $escapePrep(CsrfService::token());
$serviceOutcome = $latestService['outcome'] ?? null;
$serviceEndpoint = '../../../backend/api/paanyaya/service.php';
?>
<section class="paanyaya-preparation" id="paanyaya-invitation-<?= $invitationNumber ?>" aria-labelledby="paanyaya-prep-title-<?= $invitationNumber ?>">
    <h2 id="paanyaya-prep-title-<?= $invitationNumber ?>"><?= $invitationNumber === 2 ? 'Second Paanyaya' : 'Paanyaya Preparation' ?></h2>

    <?php if ($preparationFlash): ?>
        <p role="<?= $preparationFlash['type'] === 'error' ? 'alert' : 'status' ?>">
            <?= $escapePrep($preparationFlash['message']) ?>
        </p>
    <?php endif; ?>

    <?php if ($preparation): ?>
        <p class="paanyaya-prep-status">
            <?= match (true) {
                $paanyayaMinutes !== null => 'Dialogue recorded',
                $serviceOutcome === 'Delivered' => 'Delivered',
                !$preparation['ready_at'] => $preparation['rescheduled'] ? 'Revised invitation awaiting review' : ($preparation['prepared_at'] ? 'Invitation generated' : 'Awaiting preparation'),
                $serviceOutcome === 'Not Delivered' => 'Delivery unsuccessful',
                $latestService !== null => 'Assigned for delivery',
                default => 'Ready for Release',
            } ?>
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
                <input type="hidden" name="invitation_number" value="<?= $invitationNumber ?>">
                <input type="hidden" name="action" value="generate">
                <input type="hidden" name="party_version" value="<?= PaanyayaPreparationService::partyVersion($preparationParties) ?>">
                <div class="paanyaya-prep-fields">
                    <label>Appointment date
                        <input type="date" name="appointment_date" min="<?= date('Y-m-d') ?>" <?= $preparation['rescheduled'] ? 'readonly' : '' ?>
                               value="<?= $escapePrep($formValues['appointment_date'] ?? substr($savedAppointment, 0, 10)) ?>" required>
                    </label>
                    <label>Appointment time
                        <input type="time" name="appointment_time" <?= $preparation['rescheduled'] ? 'readonly' : '' ?>
                               value="<?= $escapePrep($formValues['appointment_time'] ?? substr($savedAppointment, 11, 5)) ?>" required>
                    </label>
                    <label>Venue
                        <input type="text" name="venue" maxlength="255" <?= $preparation['rescheduled'] ? 'readonly' : '' ?>
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
            <?php $pdfUrl = $prepEndpoint . '?complaint_id=' . $complaintId . '&invitation_number=' . $invitationNumber . '&version=' . $preparation['document_version']; ?>
            <p><strong>Appointment</strong> <?= $escapePrep(date('M j, Y, g:i A', strtotime($savedAppointment))) ?> · <?= $escapePrep($preparation['venue']) ?></p>
            <p><strong>Generated</strong> <?= $escapePrep($preparation['prepared_at']) ?> · <?= $escapePrep($preparation['prepared_by_name'] ?: 'Staff account') ?></p>
            <details class="paanyaya-prep-preview" <?= !$preparation['ready_at'] ? 'open' : '' ?>>
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
                    <input type="hidden" name="invitation_number" value="<?= $invitationNumber ?>">
                    <input type="hidden" name="action" value="ready">
                    <input type="hidden" name="document_version" value="<?= $escapePrep($preparation['document_version']) ?>">
                    <button type="submit" class="btn-case-primary">Mark Ready for Release</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($paanyayaServiceUnavailable): ?>
            <p role="status">Paanyaya service is currently unavailable.</p>
        <?php elseif ($preparation['prepared_at']): ?>
            <?php if ($paanyayaServiceHistory): ?>
                <h3>Service history</h3>
                <ul class="paanyaya-prep-list">
                    <?php foreach ($paanyayaServiceHistory as $attempt): ?>
                        <li>
                            <div>
                                <strong><?= $escapePrep($attempt['outcome'] ?? 'Assigned for delivery') ?></strong>
                                <?php if (!$attempt['is_current']): ?> · Superseded invitation<?php endif; ?>
                                <div><?= $escapePrep(date('M j, Y, g:i a', strtotime($attempt['appointment_at']))) ?><?= $attempt['venue'] ? ' · ' . $escapePrep($attempt['venue']) : '' ?></div>
                                <div><?= $escapePrep($attempt['server_name']) ?> · Released <?= $escapePrep($attempt['released_at']) ?> by <?= $escapePrep($attempt['released_by_name'] ?: 'Staff account') ?></div>
                                <?php if ($attempt['recorded_at']): ?>
                                    <div>Result recorded <?= $escapePrep($attempt['recorded_at']) ?></div>
                                <?php endif; ?>
                                <?php if ($attempt['notes']): ?>
                                    <div><?= nl2br($escapePrep($attempt['notes'])) ?></div>
                                <?php endif; ?>
                            </div>
                            <?php if ($attempt['has_pdf']): ?>
                                <a href="<?= $serviceEndpoint ?>?view=invitation&amp;attempt_id=<?= (int) $attempt['attempt_id'] ?>" target="_blank" rel="noopener">View invitation PDF</a>
                            <?php endif; ?>
                            <?php if ($attempt['proof_mime']): ?>
                                <a href="<?= $serviceEndpoint ?>?view=proof&amp;attempt_id=<?= (int) $attempt['attempt_id'] ?>" target="_blank" rel="noopener">View proof image</a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($serviceOutcome === 'Delivered'): ?>
                <p>Delivery confirmed for the <?= $escapePrep(date('M j, Y, g:i a', strtotime($savedAppointment))) ?> dialogue.</p>
                <?php include __DIR__ . '/paanyaya-dialogue.php'; ?>
            <?php elseif ($preparation['eligible'] && $preparation['ready_at'] && (!$latestService || $serviceOutcome === 'Not Delivered')): ?>
                <h3 id="paanyaya-service-<?= $invitationNumber ?>"><?= $latestService ? 'Coordinate another delivery attempt' : 'Release invitation' ?></h3>
                <?php if (!$paanyayaServers): ?>
                    <p>No active summons servers are available.</p>
                <?php elseif (strtotime($savedAppointment) > time()): ?>
                    <form method="post" action="<?= $serviceEndpoint ?>">
                        <input type="hidden" name="csrf_token" value="<?= $prepCsrf ?>">
                        <input type="hidden" name="complaint_id" value="<?= $complaintId ?>">
                        <input type="hidden" name="invitation_number" value="<?= $invitationNumber ?>">
                        <input type="hidden" name="action" value="release">
                        <input type="hidden" name="document_version" value="<?= $escapePrep($preparation['document_version']) ?>">
                        <div class="paanyaya-prep-fields">
                            <label>Summons server
                                <select name="assigned_to" required>
                                    <option value="">Select a summons server</option>
                                    <?php foreach ($paanyayaServers as $server): ?>
                                        <option value="<?= (int) $server['user_id'] ?>"><?= $escapePrep($server['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                        <button type="submit" class="btn-case-primary"><?= $latestService ? 'Assign next attempt' : 'Release and assign' ?></button>
                    </form>
                <?php else: ?>
                    <p><?= $preparation['rescheduled'] ? 'The appointment has passed. Reschedule it from the docket before assigning delivery.' : 'The appointment has passed. Revise the invitation before assigning delivery.' ?></p>
                <?php endif; ?>
                <form class="paanyaya-service-form" method="post" action="<?= $prepEndpoint ?>">
                    <input type="hidden" name="csrf_token" value="<?= $prepCsrf ?>">
                    <input type="hidden" name="complaint_id" value="<?= $complaintId ?>">
                    <input type="hidden" name="invitation_number" value="<?= $invitationNumber ?>">
                    <input type="hidden" name="action" value="revise">
                    <input type="hidden" name="document_version" value="<?= $escapePrep($preparation['document_version']) ?>">
                    <button type="submit" class="btn-case-status">Revise invitation</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>
