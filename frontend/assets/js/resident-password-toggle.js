document.querySelectorAll('.resident-password-toggle').forEach(button => {
    const field = document.getElementById(button.getAttribute('aria-controls'));
    if (!field) return;

    const showLabel = button.getAttribute('aria-label');
    button.addEventListener('click', () => {
        const reveal = field.type === 'password';
        field.type = reveal ? 'text' : 'password';
        button.textContent = reveal ? 'Hide' : 'Show';
        button.setAttribute('aria-label', reveal ? showLabel.replace(/^Show /, 'Hide ') : showLabel);
    });
});
