(() => {
    'use strict';

    const endpoint = '../../../backend/api/liso/duty.php';
    const status = document.getElementById('lisoDutyStatus');
    const startButton = document.getElementById('btnLisoDutyStart');
    const endButton = document.getElementById('btnLisoDutyEnd');
    const activeList = document.getElementById('activeLisoList');

    const csrfToken = () => window.agapCsrfToken || document.querySelector('meta[name="agap-csrf-token"]')?.content || '';
    const showMessage = (message, type = 'success') => {
        if (window.agapNotify) {
            window.agapNotify(message, type);
        }
    };
    const formatTime = (value) => new Date(String(value).replace(' ', 'T')).toLocaleTimeString('en-PH', {
        hour: 'numeric', minute: '2-digit'
    });

    const render = (payload) => {
        const active = payload.current_session;
        if (status) {
            status.textContent = active
                ? `You are on LISO duty since ${formatTime(active.started_at)}. Clerks and administrators can assign walk-in screenings to you.`
                : 'Start duty when you are available to screen walk-in intakes.';
        }
        if (startButton) startButton.hidden = Boolean(active);
        if (endButton) endButton.hidden = !active;

        if (activeList) {
            activeList.replaceChildren();
            const members = payload.active_members || [];
            if (!members.length) {
                activeList.textContent = 'No LISO is currently on duty.';
                return;
            }
            members.forEach((member) => {
                const item = document.createElement('span');
                item.className = 'active-liso-person';
                item.textContent = `${member.first_name} ${member.last_name} · since ${formatTime(member.started_at)}`;
                activeList.append(item);
            });
        }
    };

    const load = async () => {
        try {
            const response = await fetch(`${endpoint}?action=status`, { credentials: 'same-origin' });
            const payload = await response.json();
            if (!payload.success) throw new Error(payload.message || 'Unable to load LISO duty status.');
            render(payload);
        } catch (error) {
            if (status) status.textContent = 'Unable to load LISO duty status.';
            if (activeList) activeList.textContent = 'Unable to load available LISOs.';
        }
    };

    const updateDuty = async (action) => {
        const button = action === 'start' ? startButton : endButton;
        if (button) button.disabled = true;
        try {
            const body = new URLSearchParams({ action });
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-CSRF-Token': csrfToken()
                },
                credentials: 'same-origin',
                body
            });
            const result = await response.json();
            if (!result.success) throw new Error(result.message || 'Unable to update LISO duty.');
            showMessage(result.message);
            await load();
        } catch (error) {
            showMessage(error.message || 'Unable to update LISO duty.', 'error');
        } finally {
            if (button) button.disabled = false;
        }
    };

    startButton?.addEventListener('click', () => updateDuty('start'));
    endButton?.addEventListener('click', () => updateDuty('end'));
    load();
})();
