<?php
$narrativeGuideId = isset($narrativeGuideId) && is_string($narrativeGuideId) && $narrativeGuideId !== ''
    ? $narrativeGuideId
    : 'agapNarrativeGuide';
$narrativeTargetSelector = isset($narrativeTargetSelector) && is_string($narrativeTargetSelector) && $narrativeTargetSelector !== ''
    ? $narrativeTargetSelector
    : '#narrative';
?>
<div class="rd-story-guide-card" id="<?php echo htmlspecialchars($narrativeGuideId, ENT_QUOTES, 'UTF-8'); ?>" data-narrative-target="<?php echo htmlspecialchars($narrativeTargetSelector, ENT_QUOTES, 'UTF-8'); ?>" aria-label="5 W's + How and ACCOC complaint narrative checklist">
    <div class="rd-story-guide-head">
        <div class="rd-story-guide-title">
            <strong data-en="5 W's + How Affidavit w/ ACCOC">Gabay sa Salaysay (5 W&#39;s + How at ACCOC)</strong>
            <small data-en="Describe the complaint in detail. Turns green when accurate and complete.">Detalyadong isalaysay ang sumbong o reklamo. Nagiging berde kapag tama at kumpleto.</small>
        </div>
        <span class="rd-story-guide-score" data-story-score>0 / 11 natugunan</span>
    </div>

    <div class="rd-story-guide" role="list" aria-label="5 W's and How checklist">
        <span class="rd-story-guide-label">5 W&#39;s + How:</span>
        <span class="rd-story-chip" role="listitem" data-story-chip="who" title="Who (Sino): Tukuyin kung sino ang sangkot (pangalan, siya, kapitbahay, atbp.)." data-en-title="Who: Identify who was involved (name, neighbor, etc.).">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span data-en="Who">Who (Sino)</span>
        </span>
        <span class="rd-story-chip" role="listitem" data-story-chip="what" title="What (Ano): Ilahad kung ano ang eksaktong ginawa o nangyari." data-en-title="What: State exactly what happened or was done.">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span data-en="What">What (Ano)</span>
        </span>
        <span class="rd-story-chip" role="listitem" data-story-chip="where" title="Where (Saan): Banggitin kung saan naganap (hal. sa bahay, sa Purok, sa tindahan)." data-en-title="Where: Mention where it happened (e.g., at home, Purok, store).">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span data-en="Where">Where (Saan)</span>
        </span>
        <span class="rd-story-chip" role="listitem" data-story-chip="when" title="When (Kailan): Isama ang petsa, oras, o panahon (hal. kahapon, alas-3 ng hapon, ilang buwan)." data-en-title="When: Include the date, time, or timeframe.">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span data-en="When">When (Kailan)</span>
        </span>
        <span class="rd-story-chip" role="listitem" data-story-chip="why" title="Why (Bakit): Ipaliwanag ang dahilan o pinagmulan ng alitan (hal. dahil, kasi)." data-en-title="Why: Explain the cause or origin of the dispute.">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span data-en="Why">Why (Bakit)</span>
        </span>
        <span class="rd-story-chip" role="listitem" data-story-chip="how" title="How (Paano): Isalaysay kung paano nagsimula o isinagawa ang insidente." data-en-title="How: Describe how the incident started or took place.">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span data-en="How">How (Paano)</span>
        </span>
    </div>

    <div class="rd-story-guide" role="list" aria-label="ACCOC affidavit standards checklist">
        <span class="rd-story-guide-label">ACCOC:</span>
        <span class="rd-story-chip" role="listitem" data-story-chip="accuracy" title="Accuracy (Tama): Gumamit ng totoong mga salita at tiyak na detalye; iwasan ang random o dummy text." data-en-title="Accuracy: Use real words and specific details; avoid random or dummy text.">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span data-en="Accuracy">Accuracy (Tama)</span>
        </span>
        <span class="rd-story-chip" role="listitem" data-story-chip="clarity" title="Clarity (Malinaw): Gumamit ng maayos na pangungusap at bantas; iwasan ang ALL CAPS." data-en-title="Clarity: Use clear sentences and punctuation; avoid ALL CAPS.">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span data-en="Clarity">Clarity (Malinaw)</span>
        </span>
        <span class="rd-story-chip" role="listitem" data-story-chip="conciseness" title="Conciseness (Hindi Paligoy-ligoy): Direkta sa punto at hindi paulit-ulit." data-en-title="Conciseness: Keep it direct and avoid unnecessary repetition.">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span data-en="Conciseness">Conciseness (Hindi Paligoy-ligoy)</span>
        </span>
        <span class="rd-story-chip" role="listitem" data-story-chip="objectivity" title="Objectivity (Walang Panghuhusga): Ilahad ang totoong pangyayari nang walang mura o pang-iinsulto." data-en-title="Objectivity: State facts fairly without profanity or insults.">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span data-en="Objectivity">Objectivity (Walang Panghuhusga)</span>
        </span>
        <span class="rd-story-chip" role="listitem" data-story-chip="completeness" title="Completeness (Kumpleto/Detalyado): Saklaw lahat ng 5 W's + How nang may sapat na detalye." data-en-title="Completeness: Cover all 5 W's + How with enough detail.">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span data-en="Completeness">Completeness (Kumpleto/Detalyado)</span>
        </span>
    </div>

    <p class="rd-story-guide-feedback" data-story-feedback data-en="Provide a clear narrative following the 5 W&#39;s + How and ACCOC standards.">
        Maglagay ng malinaw na salaysay gamit ang 5 W&#39;s + How at ACCOC standards.
    </p>
</div>
