let users = [];
let userModalReturnFocus = null;

const esc = (value) => {
    const node = document.createElement('div');
    node.textContent = value ?? '';
    return node.innerHTML;
};

const showMessage = (message, success = false) => {
    if (window.agapNotify && message) {
        window.agapNotify(message, success ? 'success' : 'error', 'User Management');
    }
    const node = document.getElementById('userMessage');
    if (node) {
        node.textContent = '';
        node.className = '';
        node.style.display = 'none';
    }
};

const request = async (url, options = {}) => {
    const response = await fetch(url, options);
    const data = await response.json().catch(() => ({success: false, message: 'Invalid server response.'}));
    if (!response.ok || data.success === false) throw new Error(data.message || 'Request failed.');
    return data;
};

document.addEventListener('DOMContentLoaded', () => {
    loadUsers();
    const form = document.getElementById('userForm');
    form.addEventListener('submit', saveUser);
    form.addEventListener('input', clearFieldValidationMessage);
    form.addEventListener('change', clearFieldValidationMessage);
    document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
        toggle.addEventListener('click', () => togglePasswordVisibility(toggle));
    });
    document.getElementById('password')?.addEventListener('input', updatePasswordRequirements);
    document.getElementById('passwordConfirmation')?.addEventListener('input', updatePasswordRequirements);
    document.getElementById('userModal')?.addEventListener('click', (event) => {
        if (event.target.id === 'userModal') closeUserModal();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && document.getElementById('userModal')?.style.display === 'flex') closeUserModal();
    });
});

function togglePasswordVisibility(toggle) {
    const input = document.getElementById(toggle.dataset.passwordToggle);
    if (!input) return;
    const willShow = input.type === 'password';
    input.type = willShow ? 'text' : 'password';
    toggle.textContent = willShow ? 'Hide' : 'Show';
    toggle.setAttribute('aria-pressed', String(willShow));
    toggle.setAttribute('aria-label', `${willShow ? 'Hide' : 'Show'} ${input.id === 'password' ? 'password' : 'confirmation password'}`);
    input.focus({preventScroll: true});
}

function updatePasswordRequirements() {
    const password = document.getElementById('password');
    if (!password) return;
    const rules = {
        length: password.value.length >= 12,
        lowercase: /[a-z]/.test(password.value),
        uppercase: /[A-Z]/.test(password.value),
        number: /\d/.test(password.value)
    };
    document.querySelectorAll('[data-password-rule]').forEach((item) => {
        item.classList.toggle('is-met', Boolean(rules[item.dataset.passwordRule]));
    });
}

function clearFieldValidationMessage(event) {
    if (event.target instanceof HTMLInputElement || event.target instanceof HTMLSelectElement) {
        event.target.setCustomValidity('');
    }
}

async function loadUsers() {
    try {
        const data = await request('../../../backend/api/users/list.php');
        users = data.users;
        document.getElementById('userTable').innerHTML = users.map((user) => `
            <tr>
                <td>${esc(user.first_name)} ${esc(user.last_name)}</td>
                <td>${esc(user.username)}</td>
                <td>${esc(user.email)}</td>
                <td>${esc(user.contact_no || '—')}</td>
                <td><span class="role">${esc(user.role_name)}</span></td>
                <td>${esc(user.status || 'Active')}</td>
                <td class="action-buttons">
                    ${user.status === 'Inactive' ? `<button type="button" onclick="resendVerificationEmail(${Number(user.user_id)})">Resend verification</button>` : ''}
                    <button type="button" onclick="editUser(${Number(user.user_id)})">Edit</button>
                    <button type="button" class="delete" onclick="deleteUser(${Number(user.user_id)})">Delete</button>
                </td>
            </tr>`).join('') || '<tr><td colspan="7">No users found.</td></tr>';
    } catch (error) {
        document.getElementById('userTable').innerHTML = `<tr><td colspan="7">${esc(error.message)}</td></tr>`;
    }
}

function openUserModal() {
    const form = document.getElementById('userForm');
    form.reset();
    form.querySelectorAll('input, select').forEach((field) => field.setCustomValidity(''));
    form.querySelectorAll('.is-invalid').forEach((field) => field.classList.remove('is-invalid'));
    form.querySelectorAll('.field-validation-message').forEach((message) => {
        message.hidden = true;
        message.textContent = '';
    });
    form.action = '../../../backend/api/users/create.php';
    document.getElementById('userModalTitle').textContent = 'Add User';
    setAdministratorRoleOption(false);
    document.getElementById('password').required = false;
    document.getElementById('password').value = '';
    document.getElementById('passwordConfirmation').required = false;
    document.getElementById('passwordConfirmation').value = '';
    document.getElementById('passwordGroup').style.display = '';
    document.getElementById('passwordHint').textContent = '(optional; leave blank to send verification email)';
    showMessage('');
    updatePasswordRequirements();
    userModalReturnFocus = document.activeElement;
    const modal = document.getElementById('userModal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    window.setTimeout(() => document.getElementById('firstName')?.focus(), 0);
}

function closeUserModal() {
    const modal = document.getElementById('userModal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
    userModalReturnFocus?.focus?.();
}

function editUser(id) {
    const user = users.find((item) => Number(item.user_id) === Number(id));
    if (!user) return;
    openUserModal();
    // Keep the current Administrator role selectable only while editing an
    // existing administrator account; it is never available for new users.
    setAdministratorRoleOption(Number(user.role_id) === 1);
    document.getElementById('userForm').action = '../../../backend/api/users/update.php';
    document.getElementById('userModalTitle').textContent = 'Edit User';
    document.getElementById('userId').value = user.user_id;
    document.getElementById('firstName').value = user.first_name;
    document.getElementById('lastName').value = user.last_name;
    document.getElementById('username').value = user.username;
    document.getElementById('email').value = user.email;
    document.getElementById('contactNo').value = user.contact_no || '';
    document.getElementById('roleId').value = user.role_id;
    document.getElementById('password').required = false;
    document.getElementById('password').value = '';
    document.getElementById('passwordConfirmation').required = false;
    document.getElementById('passwordConfirmation').value = '';
    document.getElementById('passwordGroup').style.display = '';
    document.getElementById('passwordHint').textContent = '(leave blank to keep current password)';
    updatePasswordRequirements();
}

function setAdministratorRoleOption(allowAdministrator) {
    const roleSelect = document.getElementById('roleId');
    const administratorOption = roleSelect?.querySelector('option[value="1"]');
    if (!roleSelect || !administratorOption) return;

    administratorOption.hidden = !allowAdministrator;
    administratorOption.disabled = !allowAdministrator;
    if (!allowAdministrator && roleSelect.value === '1') {
        roleSelect.value = '2';
    }
}

async function resendVerificationEmail(id) {
    if (!confirm('Send a new email-verification link? Existing links will no longer work.')) return;
    try {
        const form = new FormData();
        form.append('user_id', id);
        const result = await request('../../../backend/api/users/resend-verification.php', {method: 'POST', body: form});
        window.agapNotify?.(result.message, 'success', 'User Management');
    } catch (error) {
        window.agapNotify?.(error.message, 'error', 'User Management');
    }
}

async function saveUser(event) {
    event.preventDefault();
    const form = event.currentTarget;
    showMessage('');
    for (const field of form.querySelectorAll('input:not([type="hidden"]):not([type="password"])')) {
        field.value = field.value.trim();
        field.setCustomValidity('');
        if (['first_name', 'last_name'].includes(field.name)
            && !/^[\p{L}\p{M}]+(?:[ '-][\p{L}\p{M}]+)*$/u.test(field.value)) {
            field.setCustomValidity('Please enter a valid name using letters, spaces, hyphens, or apostrophes.');
        }
        if (field.name === 'contact_no' && !validPhilippinePhone(field.value)) {
            field.setCustomValidity('Enter a valid Philippine mobile or telephone number, such as 09171234567 or +639171234567.');
        }
    }
    const password = document.getElementById('password');
    const passwordConfirmation = document.getElementById('passwordConfirmation');
    updatePasswordRequirements();
    if (password.value !== '' || passwordConfirmation.value !== '') {
        if (password.value === '') {
            password.setCustomValidity('Enter a password before confirming it.');
        }
        if (password.value !== passwordConfirmation.value) {
            passwordConfirmation.setCustomValidity('Password confirmation must match the password.');
        }
    }
    if (!form.reportValidity()) return;
    try {
        const result = await request(form.action, {method: 'POST', body: new FormData(form)});
        showMessage(result.message, true);
        window.agapNotify?.(result.message, 'success', 'User Management');
        closeUserModal();
        await loadUsers();
    } catch (error) {
        showMessage(error.message);
        window.agapNotify?.(error.message, 'error', 'User Management');
    }
}

function validPhilippinePhone(value) {
    if (!/^\+?[0-9 .()\-]+$/.test(value)) return false;
    let digits = value.replace(/\D/g, '');
    if (value.startsWith('+') || digits.startsWith('63')) {
        if (!digits.startsWith('63')) return false;
        digits = digits.slice(2);
    } else if (digits.startsWith('0')) {
        digits = digits.slice(1);
    }
    return /^9\d{9}$/.test(digits) || /^(?:2\d{8}|[3-8]\d{8,9})$/.test(digits);
}

async function deleteUser(id) {
    if (!confirm('Delete this user account?')) return;
    try {
        const form = new FormData();
        form.append('user_id', id);
        const result = await request('../../../backend/api/users/delete.php', {method: 'POST', body: form});
        window.agapNotify?.(result.message, 'success', 'User Management');
        await loadUsers();
    } catch (error) {
        window.agapNotify?.(error.message, 'error', 'User Management');
    }
}
