<?php
// views/hearing_session/minutes.php
// Session Minutes AI Assistance View (Voice Recording & Handwritten Notes OCR via Gemini 1.5 Flash)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hearing Session Minutes - AI Assistance</title>
    <link rel="stylesheet" href="../../frontend/assets/css/dashboard.css">
    <link rel="stylesheet" href="../../frontend/assets/css/hearings.css">
    <style>
        .minutes-ai-container {
            max-width: 860px;
            margin: 2rem auto;
            padding: 1.5rem 2rem;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .ai-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
        }
        .ai-btn-group {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .btn-ai-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #334155;
        }
        .btn-ai-action:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
        }
        .btn-record.recording {
            background: #ef4444 !important;
            color: #ffffff !important;
            border-color: #dc2626 !important;
            animation: pulse-recording 1.5s infinite;
        }
        @keyframes pulse-recording {
            0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
            50% { box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); }
        }
        .ai-status-indicator {
            font-size: 0.82rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .session-minutes-textarea {
            width: 100%;
            min-height: 220px;
            font-family: inherit;
            font-size: 0.92rem;
            line-height: 1.6;
            padding: 12px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            box-sizing: border-box;
            resize: vertical;
        }
        .session-minutes-textarea:focus {
            border-color: #0284c7;
            outline: none;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
        }
    </style>
</head>
<body>

<div class="minutes-ai-container">
    <h2>Hearing Session Minutes</h2>
    <p style="color: #64748b; font-size: 0.9rem; margin-top: -4px; margin-bottom: 20px;">
        Record formal session proceedings, party statements, proposals, and settlement terms. Use Gemini AI for Speech-to-Text and handwritten notes OCR.
    </p>

    <!-- AI Action Toolbar -->
    <div class="ai-toolbar">
        <label for="session_minutes" style="font-weight: 700; color: #1e293b; font-size: 0.95rem;">
            Recorded Session Minutes &amp; Notes
        </label>
        <div class="ai-btn-group">
            <button type="button" id="btnRecordVoice" class="btn-ai-action btn-record" onclick="toggleVoiceRecording()">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>
                <span id="recordVoiceText">Record Voice</span>
            </button>

            <button type="button" id="btnUploadNotes" class="btn-ai-action" onclick="triggerNotesUpload()">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                <span>Upload Notes</span>
            </button>

            <input type="file" id="notesFileInput" accept="image/jpeg,image/png,image/webp,image/jpg" style="display: none;" onchange="handleNotesFileSelected(this)">
        </div>
    </div>

    <!-- Live Status Banner -->
    <div id="aiStatusBanner" class="ai-status-indicator" style="display: none; margin-bottom: 8px;"></div>

    <!-- Target Minutes Textarea -->
    <textarea id="session_minutes" name="session_minutes" class="session-minutes-textarea" placeholder="Formal session minutes, party commitments, discussion notes, or transcribed audio..."></textarea>
</div>

<script>
let mediaRecorder = null;
let audioChunks = [];
let isRecording = false;

async function toggleVoiceRecording() {
    const btn = document.getElementById('btnRecordVoice');
    const textSpan = document.getElementById('recordVoiceText');
    const statusBanner = document.getElementById('aiStatusBanner');

    if (!isRecording) {
        // Start Recording
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('Audio recording is not supported in this browser.');
            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            audioChunks = [];
            mediaRecorder = new MediaRecorder(stream);

            mediaRecorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    audioChunks.push(event.data);
                }
            };

            mediaRecorder.onstop = async () => {
                stream.getTracks().forEach(track => track.stop());
                const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                await sendAudioToGeminiSTT(audioBlob);
            };

            mediaRecorder.start();
            isRecording = true;
            btn.classList.add('recording');
            textSpan.textContent = 'Stop Recording (00:00)';
            statusBanner.style.display = 'flex';
            statusBanner.innerHTML = '<span style="color: #ef4444;">●</span> Recording microphone audio... Click "Stop Recording" when finished.';
        } catch (err) {
            console.error('Microphone access denied:', err);
            alert('Unable to access microphone: ' + err.message);
        }
    } else {
        // Stop Recording
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.stop();
        }
        isRecording = false;
        btn.classList.remove('recording');
        textSpan.textContent = 'Record Voice';
        btn.disabled = true;
        statusBanner.innerHTML = '⏳ Transcribing audio with Gemini 1.5 Flash... Please wait.';
    }
}

async function sendAudioToGeminiSTT(audioBlob) {
    const btn = document.getElementById('btnRecordVoice');
    const statusBanner = document.getElementById('aiStatusBanner');
    const textarea = document.getElementById('session_minutes');

    try {
        const formData = new FormData();
        formData.append('audio', audioBlob, 'session_recording.webm');

        const response = await fetch('../../backend/api/ai/transcribe-audio.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        if (result.success && result.text) {
            appendTextToMinutes(result.text, '🎙️ Audio Transcription');
            statusBanner.style.display = 'none';
        } else {
            statusBanner.innerHTML = `<span style="color:#ef4444;">Error: ${result.message || 'Transcription failed.'}</span>`;
        }
    } catch (error) {
        console.error('Transcription error:', error);
        statusBanner.innerHTML = `<span style="color:#ef4444;">Error transcribing audio: ${error.message}</span>`;
    } finally {
        btn.disabled = false;
    }
}

function triggerNotesUpload() {
    document.getElementById('notesFileInput').click();
}

async function handleNotesFileSelected(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const statusBanner = document.getElementById('aiStatusBanner');
    const uploadBtn = document.getElementById('btnUploadNotes');

    uploadBtn.disabled = true;
    uploadBtn.textContent = 'Processing OCR...';
    statusBanner.style.display = 'flex';
    statusBanner.innerHTML = '⏳ Extracting text from image with Gemini 1.5 Flash OCR...';

    try {
        const formData = new FormData();
        formData.append('image', file);

        const response = await fetch('../../backend/api/ai/ocr-notes.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        if (result.success && result.text) {
            appendTextToMinutes(result.text, '📄 OCR Notes Extract');
            statusBanner.style.display = 'none';
        } else {
            statusBanner.innerHTML = `<span style="color:#ef4444;">Error: ${result.message || 'OCR extraction failed.'}</span>`;
        }
    } catch (error) {
        console.error('OCR error:', error);
        statusBanner.innerHTML = `<span style="color:#ef4444;">Error processing image: ${error.message}</span>`;
    } finally {
        uploadBtn.disabled = false;
        uploadBtn.innerHTML = `
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
            <span>Upload Notes</span>
        `;
        input.value = '';
    }
}

function appendTextToMinutes(newText, sourceLabel) {
    const textarea = document.getElementById('session_minutes');
    if (!textarea) return;

    const trimmed = newText.trim();
    if (!trimmed) return;

    const formattedSnippet = `\n\n[${sourceLabel} - ${new Date().toLocaleTimeString()}]:\n${trimmed}`;
    if (textarea.value.trim() === '') {
        textarea.value = trimmed;
    } else {
        textarea.value = textarea.value.trim() + formattedSnippet;
    }

    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    textarea.scrollTop = textarea.scrollHeight;
}
</script>

</body>
</html>
