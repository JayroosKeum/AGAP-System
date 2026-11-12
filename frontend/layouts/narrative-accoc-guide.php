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
            <strong>5 W&#39;s + How Affidavit w/ ACCOC</strong>
            <small>Detalyadong isalaysay ang sumbong o reklamo. Nagiging berde kapag tama at kumpleto.</small>
        </div>
        <span class="rd-story-guide-score" data-story-score>0 / 11 met</span>
    </div>

    <div class="rd-story-guide" role="list" aria-label="5 W's and How checklist">
        <span class="rd-story-guide-label">5 W&#39;s + How:</span>
        <span class="rd-story-chip" role="listitem" data-story-chip="who" title="Who (Sino): Tukuyin kung sino ang sangkot (pangalan, siya, kapitbahay, atbp.).">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span>Who (Sino)</span>
        </span>
        <span class="rd-story-chip" role="listitem" data-story-chip="what" title="What (Ano): Ilahad kung ano ang eksaktong ginawa o nangyari.">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span>What (Ano)</span>
        </span>
        <span class="rd-story-chip" role="listitem" data-story-chip="where" title="Where (Saan): Banggitin kung saan naganap (hal. sa bahay, sa Purok, sa tindahan).">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span>Where (Saan)</span>
        </span>
        <span class="rd-story-chip" role="listitem" data-story-chip="when" title="When (Kailan): Isama ang petsa, oras, o panahon (hal. kahapon, alas-3 ng hapon, ilang buwan).">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span>When (Kailan)</span>
        </span>
        <span class="rd-story-chip" role="listitem" data-story-chip="why" title="Why (Bakit): Ipaliwanag ang dahilan o pinagmulan ng alitan (hal. dahil, kasi).">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span>Why (Bakit)</span>
        </span>
        <span class="rd-story-chip" role="listitem" data-story-chip="how" title="How (Paano): Isalaysay kung paano nagsimula o isinagawa ang insidente.">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span>How (Paano)</span>
        </span>
    </div>

    <div class="rd-story-guide" role="list" aria-label="ACCOC affidavit standards checklist">
        <span class="rd-story-guide-label">ACCOC:</span>
        <span class="rd-story-chip" role="listitem" data-story-chip="accuracy" title="Accuracy (Tama): Gumamit ng totoong mga salita at tiyak na detalye; iwasan ang random o dummy text.">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span>Accuracy (Tama)</span>
        </span>
        <span class="rd-story-chip" role="listitem" data-story-chip="clarity" title="Clarity (Malinaw): Gumamit ng maayos na pangungusap at bantas; iwasan ang ALL CAPS.">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span>Clarity (Malinaw)</span>
        </span>
        <span class="rd-story-chip" role="listitem" data-story-chip="conciseness" title="Conciseness (Hindi Paligoy-ligoy): Direkta sa punto at hindi paulit-ulit.">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span>Conciseness (Hindi Paligoy-ligoy)</span>
        </span>
        <span class="rd-story-chip" role="listitem" data-story-chip="objectivity" title="Objectivity (Walang Panghuhusga): Ilahad ang totoong pangyayari nang walang mura o pang-iinsulto.">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span>Objectivity (Walang Panghuhusga)</span>
        </span>
        <span class="rd-story-chip" role="listitem" data-story-chip="completeness" title="Completeness (Kumpleto/Detalyado): Saklaw lahat ng 5 W's + How nang may sapat na detalye.">
            <span class="rd-story-chip-icon" aria-hidden="true"></span>
            <span>Completeness (Kumpleto/Detalyado)</span>
        </span>
    </div>

    <p class="rd-story-guide-feedback" data-story-feedback>
        Maglagay ng malinaw na salaysay gamit ang 5 W&#39;s + How at ACCOC standards.
    </p>
</div>
