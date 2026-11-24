(() => {
    if (document.querySelector('meta[name="agap-authenticated"]')?.content !== '1' ||
        ![1, 2, 3, 4].includes(Number(document.querySelector('meta[name="agap-role-id"]')?.content))) return;
    const endpoint = '../../../backend/api/sync/state.php';
    const eventName = 'agap:data-changed';
    const storageKey = 'agap:data-sync';
    const pollInterval = 2500;
    const subscribers = new Set();
    let currentState = null;
    let pollTimer = null;
    let checking = false;
    let channel = null;
    let stopped = false;
    let activeController = null;
    const stop = () => {
        stopped = true;
        window.clearInterval(pollTimer);
        pollTimer = null;
        activeController?.abort();
    };

    const changedModules = (previous, next) => {
        if (!previous?.modules || !next?.modules) return [];
        return Object.keys(next.modules).filter(
            (name) => previous.modules[name] !== next.modules[name]
        );
    };

    const notify = (payload) => {
        window.dispatchEvent(new CustomEvent(eventName, { detail: payload }));
        subscribers.forEach((subscriber) => {
            try {
                subscriber(payload);
            } catch (error) {
                console.error('AGAP synchronization subscriber failed:', error);
            }
        });
    };

    const broadcast = (payload) => {
        if (channel) channel.postMessage(payload);
        try {
            localStorage.setItem(storageKey, JSON.stringify({
                ...payload,
                nonce: `${Date.now()}-${Math.random()}`
            }));
        } catch (error) {
            // Synchronization still works without browser storage.
        }
    };

    const checkNow = async (force = false) => {
        if (stopped || window.agapSigningOut || checking || (!force && document.hidden)) return;
        checking = true;
        const controller = new AbortController();
        activeController = controller;
        const timeout = window.setTimeout(() => controller.abort(), 8000);
        try {
            const response = await window.fetch(`${endpoint}?_t=${Date.now()}`, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
                signal: controller.signal
            });
            const nextState = await response.json().catch(() => null);
            if (response.status === 401 || response.status === 403) {
                stop();
                return;
            }
            if (!response.ok || !nextState?.success) return;

            if (currentState && currentState.revision !== nextState.revision) {
                const payload = {
                    source: 'server',
                    revision: nextState.revision,
                    modules: changedModules(currentState, nextState),
                    serverTime: nextState.server_time
                };
                currentState = nextState;
                notify(payload);
                broadcast(payload);
            } else {
                currentState = nextState;
            }
        } catch (error) {
            // The next poll retries automatically.
        } finally {
            window.clearTimeout(timeout);
            checking = false;
        }
    };

    const acceptRemote = (payload) => {
        if (!payload || payload.revision === currentState?.revision) return;
        window.setTimeout(() => checkNow(true), 100);
    };

    const start = () => {
        if (stopped || pollTimer) return;
        checkNow(true);
        pollTimer = window.setInterval(checkNow, pollInterval);
    };

    window.agapDataSync = {
        subscribe(callback) {
            if (typeof callback !== 'function') return () => {};
            subscribers.add(callback);
            return () => subscribers.delete(callback);
        },
        checkNow: () => checkNow(true),
        stop,
        eventName
    };

    if ('BroadcastChannel' in window) {
        channel = new BroadcastChannel('agap-data-sync');
        channel.addEventListener('message', (event) => acceptRemote(event.data));
    }

    window.addEventListener('storage', (event) => {
        if (event.key !== storageKey || !event.newValue) return;
        try {
            acceptRemote(JSON.parse(event.newValue));
        } catch (error) {
            // Ignore malformed synchronization data.
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) checkNow(true);
    });
    window.addEventListener('focus', () => checkNow(true));
    window.addEventListener('pagehide', stop);
    window.addEventListener('beforeunload', stop);
    document.addEventListener('DOMContentLoaded', start);
})();

// Unified global notification bridge: preserve modern floating toast from notifications.js
if (typeof window.agapNotify !== 'function') {
    window.agapNotify = (message, type = 'info', title = null) => {
        console.warn('Notification system initializing:', message);
    };
}

// Shared, accessible loading placeholders. Keep asynchronous flows intact while
// giving each screen a stable layout during data fetches.
window.agapSkeleton = {
    block(lines = 3, className = '') {
        const widths = ['92%', '76%', '58%', '84%'];
        return `<div class="agap-skeleton ${className}" role="status" aria-label="Loading content">${Array.from({ length: lines }, (_, index) => `<span class="agap-skeleton-line" style="width:${widths[index % widths.length]}"></span>`).join('')}<span class="sr-only">Loading content</span></div>`;
    },
    tableRows(columns, rows = 4) {
        return Array.from({ length: rows }, () => `<tr class="agap-skeleton-row">${Array.from({ length: columns }, (_, index) => `<td><span class="agap-skeleton-line" style="width:${index === columns - 1 ? '54%' : '82%'}"></span></td>`).join('')}</tr>`).join('');
    }
};

document.addEventListener('DOMContentLoaded', () => {
    const requiredFieldSelector = 'input[required]:not([type="hidden"]), textarea[required], select[required]';
    const validationMessage = (field) => {
        if (field.validity.valueMissing) return 'This field is required.';
        if (field.validity.typeMismatch && field.type === 'email') return 'Enter a valid email address.';
        if (field.validity.rangeUnderflow) return `Enter a value of at least ${field.min}.`;
        if (field.validity.rangeOverflow) return `Enter a value no greater than ${field.max}.`;
        return 'Enter a valid value.';
    };
    const fieldLabel = (field) => {
        if (field.id) {
            const label = document.querySelector(`label[for="${CSS.escape(field.id)}"]`);
            if (label) return label;
        }
        return field.closest('.form-group')?.querySelector('label') ?? null;
    };
    const decorateRequiredField = (field) => {
        if (field.dataset.requiredDecorated === 'true') return;
        field.dataset.requiredDecorated = 'true';
        field.setAttribute('aria-required', 'true');
        const label = fieldLabel(field);
        if (label && !label.querySelector('.required-mark')) {
            const marker = document.createElement('span');
            marker.className = 'required-mark';
            marker.setAttribute('aria-hidden', 'true');
            marker.textContent = ' *';
            label.appendChild(marker);
        }
        const feedback = document.createElement('p');
        feedback.className = 'field-validation-message';
        feedback.hidden = true;
        feedback.setAttribute('role', 'alert');
        field.insertAdjacentElement('afterend', feedback);
        const describedBy = field.getAttribute('aria-describedby');
        feedback.id = `validation-${field.id || Math.random().toString(36).slice(2)}`;
        field.setAttribute('aria-describedby', [describedBy, feedback.id].filter(Boolean).join(' '));
        const showValidation = () => {
            if (field.validity.valid) {
                feedback.hidden = true;
                feedback.textContent = '';
                field.classList.remove('is-invalid');
                return;
            }
            feedback.textContent = validationMessage(field);
            feedback.hidden = false;
            field.classList.add('is-invalid');
        };
        field.addEventListener('invalid', showValidation);
        field.addEventListener('blur', showValidation);
        field.addEventListener('input', showValidation);
        field.addEventListener('change', showValidation);
    };
    const decorateRequiredFields = (root = document) => root.querySelectorAll?.(requiredFieldSelector).forEach(decorateRequiredField);
    decorateRequiredFields();
    new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.type === 'attributes' && mutation.target.matches(requiredFieldSelector)) decorateRequiredField(mutation.target);
            mutation.addedNodes.forEach((node) => {
                if (node.nodeType !== Node.ELEMENT_NODE) return;
                if (node.matches?.(requiredFieldSelector)) decorateRequiredField(node);
                decorateRequiredFields(node);
            });
        });
    }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['required'] });

    const sidebar = document.getElementById('appSidebar');
    const collapseBtn = document.getElementById('sidebarCollapseBtn');
    const navToggle = document.querySelector('[data-sidebar-toggle]');
    const storageCollapseKey = 'agap_sidebar_collapsed';

    if (sidebar) {
        // Restore desktop collapsed state
        const savedState = localStorage.getItem(storageCollapseKey);
        const isMediumScreen = window.innerWidth > 900 && window.innerWidth <= 1200;
        const shouldBeCollapsed = savedState === 'true' || (savedState === null && isMediumScreen);

        if (window.innerWidth > 900) {
            if (shouldBeCollapsed) {
                sidebar.classList.add('is-collapsed');
                document.documentElement.classList.add('sidebar-collapsed');
                document.documentElement.classList.remove('sidebar-expanded');
                if (collapseBtn) {
                    collapseBtn.setAttribute('title', 'Expand sidebar');
                    collapseBtn.setAttribute('aria-label', 'Expand sidebar');
                }
            } else {
                sidebar.classList.remove('is-collapsed');
                document.documentElement.classList.remove('sidebar-collapsed');
                document.documentElement.classList.add('sidebar-expanded');
                if (collapseBtn) {
                    collapseBtn.setAttribute('title', 'Collapse sidebar');
                    collapseBtn.setAttribute('aria-label', 'Collapse sidebar');
                }
            }
        }

        const toggleDesktopSidebar = () => {
            const isCurrentlyCollapsed = sidebar.classList.contains('is-collapsed') || document.documentElement.classList.contains('sidebar-collapsed');
            const willCollapse = !isCurrentlyCollapsed;
            sidebar.classList.toggle('is-collapsed', willCollapse);
            document.documentElement.classList.toggle('sidebar-collapsed', willCollapse);
            document.documentElement.classList.toggle('sidebar-expanded', !willCollapse);
            try {
                localStorage.setItem(storageCollapseKey, String(willCollapse));
            } catch (e) {}
            if (collapseBtn) {
                collapseBtn.setAttribute('title', willCollapse ? 'Expand sidebar' : 'Collapse sidebar');
                collapseBtn.setAttribute('aria-label', willCollapse ? 'Expand sidebar' : 'Collapse sidebar');
            }
        };

        const closeMobileSidebar = () => {
            sidebar.classList.remove('is-open');
            navToggle?.setAttribute('aria-expanded', 'false');
            document.querySelector('.sidebar-overlay')?.remove();
        };

        const toggleMobileSidebar = () => {
            const open = sidebar.classList.toggle('is-open');
            navToggle?.setAttribute('aria-expanded', String(open));
            if (open) {
                const overlay = document.createElement('div');
                overlay.className = 'sidebar-overlay';
                overlay.addEventListener('click', closeMobileSidebar);
                document.body.appendChild(overlay);
            } else {
                closeMobileSidebar();
            }
        };

        navToggle?.addEventListener('click', (e) => {
            e.preventDefault();
            if (window.innerWidth <= 900) {
                toggleMobileSidebar();
            } else {
                toggleDesktopSidebar();
            }
        });

        collapseBtn?.addEventListener('click', (e) => {
            e.preventDefault();
            if (window.innerWidth <= 900) {
                closeMobileSidebar();
            } else {
                toggleDesktopSidebar();
            }
        });

        window.addEventListener('resize', () => {
            if (window.innerWidth <= 900) {
                document.documentElement.classList.remove('sidebar-collapsed', 'sidebar-expanded');
                sidebar.classList.remove('is-collapsed');
            } else {
                const currentSaved = localStorage.getItem(storageCollapseKey);
                const isMed = window.innerWidth <= 1200;
                const coll = currentSaved === 'true' || (currentSaved === null && isMed);
                sidebar.classList.toggle('is-collapsed', coll);
                document.documentElement.classList.toggle('sidebar-collapsed', coll);
                document.documentElement.classList.toggle('sidebar-expanded', !coll);
                if (collapseBtn) {
                    collapseBtn.setAttribute('title', coll ? 'Expand sidebar' : 'Collapse sidebar');
                    collapseBtn.setAttribute('aria-label', coll ? 'Expand sidebar' : 'Collapse sidebar');
                }
                closeMobileSidebar();
            }
        });

        sidebar.querySelectorAll('[data-nav-group]').forEach((group) => {
            const key = `agap-nav-group-${group.dataset.navGroup}`;
            const saved = localStorage.getItem(key);
            if (saved !== null) group.open = saved === 'true';
            group.addEventListener('toggle', () => localStorage.setItem(key, String(group.open)));
        });

        sidebar.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 900) {
                    closeMobileSidebar();
                }
            });
        });
    }

    const recentlyDisplayed = new Set();
    const showMessagePopup = (element) => {
        if (!(element instanceof Element) || element.closest('.field-validation-message, .agap-field-error, .agap-toast, #agapToastContainer') || element.hidden || !element.getClientRects().length) return;
        const message = element.textContent.trim();
        if (!message || recentlyDisplayed.has(`${element.id}:${message}`)) return;
        const targetEl = element.closest('[role="alert"], [role="status"]') || element;
        // Hide the original static banner to prevent layout shift
        if (targetEl.id && !targetEl.classList.contains('modal-alert') && !targetEl.classList.contains('auth-message')) {
            targetEl.style.display = 'none';
        }
        recentlyDisplayed.add(`${element.id}:${message}`);
        const type = element.classList.contains('error') || element.classList.contains('alert-danger') ? 'error' : ((element.classList.contains('success') || element.classList.contains('alert-success')) ? 'success' : 'info');
        if (window.agapNotify) {
            window.agapNotify(message, type, type === 'error' ? 'Attention Needed' : 'Notification');
        }
    };
    new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            const element = mutation.target.nodeType === Node.ELEMENT_NODE ? mutation.target : mutation.target.parentElement;
            const messageElement = element?.closest?.('[role="alert"], [role="status"]');
            if (messageElement) showMessagePopup(messageElement);
        });
    }).observe(document.body, { childList: true, characterData: true, subtree: true });

    if (!document.getElementById('appSidebar')) return;
    const seenKey = 'agap-seen-notification-ids';
    const seen = new Set(JSON.parse(sessionStorage.getItem(seenKey) || '[]'));
    let notifPollTimer = null;
    const stopNotifPolling = () => {
        if (notifPollTimer !== null) {
            window.clearInterval(notifPollTimer);
            notifPollTimer = null;
        }
    };
    const checkWorkflowNotifications = async () => {
        if (window.agapSigningOut || document.hidden) return;
        try {
            // This endpoint is idempotent: it only creates each due-soon/overdue alert once.
            if ([1, 2].includes(Number(document.querySelector('meta[name="agap-role-id"]')?.content))) {
                const alertRes = await fetch('../../../backend/api/hearings/deadline-alerts.php', { method: 'POST' }).catch(() => null);
                if (alertRes && [401, 403, 419].includes(alertRes.status)) {
                    stopNotifPolling();
                    return;
                }
            }
            const response = await fetch('../../../backend/api/notifications/list.php');
            if ([401, 403, 419].includes(response.status)) {
                stopNotifPolling();
                return;
            }
            const result = await response.json();
            if (!response.ok || result.success === false) return;
            if (typeof window.updateNavNotificationBadge === 'function') {
                window.updateNavNotificationBadge(Number(result.unread_count || 0));
            }
            const unseen = (result.data || []).filter((item) => !Number(item.is_read) && !seen.has(String(item.notification_id)));
            unseen.slice(0, 3).forEach((item, index) => {
                seen.add(String(item.notification_id));
                window.agapNotify(item.message, item.severity || 'info', item.title || 'New notification');
                // A displayed workflow alert has been acknowledged. Persisting this
                // server-side keeps it from appearing again after a later login.
                fetch('../../../backend/api/notifications/read.php', {
                    method: 'POST',
                    body: new URLSearchParams({ notification_id: String(item.notification_id) })
                }).then((markReadResponse) => {
                    if (markReadResponse.ok && typeof window.updateNavNotificationBadge === 'function') {
                        const unreadAfterDisplay = Math.max(0, Number(result.unread_count || 0) - index - 1);
                        window.updateNavNotificationBadge(unreadAfterDisplay);
                    }
                }).catch(() => null);
            });
            sessionStorage.setItem(seenKey, JSON.stringify([...seen].slice(-100)));
        } catch (_) { /* Notification polling must not interrupt page work. */ }
    };
    checkWorkflowNotifications();
    notifPollTimer = window.setInterval(checkWorkflowNotifications, 45000);
    window.addEventListener('pagehide', stopNotifPolling);
});

// Shared Form Input & Scheduling Validation Enforcer across AGAP flows
(() => {
    const localIsoDate = () => {
        const d = new Date();
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    };

    const isPhoneInput = (el) => {
        if (!(el instanceof HTMLInputElement)) return false;
        if (['hidden', 'checkbox', 'radio', 'file', 'submit', 'button'].includes(el.type)) return false;
        if (el.id === 'walkinLookupQuery' || el.id === 'trackerVerifier') return false;
        if (el.type === 'tel' || el.dataset.validate === 'phone') return true;
        const id = (el.id || '').toLowerCase();
        const name = (el.name || '').toLowerCase();
        if (id.includes('preferredcontact') || id.includes('unknown') || name.includes('preferred') || name.includes('unknown')) return false;
        return (
            id === 'modalcontactno' ||
            id === 'modalguardiancontact' ||
            id === 'contactno' ||
            id === 'contact_no' ||
            id === 'schedcomplainantcontact' ||
            id === 'schedinviteecontact' ||
            name.includes('contact_no') ||
            name === 'contact_number' ||
            name.includes('guardian_contact')
        );
    };

    const isValidPhPhone = (raw) => {
        const val = String(raw || '').trim();
        if (val === '') return true;
        if (!/^\+?[0-9 .()\-]+$/.test(val)) return false;
        let digits = val.replace(/\D+/g, '');
        if (val.startsWith('+')) {
            if (!digits.startsWith('63')) return false;
            digits = digits.slice(2);
        } else if (digits.startsWith('63')) {
            digits = digits.slice(2);
        } else if (digits.startsWith('0')) {
            digits = digits.slice(1);
        }
        return /^9[0-9]{9}$/.test(digits) || /^(?:2[0-9]{8}|[3-8][0-9]{8,9})$/.test(digits);
    };

    const FUTURE_SCHED_DATE_IDS = new Set([
        'schedDate',
        'p2Date',
        'summonMediationDate',
        'modalPaanyayaDate',
        'escalationMedDate',
        'issueStageDate',
        'inlineSettleDueDate'
    ]);

    const FUTURE_SCHED_DATE_NAMES = new Set([
        'hearing_date',
        'mediation_date',
        'invitation_date',
        'scheduled_date'
    ]);

    const isFutureScheduleDateInput = (el) =>
        el instanceof HTMLInputElement &&
        el.type === 'date' &&
        (FUTURE_SCHED_DATE_IDS.has(el.id) || FUTURE_SCHED_DATE_NAMES.has(el.name));

    const applyConstraintsToRoot = (root = document) => {
        const today = localIsoDate();
        root.querySelectorAll('input').forEach((el) => {
            if (isPhoneInput(el)) {
                if (el.type === 'text') el.type = 'tel';
                if (!el.getAttribute('inputmode')) el.setAttribute('inputmode', 'tel');
                if (!el.getAttribute('maxlength') || Number(el.getAttribute('maxlength')) > 20) {
                    el.setAttribute('maxlength', '20');
                }
            } else if (isFutureScheduleDateInput(el)) {
                if (!el.min || el.min < today) el.min = today;
            } else if (el.type === 'date' && (el.id === 'modalBirthdate' || el.name === 'birth_date')) {
                if (!el.max || el.max > today) el.max = today;
                if (!el.min) el.min = '1900-01-01';
            }
        });
    };

    document.addEventListener('input', (e) => {
        const el = e.target;
        if (!(el instanceof HTMLInputElement)) return;

        if (isPhoneInput(el)) {
            const cleaned = el.value.replace(/[^0-9+() .-]/g, '');
            if (cleaned !== el.value) {
                const pos = el.selectionStart || cleaned.length;
                el.value = cleaned;
                try { el.setSelectionRange(pos - 1, pos - 1); } catch (_) {}
            }
            if (cleaned.trim() !== '' && !isValidPhPhone(cleaned)) {
                el.setCustomValidity('Enter a valid Philippine mobile (09XXXXXXXXX / +639XXXXXXXXX) or landline number.');
            } else {
                el.setCustomValidity('');
            }
            return;
        }

        if (el.type === 'number' && (el.id === 'modalAge' || el.name === 'age')) {
            const digitsOnly = el.value.replace(/\D+/g, '');
            if (digitsOnly !== el.value) el.value = digitsOnly;
            const num = Number(el.value);
            if (el.value !== '' && (num < 0 || num > 130)) {
                el.setCustomValidity('Age must be between 0 and 130.');
            } else {
                el.setCustomValidity('');
            }
        }
    }, true);

    document.addEventListener('change', (e) => {
        const el = e.target;
        if (!(el instanceof HTMLInputElement)) return;

        if (isFutureScheduleDateInput(el) && el.value) {
            const today = localIsoDate();
            if (el.value < today) {
                el.setCustomValidity('Scheduled date cannot be in the past.');
                el.reportValidity();
                return;
            }
            if (el.id !== 'inlineSettleDueDate') {
                const parts = el.value.split('-').map(Number);
                const dow = new Date(parts[0], parts[1] - 1, parts[2]).getDay();
                if (dow === 0 || dow === 6) {
                    el.setCustomValidity('Schedules must be set on working days (Monday to Friday).');
                    el.reportValidity();
                    return;
                }
            }
            el.setCustomValidity('');
        }
    }, true);

    document.addEventListener('DOMContentLoaded', () => applyConstraintsToRoot(document));
})();
