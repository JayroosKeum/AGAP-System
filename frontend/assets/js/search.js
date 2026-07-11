document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('recordsSearchForm');
    const results = document.getElementById('searchResults');
    const summary = document.getElementById('resultSummary');

    form.addEventListener('submit', event => {
        event.preventDefault();
        results.innerHTML = '<tr><td colspan="7">Searching records...</td></tr>';
        fetch('../../../backend/api/search/records.php?' + new URLSearchParams(new FormData(form)))
            .then(response => response.ok ? response.json() : Promise.reject())
            .then(renderRows)
            .catch(() => { results.innerHTML = '<tr><td colspan="7">The records could not be loaded. Please try again.</td></tr>'; summary.textContent = ''; });
    });

    document.getElementById('clearSearch').addEventListener('click', () => {
        form.reset();
        results.innerHTML = '<tr><td colspan="7">Use the filters above to search records.</td></tr>';
        summary.textContent = '';
    });

    function renderRows(rows) {
        summary.textContent = `${rows.length} record${rows.length === 1 ? '' : 's'} found`;
        if (!rows.length) { results.innerHTML = '<tr><td colspan="7">No records match your filters.</td></tr>'; return; }
        results.innerHTML = rows.map(row => `<tr><td>${escapeHtml(row.case_number || 'Not docketed')}<small>${escapeHtml(row.case_type || '')}</small></td><td>${escapeHtml(row.complaint_number)}<small>${escapeHtml(row.complaint_title)}</small></td><td>${escapeHtml(row.category_name || 'Uncategorized')}</td><td>${escapeHtml(row.parties || 'No parties linked')}</td><td>${escapeHtml(row.case_status)}</td><td>${escapeHtml(row.incident_date || '')}</td><td>${Number(row.repeat_party_count || 0) > 1 ? `<span class="repeat-flag">Repeat party (${Number(row.repeat_party_count)} records)</span>` : 'No repeat record'}</td></tr>`).join('');
    }

    function escapeHtml(value) { const node = document.createElement('div'); node.textContent = value ?? ''; return node.innerHTML; }
});
