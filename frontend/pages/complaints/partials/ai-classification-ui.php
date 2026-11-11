<?php

if (!function_exists('renderAiClassificationSectionBanner')) {
    function renderAiClassificationSectionBanner(array $old = []): void
    {
        $used = !empty($old['ai_classification_used']);
        $verified = !empty($old['ai_classification_verified']);
        $suggestedCatId = (string) ($old['ai_suggested_category_id'] ?? '');
        $suggestedTitle = (string) ($old['ai_suggested_title'] ?? '');
        $stateClass = $verified ? 'is-verified' : 'is-pending';
        ?>
        <input type="hidden" name="ai_classification_used" id="aiClassificationUsed" value="<?php echo $used ? '1' : '0'; ?>">
        <input type="hidden" name="ai_suggested_category_id" id="aiSuggestedCategoryId" value="<?php echo htmlspecialchars($suggestedCatId); ?>">
        <input type="hidden" name="ai_suggested_title" id="aiSuggestedTitle" value="<?php echo htmlspecialchars($suggestedTitle); ?>">

        <div id="aiClassificationVerificationBanner"
             class="ai-classification-verified-banner <?php echo $stateClass; ?>"
             <?php echo $used ? '' : 'hidden'; ?>>
            <div class="ai-verified-banner-header">
                <span class="ai-verified-banner-badge" id="aiVerifiedBannerBadge">
                    <?php echo $verified ? 'Human Verification Confirmed' : 'Human Verification Required'; ?>
                </span>
                <span class="ai-verified-banner-summary" id="aiVerifiedBannerSummary">
                    <?php echo $verified
                        ? 'Category, case type, and complaint title were reviewed and confirmed.'
                        : 'Classification details changed or require confirmation before saving.'; ?>
                </span>
            </div>
            <label class="ai-verified-banner-check">
                <input type="checkbox"
                       name="ai_classification_verified"
                       id="chkHumanVerifiedClassification"
                       value="1"
                       <?php echo $verified ? 'checked' : ''; ?>>
                <span>Human Verification Required: I reviewed the complainant's narrative and confirm that the selected complaint category, case type, and complaint title are accurate.</span>
            </label>
        </div>
        <?php
    }
}

if (!function_exists('renderAiClassificationPreviewPanel')) {
    function renderAiClassificationPreviewPanel(): void
    {
        ?>
        <div class="ai-classification-preview" data-classification-preview hidden>
            <div class="ai-classification-preview-header">
                <div>
                    <strong>AI Category &amp; Title Suggestion — Human Verification Required</strong>
                    <div class="ai-classification-meta" data-classification-meta></div>
                </div>
                <span class="ai-classification-source-pill" data-classification-source-badge></span>
            </div>

            <div class="ai-classification-advisory" data-classification-advisory hidden></div>

            <div class="ai-classification-section">
                <span class="ai-classification-label">1. Choose &amp; Verify Complaint Category</span>
                <div class="ai-classification-options" data-classification-options></div>
            </div>

            <div class="ai-classification-section" data-classification-other-wrap hidden>
                <label class="ai-classification-label" for="aiClassificationOtherSpec">Specify Custom Category ("Others")</label>
                <input type="text"
                       id="aiClassificationOtherSpec"
                       class="form-control"
                       maxlength="255"
                       data-classification-other-input
                       placeholder="Specify the particular dispute category...">
            </div>

            <div class="ai-classification-section" data-classification-secondary-wrap hidden>
                <label class="ai-classification-secondary-label">
                    <input type="checkbox" data-classification-secondary-check>
                    <span data-classification-secondary-text></span>
                </label>
            </div>

            <div class="ai-classification-section">
                <label class="ai-classification-label" for="aiClassificationTitleInput">2. Review or Edit Suggested Complaint Title</label>
                <input type="text"
                       id="aiClassificationTitleInput"
                       class="form-control"
                       maxlength="255"
                       data-classification-title-input
                       placeholder="Review or refine the suggested complaint title...">
            </div>

            <label class="ai-classification-confirm-label">
                <input type="checkbox" data-classification-confirm-check>
                <span>I verified from the complainant's narrative that the chosen category, case type, and complaint title are accurate.</span>
            </label>

            <div class="ai-narrative-preview-actions">
                <button type="button" class="btn-create btn-sm" data-apply-classification disabled>Verify &amp; Apply to Form</button>
                <button type="button" class="btn-secondary btn-sm" data-discard-classification>Discard Suggestion</button>
            </div>
        </div>
        <?php
    }
}
