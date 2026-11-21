// Wrapped, searchable category suggestions. Native datalists truncate long legal labels.
(() => {
    const normalize = value => String(value || '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
    const synonyms = {
        debt: ['utang', 'unpaid', 'loan'], noise: ['ingay', 'nuisance', 'disturbance'],
        boundary: ['hangganan', 'bakod'], slander: ['paninirang puri', 'defamation'],
        rental: ['upa', 'tenant', 'lease'], injury: ['physical', 'pananakit'],
        threats: ['banta', 'threat'], animals: ['dog', 'aso', 'pet']
    };
    const matches = (label, query) => {
        const text = normalize(label), term = normalize(query);
        if (!term) return true;
        if (text.includes(term)) return true;
        return Object.entries(synonyms).some(([key, values]) => {
            const words = [key, ...values];
            return words.some(word => normalize(word) === term) && words.some(word => text.includes(normalize(word)));
        });
    };
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('input[list]').forEach(input => {
            if (!/categoryinput/i.test(input.id)) return;
            const list = document.getElementById(input.getAttribute('list'));
            if (!list) return;
            const labels = [...list.querySelectorAll('option')].map(option => option.value);
            input.removeAttribute('list');
            input.maxLength = 255;
            input.setAttribute('role', 'combobox');
            input.setAttribute('aria-autocomplete', 'list');
            input.setAttribute('aria-expanded', 'false');
            const results = document.createElement('div');
            results.id = `${input.id}Suggestions`;
            results.className = 'category-suggestions';
            results.setAttribute('role', 'listbox');
            results.hidden = true;
            input.setAttribute('aria-controls', results.id);
            input.insertAdjacentElement('afterend', results);
            let active = -1;
            const close = () => { results.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); };
            const choose = label => {
                input.value = label;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                close();
            };
            const render = () => {
                results.replaceChildren(); active = -1;
                labels.filter(label => matches(label, input.value)).forEach((label, index) => {
                    const option = document.createElement('div');
                    option.id = `${results.id}-${index}`;
                    option.setAttribute('role', 'option');
                    option.textContent = label;
                    option.addEventListener('mousedown', event => { event.preventDefault(); choose(label); });
                    results.appendChild(option);
                });
                results.hidden = !results.children.length;
                input.setAttribute('aria-expanded', String(!results.hidden));
            };
            input.addEventListener('input', render);
            input.addEventListener('focus', render);
            input.addEventListener('blur', close);
            input.addEventListener('keydown', event => {
                if (event.key === 'Escape') { close(); return; }
                if (!['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key) || results.hidden) return;
                if (event.key === 'Enter') {
                    if (active >= 0) { event.preventDefault(); choose(results.children[active].textContent); }
                    return;
                }
                event.preventDefault();
                active = (active + (event.key === 'ArrowDown' ? 1 : -1) + results.children.length) % results.children.length;
                [...results.children].forEach((option, index) => option.setAttribute('aria-selected', String(index === active)));
                input.setAttribute('aria-activedescendant', results.children[active].id);
                results.children[active].scrollIntoView({ block: 'nearest' });
            });
        });
    });
})();
