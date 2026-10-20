<?php
// 1. Patch ComplaintController.php
$controllerPath = __DIR__ . '/../backend/controllers/ComplaintController.php';
$ctrl = file_get_contents($controllerPath);

$targetCtrl = "                if (\$paaResult['success']) {
                    \$result['message'] .= ' (1st Paanyaya informal dialogue scheduled: Complaint placed in Pre-Docketing.)';
                    \$result['paanyaya_session_id'] = \$paaResult['session_id'] ?? null;
                    \$result['pdf_path'] = \$paaResult['pdf_path'] ?? null;
                }
                \$result['redirect_url'] = '../../frontend/pages/complaints/complaint-details.php?id=' . \$complaintId . '&tab=paanyaya';";

$replacementCtrl = "                if (\$paaResult['success']) {
                    \$result['message'] .= ' (1st Paanyaya informal dialogue scheduled: Complaint placed in Pre-Docketing.)';
                    \$result['paanyaya_session_id'] = \$paaResult['session_id'] ?? null;
                    \$result['pdf_path'] = \$paaResult['pdf_path'] ?? null;

                    // Automatically initialize Paanyaya 1 stage in paanyaya_stages table
                    try {
                        \$paanyayaModel->issueStage(\$complaintId, 'Paanyaya 1', [
                            'invitation_date' => \$paaDate,
                            'invitation_time' => \$paaTime,
                            'venue' => \$paaVenue,
                            'salaysay' => \$paaSalaysay,
                            'has_e_signature' => \$paaSig,
                            'assigned_officer_id' => (int) (\$_SESSION['user_id'] ?? 1)
                        ], (int) (\$_SESSION['user_id'] ?? 1));
                    } catch (Throwable \$stageErr) {
                        error_log('Paanyaya 1 stage auto-issue notice: ' . \$stageErr->getMessage());
                    }
                }
                \$result['redirect_url'] = '../../frontend/pages/complaints/complaint-details.php?id=' . \$complaintId . '&tab=paanyaya&preview=1';";

$ctrl = str_replace($targetCtrl, $replacementCtrl, $ctrl);
file_put_contents($controllerPath, $ctrl);
echo "Patched ComplaintController.php\n";

// 2. Patch create.php
$createApiPath = __DIR__ . '/../backend/api/complaints/create.php';
$createCode = file_get_contents($createApiPath);
$createCode = str_replace(
    "header('Location: ../../../frontend/pages/complaints/complaint-details.php?id=' . (int)\$result['complaint_id'] . '&tab=paanyaya');",
    "header('Location: ../../../frontend/pages/complaints/complaint-details.php?id=' . (int)\$result['complaint_id'] . '&tab=paanyaya&preview=1');",
    $createCode
);
file_put_contents($createApiPath, $createCode);
echo "Patched create.php\n";
