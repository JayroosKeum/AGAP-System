(function () {
    'use strict';

    const NEWS_API_URL = '../../../backend/api/news/index.php';

    function esc(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatPublishedDate(dateStr) {
        const raw = String(dateStr || '').trim();
        if (!raw) return '';
        const normalized = raw.replace(' ', 'T');
        const dt = new Date(normalized);
        if (Number.isNaN(dt.getTime())) {
            return raw.slice(0, 10);
        }
        return dt.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
    }

    function categoryBadgeClass(category) {
        const c = String(category || '').toLowerCase();
        if (c.includes('public service')) return 'badge-green';
        if (c.includes('lupon')) return 'badge-blue';
        if (c.includes('advisory')) return 'badge-amber';
        return 'badge-slate';
    }

    function formatParagraphs(text) {
        return esc(text)
            .split(/\n{2,}/)
            .map((p) => `<p>${p.replace(/\n/g, '<br>')}</p>`)
            .join('');
    }

    let cachedPublicNews = [];
    let residentModalWired = false;

    function openResidentNewsModal(newsItem) {
        const modal = document.getElementById('rdNewsDetailModal');
        if (!modal || !newsItem) return;

        const titleEl = document.getElementById('rdNewsModalTitle');
        const catEl = document.getElementById('rdNewsModalCategory');
        const pinEl = document.getElementById('rdNewsModalPinned');
        const metaEl = document.getElementById('rdNewsModalMeta');
        const bodyEl = document.getElementById('rdNewsModalBody');
        const authorEl = document.getElementById('rdNewsModalAuthor');

        if (titleEl) titleEl.textContent = newsItem.title || 'Barangay Advisory';
        if (catEl) {
            catEl.className = `badge ${categoryBadgeClass(newsItem.category)}`;
            catEl.textContent = newsItem.category || 'Announcement';
        }
        if (pinEl) {
            pinEl.style.display = newsItem.is_pinned ? 'inline-flex' : 'none';
        }
        if (metaEl) {
            const d = formatPublishedDate(newsItem.published_at || newsItem.created_at);
            metaEl.textContent = d ? `Published ${d}` : 'Barangay Tumana Official Update';
        }
        if (bodyEl) {
            bodyEl.innerHTML = formatParagraphs(newsItem.content || newsItem.summary || '');
        }
        if (authorEl) {
            authorEl.textContent = `Issued by ${newsItem.author_name || 'Office of the Punong Barangay'} · Barangay Tumana, Marikina City`;
        }

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeResidentNewsModal() {
        const modal = document.getElementById('rdNewsDetailModal');
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function wireResidentNewsModal() {
        if (residentModalWired) return;
        residentModalWired = true;

        const modal = document.getElementById('rdNewsDetailModal');
        document.getElementById('btnPortalNewsClose')?.addEventListener('click', closeResidentNewsModal);
        document.getElementById('btnPortalNewsDone')?.addEventListener('click', closeResidentNewsModal);
        modal?.addEventListener('click', (e) => {
            if (e.target === modal) closeResidentNewsModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal?.classList.contains('is-open')) {
                closeResidentNewsModal();
            }
        });
    }

    let activeNewsSlide = 0;

    function wireNewsCarouselNav(container) {
        const prevBtn = document.getElementById('btnRdNewsPrev');
        const nextBtn = document.getElementById('btnRdNewsNext');
        const dotsWrap = document.getElementById('rdNewsDots');
        const cards = container ? Array.from(container.querySelectorAll('.rd-news-card')) : [];
        const total = cards.length;
        if (!container) return;

        const goToSlide = (targetIdx) => {
            if (total === 0) return;
            activeNewsSlide = ((targetIdx % total) + total) % total;
            cards.forEach((card, idx) => {
                const isActive = idx === activeNewsSlide;
                card.classList.toggle('is-active', isActive);
                card.setAttribute('aria-hidden', isActive ? 'false' : 'true');
            });
            if (dotsWrap) {
                dotsWrap.querySelectorAll('.rd-news-dot').forEach((dot, idx) => {
                    const isActive = idx === activeNewsSlide;
                    dot.classList.toggle('is-active', isActive);
                    dot.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });
            }
        };

        if (dotsWrap) {
            if (total <= 1) {
                dotsWrap.innerHTML = '';
            } else {
                dotsWrap.innerHTML = cards.map((card, idx) => `
                    <button type="button"
                            class="rd-news-dot ${idx === 0 ? 'is-active' : ''}"
                            role="tab"
                            aria-selected="${idx === 0 ? 'true' : 'false'}"
                            aria-label="Show announcement ${idx + 1} of ${total}"
                            data-news-dot-index="${idx}"></button>
                `).join('');
                dotsWrap.querySelectorAll('[data-news-dot-index]').forEach((dot) => {
                    dot.addEventListener('click', () => {
                        goToSlide(Number(dot.getAttribute('data-news-dot-index') || 0));
                    });
                });
            }
        }

        if (prevBtn) prevBtn.disabled = total <= 1;
        if (nextBtn) nextBtn.disabled = total <= 1;

        if (!container.dataset.carouselBound) {
            container.dataset.carouselBound = '1';
            prevBtn?.addEventListener('click', () => goToSlide(activeNewsSlide - 1));
            nextBtn?.addEventListener('click', () => goToSlide(activeNewsSlide + 1));
        }

        goToSlide(activeNewsSlide >= total ? 0 : activeNewsSlide);
    }

    async function loadAndRenderResidentNews(options = {}) {
        const containerId = options.containerId || 'rdHomeNewsList';
        const container = document.getElementById(containerId);
        const dotsWrap = document.getElementById('rdNewsDots');
        const prevBtn = document.getElementById('btnRdNewsPrev');
        const nextBtn = document.getElementById('btnRdNewsNext');
        if (!container) return;

        wireResidentNewsModal();

        try {
            const resp = await fetch(`${NEWS_API_URL}?scope=published&limit=6`, {
                headers: { Accept: 'application/json' },
            });
            const data = await resp.json();
            if (!resp.ok || !data.success) {
                throw new Error(data.message || 'Unable to load news.');
            }
            cachedPublicNews = Array.isArray(data.news) ? data.news : [];

            if (cachedPublicNews.length === 0) {
                if (dotsWrap) dotsWrap.innerHTML = '';
                if (prevBtn) prevBtn.disabled = true;
                if (nextBtn) nextBtn.disabled = true;
                container.innerHTML = '<div class="rd-news-empty">No barangay news or advisories posted yet.</div>';
                return;
            }

            activeNewsSlide = 0;
            container.innerHTML = cachedPublicNews.map((item, idx) => {
                const dateLabel = formatPublishedDate(item.published_at || item.created_at);
                const pinnedBadge = item.is_pinned
                    ? '<span class="rd-news-pin-pill">Pinned</span>'
                    : '';
                return `
                    <article class="rd-news-card ${item.is_pinned ? 'is-pinned' : ''} ${idx === 0 ? 'is-active' : ''}" data-news-id="${Number(item.news_id)}" aria-hidden="${idx === 0 ? 'false' : 'true'}">
                        <div class="rd-news-card-meta">
                            <div class="rd-news-badges">
                                <span class="badge ${categoryBadgeClass(item.category)}">${esc(item.category || 'Announcement')}</span>
                                ${pinnedBadge}
                            </div>
                            <time datetime="${esc(String(item.published_at || '').slice(0, 10))}">${esc(dateLabel)}</time>
                        </div>
                        <h3>${esc(item.title)}</h3>
                        <p>${esc(item.summary)}</p>
                        <div class="rd-news-card-foot">
                            <span class="rd-news-author">${esc(item.author_name || 'Office of the Punong Barangay')}</span>
                            <button type="button" class="rd-news-read-btn" data-read-news-id="${Number(item.news_id)}">Read more &rarr;</button>
                        </div>
                    </article>
                `;
            }).join('');

            container.querySelectorAll('[data-read-news-id]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const id = Number(btn.getAttribute('data-read-news-id'));
                    const match = cachedPublicNews.find((x) => Number(x.news_id) === id);
                    if (match) openResidentNewsModal(match);
                });
            });
            wireNewsCarouselNav(container);
        } catch (_) {
            if (dotsWrap) dotsWrap.innerHTML = '';
            if (prevBtn) prevBtn.disabled = true;
            if (nextBtn) nextBtn.disabled = true;
            container.innerHTML = '<div class="rd-news-empty">Unable to load Barangay Tumana news right now.</div>';
        }
    }

    function initStaffBulletin(options = {}) {
        const containerId = options.containerId || 'staffBulletinNewsList';
        const manageBtnId = options.manageBtnId || 'btnOpenStaffNewsManager';
        const modalId = options.modalId || 'staffNewsManagerModal';
        const container = document.getElementById(containerId);
        const manageBtn = document.getElementById(manageBtnId);
        const modal = document.getElementById(modalId);

        if (!container) return;

        let staffNewsAll = [];
        let editingNewsId = null;

        const formEl = document.getElementById('staffNewsForm');
        const formHeadingEl = document.getElementById('staffNewsFormHeading');
        const idInput = document.getElementById('staffNewsEditId');
        const titleInput = document.getElementById('staffNewsTitle');
        const categoryInput = document.getElementById('staffNewsCategory');
        const statusInput = document.getElementById('staffNewsStatus');
        const summaryInput = document.getElementById('staffNewsSummary');
        const contentInput = document.getElementById('staffNewsContent');
        const pinnedInput = document.getElementById('staffNewsPinned');
        const saveBtn = document.getElementById('btnSaveStaffNews');
        const resetBtn = document.getElementById('btnResetStaffNewsForm');
        const managerListEl = document.getElementById('staffNewsManagerList');
        const alertEl = document.getElementById('staffNewsFormAlert');

        function setFormAlert(msg, isError = true) {
            if (!alertEl) return;
            if (!msg) {
                alertEl.style.display = 'none';
                alertEl.textContent = '';
                return;
            }
            alertEl.className = isError ? 'alert alert-error' : 'alert alert-success';
            alertEl.textContent = msg;
            alertEl.style.display = 'block';
        }

        function resetNewsForm() {
            editingNewsId = null;
            if (idInput) idInput.value = '';
            if (formHeadingEl) formHeadingEl.textContent = 'Publish Barangay News';
            if (saveBtn) saveBtn.textContent = 'Publish News';
            formEl?.reset();
            if (categoryInput) categoryInput.value = 'Announcement';
            if (statusInput) statusInput.value = 'Published';
            setFormAlert('');
        }

        function populateNewsForm(item) {
            editingNewsId = Number(item.news_id);
            if (idInput) idInput.value = String(editingNewsId);
            if (titleInput) titleInput.value = item.title || '';
            if (categoryInput) categoryInput.value = item.category || 'Announcement';
            if (statusInput) statusInput.value = item.status || 'Published';
            if (summaryInput) summaryInput.value = item.summary || '';
            if (contentInput) contentInput.value = item.content || '';
            if (pinnedInput) pinnedInput.checked = Boolean(item.is_pinned);
            if (formHeadingEl) formHeadingEl.textContent = `Edit News #${editingNewsId}`;
            if (saveBtn) saveBtn.textContent = 'Save Changes';
            setFormAlert('');
            titleInput?.focus();
        }

        function renderPublishedFeed(items) {
            if (!items || items.length === 0) {
                container.innerHTML = '<div class="dash-aside-bulletin"><p style="color:#64748b;">No published Barangay Tumana news yet.</p></div>';
                return;
            }
            container.innerHTML = items.slice(0, 4).map((item) => {
                const dateLabel = formatPublishedDate(item.published_at || item.created_at);
                return `
                    <div class="dash-aside-bulletin dash-news-feed-item">
                        <div class="dash-news-feed-head">
                            <span class="badge ${categoryBadgeClass(item.category)}" style="font-size:10px; padding:2px 7px;">${esc(item.category || 'Announcement')}</span>
                            <small>${item.is_pinned ? 'Pinned &bull; ' : ''}${esc(dateLabel)}</small>
                        </div>
                        <strong>${esc(item.title)}</strong>
                        <p>${esc(item.summary)}</p>
                    </div>
                `;
            }).join('');
        }

        function renderManagerList(items) {
            if (!managerListEl) return;
            if (!items || items.length === 0) {
                managerListEl.innerHTML = '<p style="color:#64748b; font-size:0.85rem;">No news articles published yet.</p>';
                return;
            }
            managerListEl.innerHTML = items.map((item) => {
                const id = Number(item.news_id);
                const isArchived = item.status === 'Archived';
                const dateLabel = formatPublishedDate(item.published_at || item.created_at);
                return `
                    <div class="staff-news-row ${isArchived ? 'is-archived' : ''}">
                        <div class="staff-news-row-main">
                            <div class="staff-news-row-meta">
                                <span class="badge ${categoryBadgeClass(item.category)}">${esc(item.category)}</span>
                                <span class="badge ${isArchived ? 'badge-slate' : 'badge-green'}">${esc(item.status)}</span>
                                ${item.is_pinned ? '<span class="badge badge-amber">Pinned</span>' : ''}
                                <small>${esc(dateLabel)}</small>
                            </div>
                            <strong>${esc(item.title)}</strong>
                            <p>${esc(item.summary)}</p>
                        </div>
                        <div class="staff-news-row-actions">
                            <button type="button" class="btn-secondary" data-staff-news-edit="${id}">Edit</button>
                            <button type="button" class="btn-secondary" data-staff-news-pin="${id}">${item.is_pinned ? 'Unpin' : 'Pin'}</button>
                            <button type="button" class="btn-secondary" data-staff-news-status="${id}">${isArchived ? 'Publish' : 'Archive'}</button>
                            <button type="button" class="btn-secondary" data-staff-news-delete="${id}" style="color:#b91c1c;">Delete</button>
                        </div>
                    </div>
                `;
            }).join('');

            managerListEl.querySelectorAll('[data-staff-news-edit]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const id = Number(btn.getAttribute('data-staff-news-edit'));
                    const item = staffNewsAll.find((x) => Number(x.news_id) === id);
                    if (item) populateNewsForm(item);
                });
            });
            managerListEl.querySelectorAll('[data-staff-news-pin]').forEach((btn) => {
                btn.addEventListener('click', () => runNewsAction({ action: 'toggle_pin', news_id: Number(btn.getAttribute('data-staff-news-pin')) }));
            });
            managerListEl.querySelectorAll('[data-staff-news-status]').forEach((btn) => {
                btn.addEventListener('click', () => runNewsAction({ action: 'toggle_status', news_id: Number(btn.getAttribute('data-staff-news-status')) }));
            });
            managerListEl.querySelectorAll('[data-staff-news-delete]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const id = Number(btn.getAttribute('data-staff-news-delete'));
                    if (!confirm('Delete this barangay news item permanently?')) return;
                    runNewsAction({ action: 'delete', news_id: id });
                });
            });
        }

        async function loadStaffNews(includeAll = false) {
            try {
                const scope = includeAll ? 'all' : 'published';
                const limit = includeAll ? 30 : 4;
                const resp = await fetch(`${NEWS_API_URL}?scope=${scope}&limit=${limit}`, {
                    headers: { Accept: 'application/json' },
                });
                const data = await resp.json();
                if (!resp.ok || !data.success) return;
                const rows = Array.isArray(data.news) ? data.news : [];
                if (includeAll) {
                    staffNewsAll = rows;
                    renderManagerList(staffNewsAll);
                    renderPublishedFeed(staffNewsAll.filter((x) => x.status === 'Published'));
                } else {
                    renderPublishedFeed(rows);
                }
            } catch (_) {
                // Keep quiet on passive dashboard refresh
            }
        }

        async function runNewsAction(payload) {
            setFormAlert('');
            try {
                const resp = await fetch(NEWS_API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify(payload),
                });
                const data = await resp.json();
                if (!resp.ok || !data.success) {
                    setFormAlert(data.message || 'Unable to save news update.', true);
                    return false;
                }
                setFormAlert(data.message || 'Saved.', false);
                await loadStaffNews(true);
                return true;
            } catch (_) {
                setFormAlert('Connection error while updating barangay news.', true);
                return false;
            }
        }

        function openManagerModal() {
            if (!modal) return;
            resetNewsForm();
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            loadStaffNews(true);
        }

        function closeManagerModal() {
            if (!modal) return;
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }

        manageBtn?.addEventListener('click', openManagerModal);
        document.getElementById('btnCloseStaffNewsModal')?.addEventListener('click', closeManagerModal);
        document.getElementById('btnCloseStaffNewsFooter')?.addEventListener('click', closeManagerModal);
        resetBtn?.addEventListener('click', resetNewsForm);
        modal?.addEventListener('click', (e) => {
            if (e.target === modal) closeManagerModal();
        });

        formEl?.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (saveBtn) saveBtn.disabled = true;
            const payload = {
                action: editingNewsId ? 'update' : 'create',
                news_id: editingNewsId || 0,
                title: titleInput?.value?.trim() || '',
                category: categoryInput?.value || 'Announcement',
                status: statusInput?.value || 'Published',
                summary: summaryInput?.value?.trim() || '',
                content: contentInput?.value?.trim() || '',
                is_pinned: pinnedInput?.checked ? 1 : 0,
            };
            const ok = await runNewsAction(payload);
            if (ok) {
                resetNewsForm();
            }
            if (saveBtn) saveBtn.disabled = false;
        });

        loadStaffNews(false);
    }

    window.AgapNewsWidget = {
        loadAndRenderResidentNews,
        initStaffBulletin,
    };
})();
