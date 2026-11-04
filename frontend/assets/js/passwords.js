const passwordApi = async (url, body) => {
    const headers = { 'Content-Type': 'application/json' };
    if (window.agapCsrfToken) {
        headers['X-CSRF-Token'] = window.agapCsrfToken;
    }

    const response = await fetch(url, {
        method: 'POST',
        headers: headers,
        body: JSON.stringify(body),
        credentials: 'same-origin'
    });

    const data = await response.json().catch(() => ({
        success: false,
        message: 'Invalid server response.'
    }));

    if (!response.ok || data.success === false) {
        throw new Error(data.message || 'Request failed.');
    }

    return data;
};

const passwordMessage = (message, success = false) => {
    const node = document.getElementById('authMessage');
    if (!node) return;
    node.textContent = message;
    node.className = message ? `auth-message ${success ? 'success' : 'error'}` : '';
};

document.addEventListener('DOMContentLoaded', () => {
    const forms = [
        {
            id: 'forgotPasswordForm',
            url: '../../../backend/api/auth/request-password-reset.php',
            loadingText: 'Sending reset link…',
            done: 'If the account exists, a reset link will be sent shortly.'
        },
        {
            id: 'resetPasswordForm',
            url: '../../../backend/api/auth/reset-password.php',
            loadingText: 'Resetting password…',
            done: 'Password reset successfully! Redirecting to sign in…',
            redirect: 'login.php'
        },
        {
            id: 'verifyEmailForm',
            url: '../../../backend/api/auth/verify-email.php',
            loadingText: 'Verifying…',
            done: 'Email verified. Redirecting to sign in…',
            redirect: 'login.php'
        },
        {
            id: 'activateAccountForm',
            url: '../../../backend/api/auth/activate.php',
            loadingText: 'Activating…',
            done: 'Account activated. Redirecting to sign in…',
            redirect: 'login.php'
        },
        {
            id: 'changePasswordForm',
            url: '../../../backend/api/auth/change-password.php',
            loadingText: 'Saving…',
            done: 'Password changed successfully.'
        }
    ];

    forms.forEach(item => {
        const formEl = document.getElementById(item.id);
        if (!formEl) return;

        formEl.addEventListener('submit', async event => {
            event.preventDefault();
            passwordMessage('');

            const submitBtn = formEl.querySelector('button[type="submit"]');
            const originalBtnHtml = submitBtn ? submitBtn.innerHTML : '';

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.setAttribute('aria-busy', 'true');
                if (item.loadingText) {
                    submitBtn.textContent = item.loadingText;
                }
            }

            try {
                const formData = new FormData(formEl);
                const payload = Object.fromEntries(formData.entries());

                const result = await passwordApi(item.url, payload);
                passwordMessage(result.message || item.done, true);

                if (item.id === 'forgotPasswordForm') {
                    // Keep the email confirmation visible; don't clear until requested
                    const input = formEl.querySelector('input[name="identity"]');
                    if (input) input.disabled = true;
                    if (submitBtn) submitBtn.style.display = 'none';
                } else {
                    formEl.reset();
                }

                if (item.redirect) {
                    setTimeout(() => {
                        window.location.href = item.redirect;
                    }, 1400);
                }
            } catch (error) {
                passwordMessage(error.message || 'An error occurred. Please try again.', false);
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.removeAttribute('aria-busy');
                    submitBtn.innerHTML = originalBtnHtml;
                }
            }
        });
    });
});
