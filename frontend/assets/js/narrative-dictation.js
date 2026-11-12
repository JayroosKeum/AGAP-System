(() => {
    'use strict';

    const panel = document.querySelector('[data-narrative-dictation]');
    const narrative = document.getElementById('narrative');
    if (!panel || !narrative) return;

    const find = name => panel.querySelector(`[data-dictation-${name}]`);
    const start = find('start');
    const stop = find('stop');
    const discard = find('discard');
    const send = find('send');
    const apply = find('apply');
    const consent = find('consent');
    const audio = find('audio');
    const transcript = find('text');
    const status = find('status');
    const clock = find('time');
    const review = find('review');
    const sendControls = find('send-controls');
    const preview = find('preview');
    const maxSeconds = 90;
    const maxBytes = 1792 * 1024;

    let state = 'idle';
    let capture = null;
    let recording = null;
    let audioUrl = null;
    let request = null;

    function show(next, message = '') {
        state = next;
        status.textContent = message;
        start.hidden = next !== 'idle';
        stop.hidden = next !== 'recording';
        clock.hidden = next !== 'recording';
        discard.hidden = next === 'idle';
        review.hidden = !recording;
        sendControls.hidden = next === 'transcript';
        send.disabled = next === 'uploading';
        consent.disabled = next === 'uploading';
        preview.hidden = next !== 'transcript';
    }

    function stopTracks(stream) {
        stream?.getTracks().forEach(track => track.stop());
    }

    function clearCapture() {
        const previous = capture;
        capture = null;
        if (!previous) return;

        clearInterval(previous.timer);
        if (previous.recorder && previous.recorder.state !== 'inactive') {
            previous.recorder.stop();
        }
        stopTracks(previous.stream);
    }

    function reset(message = '') {
        clearCapture();
        request?.abort();
        request = null;
        recording = null;
        audio.pause();
        audio.removeAttribute('src');
        audio.load();
        if (audioUrl) URL.revokeObjectURL(audioUrl);
        audioUrl = null;
        consent.checked = false;
        transcript.value = '';
        show('idle', message);
    }

    function stopRecording(message = 'Recording stopped. Listen before sending it.') {
        if (state !== 'recording' || !capture) return;
        capture.stopMessage = message;
        show('stopping', 'Preparing your recording…');
        clearInterval(capture.timer);
        if (capture.recorder.state !== 'inactive') capture.recorder.stop();
        stopTracks(capture.stream);
    }

    panel.hidden = false;
    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
        show('idle', 'Microphone recording requires a supported browser on HTTPS or localhost. You can type the narrative above.');
        start.hidden = true;
        return;
    }

    start.addEventListener('click', async () => {
        if (state !== 'idle') return;
        const current = { chunks: [], bytes: 0 };
        capture = current;
        show('requesting', 'Allow microphone access to start recording.');
        discard.focus();

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            if (capture !== current) {
                stopTracks(stream);
                return;
            }
            current.stream = stream;
            const mime = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4']
                .find(type => MediaRecorder.isTypeSupported(type));
            const options = { audioBitsPerSecond: 64000 };
            if (mime) options.mimeType = mime;
            const recorder = new MediaRecorder(stream, options);
            current.recorder = recorder;

            recorder.addEventListener('dataavailable', event => {
                if (capture !== current || !event.data.size) return;
                current.bytes += event.data.size;
                if (current.bytes > maxBytes) {
                    reset('The recording is too large. Try a shorter clip.');
                    start.focus();
                    return;
                }
                current.chunks.push(event.data);
            });
            recorder.addEventListener('error', () => {
                if (capture === current) {
                    reset('Recording stopped because the microphone became unavailable.');
                    start.focus();
                }
            });
            recorder.addEventListener('stop', () => {
                if (capture !== current) return;
                clearCapture();
                if (!current.chunks.length) {
                    reset('No audio was recorded. Try speaking closer to the microphone.');
                    start.focus();
                    return;
                }
                recording = new Blob(current.chunks, { type: recorder.mimeType || current.chunks[0].type });
                audioUrl = URL.createObjectURL(recording);
                audio.src = audioUrl;
                show('review', current.stopMessage || 'Recording stopped. Listen before sending it.');
                if (panel.open) consent.focus();
            });

            recorder.start(1000);
            const startedAt = Date.now();
            clock.textContent = `0 / ${maxSeconds} s`;
            current.timer = setInterval(() => {
                const elapsed = Math.floor((Date.now() - startedAt) / 1000);
                clock.textContent = `${Math.min(elapsed, maxSeconds)} / ${maxSeconds} s`;
                if (elapsed >= maxSeconds) {
                    stopRecording('The 90-second limit was reached. Listen before sending the recording.');
                }
            }, 250);
            show('recording', 'Recording…');
            stop.focus();
        } catch (error) {
            if (capture !== current) return;
            const message = error.name === 'NotAllowedError'
                ? 'Microphone access was denied. Allow it in your browser to record.'
                : 'Couldn’t start the microphone. Check that it’s connected and available.';
            reset(message);
            start.focus();
        }
    });

    stop.addEventListener('click', () => stopRecording());
    discard.addEventListener('click', () => {
        reset('Recording discarded.');
        start.focus();
    });

    send.addEventListener('click', async () => {
        if (state !== 'review' || !recording) return;
        if (!consent.checked) {
            status.textContent = 'Confirm that you agree to send this recording to Google Gemini.';
            consent.focus();
            return;
        }

        const body = new FormData();
        const mime = recording.type.split(';', 1)[0];
        const extension = { 'audio/mp4': 'm4a', 'audio/ogg': 'ogg', 'audio/webm': 'webm' }[mime] || 'audio';
        body.append('audio', recording, `narrative.${extension}`);
        body.append('external_ai_consent', '1');

        const controller = new AbortController();
        request = controller;
        show('uploading', 'Gemini is transcribing your recording…');

        try {
            const response = await fetch('../../../backend/api/ai/transcribe-audio.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-Token': window.agapCsrfToken || document.querySelector('meta[name="agap-csrf-token"]')?.content || '',
                },
                body,
                signal: controller.signal,
            });
            const result = await response.json().catch(() => ({}));
            if (request !== controller) return;
            if (!response.ok || !result.success || typeof result.text !== 'string' || !result.text.trim()) {
                throw new Error(result.message || 'Unable to transcribe this recording. Please try again.');
            }
            transcript.value = result.text.trim();
            show('transcript', 'Transcript ready for review.');
            if (panel.open) transcript.focus();
        } catch (error) {
            if (request !== controller) return;
            show('review', error.message || 'Unable to transcribe this recording. Please try again.');
            if (panel.open) send.focus();
        } finally {
            if (request === controller) request = null;
        }
    });

    apply.addEventListener('click', () => {
        if (state !== 'transcript') return;
        const text = transcript.value.trim();
        if (!text) {
            status.textContent = 'Enter or restore the transcript before adding it.';
            transcript.focus();
            return;
        }
        const existing = narrative.value.trimEnd();
        const updated = existing ? `${existing}\n\n${text}` : text;
        if (narrative.maxLength > 0 && updated.length > narrative.maxLength) {
            status.textContent = `Shorten the transcript so the narrative fits within ${narrative.maxLength.toLocaleString()} characters.`;
            transcript.focus();
            return;
        }

        narrative.value = updated;
        narrative.dispatchEvent(new Event('input', { bubbles: true }));
        reset('Transcript added to the narrative.');
        narrative.focus();
    });

    panel.addEventListener('toggle', () => {
        if (panel.open) return;
        if (state === 'requesting') reset();
        if (state === 'recording') stopRecording();
    });
    window.addEventListener('pagehide', () => reset());

})();
