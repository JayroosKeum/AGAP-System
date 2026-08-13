document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('appSidebar');
    const toggle = document.querySelector('[data-sidebar-toggle]');
    if (!sidebar || !toggle) return;

    const close = () => {
        sidebar.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        document.querySelector('.sidebar-overlay')?.remove();
    };
    toggle.addEventListener('click', () => {
        const open = sidebar.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', String(open));
        if (open) {
            const overlay = document.createElement('div');
            overlay.className = 'sidebar-overlay';
            overlay.addEventListener('click', close);
            document.body.appendChild(overlay);
        } else close();
    });
    sidebar.querySelectorAll('a').forEach(link => link.addEventListener('click', close));
});
