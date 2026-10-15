(function () {
    'use strict';

    const STAGE_LABELS = {
        Docketed: 'Docketed',
        Summons: 'Summons',
        Mediation: 'Mediation',
        'Pangkat Formation': 'Pangkat Formation',
        Conciliation: 'Conciliation',
        'CFA Review': 'CFA Review',
        'CFA Issued': 'CFA Issued',
        Settled: 'Settled',
        Closed: 'Closed',
        Archived: 'Archived'
    };

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatDate(value) {
        if (!value) return 'Not recorded';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return escapeHtml(value);
        return date.toLocaleString('en-PH', {
            year: 'numeric',
            month: 'short',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function stageClass(stage, currentStage, sequence) {
        const currentIndex = sequence.indexOf(currentStage);
        const itemIndex = sequence.indexOf(stage);
        if (stage === currentStage) return 'is-current';
        if (currentIndex >= 0 && itemIndex >= 0 && itemIndex < currentIndex) return 'is-complete';
        return 'is-pending';
    }

    function renderStageTracker(data) {
        const sequence = data.stage_definition.sequence || [];
        const current = data.stage_definition.current;
        return `
            <div class="workflow-stage-track">
                ${sequence.map((stage) => `
                    <div class="workflow-stage ${stageClass(stage, current, sequence)}">
                        <span class="workflow-stage-dot"></span>
                        <span class="workflow-stage-label">${escapeHtml(STAGE_LABELS[stage] || stage)}</span>
                    </div>
                `).join('')}
            </div>
        `;
    }

    function renderSummary(data) {
        const caseData = data.case || {};
        return `
            <div class="workflow-summary-grid">
                <article class="workflow-card">
                    <span class="workflow-card-label">Case Number</span>
                    <strong>${escapeHtml(caseData.case_number || caseData.case_id)}</strong>
                </article>
                <article class="workflow-card">
                    <span class="workflow-card-label">Current Stage</span>
                    <strong>${escapeHtml(caseData.case_status)}</strong>
                </article>
                <article class="workflow-card">
                    <span class="workflow-card-label">Last Updated</span>
                    <strong>${formatDate(caseData.updated_at)}</strong>
                </article>
            </div>
        `;
    }

    function renderActions(actions) {
        if (!Array.isArray(actions) || actions.length === 0) {
            return '<p class="workflow-empty">No workflow action is currently available.</p>';
        }

        return `
            <div class="workflow-action-list">
                ${actions.map((action) => `
                    <button type="button" class="workflow-action-button" data-workflow-action="${escapeHtml(action)}">
                        ${escapeHtml(action.replaceAll('_', ' '))}
                    </button>
                `).join('')}
            </div>
        `;
    }

    function renderHearings(hearings) {
        if (!Array.isArray(hearings) || hearings.length === 0) {
            return '<p class="workflow-empty">No hearings recorded.</p>';
        }

        return `
            <div class="workflow-record-list">
                ${hearings.map((item) => `
                    <article class="workflow-record">
                        <div>
                            <strong>${escapeHtml(item.hearing_type)}</strong>
                            <span>${formatDate(item.hearing_date)}</span>
                        </div>
                        <div>
                            <span>${escapeHtml(item.venue || 'No venue')}</span>
                            <span class="workflow-status">${escapeHtml(item.status)}</span>
                        </div>
                    </article>
                `).join('')}
            </div>
        `;
    }

    function renderHistory(history) {
        if (!Array.isArray(history) || history.length === 0) {
            return '<p class="workflow-empty">No stage history recorded.</p>';
        }

        return `
            <div class="workflow-timeline">
                ${history.slice().reverse().map((item) => `
                    <article class="workflow-timeline-item">
                        <span class="workflow-timeline-marker"></span>
                        <div>
                            <strong>${escapeHtml(item.previous_stage)} → ${escapeHtml(item.new_stage)}</strong>
                            <span>${escapeHtml(item.action_code)}</span>
                            <small>${formatDate(item.created_at)}</small>
                        </div>
                    </article>
                `).join('')}
            </div>
        `;
    }

    function bindActions(container, caseId) {
        container.querySelectorAll('[data-workflow-action]').forEach((button) => {
            button.addEventListener('click', () => {
                const action = button.dataset.workflowAction;
                window.dispatchEvent(new CustomEvent('agap:workflow-action', {
                    detail: { caseId, action }
                }));
            });
        });
    }

    async function load(caseId, containerId = 'caseWorkflowWorkspace') {
        const container = document.getElementById(containerId);
        if (!container) return;

        container.innerHTML = '<div class="workflow-loading">Loading workflow...</div>';

        try {
            const response = await fetch(`../../backend/api/workflow/workspace.php?case_id=${encodeURIComponent(caseId)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin'
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to load workflow workspace.');
            }

            const data = result.data;
            container.innerHTML = `
                <section class="workflow-workspace">
                    ${renderSummary(data)}
                    ${renderStageTracker(data)}
                    <div class="workflow-section-grid">
                        <section class="workflow-panel">
                            <div class="workflow-panel-heading">
                                <h3>Available Actions</h3>
                            </div>
                            ${renderActions(data.available_actions)}
                        </section>
                        <section class="workflow-panel">
                            <div class="workflow-panel-heading">
                                <h3>Hearings</h3>
                            </div>
                            ${renderHearings(data.hearings)}
                        </section>
                        <section class="workflow-panel workflow-panel-wide">
                            <div class="workflow-panel-heading">
                                <h3>Stage History</h3>
                            </div>
                            ${renderHistory(data.history)}
                        </section>
                    </div>
                </section>
            `;
            bindActions(container, caseId);
        } catch (error) {
            container.innerHTML = `<div class="workflow-error">${escapeHtml(error.message)}</div>`;
        }
    }

    window.AGAPWorkflowWorkspace = { load };
})();
