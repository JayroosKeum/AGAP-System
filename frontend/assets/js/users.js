let users = [];

const esc = (value) => {
    const node = document.createElement('div');
    node.textContent = value ?? '';
    return node.innerHTML;
};

const showMessage = (message, success = false) => {
    const node = document.getElementById('userMessage');
    node.textContent = message;
    node.className = message ? `user-message ${success ? 'success' : 'error'}` : '';
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
});

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
                <td class="action-buttons">
                    <button type="button" onclick="editUser(${Number(user.user_id)})">Edit</button>
                    <button type="button" class="delete" onclick="deleteUser(${Number(user.user_id)})">Delete</button>
                </td>
            </tr>`).join('') || '<tr><td colspan="6">No users found.</td></tr>';
    } catch (error) {
        document.getElementById('userTable').innerHTML = `<tr><td colspan="6">${esc(error.message)}</td></tr>`;
    }
}

function openUserModal() {
    const form = document.getElementById('userForm');
    form.reset();
    form.querySelectorAll('input, select').forEach((field) => field.setCustomValidity(''));
    form.action = '../../../backend/api/users/create.php';
    document.getElementById('userModalTitle').textContent = 'Add User';
    document.getElementById('password').required = true;
    document.getElementById('passwordHint').textContent = '(required; 12+ characters with uppercase, lowercase, and a number)';
    showMessage('');
    document.getElementById('userModal').style.display = 'flex';
}

function closeUserModal() {
    document.getElementById('userModal').style.display = 'none';
}

function editUser(id) {
    const user = users.find((item) => Number(item.user_id) === Number(id));
    if (!user) return;
    openUserModal();
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
    document.getElementById('passwordHint').textContent = '(leave blank to keep current password)';
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
        if (field.name === 'contact_no' && field.value && !validPhilippinePhone(field.value)) {
            field.setCustomValidity('Please enter a valid Philippine mobile or telephone number.');
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
        alert(error.message);
    }
}
