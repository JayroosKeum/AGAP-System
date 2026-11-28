<?php
$escapePrep = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$canManagePaanyayaPrep = in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2], true);
$prepEndpoint = '../../../backend/api/paanyaya/preparation.php';
$savedAppointment = $preparation['appointment_at'] ?? '';
$formValues = $preparationFlash['old'] ?? [];
$prepCsrf = $escapePrep(CsrfService::token());
$serviceOutcome = $latestService['outcome'] ?? null;
$serviceEndpoint = '../../../backend/api/paanyaya/service.php';
$prepCaseType = trim((string) ($preparation['case_type'] ?? 'Civil')) === 'Criminal' ? 'Criminal' : 'Civil';
$prepCategoryText = trim((string) ($preparation['category_name'] ?? ''));
if (strcasecmp($prepCategoryText, 'Others') === 0 && !empty($preparation['other_category_specification'])) {
    $prepCategoryText = 'Others — ' . trim((string) $preparation['other_category_specification']);
}
$prepNarrative = trim((string) ($preparation['narrative'] ?? ''));
$isPanelVisible = $isSubPanelActive ?? ($invitationNumber === 1);

$compNames = [];
$respNames = [];
foreach ($preparationParties as $pt) {
    $nm = trim((string) ($pt['full_name'] ?? ''));
    if ($nm === '') continue;
    if (strcasecmp((string) ($pt['party_type'] ?? ''), 'Respondent') === 0) {
        $respNames[] = $nm;
    } else {
        $compNames[] = $nm;
    }
}
$compDisplay = $compNames ? implode(', ', $compNames) : 'May Sumbong (Complainant)';
$respDisplay = $respNames ? implode(', ', $respNames) : 'Ipinagsusumbong (Respondent)';
$scheduleLabel = $savedAppointment
    ? date('M j, Y · g:i A', strtotime($savedAppointment)) . ' (' . ($preparation['venue'] ?: 'Tanggapan ng Barangay Tumana') . ')'
    : 'Nakatakda / Hindi pa naigagawa ng iskedyul (' . (($preparation['venue'] ?? '') ?: 'Tanggapan ng Barangay Tumana') . ')';
$deliveryStatusText = match (true) {
    $paanyayaMinutes !== null => 'Naitala ang Katitikan at Pagdinig',
    $serviceOutcome === 'Delivered' => 'Naihatid at Natanggap (Delivered)',
    $serviceOutcome === 'Not Delivered' => 'Hindi Naihatid (Retry Required)',
    $latestService !== null => 'Na-dispatch sa Summons Server',
    !empty($preparation['ready_at']) => 'Nakumpirma at Handa sa Paghahatid',
    !empty($preparation['prepared_at']) => 'May Draft ng Paanyaya (I-preview at I-print)',
    default => 'Hindi pa naihanda',
};
$serverDisplayText = $latestService['server_name'] ?? 'Auto-assigned Summons Server';
$latestProofUrl = ($latestService && !empty($latestService['proof_mime']))
    ? $serviceEndpoint . '?view=proof&attempt_id=' . (int) $latestService['attempt_id']
    : null;
$latestPdfUrl = ($latestService && !empty($latestService['has_pdf']))
    ? $serviceEndpoint . '?view=invitation&attempt_id=' . (int) $latestService['attempt_id']
    : (!empty($preparation['prepared_at']) ? $prepEndpoint . '?complaint_id=' . $complaintId . '&invitation_number=' . $invitationNumber . '&version=' . $preparation['document_version'] : null);
$compAddrItems = [];
$respAddrItems = [];
foreach ($preparationParties as $ptAddr) {
    $addrTxt = trim((string) ($ptAddr['address'] ?? ''));
    if ($addrTxt === '') continue;
    if (strcasecmp((string) ($ptAddr['party_type'] ?? ''), 'Respondent') === 0) {
        $respAddrItems[] = $addrTxt;
    } else {
        $compAddrItems[] = $addrTxt;
    }
}
$compAddrDisplay = $compAddrItems ? implode(', ', array_unique($compAddrItems)) : 'Barangay Tumana, Marikina City';
$respAddrDisplay = $respAddrItems ? implode(', ', array_unique($respAddrItems)) : 'Barangay Tumana, Marikina City';
$modalSeedPayload = [
    'complaint_id' => $complaintId,
    'invitation_number' => $invitationNumber,
    'party_version' => PaanyayaPreparationService::partyVersion($preparationParties),
    'doc_title' => $invitationNumber === 2 ? 'I K A L A W A N G   P A A N Y A Y A' : 'P A A N Y A Y A',
    'complainant_display' => implode(' / ', $compNames) ?: $compDisplay,
    'complainant_address' => $compAddrDisplay,
    'respondent_display' => implode(' / ', $respNames) ?: $respDisplay,
    'respondent_address' => $respAddrDisplay,
    'salaysay' => $prepNarrative !== '' ? $prepNarrative : 'naisin nilang magkausap at magkaliwanagan.',
    'mode' => (!empty($preparation['ready_at']) && (!$latestService || $serviceOutcome !== 'Not Delivered')) ? 'reprint' : 'dispatch',
    'rescheduled' => !empty($preparation['rescheduled']),
];
$modalSeedJson = htmlspecialchars(json_encode($modalSeedPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
?>
<section class="cd-p-panel <?= $isPanelVisible ? '' : 'is-hidden' ?>" id="paanyaya-invitation-<?= $invitationNumber ?>" aria-labelledby="paanyaya-prep-title-<?= $invitationNumber ?>">
    <h2 id="paanyaya-prep-title-<?= $invitationNumber ?>" style="position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0,0,0,0);"><?= $invitationNumber === 2 ? 'Second Paanyaya' : 'Paanyaya Preparation' ?></h2>

    <?php if ($preparationFlash): ?>
        <p role="<?= $preparationFlash['type'] === 'error' ? 'alert' : 'status' ?>"
           style="margin: 0 0 14px; padding: 12px 14px; border-radius: 8px; font-weight: 600; font-size: 0.86rem; <?= $preparationFlash['type'] === 'error' ? 'background:#fef2f2; color:#991b1b; border:1px solid #fecaca;' : 'background:#f0fdf4; color:#166534; border:1px solid #bbf7d0;' ?>">
            <?= $escapePrep($preparationFlash['message']) ?>
        </p>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                window.agapNotify?.(<?= json_encode((string) $preparationFlash['message']) ?>, <?= json_encode($preparationFlash['type'] === 'error' ? 'error' : 'success') ?>, 'Paanyaya');
            });
        </script>
    <?php endif; ?>

    <?php if ($preparation): ?>
        <!-- Schedule Strip -->
        <div class="cd-p-schedule-strip">
            <div>
                <span style="display:block; font-size:0.7rem; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:0.05em;">
                    Iskedyul ng <?= $invitationNumber === 2 ? 'Ikalawang' : 'Unang' ?> Di-Pormal na Usapan
                </span>
                <strong style="font-size:0.9rem; color:#0f172a; display:block; margin-top:2px;">
                    <?= $escapePrep($scheduleLabel) ?>
                </strong>
            </div>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <span class="paanyaya-prep-status" style="font-size:0.74rem;">
                    <?= match (true) {
                        $paanyayaMinutes !== null => 'Dialogue recorded',
                        $serviceOutcome === 'Delivered' => 'Delivered',
                        $serviceOutcome === 'Not Delivered' => 'Delivery unsuccessful',
                        $latestService !== null => 'Dispatched to Summons Server',
                        !empty($preparation['ready_at']) => 'Confirmed for Service',
                        !empty($preparation['rescheduled']) => 'Revised invitation awaiting confirmation',
                        !empty($preparation['prepared_at']) => 'Draft saved · Preview & Confirm',
                        default => 'Awaiting Schedule & Print Confirmation',
                    } ?>
                </span>
                <?php if ($canManagePaanyayaPrep && $preparation['eligible'] && !empty($preparation['ready_at']) && $serviceOutcome !== 'Delivered'): ?>
                    <form class="paanyaya-service-form" method="post" action="<?= $prepEndpoint ?>" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= $prepCsrf ?>">
                        <input type="hidden" name="complaint_id" value="<?= $complaintId ?>">
                        <input type="hidden" name="invitation_number" value="<?= $invitationNumber ?>">
                        <input type="hidden" name="action" value="revise">
                        <input type="hidden" name="document_version" value="<?= $escapePrep($preparation['document_version']) ?>">
                        <button type="submit" class="cd-btn-soft" style="padding:5px 11px; font-size:0.78rem;">Baguhin ang Iskedyul</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- 2-Column Party Service Cards -->
        <div class="cd-p-party-grid">
            <div class="cd-p-party-card">
                <div class="cd-p-party-top">
                    <span class="cd-p-party-badge">May Sumbong (Complainant)</span>
                    <span style="font-size:0.72rem; font-weight:700; padding:2px 8px; border-radius:9999px; background:#ecfdf5; color:#047857;">May Paunawa</span>
                </div>
                <div>
                    <div style="font-size:0.98rem; font-weight:800; color:#0f172a;"><?= $escapePrep($compDisplay) ?></div>
                    <div style="font-size:0.76rem; color:#64748b; margin-top:2px;">Uri ng Dokumento: <strong>Paunawa ng Pagdinig (KP-8 / Notice)</strong></div>
                </div>
                <div class="cd-p-meta-box">
                    <div>Katayuan: <strong style="color:#0284c7;"><?= $escapePrep($deliveryStatusText) ?></strong></div>
                    <div>Tagapaghatid: <strong><?= $escapePrep($serverDisplayText) ?></strong></div>
                </div>
                <?php if ($latestPdfUrl): ?>
                    <a href="<?= $escapePrep($latestPdfUrl) ?>" target="_blank" rel="noopener" class="cd-btn-soft" style="justify-content:center; font-size:0.78rem;">
                        Tingnan ang Patunay / Paanyaya ng May Sumbong
                    </a>
                <?php endif; ?>
            </div>

            <div class="cd-p-party-card">
                <div class="cd-p-party-top">
                    <span class="cd-p-party-badge is-resp">Ipinagsusumbong (Respondent)</span>
                    <span style="font-size:0.72rem; font-weight:700; padding:2px 8px; border-radius:9999px; background:#eff6ff; color:#0369a1;">
                        <?= $serviceOutcome === 'Delivered' ? 'Naihatid' : 'May Taning' ?>
                    </span>
                </div>
                <div>
                    <div style="font-size:0.98rem; font-weight:800; color:#0f172a;"><?= $escapePrep($respDisplay) ?></div>
                    <div style="font-size:0.76rem; color:#64748b; margin-top:2px;">Uri ng Dokumento: <strong>Paanyaya sa Ipinagsusumbong (<?= $invitationNumber === 2 ? '2nd' : '1st' ?> Invitation)</strong></div>
                </div>
                <div class="cd-p-meta-box">
                    <div>Katayuan: <strong style="color:#0284c7;"><?= $escapePrep($deliveryStatusText) ?></strong></div>
                    <div>Tagapaghatid: <strong><?= $escapePrep($serverDisplayText) ?></strong></div>
                </div>
                <?php if ($latestProofUrl): ?>
                    <a href="<?= $escapePrep($latestProofUrl) ?>" target="_blank" rel="noopener" class="cd-btn-soft" style="justify-content:center; font-size:0.78rem;">
                        Tingnan ang Patunay ng Ipinagsusumbong
                    </a>
                <?php else: ?>
                    <a href="../gps/proof-service.php?complaint_id=<?= $complaintId ?>" class="cd-btn-soft" style="justify-content:center; font-size:0.78rem;">
                        Tingnan ang Patunay ng Ipinagsusumbong
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($preparation['review_notes'])): ?>
            <p style="margin: 0 0 12px; font-size: 0.84rem; color: #334155;"><strong>LISO screening notes:</strong> <?= $escapePrep($preparation['review_notes']) ?></p>
        <?php endif; ?>

        <!-- Initial Salaysay & Verified Parties Accordion (Preserves Intake Inheritance + Party Verification) -->
        <details class="paanyaya-prep-preview" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; margin: 0 0 14px;">
            <summary style="font-size:0.84rem;">Initial Salaysay &amp; <?= $preparation['ready_at'] ? 'Verified Parties' : 'Verify Parties' ?></summary>
            <div style="margin-top:10px;">
                <?php if ($prepNarrative !== '' || $prepCategoryText !== ''): ?>
                    <div style="margin-bottom: 12px; padding: 12px 14px; border-radius: 8px; background: #ffffff; border: 1px solid #e2e8f0;">
                        <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 6px;">
                            <strong style="font-size: 0.86rem; color: #0f172a;">Initial Salaysay (Statement of Complaint)</strong>
                            <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                <span style="display:inline-flex; align-items:center; padding: 2px 9px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; <?= $prepCaseType === 'Criminal' ? 'background:#fef2f2; color:#b91c1c; border:1px solid #fecaca;' : 'background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;' ?>"><?= $escapePrep($prepCaseType) ?></span>
                                <?php if ($prepCategoryText !== ''): ?>
                                    <span style="display:inline-flex; align-items:center; padding: 2px 9px; border-radius: 999px; font-size: 0.72rem; font-weight: 600; background:#f8fafc; color:#334155; border:1px solid #cbd5e1;"><?= $escapePrep($prepCategoryText) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="font-size: 0.86rem; color: #1e293b; line-height: 1.55; white-space: pre-wrap;"><?= $escapePrep($prepNarrative !== '' ? $prepNarrative : 'No Salaysay narrative recorded.') ?></div>
                    </div>
                <?php endif; ?>
                <?php if (!$preparationParties): ?>
                    <p style="font-size:0.84rem; color:#64748b; margin:0;">Add the complainant and respondent to this intake before preparing the invitation.</p>
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
            </div>
        </details>

        <!-- Action / Dialogue Box -->
        <div class="cd-p-action-box">
            <h3 style="margin:0 0 6px; font-size:0.94rem; font-weight:800; color:#0f172a;">
                Tala at Kinahinatnan ng <?= $invitationNumber === 2 ? 'Ikalawang' : 'Unang' ?> Paanyaya
            </h3>
            <p style="margin:0 0 12px; font-size:0.8rem; color:#64748b;">
                Itala ang naging takbo ng pag-uusap ng mga partido. Kung hindi sumipot o kailangan ng follow-up, magtakda ng ikalawang Paanyaya bago pormal na mag-docket sa pamamagitan.
            </p>

            <?php if ($canManagePaanyayaPrep && $preparation['eligible'] && !$preparation['ready_at']): ?>
                <form method="post" action="<?= $prepEndpoint ?>" data-pny-seed="<?= $modalSeedJson ?>" onsubmit="return window.openPaanyayaPreviewModalFromForm ? window.openPaanyayaPreviewModalFromForm(event, this) : false;">
                    <input type="hidden" name="csrf_token" value="<?= $prepCsrf ?>">
                    <input type="hidden" name="complaint_id" value="<?= $complaintId ?>">
                    <input type="hidden" name="invitation_number" value="<?= $invitationNumber ?>">
                    <input type="hidden" name="action" value="confirm_and_dispatch">
                    <input type="hidden" name="party_version" value="<?= PaanyayaPreparationService::partyVersion($preparationParties) ?>">
                    <?php
                    $todayDow = (int) date('N');
                    $minApptDate = $todayDow === 6 ? date('Y-m-d', strtotime('+2 days')) : ($todayDow === 7 ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d'));
                    ?>
                    <div class="paanyaya-prep-fields">
                        <label>Appointment date (Mon–Fri)
                            <input type="date" name="appointment_date" min="<?= $minApptDate ?>" <?= $preparation['rescheduled'] ? 'readonly' : '' ?>
                                   value="<?= $escapePrep($formValues['appointment_date'] ?? substr($savedAppointment, 0, 10)) ?>"
                                   oninput="const d=this.value?new Date(this.value+'T00:00:00').getDay():1;if(d===0||d===6){this.setCustomValidity('Paanyaya appointments can only be scheduled on weekdays (Monday to Friday).');this.reportValidity();window.agapNotify?.('Paanyaya appointments can only be scheduled on weekdays (Monday to Friday).','warning','Paanyaya Schedule');}else{this.setCustomValidity('');}"
                                   required>
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
                    <button type="submit" class="cd-btn-green">Preview, Edit &amp; Print Invitation</button>
                </form>
            <?php endif; ?>

            <?php if ($preparation['prepared_at']): ?>
                <?php $pdfUrl = $prepEndpoint . '?complaint_id=' . $complaintId . '&invitation_number=' . $invitationNumber . '&version=' . $preparation['document_version']; ?>
                <details class="paanyaya-prep-preview" ontoggle="if(this.open){const f=this.querySelector('iframe[data-src]');if(f&&!f.getAttribute('src')){f.src=f.dataset.src;}}" <?= !$preparation['ready_at'] ? 'open' : '' ?>>
                    <summary>Preview and print invitation (<?= $escapePrep(date('M j, Y, g:i A', strtotime($savedAppointment))) ?>)</summary>
                    <p style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <span><a href="<?= $escapePrep($pdfUrl) ?>" target="_blank" rel="noopener">Open saved PDF</a></span>
                        <?php if ($canManagePaanyayaPrep && $preparation['eligible']): ?>
                            <button type="button" class="cd-btn-soft" data-pny-seed="<?= $modalSeedJson ?>"
                                    data-pny-date="<?= $escapePrep(substr($savedAppointment, 0, 10)) ?>"
                                    data-pny-time="<?= $escapePrep(substr($savedAppointment, 11, 5)) ?>"
                                    data-pny-venue="<?= $escapePrep($preparation['venue'] ?? 'Tanggapan ng Barangay Tumana') ?>"
                                    onclick="window.openPaanyayaPreviewModalFromButton && window.openPaanyayaPreviewModalFromButton(this)"
                                    style="padding:4px 10px; font-size:0.78rem;">
                                <?= $preparation['ready_at'] ? 'Open Print / Preview' : 'Edit &amp; Print Letter' ?>
                            </button>
                        <?php endif; ?>
                    </p>
                    <iframe title="Prepared Paanyaya invitation PDF" <?= !$preparation['ready_at'] ? ('src="' . $escapePrep($pdfUrl) . '"') : ('data-src="' . $escapePrep($pdfUrl) . '"') ?> loading="lazy"></iframe>
                </details>
            <?php endif; ?>

            <?php if ($paanyayaServiceUnavailable): ?>
                <p role="status">Paanyaya service is currently unavailable.</p>
            <?php elseif ($preparation['prepared_at']): ?>
                <?php if ($canManagePaanyayaPrep && $latestService && $serviceOutcome === null && !empty($paanyayaServers)): ?>
                    <form method="post" action="<?= $serviceEndpoint ?>" style="margin:10px 0 12px; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; background:#f8fafc; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                        <input type="hidden" name="csrf_token" value="<?= $prepCsrf ?>">
                        <input type="hidden" name="complaint_id" value="<?= $complaintId ?>">
                        <input type="hidden" name="invitation_number" value="<?= $invitationNumber ?>">
                        <input type="hidden" name="attempt_id" value="<?= (int) $latestService['attempt_id'] ?>">
                        <input type="hidden" name="action" value="reassign">
                        <div style="font-size:0.8rem; color:#334155;">
                            <strong>Assigned Summons Server:</strong> <?= $escapePrep($latestService['server_name'] ?? $serverDisplayText) ?>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <select name="assigned_to" aria-label="Reassign Summons Server" style="padding:5px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.8rem; background:#fff;">
                                <?php foreach ($paanyayaServers as $srv): ?>
                                    <option value="<?= (int) $srv['user_id'] ?>" <?= (int) ($latestService['assigned_to'] ?? 0) === (int) $srv['user_id'] ? 'selected' : '' ?>>
                                        <?= $escapePrep($srv['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="cd-btn-soft" style="padding:5px 11px; font-size:0.78rem;">Reassign Server</button>
                        </div>
                    </form>
                <?php endif; ?>
                <?php if ($paanyayaServiceHistory): ?>
                    <details style="margin: 10px 0;">
                        <summary style="cursor:pointer; font-size:0.82rem; font-weight:700; color:#334155;">Service history (<?= count($paanyayaServiceHistory) ?>)</summary>
                        <ul class="paanyaya-prep-list">
                            <?php foreach ($paanyayaServiceHistory as $attempt): ?>
                                <li>
                                    <div>
                                        <strong><?= $escapePrep($attempt['outcome'] ?? 'Assigned for delivery') ?></strong>
                                        <?php if (!$attempt['is_current']): ?> · Superseded invitation<?php endif; ?>
                                        <div><?= $escapePrep(date('M j, Y, g:i a', strtotime($attempt['appointment_at']))) ?><?= $attempt['venue'] ? ' · ' . $escapePrep($attempt['venue']) : '' ?></div>
                                        <div><?= $escapePrep($attempt['server_name']) ?> · Dispatched <?= $escapePrep($attempt['released_at']) ?> by <?= $escapePrep($attempt['released_by_name'] ?: 'Staff account') ?></div>
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
                    </details>
                <?php endif; ?>

                <?php if ($serviceOutcome === 'Delivered'): ?>
                    <?php include __DIR__ . '/paanyaya-dialogue.php'; ?>
                <?php elseif ($canManagePaanyayaPrep && $preparation['eligible'] && $preparation['ready_at'] && $serviceOutcome === 'Not Delivered'): ?>
                    <div id="paanyaya-service-<?= $invitationNumber ?>" style="margin-top:10px;">
                        <h4 style="margin:0 0 8px; font-size:0.88rem; color:#0f172a;">Coordinate another delivery attempt</h4>
                        <?php if (!$paanyayaServers): ?>
                            <p style="font-size:0.84rem; color:#64748b;">No active summons servers are available.</p>
                        <?php elseif (strtotime($savedAppointment) > time()): ?>
                            <form method="post" action="<?= $serviceEndpoint ?>" onsubmit="if(this.dataset.submitting==='1'){event.preventDefault();return false;}this.dataset.submitting='1';const b=this.querySelector('button[type=submit]');if(b){b.disabled=true;b.textContent='Dispatching retry...';}">
                                <input type="hidden" name="csrf_token" value="<?= $prepCsrf ?>">
                                <input type="hidden" name="complaint_id" value="<?= $complaintId ?>">
                                <input type="hidden" name="invitation_number" value="<?= $invitationNumber ?>">
                                <input type="hidden" name="action" value="release">
                                <input type="hidden" name="document_version" value="<?= $escapePrep($preparation['document_version']) ?>">
                                <button type="submit" class="cd-btn-green">Retry Delivery to Summons Server</button>
                            </form>
                        <?php else: ?>
                            <p style="font-size:0.84rem; color:#b45309;"><?= $preparation['rescheduled'] ? 'The appointment has passed. Reschedule it from the docket before retrying delivery.' : 'The appointment has passed. Revise the schedule and re-confirm before retrying delivery.' ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
