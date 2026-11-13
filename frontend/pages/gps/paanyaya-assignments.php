<?php
$escapeService = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$serviceUrl = '../../../backend/api/paanyaya/service.php';
$serviceCsrf = $escapeService(CsrfService::token());
?>
<section class="paanyaya-preparation" id="paanyaya-service" aria-labelledby="paanyaya-service-heading">
    <h2 id="paanyaya-service-heading">My Paanyaya Assignments</h2>
    <?php if ($paanyayaServiceFlash): ?>
        <p role="<?= $paanyayaServiceFlash['type'] === 'error' ? 'alert' : 'status' ?>"><?= $escapeService($paanyayaServiceFlash['message']) ?></p>
    <?php endif; ?>
    <?php if ($paanyayaServiceUnavailable): ?>
        <p>Paanyaya assignments are currently unavailable.</p>
    <?php elseif (!$paanyayaAssignments): ?>
        <p>No Paanyaya invitations are assigned to you.</p>
    <?php else: ?>
        <?php foreach ($paanyayaAssignments as $assignment): ?>
            <?php
            $attemptId = (int) $assignment['attempt_id'];
            $pending = $assignment['outcome'] === null;
            $parties = $pending ? json_decode($assignment['verified_parties'], true) : [];
            ?>
            <details class="paanyaya-prep-preview">
                <summary>
                    <?= $escapeService($assignment['intake_reference'] ?: 'Intake #' . $assignment['complaint_id']) ?>
                    · Paanyaya <?= (int) $assignment['invitation_number'] ?>
                    · <?= $escapeService($assignment['complaint_title']) ?>
                    · <?= $escapeService($assignment['outcome'] ?? 'Pending delivery') ?>
                </summary>
                <?php if ($pending): ?>
                    <ul class="paanyaya-prep-list">
                        <?php foreach ($parties ?: [] as $party): ?>
                            <?php if ($party['party_type'] === 'Respondent'): ?>
                                <li><strong><?= $escapeService($party['full_name']) ?></strong> <?= $escapeService($party['address']) ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                    <p>Dialogue <?= $escapeService(date('M j, Y, g:i a', strtotime($assignment['appointment_at']))) ?> · <?= $escapeService($assignment['venue']) ?></p>
                    <a href="<?= $serviceUrl ?>?view=invitation&amp;attempt_id=<?= $attemptId ?>" target="_blank" rel="noopener">Open invitation PDF</a>
                    <form class="paanyaya-service-form" method="post" enctype="multipart/form-data" action="<?= $serviceUrl ?>">
                        <input type="hidden" name="csrf_token" value="<?= $serviceCsrf ?>">
                        <input type="hidden" name="action" value="record">
                        <input type="hidden" name="attempt_id" value="<?= $attemptId ?>">
                        <div class="paanyaya-prep-fields">
                            <label>Delivery result
                                <select name="outcome" required>
                                    <option value="">Select a result</option>
                                    <option value="Delivered">Delivered to all listed respondents</option>
                                    <option value="Not Delivered">Not delivered</option>
                                </select>
                            </label>
                            <label>Proof image (optional)
                                <input type="file" name="proof_image" accept="image/jpeg,image/png,image/webp">
                            </label>
                        </div>
                        <div class="paanyaya-prep-fields">
                            <label>Notes (required if unsuccessful)
                                <textarea name="notes" maxlength="2000" rows="3"></textarea>
                            </label>
                        </div>
                        <button type="submit" class="btn-create">Record delivery result</button>
                    </form>
                <?php else: ?>
                    <?php if ($assignment['notes']): ?>
                        <p><?= nl2br($escapeService($assignment['notes'])) ?></p>
                    <?php endif; ?>
                    <?php if ($assignment['proof_mime']): ?>
                        <a href="<?= $serviceUrl ?>?view=proof&amp;attempt_id=<?= $attemptId ?>" target="_blank" rel="noopener">View proof image</a>
                    <?php endif; ?>
                <?php endif; ?>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
