document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('recordsSearchForm');
    const results = document.getElementById('complaintTable') || document.getElementById('searchResults');
    const summary = document.getElementById('resultSummary');
    if (!form || !results) return;

    const complaintView = results.id === 'complaintTable';
    const columnCount = complaintView ? 6 : 7;

    form.addEventListener('submit', event => {
        event.preventDefault();
        results.innerHTML = `<tr><td colspan="${columnCount}">Searching records...</td></tr>`;
        fetch('../../../backend/api/search/records.php?' + new URLSearchParams(new FormData(form)))
            .then(async response => {
                const data = await response.json();
                if (!response.ok) throw new Error(data.message || 'The records could not be loaded.');
                return data;
            })
            .then(renderRows)
            .catch(() => {
                results.innerHTML = `<tr><td colspan="${columnCount}">The records could not be loaded. Please try again.</td></tr>`;
                if (summary) summary.textContent = '';
            });
    });

    document.getElementById('clearSearch')?.addEventListener('click', () => {
        form.reset();
        if (complaintView) {
            form.requestSubmit();
        } else {
            results.innerHTML = `<tr><td colspan="${columnCount}">Use the filters above to search records.</td></tr>`;
            if (summary) summary.textContent = '';
        }
    });

    function renderRows(rows) {
        if (!Array.isArray(rows)) throw new Error('Invalid records response.');
        if (summary) summary.textContent = `${rows.length} record${rows.length === 1 ? '' : 's'} found`;
        if (!rows.length) {
            results.innerHTML = `<tr><td colspan="${columnCount}">No records match your filters.</td></tr>`;
            return;
        }
        results.innerHTML = rows.map(row => complaintView ? renderComplaintRow(row) : renderSearchRow(row)).join('');
    }

    function renderComplaintRow(row) {
        const id = Number(row.complaint_id);
        const status = String(row.record_status || row.case_status || '');
        const statusClass = status.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        const complaint = `<strong>${escapeHtml(row.complaint_number || `Complaint #${id}`)}</strong><small>${escapeHtml(row.complaint_title)}</small>`;
        const actions = Number.isInteger(id) && id > 0
            ? `<div class="action-buttons"><button type="button" onclick="window.location.href='complaint-details.php?id=${id}'">View</button><button type="button" onclick="editComplaint(${id})">Edit</button><button type="button" class="delete-button" onclick="deleteComplaint(${id})">Delete</button></div>`
            : '';
        return `<tr><td>${id}</td><td>${escapeHtml(row.case_number || '—')}</td><td>${complaint}${actions}</td><td>${escapeHtml(row.parties || '—')}</td><td><span class="status status-${statusClass}">${escapeHtml(status || '—')}</span></td><td>${escapeHtml(row.incident_date || '—')}</td></tr>`;
    }

    function renderSearchRow(row) {
        return `<tr><td>${escapeHtml(row.case_number || 'Not docketed')}<small>${escapeHtml(row.case_type || '')}</small></td><td>${escapeHtml(row.complaint_number)}<small>${escapeHtml(row.complaint_title)}</small></td><td>${escapeHtml(row.category_name || 'Uncategorized')}</td><td>${escapeHtml(row.parties || 'No parties linked')}</td><td>${escapeHtml(row.record_status || row.case_status || '')}</td><td>${escapeHtml(row.incident_date || '')}</td><td>${Number(row.repeat_party_count || 0) > 1 ? `<span class="repeat-flag">Repeat party (${Number(row.repeat_party_count)} records)</span>` : 'No repeat record'}</td></tr>`;
    }

    function escapeHtml(value) {
        const node = document.createElement('div');
        node.textContent = value ?? '';
        return node.innerHTML;
    }

    if (complaintView) form.requestSubmit();
});
