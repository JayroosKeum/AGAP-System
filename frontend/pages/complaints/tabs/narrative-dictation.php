<?php $isResidentDictation = (($_SESSION['role_name'] ?? '') === 'Resident'); ?>
<details class="narrative-dictation" data-narrative-dictation hidden>
    <summary<?= $isResidentDictation ? ' data-en="Dictate with Gemini"' : '' ?>><?= $isResidentDictation ? 'Idikta gamit ang Gemini' : 'Dictate with Gemini' ?></summary>
    <div class="narrative-dictation-body">
        <p class="field-hint"<?= $isResidentDictation ? ' data-en="Record up to 90 seconds in Filipino, English, or both. Get permission from anyone you record."' : '' ?>><?= $isResidentDictation ? 'Magrekord ng hanggang 90 segundo sa Filipino, English, o pareho. Humingi ng pahintulot sa sinumang ire-rekord.' : 'Record up to 90 seconds in Filipino, English, or both. Get permission from anyone you record.' ?></p>
        <p data-dictation-status role="status"></p>
        <div class="ai-narrative-tools">
            <button type="button" class="btn-outline-sm" data-dictation-start<?= $isResidentDictation ? ' data-en="Start recording"' : '' ?>><?= $isResidentDictation ? 'Simulan ang pagrekord' : 'Start recording' ?></button>
            <button type="button" class="btn-outline-sm" data-dictation-stop hidden<?= $isResidentDictation ? ' data-en="Stop recording"' : '' ?>><?= $isResidentDictation ? 'Ihinto ang pagrekord' : 'Stop recording' ?></button>
            <button type="button" class="btn-outline-sm" data-dictation-discard hidden<?= $isResidentDictation ? ' data-en="Discard"' : '' ?>><?= $isResidentDictation ? 'Burahin' : 'Discard' ?></button>
            <span data-dictation-time aria-hidden="true" hidden></span>
        </div>
        <div data-dictation-review hidden>
            <audio controls preload="metadata" aria-label="<?= $isResidentDictation ? 'Suriin ang nairekord na audio' : 'Review recorded audio' ?>"<?= $isResidentDictation ? ' data-en-aria-label="Review recorded audio"' : '' ?> data-dictation-audio></audio>
            <div data-dictation-send-controls>
                <label class="ai-consent-checkbox-label">
                    <input type="checkbox" data-dictation-consent>
                    <span<?= $isResidentDictation ? ' data-en="I agree to send this recording to Google Gemini for transcription."' : '' ?>><?= $isResidentDictation ? 'Sumasang-ayon akong ipadala ang rekord na ito sa Google Gemini para sa transkripsyon.' : 'I agree to send this recording to Google Gemini for transcription.' ?></span>
                </label>
                <div class="ai-narrative-preview-actions">
                    <button type="button" class="btn-create btn-sm" data-dictation-send<?= $isResidentDictation ? ' data-en="Transcribe recording"' : '' ?>><?= $isResidentDictation ? 'I-transcribe ang rekord' : 'Transcribe recording' ?></button>
                </div>
            </div>
        </div>
        <div data-dictation-preview hidden>
            <label for="narrativeTranscript"<?= $isResidentDictation ? ' data-en="Review and edit transcript"' : '' ?>><?= $isResidentDictation ? 'Suriin at i-edit ang transcript' : 'Review and edit transcript' ?></label>
            <textarea id="narrativeTranscript" data-dictation-text rows="4" maxlength="15000"></textarea>
            <small class="field-hint"<?= $isResidentDictation ? ' data-en="Check names, dates, amounts, and any unclear words against the recording."' : '' ?>><?= $isResidentDictation ? 'Suriin ang mga pangalan, petsa, halaga, at anumang malabong salita laban sa rekord.' : 'Check names, dates, amounts, and any unclear words against the recording.' ?></small>
            <div class="ai-narrative-preview-actions">
                <button type="button" class="btn-create btn-sm" data-dictation-apply<?= $isResidentDictation ? ' data-en="Add to narrative"' : '' ?>><?= $isResidentDictation ? 'Idagdag sa salaysay' : 'Add to narrative' ?></button>
            </div>
        </div>
    </div>
</details>
<script defer src="../../assets/js/narrative-dictation.js?v=<?= filemtime(__DIR__ . '/../../../assets/js/narrative-dictation.js') ?>"></script>
