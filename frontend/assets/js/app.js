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
    const mobileToggle = document.querySelector('[data-sidebar-toggle]');
    const collapseToggle = document.querySelector('[data-sidebar-collapse]');
    if (!sidebar) return;

    const closeMobileSidebar = () => {
        sidebar.classList.remove('is-open');
        mobileToggle?.setAttribute('aria-expanded', 'false');
        document.querySelector('.sidebar-overlay')?.remove();
    };
    const setCollapsed = (collapsed) => {
        sidebar.classList.toggle('is-collapsed', collapsed);
        collapseToggle?.setAttribute('aria-expanded', String(!collapsed));
        collapseToggle?.setAttribute('aria-label', collapsed ? 'Expand navigation' : 'Collapse navigation');
        if (collapseToggle) collapseToggle.textContent = collapsed ? '›' : '‹';
        localStorage.setItem('agap-sidebar-collapsed', String(collapsed));
    };

    setCollapsed(localStorage.getItem('agap-sidebar-collapsed') === 'true');
    collapseToggle?.addEventListener('click', () => setCollapsed(!sidebar.classList.contains('is-collapsed')));

    mobileToggle?.addEventListener('click', () => {
        const open = sidebar.classList.toggle('is-open');
        mobileToggle.setAttribute('aria-expanded', String(open));
        if (open) {
            const overlay = document.createElement('div');
            overlay.className = 'sidebar-overlay';
            overlay.addEventListener('click', closeMobileSidebar);
            document.body.appendChild(overlay);
        } else {
            closeMobileSidebar();
        }
    });

    sidebar.querySelectorAll('[data-nav-group]').forEach((group) => {
        const key = `agap-nav-group-${group.dataset.navGroup}`;
        const saved = localStorage.getItem(key);
        if (saved !== null) group.open = saved === 'true';
        group.addEventListener('toggle', () => localStorage.setItem(key, String(group.open)));
    });
    sidebar.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeMobileSidebar));
});
