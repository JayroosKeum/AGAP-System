(() => {
    'use strict';
    const monthNames = Array.from({ length: 12 }, (_, index) => new Date(2000, index, 1).toLocaleString('en', { month: 'long' }));
    const descriptions = {
        certificate: ['Certificate to File Action Cases', 'Generate the Certificate to File Action Cases report using recorded case information.'],
        monitoring: ['Generate KP Monitoring Report', 'Choose the reporting quarter and covering months. Statistics are calculated from docketed cases.'],
        form8: ['Generate LTIA Form 8', 'Generate the KP Case Record and Settlement Log for the selected filing period.'],
        form6: ['Generate LTIA Form 6 – Summary of Cases', 'Generate the official Summary of Cases for the selected filing period.']
    };

    document.addEventListener('DOMContentLoaded', () => {
        const modal = document.getElementById('officialReportModal');
        const form = document.getElementById('officialReportForm');
        const month = document.getElementById('officialReportMonth');
        const year = document.getElementById('officialReportYear');
        const type = document.getElementById('officialReportType');
        const monitoring = document.getElementById('officialMonitoringFields');
        const quarter = document.getElementById('officialReportQuarter');
        const checks = document.getElementById('officialReportMonths');
        const dateAccomplished = document.getElementById('officialReportDateAccomplished');
        if (!modal || !form || !month || !year || !type) return;

        const now = new Date();
        monthNames.forEach((name, index) => month.add(new Option(name, String(index + 1))));
        checks.innerHTML = monthNames.map((name, index) => `<label><input type="checkbox" name="months[]" value="${index + 1}"> ${name.slice(0, 3)}</label>`).join('');
        month.value = String(now.getMonth() + 1); year.value = String(now.getFullYear());
        dateAccomplished.value = now.toISOString().slice(0, 10);

        const close = () => { modal.style.display = 'none'; document.body.style.overflow = ''; };
        const setQuarterMonths = () => {
            const start = (Number(quarter.value) - 1) * 3 + 1;
            checks.querySelectorAll('input').forEach(box => { box.checked = Number(box.value) >= start && Number(box.value) < start + 3; });
            updateAccomplishedDate();
        };
        const updateAccomplishedDate = () => {
            const selected = [...checks.querySelectorAll('input:checked')].map(box => Number(box.value));
            if (!selected.length) return;
            const reportYear = Number(year.value) || now.getFullYear();
            const lastMonth = Math.max(...selected);
            dateAccomplished.value = new Date(reportYear, lastMonth, 0).toISOString().slice(0, 10);
        };
        const open = (reportType) => {
            if (reportType === 'council-brief') {
                const query = new URLSearchParams({ year: String(year.value || now.getFullYear()), month: String(month.value || now.getMonth() + 1), autoprint: '1' });
                window.open(`../../../backend/api/reports/council_brief.php?${query}`, '_blank', 'noopener,noreferrer'); return;
            }
            const [title, description] = descriptions[reportType];
            type.value = reportType;
            document.getElementById('officialReportModalTitle').textContent = title;
            document.getElementById('officialReportDescription').textContent = description;
            monitoring.hidden = reportType !== 'monitoring';
            month.closest('label').style.display = reportType === 'monitoring' ? 'none' : '';
            month.required = reportType !== 'monitoring';
            if (reportType === 'monitoring') { quarter.value = String(Math.floor(now.getMonth() / 3) + 1); setQuarterMonths(); }
            modal.style.display = 'flex'; document.body.style.overflow = 'hidden';
        };
        document.querySelectorAll('.official-report-launch').forEach(button => button.addEventListener('click', () => open(button.dataset.reportType)));
        [document.getElementById('officialReportClose'), document.getElementById('officialReportCancel')].forEach(button => button?.addEventListener('click', close));
        modal.addEventListener('click', event => { if (event.target === modal) close(); });
        quarter.addEventListener('change', setQuarterMonths);
        checks.addEventListener('change', updateAccomplishedDate);
        year.addEventListener('change', updateAccomplishedDate);
        form.addEventListener('submit', event => {
            event.preventDefault();
            const params = new URLSearchParams(new FormData(form));
            if (type.value === 'monitoring') {
                const months = [...checks.querySelectorAll('input:checked')].map(box => box.value);
                if (!months.length) { window.agapNotify?.('Select at least one covering month.', 'error', 'Report period'); return; }
                params.delete('months[]'); params.set('months', months.join(','));
            }
            window.open(`../../../backend/api/reports/official.php?${params}`, '_blank', 'width=1200,height=820,noopener,noreferrer');
            close();
        });
    });
})();
