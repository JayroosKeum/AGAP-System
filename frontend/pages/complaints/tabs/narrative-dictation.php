<details class="narrative-dictation" data-narrative-dictation hidden>
    <summary>Dictate with Gemini</summary>
    <div class="narrative-dictation-body">
        <p class="field-hint">Record up to 90 seconds in Filipino, English, or both. Get permission from anyone you record.</p>
        <p data-dictation-status role="status"></p>
        <div class="ai-narrative-tools">
            <button type="button" class="btn-outline-sm" data-dictation-start>Start recording</button>
            <button type="button" class="btn-outline-sm" data-dictation-stop hidden>Stop recording</button>
            <button type="button" class="btn-outline-sm" data-dictation-discard hidden>Discard</button>
            <span data-dictation-time aria-hidden="true" hidden></span>
        </div>
        <div data-dictation-review hidden>
            <audio controls preload="metadata" aria-label="Review recorded audio" data-dictation-audio></audio>
            <div data-dictation-send-controls>
                <label class="ai-consent-checkbox-label">
                    <input type="checkbox" data-dictation-consent>
                    <span>I agree to send this recording to Google Gemini for transcription.</span>
                </label>
                <div class="ai-narrative-preview-actions">
                    <button type="button" class="btn-create btn-sm" data-dictation-send>Transcribe recording</button>
                </div>
            </div>
        </div>
        <div data-dictation-preview hidden>
            <label for="narrativeTranscript">Review and edit transcript</label>
            <textarea id="narrativeTranscript" data-dictation-text rows="4" maxlength="15000"></textarea>
            <small class="field-hint">Check names, dates, amounts, and any unclear words against the recording.</small>
            <div class="ai-narrative-preview-actions">
                <button type="button" class="btn-create btn-sm" data-dictation-apply>Add to narrative</button>
            </div>
        </div>
    </div>
</details>
<script defer src="../../assets/js/narrative-dictation.js?v=<?= filemtime(__DIR__ . '/../../../assets/js/narrative-dictation.js') ?>"></script>
