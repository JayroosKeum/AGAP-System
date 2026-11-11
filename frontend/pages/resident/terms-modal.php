<div id="residentTermsModal" class="resident-terms-modal" role="dialog" aria-modal="true" aria-labelledby="residentTermsTitle" hidden>
    <div class="resident-terms-dialog">
        <header class="resident-terms-header">
            <div>
                <p class="resident-eyebrow">AGAP Resident Portal</p>
                <h2 id="residentTermsTitle">User Agreement and Privacy Notice</h2>
            </div>
            <button type="button" class="resident-terms-close" data-close-resident-terms aria-label="Close User Agreement">&times;</button>
        </header>
        <div class="resident-terms-body" tabindex="0">
            <p>By creating or using a Resident Portal account, you agree to the following:</p>
            <ol>
                <li><strong>Official purpose.</strong> Use AGAP only to submit or follow up on concerns connected with Barangay Tumana's Katarungang Pambarangay services.</li>
                <li><strong>Accurate information.</strong> Provide truthful, current contact and complaint information. The Barangay may verify residency, identity, and submitted details before acting on a concern.</li>
                <li><strong>Privacy.</strong> AGAP processes personal information only for legitimate barangay justice administration, case handling, official notices, and lawful recordkeeping under the Data Privacy Act of 2012 (RA 10173).</li>
                <li><strong>Account security.</strong> Keep your password and verification codes confidential. You are responsible for activity performed through your account and must promptly report suspected unauthorized use.</li>
                <li><strong>Respectful and lawful use.</strong> Do not submit fabricated, threatening, abusive, or unauthorized information, and do not attempt to access another person's account or case information.</li>
                <li><strong>Not for emergencies.</strong> AGAP is not an emergency-response service. Contact the appropriate emergency authority for immediate danger, violence, or urgent police assistance.</li>
                <li><strong>Review and updates.</strong> Online submissions remain subject to barangay screening and applicable Katarungang Pambarangay procedures. Continued use after a published update means acceptance of the updated agreement.</li>
            </ol>
            <p class="resident-terms-note">The Barangay may suspend access that violates this agreement, compromises system security, or is inconsistent with official procedures.</p>
        </div>
        <footer class="resident-terms-footer">
            <span>Barangay Tumana · AGAP</span>
            <button type="button" class="resident-terms-ack" data-close-resident-terms>I Understand</button>
        </footer>
    </div>
</div>
<script>
(() => {
    const modal = document.getElementById('residentTermsModal');
    if (!modal) return;
    const open = (event) => {
        event?.preventDefault();
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        modal.querySelector('.resident-terms-ack')?.focus();
    };
    const close = () => {
        modal.hidden = true;
        document.body.style.overflow = '';
    };
    document.querySelectorAll('[data-open-resident-terms]').forEach((button) => button.addEventListener('click', open));
    modal.querySelectorAll('[data-close-resident-terms]').forEach((button) => button.addEventListener('click', close));
    modal.addEventListener('click', (event) => { if (event.target === modal) close(); });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !modal.hidden) close(); });
})();
</script>
