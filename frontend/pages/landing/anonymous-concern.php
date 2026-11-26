<?php

declare(strict_types=1);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anonymous Concern &amp; Token Tracker · AGAP Barangay Tumana</title>
    <link rel="icon" type="image/jpeg" href="../../assets/images/tumana_logo.jpg">
    <link rel="stylesheet" href="../../assets/css/app.css?v=<?= file_exists(__DIR__ . '/../../assets/css/app.css') ? filemtime(__DIR__ . '/../../assets/css/app.css') : '1' ?>">
    <link rel="stylesheet" href="../../assets/css/landing.css?v=<?= file_exists(__DIR__ . '/../../assets/css/landing.css') ? filemtime(__DIR__ . '/../../assets/css/landing.css') : '1' ?>">
</head>
<body class="landing-page">
    <header>
        <nav class="landing-nav" aria-label="Anonymous portal navigation">
            <a class="landing-brand" href="index.php">
                <img src="../../assets/images/tumana_logo.jpg" alt="Barangay Tumana Seal">
                <span>
                    <strong>AGAP</strong>
                    <small>Barangay Tumana, Marikina City</small>
                </span>
            </a>
            <div class="landing-nav-actions">
                <a href="index.php" class="landing-login">&larr; Back to Home</a>
            </div>
        </nav>
    </header>

    <main style="max-width:920px; margin:36px auto; padding:0 16px; display:grid; gap:24px;">
        <section style="background:#fff; border:1px solid #d7e1db; border-radius:16px; padding:28px; box-shadow:0 8px 24px rgba(16,43,45,0.06);">
            <p class="landing-kicker" style="margin-bottom:6px;">Data-Minimized Anonymous Channel (RA 10173)</p>
            <h1 style="margin:0 0 8px; color:#102b2d; font-size:1.6rem;">Submit an Anonymous General Concern</h1>
            <p style="margin:0 0 18px; color:#475569; font-size:0.93rem; line-height:1.5;">
                No account, name, contact number, or IP address is stored. Save the one-time follow-up token shown after submission—only its cryptographic hash is kept in the database.
                For formal Katarungang Pambarangay mediation/summons involving named parties, please use the <a href="../resident/login.php" style="color:#2b6b5e; font-weight:700;">Resident Portal</a>.
            </p>
            <form id="anonSubmitForm" style="display:grid; gap:12px;">
                <div>
                    <label for="anonTitle" style="display:block; font-weight:600; margin-bottom:4px; color:#1e293b;">Concern Title *</label>
                    <input id="anonTitle" name="concern_title" type="text" required maxlength="255" placeholder="e.g., Street lighting or late-night disturbance" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px;">
                </div>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px;">
                    <div>
                        <label for="anonCategory" style="display:block; font-weight:600; margin-bottom:4px; color:#1e293b;">Category Hint (Optional)</label>
                        <input id="anonCategory" name="category_hint" type="text" maxlength="120" placeholder="e.g., Neighborhood Nuisance" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px;">
                    </div>
                    <div>
                        <label for="anonArea" style="display:block; font-weight:600; margin-bottom:4px; color:#1e293b;">Purok / Area (Optional)</label>
                        <input id="anonArea" name="barangay_area" type="text" maxlength="120" placeholder="e.g., Purok 3" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px;">
                    </div>
                </div>
                <div>
                    <label for="anonNarrative" style="display:block; font-weight:600; margin-bottom:4px; color:#1e293b;">Concern Details *</label>
                    <textarea id="anonNarrative" name="narrative" rows="4" required minlength="10" placeholder="Describe the concern without including personal identifying details..." style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px;"></textarea>
                </div>
                <div>
                    <button type="submit" id="anonSubmitBtn" style="padding:11px 22px; background:#2b6b5e; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Submit Anonymous Concern</button>
                </div>
            </form>
            <div id="anonSubmitResult" hidden style="margin-top:16px; padding:14px; border-radius:10px; font-size:0.92rem; line-height:1.5;"></div>
        </section>

        <section style="background:#fff; border:1px solid #d7e1db; border-radius:16px; padding:28px; box-shadow:0 8px 24px rgba(16,43,45,0.06);">
            <h2 style="margin:0 0 8px; color:#102b2d; font-size:1.3rem;">Track Anonymous Concern with Secret Token</h2>
            <p style="margin:0 0 16px; color:#475569; font-size:0.9rem;">Enter your Reference Code and one-time Follow-Up Token to view sanitized milestones.</p>
            <form id="anonTrackForm" style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                <input id="anonTrackRef" type="text" required placeholder="Reference Code (e.g. ANON-2026-000001)" style="flex:1 1 220px; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px;">
                <input id="anonTrackToken" type="text" required placeholder="Follow-Up Token (AGAP-ANON-...)" style="flex:1 1 280px; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px;">
                <button type="submit" id="anonTrackBtn" style="padding:10px 20px; background:#0f172a; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Verify Token &amp; Track</button>
            </form>
            <div id="anonTrackResult" hidden style="margin-top:14px; padding:12px 14px; border-radius:10px; font-size:0.9rem;"></div>
        </section>
    </main>

    <script>
        document.getElementById('anonSubmitForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const out = document.getElementById('anonSubmitResult');
            out.hidden = false;
            out.style.background = '#f1f5f9';
            out.style.color = '#334155';
            out.textContent = 'Submitting anonymously...';
            try {
                const payload = {
                    concern_title: document.getElementById('anonTitle').value.trim(),
                    category_hint: document.getElementById('anonCategory').value.trim(),
                    barangay_area: document.getElementById('anonArea').value.trim(),
                    narrative: document.getElementById('anonNarrative').value.trim()
                };
                const res = await fetch('../../../backend/api/public/anonymous-submit.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    out.style.background = '#ecfdf5';
                    out.style.color = '#065f46';
                    out.innerHTML = '';
                    const strong = document.createElement('strong');
                    strong.textContent = 'Submitted! Save these credentials now (token cannot be recovered): ';
                    const details = document.createElement('div');
                    details.textContent = `Reference: ${data.reference_code} | Token: ${data.follow_up_token} | Queue: ${data.assigned_queue}`;
                    out.appendChild(strong);
                    out.appendChild(details);
                    document.getElementById('anonTrackRef').value = data.reference_code;
                    document.getElementById('anonTrackToken').value = data.follow_up_token;
                } else {
                    out.style.background = '#fef2f2';
                    out.style.color = '#991b1b';
                    out.textContent = data.message || 'Unable to submit concern.';
                }
            } catch {
                out.style.background = '#fef2f2';
                out.style.color = '#991b1b';
                out.textContent = 'Network error while submitting concern.';
            }
        });

        document.getElementById('anonTrackForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const out = document.getElementById('anonTrackResult');
            out.hidden = false;
            out.style.background = '#f1f5f9';
            out.style.color = '#334155';
            out.textContent = 'Verifying token...';
            try {
                const res = await fetch('../../../backend/api/public/anonymous-track.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        reference_code: document.getElementById('anonTrackRef').value.trim(),
                        follow_up_token: document.getElementById('anonTrackToken').value.trim()
                    })
                });
                const data = await res.json();
                if (data.success && data.record) {
                    const r = data.record;
                    out.style.background = '#ecfdf5';
                    out.style.color = '#065f46';
                    out.textContent = `Ref: ${r.reference_code} | Status: ${r.status} | Queue: ${r.assigned_queue} | Note: ${r.milestone_note}`;
                } else {
                    out.style.background = '#fef2f2';
                    out.style.color = '#991b1b';
                    out.textContent = data.message || 'Invalid reference code or follow-up token.';
                }
            } catch {
                out.style.background = '#fef2f2';
                out.style.color = '#991b1b';
                out.textContent = 'Unable to verify token right now.';
            }
        });
    </script>
</body>
</html>
