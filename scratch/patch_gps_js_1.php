<?php

$file = __DIR__ . '/../frontend/assets/js/gps.js';
$content = file_get_contents($file);

// 4. Update row rendering kpNumber in renderSummonCasesTable
$targetKp = <<<'JS'
const kpNumber = item.case_number || (item.case_id ? `Case #${item.case_id}` : 'N/A');
JS;

$replaceKp = <<<'JS'
const kpNumberRaw = item.case_number || item.complaint_number || (item.case_id ? `Case #${item.case_id}` : 'N/A');
        const kpNumber = item.is_paanyaya
            ? `<div style="display:inline-flex; align-items:center; gap:6px; flex-wrap:wrap;"><span>${escapeGpsHtml(kpNumberRaw)}</span><span style="font-size:0.72rem; padding:2px 7px; background:#ecfdf5; color:#166534; border:1px solid #bbf7d0; border-radius:4px; font-weight:700;">${escapeGpsHtml(item.paanyaya_stage_type || item.hearing_type || 'Paanyaya')}</span></div>`
            : escapeGpsHtml(kpNumberRaw);
JS;

$searchStr = '${escapeGpsHtml(kpNumber)}';
$replaceStr = '${kpNumber}';

if (strpos($content, 'const kpNumberRaw = item.case_number') === false) {
    $content = str_replace($targetKp, $replaceKp, $content);
    $content = str_replace($searchStr, $replaceStr, $content);
    echo "Updated kpNumber rendering in gps.js\n";
}

file_put_contents($file, $content);
echo "gps.js part 1 saved.\n";
