const reportForm = document.getElementById('reportForm');
let activeFilters = null;

document.addEventListener('DOMContentLoaded', () => {
    const month = document.getElementById('reportMonth');
    new Intl.DateTimeFormat(undefined, { month: 'long' }).formatToParts(new Date(2026, 0, 1));
    for (let index = 0; index < 12; index += 1) {
        month.add(new Option(new Date(2026, index, 1).toLocaleString(undefined, { month: 'long' }), String(index + 1)));
    }
    month.value = String(new Date().getMonth() + 1);
    document.getElementById('reportType').addEventListener('change', updatePeriodFields);
    reportForm.addEventListener('submit', generateReport);
    document.getElementById('exportReport').addEventListener('click', exportReport);
    updatePeriodFields();
});

function updatePeriodFields() {
    const type = document.getElementById('reportType').value;
    document.getElementById('monthGroup').hidden = type !== 'Monthly';
    document.getElementById('quarterGroup').hidden = type !== 'Quarterly';
}

async function requestJson(url, options = {}) {
    const response = await fetch(url, options);
    const result = await response.json().catch(() => ({ success: false, message: 'Invalid server response.' }));
    if (!response.ok || result.success === false) throw new Error(result.message || 'Request failed.');
    return result;
}

function filtersFromForm() {
    const data = Object.fromEntries(new FormData(reportForm).entries());
    if (data.type !== 'Monthly') delete data.month;
    if (data.type !== 'Quarterly') delete data.quarter;
    return data;
}

async function generateReport(event) {
    event.preventDefault();
    setMessage('');
    activeFilters = filtersFromForm();
    const typePath = activeFilters.type.toLowerCase();
    const query = new URLSearchParams(activeFilters);
    try {
        const result = await requestJson(`../../../backend/api/reports/${typePath}.php?${query}`);
        renderReport(result.data);
        document.getElementById('exportReport').disabled = false;
    } catch (error) {
        document.getElementById('reportOutput').hidden = true;
        document.getElementById('exportReport').disabled = true;
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
    document.getElementById('reportTitle').textContent = `${data.type} report: ${data.period_label}`;
    const cards = document.getElementById('summaryCards');
    cards.replaceChildren();
    Object.entries(data.totals).forEach(([label, value]) => {
        const card = document.createElement('div'); card.className = 'summary-card';
        const count = document.createElement('strong'); count.textContent = value;
        const text = document.createElement('span'); text.textContent = label.replaceAll('_', ' ');
        card.append(count, text); cards.appendChild(card);
    });
    renderTotals('statusTotals', data.status_totals, 'No cases in this period.');
    renderTotals('categoryTotals', Object.fromEntries(data.categories.map(item => [item.category_name, item.total])), 'No docketed case categories in this period.');
    const body = document.getElementById('reportCases'); body.replaceChildren();
    if (!data.cases.length) {
        const row = document.createElement('tr'); const cell = document.createElement('td');
        cell.colSpan = 8; cell.className = 'empty-state'; cell.textContent = 'No cases were docketed in this period.';
        row.appendChild(cell); body.appendChild(row);
    } else data.cases.forEach(item => {
        const row = document.createElement('tr');
        const resolution = item.cfa_issuance_date ? `CFA: ${item.cfa_issuance_date}` : item.award_date ? `Award: ${item.award_date}` : item.settlement_date ? `Settlement: ${item.settlement_date}` : item.archived_date ? `Archived: ${item.archived_date}` : '-';
        [item.case_number, `${item.complaint_number} - ${item.complaint_title}`, item.category_name, item.case_type, item.case_status, item.docket_date, item.hearing_count, resolution].forEach(value => {
            const cell = document.createElement('td'); cell.textContent = value ?? '-'; row.appendChild(cell);
        });
        body.appendChild(row);
    });
    document.getElementById('reportOutput').hidden = false;
}

function renderTotals(target, values, emptyMessage) {
    const container = document.getElementById(target); container.replaceChildren();
    const entries = Object.entries(values);
    if (!entries.length) { container.textContent = emptyMessage; return; }
    const list = document.createElement('ul'); list.className = 'totals-list';
    entries.forEach(([label, value]) => { const item = document.createElement('li'); const name = document.createElement('span'); const count = document.createElement('strong'); name.textContent = label; count.textContent = value; item.append(name, count); list.appendChild(item); });
    container.appendChild(list);
}

function setMessage(message, success = false) {
    const box = document.getElementById('reportMessage'); box.textContent = message; box.className = message ? `alert ${success ? 'success' : 'error'}` : '';
}
