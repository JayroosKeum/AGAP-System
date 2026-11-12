<!-- Global Sign Out Confirmation Modal -->
<div id="globalLogoutModal" class="agap-signout-modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="signoutModalTitle">
    <div class="agap-signout-card">
        <div class="agap-signout-icon-wrap">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
        </div>
        <div class="agap-signout-content">
            <h3 id="signoutModalTitle">Nais mo bang mag-sign out?</h3>
            <p>Sigurado ka bang nais mong lumabas sa iyong account? Kakailanganin mong mag-log in muli upang ma-access ang system.</p>
        </div>
        <div class="agap-signout-actions">
            <button type="button" class="btn-signout-cancel" id="btnCancelSignout">Manatili (Cancel)</button>
            <button type="button" class="btn-signout-confirm" id="btnConfirmSignout">Oo, Mag-sign Out</button>
        </div>
    </div>
</div>
<script>
(() => {
    if (window.__agapSignoutModalInit) return;
    window.__agapSignoutModalInit = true;

    const bindSignoutModal = () => {
        const logoutBtn = document.getElementById('globalLogoutBtn');
        const logoutModal = document.getElementById('globalLogoutModal');
        const cancelLogoutBtn = document.getElementById('btnCancelSignout');
        const confirmLogoutBtn = document.getElementById('btnConfirmSignout');
        const logoutForm = document.getElementById('globalLogoutForm');
        if (!logoutBtn || !logoutModal || logoutBtn.dataset.signoutBound === '1') return;
        logoutBtn.dataset.signoutBound = '1';

        const openLogoutModal = () => {
            logoutModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            cancelLogoutBtn?.focus();
        };

        const closeLogoutModal = () => {
            logoutModal.style.display = 'none';
            document.body.style.overflow = '';
            logoutBtn.focus();
        };

        logoutBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            openLogoutModal();
        });

        cancelLogoutBtn?.addEventListener('click', closeLogoutModal);

        confirmLogoutBtn?.addEventListener('click', () => {
            if (!logoutForm) return;
            confirmLogoutBtn.disabled = true;
            confirmLogoutBtn.textContent = 'Lumalabas...';
            logoutForm.submit();
        });

        logoutModal.addEventListener('click', (e) => {
            if (e.target === logoutModal) {
                closeLogoutModal();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && logoutModal.style.display === 'flex') {
                closeLogoutModal();
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindSignoutModal);
    } else {
        bindSignoutModal();
    }
})();
</script>
