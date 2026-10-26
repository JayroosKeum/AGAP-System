let reportForm = null;
let activeFilters = null;

document.addEventListener('DOMContentLoaded', () => {
    reportForm = document.getElementById('reportForm');
    if (!reportForm) return;

    const month = document.getElementById('reportMonth');
    if (month && month.options.length === 0) {
        for (let index = 0; index < 12; index += 1) {
            month.add(new Option(new Date(2026, index, 1).toLocaleString(undefined, { month: 'long' }), String(index + 1)));
        }
        month.value = String(new Date().getMonth() + 1);
    }

    const typeSelect = document.getElementById('reportType');
    if (typeSelect) {
        typeSelect.addEventListener('change', updatePeriodFields);
    }
    reportForm.addEventListener('submit', generateReport);

    const exportBtn = document.getElementById('exportReport');
    if (exportBtn) {
        exportBtn.addEventListener('click', exportReport);
    }

    // Case Register Excel export
    const regBtn = document.getElementById('btnExportRegister');
    if (regBtn) regBtn.addEventListener('click', exportCaseRegister);

    // DILG / LTIA print report
    const dilgBtn = document.getElementById('btnPrintDilg');
    if (dilgBtn) dilgBtn.addEventListener('click', printDilgReport);

    updatePeriodFields();
});

function updatePeriodFields() {
    const typeEl = document.getElementById('reportType');
    if (!typeEl) return;
    const type = typeEl.value;
    const monthGroup = document.getElementById('monthGroup');
    const quarterGroup = document.getElementById('quarterGroup');
    if (monthGroup) monthGroup.hidden = type !== 'Monthly';
    if (quarterGroup) quarterGroup.hidden = type !== 'Quarterly';
}

async function requestJson(url, options = {}) {
    const response = await fetch(url, options);
    const result = await response.json().catch(() => ({ success: false, message: 'Invalid server response.' }));
    if (!response.ok || result.success === false) throw new Error(result.message || 'Request failed.');
    return result;
}

function filtersFromForm() {
    const form = reportForm || document.getElementById('reportForm');
    if (!form) return {};
    const data = Object.fromEntries(new FormData(form).entries());
    if (data.type !== 'Monthly') delete data.month;
    if (data.type !== 'Quarterly') delete data.quarter;
    return data;
}

async function generateReport(event) {
    event.preventDefault();
    setMessage('');
    activeFilters = filtersFromForm();
    const typePath = (activeFilters.type || 'monthly').toLowerCase();
    const query = new URLSearchParams(activeFilters);
    try {
        const result = await requestJson(`../../../backend/api/reports/${typePath}.php?${query}`);
        renderReport(result.data);
        const exportBtn = document.getElementById('exportReport');
        if (exportBtn) exportBtn.disabled = false;
    } catch (error) {
        const output = document.getElementById('reportOutput');
        if (output) output.hidden = true;
        const exportBtn = document.getElementById('exportReport');
        if (exportBtn) exportBtn.disabled = true;
        setMessage(error.message, false);
    }
}

async function exportReport() {
    if (!activeFilters) return;
    try {
        const result = await requestJson('../../../backend/api/reports/export.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(activeFilters)
        });
        setMessage(result.message, true);
        window.location.assign(result.download_url);
    } catch (error) { setMessage(error.message, false); }
}

function renderReport(data) {
    const titleEl = document.getElementById('reportTitle');
    if (titleEl) titleEl.textContent = `${data.type} report: ${data.period_label}`;
    const cards = document.getElementById('summaryCards');
    if (cards) {
        cards.replaceChildren();
        Object.entries(data.totals || {}).forEach(([label, value]) => {
            const card = document.createElement('div'); card.className = 'summary-card';
            const count = document.createElement('strong'); count.textContent = value;
            const text = document.createElement('span'); text.textContent = label.replaceAll('_', ' ');
            card.append(count, text); cards.appendChild(card);
        });
    }
    renderTotals('statusTotals', data.status_totals || {}, 'No cases in this period.');
    renderTotals('categoryTotals', Object.fromEntries((data.categories || []).map(item => [item.category_name, item.total])), 'No docketed case categories in this period.');
    const body = document.getElementById('reportCases');
    if (body) {
        body.replaceChildren();
        if (!data.cases || !data.cases.length) {
            const row = document.createElement('tr'); const cell = document.createElement('td');
            cell.colSpan = 8; cell.className = 'empty-state'; cell.textContent = 'No cases were docketed in this period.';
            row.appendChild(cell); body.appendChild(row);
        } else {
            data.cases.forEach(item => {
                const row = document.createElement('tr');
                const resolution = item.cfa_issuance_date ? `CFA: ${item.cfa_issuance_date}` : item.award_date ? `Award: ${item.award_date}` : item.settlement_date ? `Settlement: ${item.settlement_date}` : item.archived_date ? `Archived: ${item.archived_date}` : '-';
                [item.case_number, `${item.complaint_number} - ${item.complaint_title}`, item.category_name, item.case_type, item.case_status, item.docket_date, item.hearing_count, resolution].forEach(value => {
                    const cell = document.createElement('td'); cell.textContent = value ?? '-'; row.appendChild(cell);
                });
                body.appendChild(row);
            });
        }
    }
    const output = document.getElementById('reportOutput');
    if (output) output.hidden = false;
}

function renderTotals(target, values, emptyMessage) {
    const container = document.getElementById(target);
    if (!container) return;
    container.replaceChildren();
    const entries = Object.entries(values || {});
    if (!entries.length) { container.textContent = emptyMessage; return; }
    const list = document.createElement('ul'); list.className = 'totals-list';
    entries.forEach(([label, value]) => { const item = document.createElement('li'); const name = document.createElement('span'); const count = document.createElement('strong'); name.textContent = label; count.textContent = value; item.append(name, count); list.appendChild(item); });
    container.appendChild(list);
}

function setMessage(message, success = false) {
    if (window.agapNotify && message) {
        window.agapNotify(message, success ? 'success' : 'error', success ? 'Reports' : 'Report Error');
    }
    const box = document.getElementById('reportMessage');
    if (box) {
        box.textContent = '';
        box.className = '';
        box.style.display = 'none';
    }
}

// ── Case Register Excel Export ───────────────────────────────────────────────
async function exportCaseRegister() {
    const btn = document.getElementById('btnExportRegister');
    const startDate = document.getElementById('registerStartDate')?.value || '';
    const endDate   = document.getElementById('registerEndDate')?.value || '';

    const origText = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Generating…'; }
    setMessage('');

    try {
        const payload = { format: 'register_excel' };
        if (startDate) payload.start_date = startDate;
        if (endDate)   payload.end_date   = endDate;

        const result = await requestJson('../../../backend/api/reports/export.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        setMessage(result.message, true);
        // Trigger download
        window.location.assign(result.download_url);
    } catch (error) {
        setMessage(`Export failed: ${error.message}`, false);
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = origText; }
    }
}

// ── DILG / LTIA Printable Report ─────────────────────────────────────────────
async function printDilgReport() {
    const type    = document.getElementById('reportType')?.value || 'Monthly';
    const year    = document.getElementById('reportYear')?.value || new Date().getFullYear();
    const month   = document.getElementById('reportMonth')?.value || (new Date().getMonth() + 1);
    const quarter = document.getElementById('reportQuarter')?.value || 1;

    const params = new URLSearchParams({ type, year });
    if (type === 'Monthly')   params.set('month', month);
    if (type === 'Quarterly') params.set('quarter', quarter);

    const url = `../../../backend/api/reports/print.php?${params.toString()}`;
    // Open in a new tab — the page has @media print CSS for 1-click printing
    window.open(url, '_blank', 'width=900,height=700,noopener,noreferrer');
}
