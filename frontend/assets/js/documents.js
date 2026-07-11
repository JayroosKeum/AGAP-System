document.addEventListener('DOMContentLoaded', () => {
    fetch('../../../backend/api/cases/list.php')
        .then(r => r.ok ? r.json() : Promise.reject())
        .then(cases => {
            const select = document.getElementById('documentCaseId');
            select.innerHTML = '<option value="">Select a case</option>' + 
                cases.filter(c => c.case_status !== 'Archived')
                     .map(c => `<option value="${Number(c.case_id)}">${escapeHtml(c.case_number)} - ${escapeHtml(c.complaint_title)}</option>`)
                     .join('');
        });
});

function escapeHtml(value) {
    const el = document.createElement('div');
    el.textContent = value ?? '';
    return el.innerHTML;
}

function generateSummons() {
    const id = document.getElementById('documentCaseId').value;
    if (!id) {
        alert('Select a case before generating a summons.');
        return;
    }
    window.open('kp-form-9.php?case_id=' + encodeURIComponent(id), '_blank');
}