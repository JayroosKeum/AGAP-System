<?php
$file = __DIR__ . '/../frontend/pages/gps/proof-service.php';
$content = file_get_contents($file);

$target = '<input type="hidden" name="action" value="officer_return">';
$replacement = '<input type="hidden" id="returnIsPaanyaya" name="is_paanyaya" value="0">' . "\n" .
               '        <input type="hidden" id="returnStageType" name="stage_type" value="">' . "\n" .
               '        <input type="hidden" id="returnComplaintId" name="complaint_id" value="">' . "\n" .
               '        <input type="hidden" name="action" value="officer_return">';

if (strpos($content, 'returnIsPaanyaya') === false) {
    $content = str_replace($target, $replacement, $content);
    file_put_contents($file, $content);
    echo "proof-service.php patched.\n";
} else {
    echo "Already present.\n";
}
