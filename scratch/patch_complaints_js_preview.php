<?php
$path = __DIR__ . '/../frontend/assets/js/complaints.js';
$js = file_get_contents($path);

// 1. In loadComplaintDetails, add auto-preview logic after loading data
$targetLoadEnd = "window.complaintData = data;";
$replaceLoadEnd = "window.complaintData = data;

        // Auto-open Print Preview modal if redirected from complaint creation
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('preview') === '1' || urlParams.get('print_preview') === '1') {
            setTimeout(() => {
                if (typeof openPaanyayaPreviewModal === 'function') {
                    openPaanyayaPreviewModal();
                }
            }, 350);
            try {
                const cleanUrl = new URL(window.location);
                cleanUrl.searchParams.delete('preview');
                cleanUrl.searchParams.delete('print_preview');
                window.history.replaceState({}, '', cleanUrl);
            } catch (_) {}
        }";

if (strpos($js, "Auto-open Print Preview modal if redirected") === false) {
    $js = str_replace($targetLoadEnd, $replaceLoadEnd, $js);
    echo "Added auto-preview check in loadComplaintDetails.\n";
}

// 2. Add proceedToOfficersReturnFromPreview
$proceedFunc = <<<'JS'
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

if (strpos($js, "proceedToOfficersReturnFromPreview") === false) {
    $js .= "\n\n" . $proceedFunc . "\n";
    echo "Added proceedToOfficersReturnFromPreview handler.\n";
}

// 3. Update button label in Paanyaya card actions to match Image 2: "Log Officer's Return"
$js = str_replace(
    "btnRecord.textContent = '📝 Record Officer\'s Return';",
    "btnRecord.innerHTML = '<svg width=\"14\" height=\"14\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2.2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7\"></path><path d=\"M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z\"></path></svg> Log Officer\'s Return';",
    $js
);

file_put_contents($path, $js);
echo "complaints.js updated successfully!\n";
