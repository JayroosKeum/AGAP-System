<?php
/**
 * Shared Stepped Font Size Slider with Discrete Point Stops
 * Steps: 0 = 14px (A-), 1 = 16px (A Normal), 2 = 18px (A+ Large), 3 = 20px (A++ XL)
 */
$sliderCompact = !empty($agapFontSliderCompact);
?>
<div class="agap-font-slider-control<?php echo $sliderCompact ? ' is-compact' : ''; ?>" data-font-slider-wrap>
    <div class="agap-font-slider-head">
        <span class="agap-font-slider-label">Font Size</span>
        <span class="agap-font-size-badge" data-font-size-badge>16px · Normal</span>
    </div>
    <div class="agap-stepped-slider">
        <div class="agap-slider-rail-wrap">
            <input type="range"
                   class="agap-font-range"
                   min="0"
                   max="3"
                   step="1"
                   value="1"
                   aria-label="Adjust font size across 4 stepped points">
        </div>
        <div class="agap-slider-points" role="group" aria-label="Font size point presets">
            <button type="button" class="agap-slider-point" data-font-step="0" title="Compact (14px)">
                <span class="agap-point-dot"></span>
                <span class="agap-point-text">A-</span>
            </button>
            <button type="button" class="agap-slider-point is-active" data-font-step="1" title="Normal (16px)">
                <span class="agap-point-dot"></span>
                <span class="agap-point-text">A</span>
            </button>
            <button type="button" class="agap-slider-point" data-font-step="2" title="Large (18px)">
                <span class="agap-point-dot"></span>
                <span class="agap-point-text">A+</span>
            </button>
            <button type="button" class="agap-slider-point" data-font-step="3" title="Extra Large (20px)">
                <span class="agap-point-dot"></span>
                <span class="agap-point-text">A++</span>
            </button>
        </div>
    </div>
</div>
