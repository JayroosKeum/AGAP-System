/**
 * AGAP Unified Client-Side Field Validator
 * Enforces field-level rules across all forms before submission and on blur/input.
 */
(() => {
    const NAME_REGEX = /^(?=.{1,150}$)[\p{L}\p{M}.]+(?:[ '-][\p{L}\p{M}.]+)*$/u;
    const USERNAME_REGEX = /^[A-Za-z0-9_.-]+$/;
    const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    const TIME_REGEX = /^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/;

    function todayIsoDate() {
        const d = new Date();
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    function isValidName(value) {
        const v = String(value || '').trim();
        if (!v) return true;
        return NAME_REGEX.test(v) && /\p{L}/u.test(v);
    }

    function isValidPhone(value) {
        const v = String(value || '').trim();
        if (!v) return true;
        if (!/^\+?[0-9 .()\-]+$/.test(v) || v.length > 20) return false;
        let digits = v.replace(/\D/g, '');
        if (v.startsWith('+')) {
            if (!digits.startsWith('63')) return false;
            digits = digits.slice(2);
        } else if (digits.startsWith('63')) {
            digits = digits.slice(2);
        } else if (digits.startsWith('0')) {
            digits = digits.slice(1);
        }
        if (/^9[0-9]{9}$/.test(digits)) return true;
        return /^(?:2[0-9]{8}|[3-8][0-9]{8,9})$/.test(digits);
    }

    function isValidEmail(value) {
        const v = String(value || '').trim();
        if (!v) return true;
        return v.length <= 150 && EMAIL_REGEX.test(v);
    }

    function isValidPassword(value, minLen = 12) {
        const v = String(value || '');
        if (!v) return true;
        return v.length >= minLen && /[a-z]/.test(v) && /[A-Z]/.test(v) && /[0-9]/.test(v);
    }

    function isControlCharFree(value, allowLineBreaks = false) {
        const v = String(value || '');
        const re = allowLineBreaks
            ? /[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/
            : /[\x00-\x1F\x7F]/;
        return !re.test(v);
    }

    function isVisibleElement(el) {
        if (!el || el.disabled) return false;
        if (el.type === 'hidden') return false;
        if (el.closest('[hidden], [aria-hidden="true"]')) return false;
        return el.offsetParent !== null || el.getClientRects().length > 0;
    }

    function shouldSkipForm(form) {
        if (!form || form.hasAttribute('data-skip-global-validate')) return true;
        if (form.classList.contains('complaints-toolbar-form') || form.classList.contains('paanyaya-search-form')) {
            return true;
        }
        return false;
    }

    function formAllowsEntityNames(form) {
        if (!form) return false;
        const jur = form.querySelector('[name="party_is_juridical"], [name="party_is_government"]');
        if (jur && jur.checked) return true;
        const cap = form.querySelector('[name="party_capacity_type"]');
        if (cap && ['juridical', 'government'].includes(String(cap.value || '').toLowerCase())) return true;
        return false;
    }

    function getValidationError(el) {
        if (!el || el.disabled || el.readOnly) return '';
        if (el.type === 'hidden' || el.type === 'checkbox' || el.type === 'radio'
            || el.type === 'button' || el.type === 'submit' || el.type === 'reset' || el.type === 'search') {
            return '';
        }

        if (el.type === 'file') {
            if (el.hasAttribute('required') && (!el.files || el.files.length === 0)) {
                return 'Please attach the required file.';
            }
            if (el.files && el.files.length > 0) {
                const maxMb = Number(el.dataset.maxMb || 25);
                const maxBytes = maxMb * 1024 * 1024;
                const acceptRaw = (el.getAttribute('accept') || '').trim();
                const acceptTokens = acceptRaw ? acceptRaw.split(',').map((t) => t.trim().toLowerCase()).filter(Boolean) : [];
                for (const file of el.files) {
                    if (file.size > maxBytes) {
                        return `"${file.name}" exceeds the ${maxMb} MB file size limit.`;
                    }
                    if (acceptTokens.length > 0) {
                        const lowerName = file.name.toLowerCase();
                        const mime = (file.type || '').toLowerCase();
                        const ok = acceptTokens.some((tok) => {
                            if (tok.startsWith('.')) return lowerName.endsWith(tok);
                            if (tok.endsWith('/*')) return mime.startsWith(tok.slice(0, -1));
                            return mime === tok;
                        });
                        if (!ok) {
                            return `Unsupported file type for "${file.name}". Allowed: ${acceptRaw}`;
                        }
                    }
                }
            }
            return '';
        }

        const raw = String(el.value ?? '');
        const trimmed = raw.trim();
        const name = String(el.name || '');
        const id = String(el.id || '');
        const form = el.form || el.closest('form');
        const validateHint = String(el.dataset.validate || '').toLowerCase();

        // Check masked API key placeholder in settings
        if (trimmed.includes('••••') && /api_key/i.test(name)) {
            return '';
        }

        if (el.hasAttribute('required') && trimmed === '') {
            return 'This field is required and cannot be blank.';
        }

        if (!isControlCharFree(raw, el.tagName === 'TEXTAREA')) {
            return 'Unsupported control characters are not allowed.';
        }

        if (trimmed === '') {
            return '';
        }

        const minLen = el.getAttribute('minlength');
        if (minLen && trimmed.length < Number(minLen)) {
            return `Must be at least ${minLen} characters.`;
        }
        const maxLen = el.getAttribute('maxlength');
        if (maxLen && raw.length > Number(maxLen)) {
            return `Must be ${maxLen} characters or fewer.`;
        }

        if (el.type === 'number') {
            if (el.validity && el.validity.badInput) {
                return 'Enter a valid numeric value.';
            }
            const num = Number(trimmed);
            if (!Number.isFinite(num)) {
                return 'Enter a valid numeric value.';
            }
            if (el.min !== '' && num < Number(el.min)) {
                return `Value must be at least ${el.min}.`;
            }
            if (el.max !== '' && num > Number(el.max)) {
                return `Value must not exceed ${el.max}.`;
            }
        }

        if (el.type === 'email' || validateHint === 'email' || /(?:^|_)email$/i.test(name)) {
            if (!isValidEmail(trimmed)) {
                return 'Enter a valid email address (e.g. name@example.com).';
            }
        }

        if (validateHint === 'phone' || el.type === 'tel'
            || (/(?:contact|phone|mobile|recipient)(?:_no)?$/i.test(name) && !/unknown/i.test(name))) {
            if (!isValidPhone(trimmed)) {
                return 'Enter a valid Philippine mobile (09XXXXXXXXX / +639XXXXXXXXX) or landline number.';
            }
        }

        const isNameField = validateHint === 'name'
            || (/^(?:complainant_|respondent_|guardian_)?(?:first_name|middle_name|last_name)$/i.test(name) && !formAllowsEntityNames(form))
            || /^(?:captain_name|barangay_secretary_name|lupon_secretary_name|legal_officer_name)$/i.test(name);
        if (isNameField && !isValidName(trimmed)) {
            return 'Enter a valid name using letters, spaces, hyphens, apostrophes, or periods only.';
        }

        const isLoginForm = Boolean(form && /auth\/login\.php/i.test(form.getAttribute('action') || ''));
        if (validateHint === 'login-identity' || (name === 'username' && isLoginForm)) {
            if (trimmed.length < 3 || trimmed.length > 150 || (!USERNAME_REGEX.test(trimmed) && !isValidEmail(trimmed))) {
                return 'Enter a valid username or email address.';
            }
        } else if (validateHint === 'username' || name === 'username') {
            const minU = Number(minLen || 3);
            const maxU = Number(maxLen || 100);
            if (trimmed.length < minU || trimmed.length > maxU || !USERNAME_REGEX.test(trimmed)) {
                return `Username must be ${minU}–${maxU} characters (letters, numbers, dots, underscores, or hyphens).`;
            }
        }

        const isLoginPassword = isLoginForm;
        const isCurrentPassword = /current/i.test(name) || /current/i.test(id);
        const isPasswordEl = el.type === 'password' || el.dataset.agapPasswordField === '1';
        const isNewPassword = validateHint === 'password'
            || (!isLoginPassword && !isCurrentPassword && isPasswordEl && /^(?:password|new_password)$/i.test(name));
        if (isNewPassword) {
            if (!isValidPassword(raw, 12)) {
                return 'Password must be at least 12 characters with uppercase, lowercase, and a number.';
            }
        }

        if (validateHint === 'password-confirm' || name === 'password_confirmation' || name === 'confirm_password') {
            const scope = form || document;
            const targetId = el.dataset.match;
            const pwInput = targetId
                ? document.getElementById(targetId)
                : scope.querySelector('input[name="password"], input[name="new_password"]');
            if (pwInput && raw !== String(pwInput.value || '')) {
                return 'Password confirmation does not match.';
            }
        }

        if (el.type === 'date') {
            if (!/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
                return 'Enter a valid date (YYYY-MM-DD).';
            }
            if (el.min && trimmed < el.min) {
                return `Date cannot be earlier than ${el.min}.`;
            }
            const maxDate = el.max || (/birth/i.test(name || id) ? todayIsoDate() : '');
            if (maxDate && trimmed > maxDate) {
                return /birth/i.test(name || id)
                    ? 'Birth date cannot be in the future.'
                    : `Date cannot be later than ${maxDate}.`;
            }
        }

        if (el.type === 'time') {
            if (!TIME_REGEX.test(trimmed)) {
                return 'Enter a valid time (HH:MM).';
            }
            if (name === 'working_hours_end' && form) {
                const startEl = form.querySelector('[name="working_hours_start"]');
                if (startEl && startEl.value && trimmed <= startEl.value) {
                    return 'Closing time must be after opening time.';
                }
            }
            if (name === 'mediation_hours_end' && form) {
                const startEl = form.querySelector('[name="mediation_hours_start"]');
                if (startEl && startEl.value && trimmed <= startEl.value) {
                    return 'Mediation end time must be after start time.';
                }
            }
        }

        if (/latitude/i.test(name || id) && el.type !== 'hidden') {
            const lat = Number(trimmed);
            if (!Number.isFinite(lat) || lat < -90 || lat > 90) {
                return 'Latitude must be a valid number between -90 and 90.';
            }
        }
        if (/longitude/i.test(name || id) && el.type !== 'hidden') {
            const lng = Number(trimmed);
            if (!Number.isFinite(lng) || lng < -180 || lng > 180) {
                return 'Longitude must be a valid number between -180 and 180.';
            }
        }

        const pattern = el.getAttribute('pattern');
        if (pattern) {
            try {
                const re = new RegExp(`^(?:${pattern})$`);
                if (!re.test(trimmed)) {
                    return el.getAttribute('title') || 'Input format does not match the required pattern.';
                }
            } catch (_) {
                // Ignore malformed custom pattern
            }
        }

        return '';
    }

    function renderFieldError(el, message) {
        if (typeof el.setCustomValidity === 'function') {
            el.setCustomValidity(message || '');
        }
        const host = el.closest('.form-group, .party-field-col') || el.parentElement;
        if (!host) return;
        let msgEl = host.querySelector(':scope > .agap-field-error');
        if (message) {
            el.classList.add('agap-field-invalid');
            el.setAttribute('aria-invalid', 'true');
            if (!msgEl) {
                msgEl = document.createElement('div');
                msgEl.className = 'agap-field-error';
                msgEl.setAttribute('role', 'alert');
                host.appendChild(msgEl);
            }
            msgEl.textContent = message;
        } else {
            el.classList.remove('agap-field-invalid');
            el.removeAttribute('aria-invalid');
            if (msgEl) msgEl.remove();
        }
    }

    function validateElement(el, showMessage = true) {
        const msg = getValidationError(el);
        if (showMessage || !msg) {
            renderFieldError(el, msg);
        } else if (typeof el.setCustomValidity === 'function') {
            el.setCustomValidity(msg);
        }
        return msg === '';
    }

    function validateForm(form) {
        if (shouldSkipForm(form)) return true;
        const fields = Array.from(form.querySelectorAll('input, select, textarea'));
        let firstInvalid = null;

        for (const el of fields) {
            if (!isVisibleElement(el)) {
                if (typeof el.setCustomValidity === 'function') el.setCustomValidity('');
                continue;
            }
            const ok = validateElement(el, true);
            if (!ok && !firstInvalid) {
                firstInvalid = el;
            }
        }

        if (firstInvalid) {
            firstInvalid.focus({ preventScroll: false });
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }
        return true;
    }

    document.addEventListener('input', (e) => {
        const el = e.target;
        if (!(el instanceof HTMLInputElement || el instanceof HTMLSelectElement || el instanceof HTMLTextAreaElement)) return;
        const form = el.form || el.closest('form');
        if (form && shouldSkipForm(form)) return;
        if (el.classList.contains('agap-field-invalid') || el.getAttribute('name') === 'password_confirmation' || el.getAttribute('name') === 'confirm_password') {
            validateElement(el, true);
        } else if (typeof el.setCustomValidity === 'function') {
            el.setCustomValidity('');
        }
    }, true);

    document.addEventListener('blur', (e) => {
        const el = e.target;
        if (!(el instanceof HTMLInputElement || el instanceof HTMLSelectElement || el instanceof HTMLTextAreaElement)) return;
        const form = el.form || el.closest('form');
        if (form && shouldSkipForm(form)) return;
        if (!isVisibleElement(el)) return;
        if (String(el.value || '').trim() !== '' || el.classList.contains('agap-field-invalid')) {
            validateElement(el, true);
        }
    }, true);

    document.addEventListener('change', (e) => {
        const el = e.target;
        if (!(el instanceof HTMLInputElement || el instanceof HTMLSelectElement || el instanceof HTMLTextAreaElement)) return;
        const form = el.form || el.closest('form');
        if (form && shouldSkipForm(form)) return;
        if (el.type === 'file' || isVisibleElement(el)) {
            if (String(el.value || '').trim() !== '' || el.hasAttribute('required')) {
                validateElement(el, true);
            }
        }
    }, true);

    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || shouldSkipForm(form)) return;
        if (!validateForm(form)) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    function collectPartyModalErrors({
        firstName = '',
        middleName = '',
        lastName = '',
        birthDate = '',
        age = '',
        contactNo = '',
        contactUnknown = false,
        email = '',
        isMinor = false,
        guardianName = '',
        guardianContact = '',
        allowEntity = false
    }) {
        const errors = [];
        const fieldIds = [];
        const birth = String(birthDate || '').trim();
        const ageRaw = String(age || '').trim();
        const contact = String(contactNo || '').trim();
        const mail = String(email || '').trim();
        const gName = String(guardianName || '').trim();
        const gContact = String(guardianContact || '').trim();

        if (!allowEntity) {
            if (firstName && !isValidName(firstName)) {
                errors.push('Invalid First Name: use letters, spaces, hyphens, apostrophes, or periods only.');
                fieldIds.push('modalFirstName');
            }
            if (middleName && !isValidName(middleName)) {
                errors.push('Invalid Middle Name: use letters, spaces, hyphens, apostrophes, or periods only.');
                fieldIds.push('modalMiddleName');
            }
            if (lastName && !isValidName(lastName)) {
                errors.push('Invalid Last Name: use letters, spaces, hyphens, apostrophes, or periods only.');
                fieldIds.push('modalLastName');
            }
        }
        if (birth && (!/^\d{4}-\d{2}-\d{2}$/.test(birth) || birth > todayIsoDate())) {
            errors.push('Birthdate must be a valid date and cannot be in the future.');
            fieldIds.push('modalBirthdate');
        }
        if (ageRaw !== '') {
            const ageNum = Number(ageRaw);
            if (!Number.isInteger(ageNum) || ageNum < 0 || ageNum > 130) {
                errors.push('Age must be a whole number from 0 to 130.');
                fieldIds.push('modalAge');
            }
        }
        if (!contactUnknown && contact && !isValidPhone(contact)) {
            errors.push('Enter a valid Philippine mobile (09XXXXXXXXX / +639XXXXXXXXX) or landline number.');
            fieldIds.push('modalContactNo');
        }
        if (mail && !isValidEmail(mail)) {
            errors.push('Enter a valid email address (up to 150 characters).');
            fieldIds.push('modalEmail');
        }
        if (isMinor) {
            if (gName && !isValidName(gName)) {
                errors.push('Legal Guardian name may contain letters, spaces, hyphens, apostrophes, or periods only.');
                fieldIds.push('modalGuardianName');
            }
            if (gContact && !isValidPhone(gContact)) {
                errors.push('Enter a valid Philippine contact number for the Legal Guardian.');
                fieldIds.push('modalGuardianContact');
            }
        }
        return { errors, fieldIds };
    }

    window.AgapFieldValidator = {
        isValidName,
        isValidPhone,
        isValidEmail,
        isValidPassword,
        isValidTime: (v) => !v || TIME_REGEX.test(String(v).trim()),
        isControlCharFree,
        validateElement,
        validateForm,
        renderFieldError,
        collectPartyModalErrors,
    };
})();
