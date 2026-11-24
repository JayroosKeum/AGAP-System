// Wrapped, searchable category suggestions with Civil/Criminal badge cues & Other/Custom fallback.
(() => {
    const normalize = value => String(value || '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
    const synonyms = {
        debt: ['utang', 'unpaid', 'loan', 'pagbabayad', 'koleksyon'],
        noise: ['ingay', 'nuisance', 'disturbance', 'videoke', 'karaoke', 'pambubulabog'],
        boundary: ['hangganan', 'bakod', 'lupa', 'encroach'],
        slander: ['paninirang puri', 'defamation', 'tsismis', 'chismis', 'libel'],
        rental: ['upa', 'tenant', 'lease', 'paupahan', 'landlord'],
        injury: ['physical', 'pananakit', 'slight', 'sugat', 'bugbog'],
        threats: ['banta', 'threat', 'pagbabanta', 'pananakot'],
        animals: ['dog', 'aso', 'pet', 'hayop', 'kagat'],
        theft: ['nakaw', 'pagnanakaw', 'stolen', 'tinangay'],
        fraud: ['estafa', 'scam', 'swindling', 'panlilinlang'],
        trespass: ['pasok', 'bakuran', 'bahay', 'pumasok'],
        mischief: ['paninira', 'vandalism', 'ari arian', 'nasira'],
        harassment: ['catcalling', 'whistling', 'safe spaces', 'bawal bastos', 'vexation', 'paninita'],
        check: ['tseke', 'bouncing', 'bp 22', 'talbog']
    };

    const matches = (label, query) => {
        const text = normalize(label);
        const term = normalize(query);
        if (!term) return true;
        if (text.includes(term)) return true;
        return Object.entries(synonyms).some(([key, values]) => {
            const words = [key, ...values];
            return words.some(word => {
                const nw = normalize(word);
                return nw === term || (term.length >= 3 && nw.includes(term));
            }) && words.some(word => text.includes(normalize(word)));
        });
    };

    const isOthersLabel = label => /\b(others|iba pa)\b/i.test(String(label || ''));

    const syncSegmentedGroupForSelect = (selectEl) => {
        if (!selectEl || !selectEl.id) return;
        const group = document.querySelector(`.agap-segmented-group[data-seg-target="${selectEl.id}"]`);
        if (!group) return;
        const val = selectEl.value;
        group.querySelectorAll('.agap-seg-btn').forEach(btn => {
            const isMatch = btn.getAttribute('data-seg-value') === val;
            btn.classList.toggle('is-active', isMatch);
            btn.setAttribute('aria-pressed', isMatch ? 'true' : 'false');
        });
    };

    const setCaseTypeSelectValue = (selectEl, newCaseType) => {
        if (!selectEl || !['Civil', 'Criminal'].includes(newCaseType)) return;
        if (selectEl.value !== newCaseType) {
            selectEl.value = newCaseType;
        }
        syncSegmentedGroupForSelect(selectEl);
        selectEl.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const inferCaseTypeFromCategoryText = (label) => {
        const lower = String(label || '').toLowerCase();
        if (!lower) return null;
        if (/\b(others|iba pa)\b/.test(lower)) return 'Civil';
        if (/(slander|defamation|physical injuries|threats|theft|estafa|bouncing checks|malicious mischief|trespass|unjust vexation|coercion|alarms and scandals|grave scandal|safe spaces|catcalling|anti-fencing|imprudence|negligence|abandonment|lasciviousness|alteration of boundary|usurpation|unlawful arrest|false testimony|false certificates|fictitious name|local ordinances|price control|\(art\.|\(arts\.|\(bp 22|\(pd 1612|\(ra 11313)/i.test(lower)) {
            return 'Criminal';
        }
        return 'Civil';
    };

    window.AgapCategoryClassification = {
        syncSegmentedGroupForSelect,
        setCaseTypeSelectValue,
        inferCaseTypeFromCategoryText,
        isOthersLabel
    };

    function enhanceCategoryInput(input) {
        if (!input || input.dataset.categoryPickerEnhanced === '1') return;
        const listId = input.getAttribute('list');
        const list = listId ? document.getElementById(listId) : null;
        if (!list) return;

        input.dataset.categoryPickerEnhanced = '1';
        const items = [...list.querySelectorAll('option')].map(option => {
            const label = String(option.value || '').trim();
            const caseType = option.getAttribute('data-case-type') || inferCaseTypeFromCategoryText(label) || 'Civil';
            const categoryId = option.getAttribute('data-category-id') || '';
            return {
                label,
                caseType,
                categoryId,
                isOther: isOthersLabel(label)
            };
        }).filter(item => item.label !== '');

        const othersItem = items.find(item => item.isOther) || null;

        input.removeAttribute('list');
        input.maxLength = 255;
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');

        if (input.parentElement && getComputedStyle(input.parentElement).position === 'static') {
            input.parentElement.classList.add('category-picker-wrap');
        }

        const results = document.createElement('div');
        results.id = `${input.id}Suggestions`;
        results.className = 'category-suggestions';
        results.setAttribute('role', 'listbox');
        results.hidden = true;
        input.setAttribute('aria-controls', results.id);
        input.insertAdjacentElement('afterend', results);

        let active = -1;
        const close = () => {
            results.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        };

        const choose = item => {
            const label = typeof item === 'string' ? item : item.label;
            input.value = label;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            close();
        };

        const render = () => {
            results.replaceChildren();
            active = -1;
            const query = input.value;
            const filtered = items.filter(item => matches(item.label, query));

            // Always include the "Other / Custom" fallback option at the bottom when filtering
            const hasOtherInFiltered = filtered.some(item => item.isOther);
            if (!hasOtherInFiltered && othersItem) {
                filtered.push(othersItem);
            }

            filtered.forEach((item, index) => {
                const option = document.createElement('div');
                option.id = `${results.id}-${index}`;
                option.setAttribute('role', 'option');
                option.dataset.label = item.label;
                option.dataset.caseType = item.caseType;
                if (item.categoryId) option.dataset.categoryId = item.categoryId;

                const labelSpan = document.createElement('span');
                labelSpan.className = 'cat-opt-label';
                labelSpan.textContent = item.isOther && query.trim() !== '' && !matches(item.label, query)
                    ? `Other / Custom: "${query.trim()}"`
                    : item.label;

                const badge = document.createElement('span');
                if (item.isOther) {
                    badge.className = 'cat-type-pill is-custom';
                    badge.textContent = 'Custom';
                } else {
                    badge.className = `cat-type-pill ${item.caseType === 'Criminal' ? 'is-criminal' : 'is-civil'}`;
                    badge.textContent = item.caseType;
                }

                option.appendChild(labelSpan);
                option.appendChild(badge);
                option.addEventListener('mousedown', event => {
                    event.preventDefault();
                    if (item.isOther && query.trim() !== '' && !matches(item.label, query)) {
                        choose(item.label);
                    } else {
                        choose(item);
                    }
                });
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
                if (active >= 0) {
                    event.preventDefault();
                    const opt = results.children[active];
                    choose(opt.dataset.label || opt.textContent);
                }
                return;
            }
            event.preventDefault();
            active = (active + (event.key === 'ArrowDown' ? 1 : -1) + results.children.length) % results.children.length;
            [...results.children].forEach((option, index) => option.setAttribute('aria-selected', String(index === active)));
            input.setAttribute('aria-activedescendant', results.children[active].id);
            results.children[active].scrollIntoView({ block: 'nearest' });
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('input[list]').forEach(input => {
            if (!/categoryinput/i.test(input.id) && !input.hasAttribute('data-category-picker')) return;
            enhanceCategoryInput(input);
        });
    });

    window.enhanceAgapCategoryInput = enhanceCategoryInput;
})();
