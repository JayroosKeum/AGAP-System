<?php
if (defined('AGAP_SIGNOUT_MODAL_INCLUDED')) {
    return;
}
define('AGAP_SIGNOUT_MODAL_INCLUDED', true);
$signoutIsResident = (($_SESSION['role_name'] ?? '') === 'Resident' || (int) ($_SESSION['role_id'] ?? 0) === 5);
?>
<style>
#globalLogoutModal.agap-signout-modal-overlay {
    position: fixed;
    inset: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
#globalLogoutModal .agap-signout-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
    max-width: 440px;
    width: 100%;
    padding: 24px;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
}
#globalLogoutModal .agap-signout-icon-wrap {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: #fee2e2;
    color: #dc2626;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
    flex-shrink: 0;
}
#globalLogoutModal .agap-signout-content h3 {
    margin: 0 0 8px 0;
    font-size: 1.25rem;
    font-weight: 800;
    color: #0f172a;
}
#globalLogoutModal .agap-signout-content p {
    margin: 0 0 22px 0;
    font-size: 0.92rem;
    color: #64748b;
    line-height: 1.5;
}
#globalLogoutModal .agap-signout-actions {
    display: flex;
    gap: 12px;
    width: 100%;
    justify-content: center;
}
#globalLogoutModal .btn-signout-cancel,
#globalLogoutModal .btn-signout-confirm {
    flex: 1;
    font-size: 0.92rem;
    font-weight: 700;
    padding: 10px 16px;
    border-radius: 8px;
    cursor: pointer;
    min-height: 42px;
}
#globalLogoutModal .btn-signout-cancel {
    background: #ffffff;
    border: 1.5px solid #cbd5e1;
    color: #334155;
}
#globalLogoutModal .btn-signout-confirm {
    background: #dc2626;
    border: 1.5px solid #dc2626;
    color: #ffffff;
}
</style>
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
            <h3 id="signoutModalTitle" data-en="Sign out?"><?= $signoutIsResident ? 'Nais mo bang mag-sign out?' : 'Sign out?' ?></h3>
            <p id="signoutModalBody" data-en="Are you sure you want to leave your account? You will need to log in again to access the system."><?= $signoutIsResident ? 'Sigurado ka bang nais mong lumabas sa iyong account? Kakailanganin mong mag-log in muli upang ma-access ang system.' : 'Are you sure you want to leave your account? You will need to log in again to access the system.' ?></p>
        </div>
        <div class="agap-signout-actions">
            <button type="button" class="btn-signout-cancel" id="btnCancelSignout" data-en="Stay (Cancel)"><?= $signoutIsResident ? 'Manatili (Kanselahin)' : 'Stay (Cancel)' ?></button>
            <button type="button" class="btn-signout-confirm" id="btnConfirmSignout" data-en="Yes, Sign Out"><?= $signoutIsResident ? 'Oo, Mag-sign Out' : 'Yes, Sign Out' ?></button>
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

        const syncSignoutModalLang = () => {
            const isTl = typeof window.getAppLanguage === 'function'
                ? window.getAppLanguage() === 'tl'
                : document.documentElement.getAttribute('data-agap-lang') === 'tl';
            const titleEl = document.getElementById('signoutModalTitle');
            const bodyEl = document.getElementById('signoutModalBody');
            if (titleEl) titleEl.textContent = isTl ? 'Nais mo bang mag-sign out?' : 'Sign out?';
            if (bodyEl) bodyEl.textContent = isTl
                ? 'Sigurado ka bang nais mong lumabas sa iyong account? Kakailanganin mong mag-log in muli upang ma-access ang system.'
                : 'Are you sure you want to leave your account? You will need to log in again to access the system.';
            if (cancelLogoutBtn) cancelLogoutBtn.textContent = isTl ? 'Manatili (Kanselahin)' : 'Stay (Cancel)';
            if (confirmLogoutBtn && !confirmLogoutBtn.disabled) confirmLogoutBtn.textContent = isTl ? 'Oo, Mag-sign Out' : 'Yes, Sign Out';
        };

        const openLogoutModal = () => {
            syncSignoutModalLang();
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

        document.addEventListener('click', (e) => {
            const targetBtn = e.target instanceof Element ? e.target.closest('#globalLogoutBtn') : null;
            if (!targetBtn || targetBtn === logoutBtn) return;
            e.preventDefault();
            openLogoutModal();
        });

        cancelLogoutBtn?.addEventListener('click', closeLogoutModal);
        window.addEventListener('agapLanguageChanged', syncSignoutModalLang);

        confirmLogoutBtn?.addEventListener('click', () => {
            if (!logoutForm) return;
            confirmLogoutBtn.disabled = true;
            const isTl = typeof window.getAppLanguage === 'function'
                ? window.getAppLanguage() === 'tl'
                : document.documentElement.getAttribute('data-agap-lang') === 'tl';
            confirmLogoutBtn.textContent = isTl ? 'Lumalabas...' : 'Signing out...';
            window.agapSigningOut = true;
            window.agapDataSync?.stop();
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
