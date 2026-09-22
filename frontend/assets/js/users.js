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
    document.getElementById('userForm').addEventListener('submit', saveUser);
});

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
