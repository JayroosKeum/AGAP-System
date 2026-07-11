let users = [];
const roles = {
    1: 'Administrator',
    2: 'Lupon Clerk',
    3: 'Lupon Member',
    4: 'Summons Server'
};

document.addEventListener('DOMContentLoaded', loadUsers);

function esc(v) {
    const d = document.createElement('div');
    d.textContent = v ?? '';
    return d.innerHTML;
}

function loadUsers() {
    fetch('../../../backend/api/users/list.php')
        .then(r => r.json())
        .then(data => {
            users = data;
            document.getElementById('userTable').innerHTML = data.map(u => `
                <tr>
                    <td>${esc(u.first_name)} ${esc(u.last_name)}</td>
                    <td>${esc(u.username)}</td>
                    <td>${esc(u.email)}</td>
                    <td><span class="role">${roles[u.role_id]}</span></td>
                    <td class="action-buttons">
                        <button onclick="editUser(${u.user_id})">Edit</button>
                        <button class="delete" onclick="deleteUser(${u.user_id})">Delete</button>
                    </td>
                </tr>
            `).join('') || '<tr><td colspan="5">No users found.</td></tr>';
        });
}

function openUserModal() {
    document.getElementById('userForm').reset();
    document.getElementById('userForm').action = '../../../backend/api/users/create.php';
    document.getElementById('userModalTitle').textContent = 'Add User';
    document.getElementById('password').required = true;
    document.getElementById('passwordHint').textContent = '(required)';
    document.getElementById('userModal').style.display = 'flex';
}

function closeUserModal() {
    document.getElementById('userModal').style.display = 'none';
}

function editUser(id) {
    const u = users.find(x => x.user_id == id);
    openUserModal();
    document.getElementById('userForm').action = '../../../backend/api/users/update.php';
    document.getElementById('userModalTitle').textContent = 'Edit User';
    document.getElementById('userId').value = u.user_id;
    document.getElementById('firstName').value = u.first_name;
    document.getElementById('lastName').value = u.last_name;
    document.getElementById('username').value = u.username;
    document.getElementById('email').value = u.email;
    document.getElementById('roleId').value = u.role_id;
    document.getElementById('password').required = false;
    document.getElementById('passwordHint').textContent = '(leave blank to keep current password)';
}

function deleteUser(id) {
    if (confirm('Delete this user account?')) {
        location.href = '../../../backend/api/users/delete.php?id=' + id;
    }
}