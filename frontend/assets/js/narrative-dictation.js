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

    function encodeMonoWav(audioBuffer, targetRate) {
        const sourceRate = audioBuffer.sampleRate || 48000;
        const rate = Math.min(targetRate, sourceRate);
        const channels = audioBuffer.numberOfChannels || 1;
        const totalFrames = audioBuffer.length || 0;
        const ratio = sourceRate / rate;
        const outLength = Math.max(1, Math.floor(totalFrames / ratio));
        const pcm = new Int16Array(outLength);

        for (let i = 0; i < outLength; i++) {
            const srcIndex = Math.min(totalFrames - 1, Math.floor(i * ratio));
            let sum = 0;
            for (let ch = 0; ch < channels; ch++) {
                sum += audioBuffer.getChannelData(ch)[srcIndex] || 0;
            }
            const sample = Math.max(-1, Math.min(1, sum / channels));
            pcm[i] = sample < 0 ? Math.round(sample * 0x8000) : Math.round(sample * 0x7fff);
        }

        const buffer = new ArrayBuffer(44 + pcm.byteLength);
        const view = new DataView(buffer);
        const writeStr = (offset, str) => {
            for (let i = 0; i < str.length; i++) view.setUint8(offset + i, str.charCodeAt(i));
        };
        writeStr(0, 'RIFF');
        view.setUint32(4, 36 + pcm.byteLength, true);
        writeStr(8, 'WAVE');
        writeStr(12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, 1, true);
        view.setUint16(22, 1, true);
        view.setUint32(24, rate, true);
        view.setUint32(28, rate * 2, true);
        view.setUint16(32, 2, true);
        view.setUint16(34, 16, true);
        writeStr(36, 'data');
        view.setUint32(40, pcm.byteLength, true);
        new Uint8Array(buffer, 44).set(new Uint8Array(pcm.buffer));
        return new Blob([buffer], { type: 'audio/wav' });
    }

    async function toCleanWavBlob(blob) {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx || !blob || !blob.size) return blob;
        const ctx = new AudioCtx();
        try {
            const raw = await blob.arrayBuffer();
            const decoded = await ctx.decodeAudioData(raw);
            if (!decoded || !decoded.length || decoded.duration <= 0.05) {
                return blob;
            }
            // Keep WAV under ~1.6 MB (safe for 2 MB default PHP upload_max_filesize)
            const targetRate = decoded.duration > 48 ? 8000 : (decoded.duration > 32 ? 12000 : 16000);
            const wavBlob = encodeMonoWav(decoded, targetRate);
            return wavBlob.size <= maxBytes ? wavBlob : encodeMonoWav(decoded, 8000);
        } catch (_) {
            return blob;
        } finally {
            ctx.close().catch(() => {});
        }
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
            recorder.addEventListener('stop', async () => {
                if (capture !== current) return;
                clearCapture();
                if (!current.chunks.length) {
                    reset('No audio was recorded. Try speaking closer to the microphone.');
                    start.focus();
                    return;
                }
                const rawBlob = new Blob(current.chunks, { type: recorder.mimeType || current.chunks[0].type });
                const converted = await toCleanWavBlob(rawBlob);
                if (state === 'idle') return;
                recording = converted;
                audioUrl = URL.createObjectURL(recording);
                audio.src = audioUrl;
                show('review', current.stopMessage || 'Recording stopped. Listen before sending it.');
                if (panel.open) consent.focus();
            });

            recorder.start();
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
        const mime = (recording.type || '').split(';', 1)[0].toLowerCase();
        const extension = {
            'audio/wav': 'wav',
            'audio/x-wav': 'wav',
            'audio/mp4': 'm4a',
            'video/mp4': 'm4a',
            'audio/ogg': 'ogg',
            'application/ogg': 'ogg',
            'audio/webm': 'webm',
            'video/webm': 'webm',
            'audio/x-matroska': 'webm',
            'video/x-matroska': 'webm',
        }[mime] || 'webm';
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
            const rawBody = await response.text();
            let result = null;
            try {
                result = rawBody ? JSON.parse(rawBody) : null;
            } catch (_) {
                result = null;
            }
            if (request !== controller) return;
            if (!result || typeof result !== 'object') {
                console.error('Transcription non-JSON response', response.status, rawBody.slice(0, 300));
                throw new Error(`Transcription failed (HTTP ${response.status}). Please try again or type the narrative.`);
            }
            if (!response.ok || !result.success || typeof result.text !== 'string' || !result.text.trim()) {
                throw new Error(result.message || `Unable to transcribe this recording (HTTP ${response.status}). Please try again.`);
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
