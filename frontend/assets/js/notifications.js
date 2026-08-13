document.addEventListener('DOMContentLoaded', loadNotifications);

async function loadNotifications() {
    const list = document.getElementById('notificationList');
    try {
        const result = await requestNotifications('../../../backend/api/notifications/list.php');
        document.getElementById('notificationSummary').textContent = result.unread_count ? `${result.unread_count} unread notification${result.unread_count === 1 ? '' : 's'}.` : 'You are all caught up.';
        list.replaceChildren();
        if (!result.data.length) { list.textContent = 'No notifications yet.'; return; }
        result.data.forEach(item => {
            const article = document.createElement('article'); article.className = `notification-item${Number(item.is_read) ? '' : ' unread'}`;
            const heading = document.createElement('h2'); heading.textContent = item.title;
            const message = document.createElement('p'); message.textContent = item.message;
            const meta = document.createElement('time'); meta.dateTime = item.created_at; meta.textContent = new Date(item.created_at.replace(' ', 'T')).toLocaleString();
            article.append(heading, message, meta);
            if (!Number(item.is_read)) { const button = document.createElement('button'); button.type = 'button'; button.className = 'btn-create'; button.textContent = 'Mark as read'; button.addEventListener('click', () => markRead(item.notification_id)); article.appendChild(button); }
            list.appendChild(article);
        });
    } catch (error) { list.textContent = ''; setNotificationMessage(error.message, false); }
}

async function markRead(notificationId) {
    try { const result = await requestNotifications('../../../backend/api/notifications/read.php', { method: 'POST', body: new URLSearchParams({ notification_id: notificationId }) }); setNotificationMessage(result.message, true); loadNotifications(); }
    catch (error) { setNotificationMessage(error.message, false); }
}

async function requestNotifications(url, options = {}) { const response = await fetch(url, options); const result = await response.json().catch(() => ({ success: false, message: 'Invalid server response.' })); if (!response.ok || result.success === false) throw new Error(result.message || 'Request failed.'); return result; }
function setNotificationMessage(message, success) { const box = document.getElementById('notificationMessage'); box.textContent = message; box.className = message ? `alert ${success ? 'success' : 'error'}` : ''; }
