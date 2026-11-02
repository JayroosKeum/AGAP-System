/**
 * paanyaya.js
 * Handles the Schedule Paanyaya modal:
 *  - Open / close modal
 *  - Complainant & Respondent autocomplete from complaint_parties
 *  - Derived date fields: Buwan, Taon, Araw ng Paanyaya
 *  - Form submission via fetch → backend/api/paanyaya/schedule.php
 *  - PDF preview modal
 */

(function () {
    'use strict';

    /* ------------------------------------------------------------------ */
    /* Tagalog month & day names                                            */
    /* ------------------------------------------------------------------ */
    const BUWAN = [
        'Enero','Pebrero','Marso','Abril','Mayo','Hunyo',
        'Hulyo','Agosto','Setyembre','Oktubre','Nobyembre','Disyembre'
    ];
    const ARAW = ['Linggo','Lunes','Martes','Miyerkules','Huwebes','Biyernes','Sabado'];

    /* ------------------------------------------------------------------ */
    /* Element refs                                                         */
    /* ------------------------------------------------------------------ */
    const openBtn           = document.getElementById('openScheduleModalBtn');
    const closeBtn          = document.getElementById('closeScheduleModalBtn');
    const cancelBtn         = document.getElementById('cancelScheduleModalBtn');
    const modal             = document.getElementById('schedulePaanyayaModal');
    const form              = document.getElementById('paanyayaScheduleForm');
    const alertBox          = document.getElementById('modalAlertBox');
    const submitBtn         = document.getElementById('submitScheduleBtn');

    const complainantInput  = document.getElementById('complainantInput');
    const complainantList   = document.getElementById('complainantSuggestions');
    const respondentInput   = document.getElementById('respondentInput');
    const respondentList    = document.getElementById('respondentSuggestions');

    const dateInput         = document.getElementById('scheduledDateInput');
    const derivedInfo       = document.getElementById('derivedDateInfo');
    const monthHidden       = document.getElementById('invitationMonthInput');
    const yearHidden        = document.getElementById('invitationYearInput');
    const dayNameHidden     = document.getElementById('invitationDayNameInput');

    /* PDF preview refs */
    const pdfModal          = document.getElementById('paanyayaPdfModal');
    const pdfIframe         = document.getElementById('pdfViewerIframe');
    const pdfTitle          = document.getElementById('pdfModalTitle');
    const pdfDownloadBtn    = document.getElementById('pdfDownloadBtn');
    const closePdfBtn       = document.getElementById('closePdfModalBtn');

    /* PDF preview trigger buttons (rendered in the table) */
    document.querySelectorAll('.preview-pdf-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const pdfPath = this.dataset.pdf;
            const title   = this.dataset.title || 'Paanyaya Document';
            openPdfModal(pdfPath, title);
        });
    });

    /* ------------------------------------------------------------------ */
    /* Modal open / close                                                   */
    /* ------------------------------------------------------------------ */
    function openModal() {
        modal.style.display = 'flex';
        if (complainantInput) complainantInput.focus();
        updateDerivedDate();
    }

    function closeModal() {
        modal.style.display = 'none';
        form.reset();
        hideAlert();
        clearSuggestions(complainantList);
        clearSuggestions(respondentList);
        updateDerivedDate();
        submitBtn.disabled = false;
        submitBtn.textContent = 'I-iskedyul ang Paanyaya';
    }

    if (openBtn)   openBtn.addEventListener('click', openModal);
    if (closeBtn)  closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    modal && modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });

    /* ------------------------------------------------------------------ */
    /* Derived date display                                                 */
    /* ------------------------------------------------------------------ */
    function updateDerivedDate() {
        if (!dateInput || !derivedInfo) return;
        const val = dateInput.value;
        if (!val) {
            derivedInfo.innerHTML = '';
            if (monthHidden)  monthHidden.value   = '';
            if (yearHidden)   yearHidden.value    = '';
            if (dayNameHidden) dayNameHidden.value = '';
            return;
        }
        // Parse as local date to avoid timezone offset issues
        const parts = val.split('-');
        const d = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
        const buwan    = BUWAN[d.getMonth()];
        const taon     = d.getFullYear();
        const araw     = ARAW[d.getDay()];

        if (monthHidden)   monthHidden.value   = d.getMonth() + 1;
        if (yearHidden)    yearHidden.value    = taon;
        if (dayNameHidden) dayNameHidden.value = araw;

        derivedInfo.innerHTML =
            '<span>📅 <strong>Buwan:</strong> ' + buwan + '</span>' +
            '<span>📆 <strong>Taon:</strong> ' + taon + '</span>' +
            '<span>🗓️ <strong>Araw:</strong> ' + araw + '</span>';
    }

    if (dateInput) {
        dateInput.addEventListener('change', updateDerivedDate);
        updateDerivedDate(); // run once on load
    }

    /* ------------------------------------------------------------------ */
    /* Autocomplete helper                                                  */
    /* ------------------------------------------------------------------ */
    function clearSuggestions(list) {
        if (list) { list.innerHTML = ''; list.style.display = 'none'; }
    }

    function bindAutocomplete(input, list) {
        if (!input || !list) return;

        let debounceTimer;
        input.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            const q = this.value.trim();
            if (q.length < 2) { clearSuggestions(list); return; }
            debounceTimer = setTimeout(() => fetchParties(q, input, list), 250);
        });

        input.addEventListener('blur', function () {
            setTimeout(() => clearSuggestions(list), 200);
        });
    }

    function fetchParties(query, input, list) {
        fetch('../../backend/api/residents/search.php?q=' + encodeURIComponent(query) + '&limit=8')
            .then(r => r.json())
            .then(data => {
                clearSuggestions(list);
                const residents = data.residents || data.data || data;
                if (!Array.isArray(residents) || residents.length === 0) return;

                residents.forEach(function (person) {
                    const name = [person.first_name, person.middle_name, person.last_name]
                        .filter(Boolean).join(' ').trim();
                    if (!name) return;
                    const li = document.createElement('li');
                    li.textContent = name;
                    li.style.cssText = 'padding:8px 12px; cursor:pointer; font-size:0.87rem; border-bottom:1px solid #f1f5f9; color:#1e293b;';
                    li.addEventListener('mouseenter', () => li.style.background = '#f0fdf4');
                    li.addEventListener('mouseleave', () => li.style.background = '');
                    li.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        input.value = name;
                        clearSuggestions(list);
                    });
                    list.appendChild(li);
                });
                list.style.display = 'block';
            })
            .catch(() => clearSuggestions(list));
    }

    bindAutocomplete(complainantInput, complainantList);
    bindAutocomplete(respondentInput, respondentList);

    /* ------------------------------------------------------------------ */
    /* Alert helpers                                                        */
    /* ------------------------------------------------------------------ */
    function showAlert(msg, type) {
        if (!alertBox) return;
        const colors = {
            error:   { bg: '#fef2f2', border: '#fca5a5', color: '#b91c1c' },
            success: { bg: '#f0fdf4', border: '#86efac', color: '#15803d' },
            info:    { bg: '#eff6ff', border: '#93c5fd', color: '#1d4ed8' }
        };
        const c = colors[type] || colors.info;
        alertBox.style.cssText = 'display:block; padding:10px 14px; border-radius:8px; margin-bottom:16px; font-size:0.85rem;'
            + 'background:' + c.bg + '; border:1px solid ' + c.border + '; color:' + c.color + ';';
        alertBox.textContent = msg;
    }

    function hideAlert() {
        if (alertBox) { alertBox.style.display = 'none'; alertBox.textContent = ''; }
    }

    /* ------------------------------------------------------------------ */
    /* Form submission                                                      */
    /* ------------------------------------------------------------------ */
    form && form.addEventListener('submit', function (e) {
        e.preventDefault();
        hideAlert();

        const complainant = complainantInput ? complainantInput.value.trim() : '';
        const respondent  = respondentInput  ? respondentInput.value.trim()  : '';
        const date        = dateInput        ? dateInput.value.trim()        : '';
        const narrative   = document.getElementById('shortNarrativeInput')
                            ? document.getElementById('shortNarrativeInput').value.trim() : '';

        if (!complainant) { showAlert('Pakiusap, ilagay ang pangalan ng Komplainante.', 'error'); return; }
        if (!respondent)  { showAlert('Pakiusap, ilagay ang pangalan ng Responde.', 'error'); return; }
        if (!date)        { showAlert('Pakiusap, piliin ang Petsa ng Paanyaya.', 'error'); return; }
        if (!narrative)   { showAlert('Pakiusap, ilagay ang Maikling Salaysay.', 'error'); return; }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Nagpapadala...';

        const payload = new FormData(form);

        fetch('../../backend/api/paanyaya/schedule.php', {
            method: 'POST',
            body: payload
        })
        .then(r => r.json())
        .then(function (data) {
            if (data.success) {
                showAlert('Matagumpay na nai-iskedyul ang Paanyaya!', 'success');
                submitBtn.textContent = 'Naiskedyul ✓';
                if (data.pdf_url) {
                    setTimeout(function () {
                        openPdfModal(data.pdf_url, 'Bagong Paanyaya');
                        closeModal();
                        setTimeout(() => location.reload(), 1500);
                    }, 800);
                } else {
                    setTimeout(() => { closeModal(); location.reload(); }, 1200);
                }
            } else {
                showAlert(data.message || 'May pagkakamali. Subukan muli.', 'error');
                submitBtn.disabled = false;
                submitBtn.textContent = 'I-iskedyul ang Paanyaya';
            }
        })
        .catch(function () {
            showAlert('Hindi makakonekta sa server. Subukan muli.', 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = 'I-iskedyul ang Paanyaya';
        });
    });

    /* ------------------------------------------------------------------ */
    /* PDF Preview modal                                                    */
    /* ------------------------------------------------------------------ */
    function openPdfModal(pdfUrl, title) {
        if (!pdfModal) return;
        if (pdfTitle)       pdfTitle.textContent = title || 'Paanyaya Document';
        if (pdfIframe)      pdfIframe.src = pdfUrl;
        if (pdfDownloadBtn) pdfDownloadBtn.href = pdfUrl;
        pdfModal.style.display = 'flex';
    }

    function closePdfModal() {
        if (!pdfModal) return;
        pdfModal.style.display = 'none';
        if (pdfIframe) pdfIframe.src = 'about:blank';
    }

    if (closePdfBtn) closePdfBtn.addEventListener('click', closePdfModal);
    pdfModal && pdfModal.addEventListener('click', function (e) {
        if (e.target === pdfModal) closePdfModal();
    });

    /* ------------------------------------------------------------------ */
    /* Print Schedule button                                                */
    /* ------------------------------------------------------------------ */
    const printScheduleBtn = document.getElementById('printScheduleBtn');
    if (printScheduleBtn) {
        printScheduleBtn.addEventListener('click', function () {
            window.print();
        });
    }

    /* ------------------------------------------------------------------ */
    /* KPI card filter click → filter table rows                           */
    /* ------------------------------------------------------------------ */
    document.querySelectorAll('.paanyaya-kpi-card[data-filter]').forEach(function (card) {
        card.addEventListener('click', function () {
            const filter = this.dataset.filter.toLowerCase();
            // Sync with tab buttons
            document.querySelectorAll('.nav-tab-btn').forEach(btn => btn.classList.remove('active'));
            const matchTab = document.querySelector('.nav-tab-btn[data-tab-status="' + this.dataset.filter + '"]');
            if (matchTab) matchTab.classList.add('active');
            filterTableByStatus(filter);
        });
    });

    /* ------------------------------------------------------------------ */
    /* Tab filter                                                           */
    /* ------------------------------------------------------------------ */
    document.querySelectorAll('.nav-tab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.nav-tab-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            filterTableByStatus((this.dataset.tabStatus || '').toLowerCase());
        });
    });

    function filterTableByStatus(status) {
        document.querySelectorAll('tbody tr[data-status]').forEach(function (row) {
            if (!status || row.dataset.status.toLowerCase().includes(status)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* Search input filter                                                  */
    /* ------------------------------------------------------------------ */
    const searchInput = document.getElementById('paanyayaSearchInput');
    const clearSearch = document.getElementById('clearSearchBtn');

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const q = this.value.toLowerCase();
            if (clearSearch) clearSearch.style.display = q ? 'block' : 'none';
            document.querySelectorAll('tbody tr[data-status]').forEach(function (row) {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(q) ? '' : 'none';
            });
        });
    }

    if (clearSearch) {
        clearSearch.addEventListener('click', function () {
            if (searchInput) { searchInput.value = ''; searchInput.dispatchEvent(new Event('input')); }
        });
    }

    /* ------------------------------------------------------------------ */
    /* Date filter dropdown                                                 */
    /* ------------------------------------------------------------------ */
    const dateFilter = document.getElementById('dateFilterSelect');
    if (dateFilter) {
        dateFilter.addEventListener('change', function () {
            const val = this.value;
            const now = new Date();
            document.querySelectorAll('tbody tr[data-date]').forEach(function (row) {
                const d = new Date(row.dataset.date);
                let show = true;
                if (val === 'today') {
                    show = d.toDateString() === now.toDateString();
                } else if (val === 'tomorrow') {
                    const tom = new Date(now); tom.setDate(now.getDate() + 1);
                    show = d.toDateString() === tom.toDateString();
                } else if (val === 'this_week') {
                    const start = new Date(now); start.setDate(now.getDate() - now.getDay());
                    const end   = new Date(start); end.setDate(start.getDate() + 6);
                    show = d >= start && d <= end;
                } else if (val === 'next_7_days') {
                    const end = new Date(now); end.setDate(now.getDate() + 7);
                    show = d >= now && d <= end;
                } else if (val === 'this_month') {
                    show = d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
                } else if (val === 'past') {
                    show = d < now;
                }
                row.style.display = show ? '' : 'none';
            });
        });
    }

    /* ------------------------------------------------------------------ */
    /* Reset filters                                                        */
    /* ------------------------------------------------------------------ */
    const resetBtn = document.getElementById('resetFiltersBtn');
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            if (searchInput) { searchInput.value = ''; }
            if (dateFilter)  { dateFilter.value  = ''; }
            if (clearSearch) { clearSearch.style.display = 'none'; }
            document.querySelectorAll('tbody tr[data-status]').forEach(r => r.style.display = '');
        });
    }

})();
