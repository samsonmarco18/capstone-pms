function escapeHtml(text) {
    if (text === null || text === undefined) {
        return '';
    }

    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

function formatProjectDate(value) {
    return value ? new Date(value).toLocaleDateString() : 'N/A';
}

function formatProjectMoney(value) {
    if (!value || Number.isNaN(Number(value))) {
        return 'N/A';
    }

    return '$' + Number(value).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatProjectLabel(value) {
    return value ? String(value).replace(/_/g, ' ').toUpperCase() : 'N/A';
}

function renderProjectTextBlock(label, value) {
    return `
        <div class="project-detail-item project-detail-full">
            <label>${escapeHtml(label)}</label>
            <div class="value project-context-text">${value ? escapeHtml(value) : 'Not provided yet.'}</div>
        </div>
    `;
}

function renderProjectMetric(label, value) {
    return `
        <div class="project-context-metric">
            <span>${escapeHtml(label)}</span>
            <strong>${escapeHtml(String(value !== null && value !== undefined ? value : 0))}</strong>
        </div>
    `;
}

function renderProjectPillList(items, emptyText) {
    if (!Array.isArray(items) || items.length === 0) {
        return `<div class="project-context-empty">${escapeHtml(emptyText)}</div>`;
    }

    return `<div class="project-context-pills">${items.map(item => `<span>${escapeHtml(item)}</span>`).join('')}</div>`;
}

function groupAssignmentsByUser(assignments) {
    const grouped = {};

    (assignments || []).forEach(item => {
        const userId = item.user_id || item.id || item.full_name || 'unknown';
        if (!grouped[userId]) {
            grouped[userId] = {
                full_name: item.full_name || 'Unnamed',
                role: item.role || 'member',
                tasks: []
            };
        }

        if (item.title || item.task_id) {
            grouped[userId].tasks.push(item.title || 'Assigned task');
        }
    });

    return Object.values(grouped);
}

function renderApprovedTimelineGantt(timeline) {
    const tasks = timeline && Array.isArray(timeline.tasks) ? timeline.tasks : [];
    if (!tasks.length) {
        return '<div class="project-context-empty">No approved timeline available yet.</div>';
    }

    const starts = tasks.map(task => new Date(task.start).getTime()).filter(Number.isFinite);
    const ends = tasks.map(task => new Date(task.end).getTime()).filter(Number.isFinite);
    if (!starts.length || !ends.length) {
        return '<div class="project-context-empty">Timeline dates are not available yet.</div>';
    }

    const rangeStart = Math.min.apply(null, starts);
    const rangeEnd = Math.max.apply(null, ends);
    const range = Math.max(1, rangeEnd - rangeStart);

    const rows = tasks.map(task => {
        const startTs = new Date(task.start).getTime();
        const endTs = new Date(task.end).getTime();
        const left = ((startTs - rangeStart) / range) * 100;
        const width = Math.max(2, ((endTs - startTs) / range) * 100);
        const barClass = task.type === 'phase' ? 'gantt-bar-phase' : 'gantt-bar-task';

        return `
            <div class="project-gantt-row">
                <div class="project-gantt-label">
                    <strong>${escapeHtml(task.name || 'Timeline Item')}</strong>
                    <small>${formatProjectDate(task.start)} - ${formatProjectDate(task.end)}</small>
                </div>
                <div class="project-gantt-track">
                    <div class="project-gantt-bar ${barClass}" style="left:${left}%; width:${width}%;"></div>
                </div>
            </div>
        `;
    }).join('');

    return `
        <div class="project-gantt-chart">
            <div class="project-gantt-range">
                <span>${formatProjectDate(timeline.timeline_start || new Date(rangeStart).toISOString())}</span>
                <span>${formatProjectDate(timeline.timeline_end || new Date(rangeEnd).toISOString())}</span>
            </div>
            ${rows}
        </div>
    `;
}

function renderSalesIntakeSection(project) {
    const receipts = Array.isArray(project.sales_receipts) ? project.sales_receipts : [];

    return `
        <div class="project-context-section">
            <h6><i class="fas fa-clipboard-list"></i> Sales — Client & Project Intake</h6>
            <div class="project-details-grid">
                ${renderProjectTextBlock('Description', project.description)}
                ${renderProjectTextBlock('Requested Features & Requirements', project.requested_features)}
                ${renderProjectTextBlock('Meeting Notes', project.meeting_notes)}
                ${renderProjectTextBlock('Minutes of Meeting', project.mom)}
                <div class="project-detail-item">
                    <label>Budget</label>
                    <div class="value">${formatProjectMoney(project.sales_budget)}</div>
                </div>
                <div class="project-detail-item">
                    <label>Contact Person</label>
                    <div class="value">${escapeHtml(project.contact_person || 'N/A')}</div>
                </div>
                <div class="project-detail-item">
                    <label>Sales Owner</label>
                    <div class="value">${escapeHtml(project.created_by_name || 'N/A')}</div>
                </div>
            </div>
            <div class="project-context-list">
                <label>Project Receipts</label>
                ${receipts.length ? receipts.map(receipt => `
                    <div class="project-context-row">
                        <span>
                            ${escapeHtml(receipt.receipt_number || receipt.file_name || 'Receipt')}
                            <small class="d-block text-muted">${formatProjectMoney(receipt.amount_paid || receipt.amount_due)} · ${formatProjectLabel(receipt.status)}</small>
                        </span>
                        <small>
                            ${receipt.file_url ? `<a href="${escapeHtml(receipt.file_url)}" target="_blank" class="file-link">View File</a>` : ''}
                            ${receipt.receipt_url ? `<a href="${escapeHtml(receipt.receipt_url)}" target="_blank" class="file-link ms-2">Details</a>` : ''}
                        </small>
                    </div>
                `).join('') : '<div class="project-context-empty">No receipts uploaded yet.</div>'}
            </div>
        </div>
    `;
}

function renderArchitectSection(project) {
    const summary = project.architecture_summary || {};
    const documents = Array.isArray(project.architecture_documents) ? project.architecture_documents : [];
    const technologies = Array.isArray(project.technology_recommendations) ? project.technology_recommendations : [];
    const canOpenArchitectPages = project.current_user_role === 'architect';
    const architectActions = canOpenArchitectPages
        ? `
            ${summary.summary_url ? `<a href="${escapeHtml(summary.summary_url)}" class="btn-primary-modern btn-sm"><i class="fas fa-external-link-alt"></i> Open Full Summary</a>` : ''}
            ${summary.review_url ? `<a href="${escapeHtml(summary.review_url)}" class="btn-outline-modern btn-sm ms-2"><i class="fas fa-clipboard-check"></i> Project Review</a>` : ''}
        `
        : '<div class="project-context-empty">Full Summary and Project Review are available to Architect accounts.</div>';

    return `
        <div class="project-context-section">
            <h6><i class="fas fa-drafting-compass"></i> Architect — Project Review Summary</h6>
            <div class="project-context-metrics">
                ${renderProjectMetric('Features', summary.feature_count !== null && summary.feature_count !== undefined ? summary.feature_count : 0)}
                ${renderProjectMetric('Technologies', summary.technology_count !== null && summary.technology_count !== undefined ? summary.technology_count : 0)}
                ${renderProjectMetric('Developer Roles', summary.allocation_count !== null && summary.allocation_count !== undefined ? summary.allocation_count : 0)}
                ${renderProjectMetric('Documents', summary.document_count !== null && summary.document_count !== undefined ? summary.document_count : 0)}
            </div>
            <div class="project-details-grid">
                <div class="project-detail-item">
                    <label>Cloud Platform</label>
                    <div class="value">${escapeHtml(project.cloud_platform || 'Not selected yet.')}</div>
                </div>
                <div class="project-detail-item">
                    <label>Database Server</label>
                    <div class="value">${escapeHtml(project.database_server || 'Not selected yet.')}</div>
                </div>
                <div class="project-detail-item">
                    <label>Storage</label>
                    <div class="value">${escapeHtml(project.storage_requirements || 'Not specified yet.')}</div>
                </div>
                ${renderProjectTextBlock('Additional Services', project.additional_services)}
            </div>
            <div class="project-context-list">
                ${architectActions}
            </div>
            <div class="project-context-list">
                <label>Recommended Technologies</label>
                ${renderProjectPillList(
                    technologies.map(item => item.technology_name || item.service_type || 'Recommendation'),
                    'No technology recommendations submitted yet.'
                )}
            </div>
            <div class="project-context-list">
                <label>Uploaded Architecture Documents</label>
                ${documents.length ? documents.map(doc => `
                    <div class="project-context-row">
                        <span>
                            ${escapeHtml(doc.file_name || doc.document_type || 'Document')}
                            <small class="d-block text-muted">${escapeHtml(formatProjectLabel(doc.document_type))}${doc.uploaded_by_name ? ' · ' + escapeHtml(doc.uploaded_by_name) : ''}</small>
                        </span>
                        <small>
                            ${doc.file_url ? `<a href="${escapeHtml(doc.file_url)}" target="_blank" class="file-link">View</a>` : '—'}
                        </small>
                    </div>
                `).join('') : '<div class="project-context-empty">No architecture documents uploaded yet.</div>'}
            </div>
        </div>
    `;
}

function renderProjectDepartmentTeams(project) {
    const teams = Array.isArray(project.department_teams) ? project.department_teams : [];
    return teams.map(team => `
        <div class="project-context-list">
            <h6>${escapeHtml(team.department)}</h6>
            <div class="project-context-row">
                <span>Service Unit Leader</span>
                <small>${escapeHtml((team.suls || []).map(member => member.full_name).join(', ') || 'No department SUL assigned')}</small>
            </div>
            <label>Assigned Developers</label>
            ${(team.developers || []).length ? team.developers.map(member => `
                <div class="project-context-row"><span>${escapeHtml(member.full_name)}</span></div>
            `).join('') : '<div class="project-context-empty">No developers assigned yet.</div>'}
        </div>
    `).join('') || '<div class="project-context-empty">No department assignments yet.</div>';
}

function renderSulSection(project) {
    const assignments = project.sul_assignments || {};
    const experts = groupAssignmentsByUser(assignments.experts || []);
    const developerTasks = groupAssignmentsByUser(assignments.developer_tasks || []);
    const developerAllocations = Array.isArray(assignments.developer_allocations) ? assignments.developer_allocations : [];

    const allocationRows = developerAllocations.filter(row => row.full_name).map(row => `
        <div class="project-context-row">
            <span>${escapeHtml(row.full_name)} <small class="text-muted">(${escapeHtml(row.allocation_role || row.role || 'Developer')})</small></span>
            <small>${escapeHtml(formatProjectLabel(row.assignment_status || 'assigned'))}</small>
        </div>
    `).join('');

    return `
        <div class="project-context-section">
            <h6><i class="fas fa-users-cog"></i> SUL — Team Assignments</h6>
            ${renderProjectDepartmentTeams(project)}
            <div class="project-context-list">
                <label>Assigned Experts</label>
                ${experts.length ? experts.map(expert => `
                    <div class="project-context-row">
                        <span>${escapeHtml(expert.full_name)}</span>
                        <small>${expert.tasks.length ? escapeHtml(expert.tasks.join(', ')) : 'Assigned'}</small>
                    </div>
                `).join('') : '<div class="project-context-empty">No experts assigned yet.</div>'}
            </div>
            ${!Array.isArray(project.department_teams) ? `<div class="project-context-list">
                <label>Assigned Developers</label>
                ${(developerTasks.length || allocationRows) ? `
                    ${developerTasks.map(developer => `
                        <div class="project-context-row">
                            <span>${escapeHtml(developer.full_name)}</span>
                            <small>${developer.tasks.length ? escapeHtml(developer.tasks.join(', ')) : 'Assigned'}</small>
                        </div>
                    `).join('')}
                    ${allocationRows}
                ` : '<div class="project-context-empty">No developers assigned yet.</div>'}
            </div>` : ''}
        </div>
    `;
}

function renderExpertSection(project) {
    const timeline = project.approved_timeline || {};

    return `
        <div class="project-context-section">
            <h6><i class="fas fa-calendar-check"></i> Expert — Approved Timeline & Gantt</h6>
            ${timeline.approved
                ? '<div class="project-context-pills"><span>Timeline Approved by Sales</span></div>'
                : '<div class="project-context-empty">Timeline has not been approved yet.</div>'}
            ${renderApprovedTimelineGantt(timeline)}
        </div>
    `;
}

function renderSharedFolderFilesSection(project) {
    const files = Array.isArray(project.shared_folder_files) ? project.shared_folder_files : [];

    return `
        <div class="project-context-section">
            <h6><i class="fas fa-folder-open"></i> Shared Folder Uploads</h6>
            ${files.length ? files.map(file => `
                <div class="project-context-row">
                    <span>
                        ${escapeHtml(file.original_file_name || 'File')}
                        <small class="d-block text-muted">${escapeHtml(file.owner_name || 'Unknown')}${file.folder_name ? ' · ' + escapeHtml(file.folder_name) : ''}</small>
                    </span>
                    <small>
                        ${file.file_url ? `<a href="${escapeHtml(file.file_url)}" target="_blank" class="file-link">View</a>` : '—'}
                    </small>
                </div>
            `).join('') : '<div class="project-context-empty">No files uploaded to this project folder yet.</div>'}
        </div>
    `;
}

function renderFullProjectPanel(project, options = {}) {
    const tasks = project.task_counts || {};
    const showSharedFolderFiles = options.showSharedFolderFiles !== false;

    return `
        <div class="project-details-panel">
            <div class="project-context-header">
                <div>
                    <h5><i class="fas fa-project-diagram"></i> Project Information</h5>
                    <p>All uploads and submissions from every role working on this project.</p>
                </div>
                <span class="status-badge">${formatProjectLabel(project.status)}</span>
            </div>

            <div class="project-details-grid project-details-summary">
                <div class="project-detail-item">
                    <label>Client</label>
                    <div class="value">${escapeHtml(project.client_name || 'N/A')}</div>
                </div>
                <div class="project-detail-item">
                    <label>Project Type</label>
                    <div class="value">${escapeHtml(project.project_type || 'Not specified')}</div>
                </div>
                <div class="project-detail-item">
                    <label>Timeline</label>
                    <div class="value">${formatProjectDate(project.timeline_start)} to ${formatProjectDate(project.timeline_end)}</div>
                </div>
                <div class="project-detail-item">
                    <label>Budget</label>
                    <div class="value">${formatProjectMoney(project.sales_budget)}</div>
                </div>
                <div class="project-detail-item">
                    <label>Complexity</label>
                    <div class="value">${formatProjectLabel(project.complexity_level)}</div>
                </div>
                <div class="project-detail-item">
                    <label>Architect</label>
                    <div class="value">${escapeHtml(project.architect_name || 'Unassigned')}</div>
                </div>
            </div>

            ${renderSalesIntakeSection(project)}
            ${renderArchitectSection(project)}
            ${renderSulSection(project)}
            ${renderExpertSection(project)}
            ${showSharedFolderFiles ? renderSharedFolderFilesSection(project) : ''}

            <div class="project-context-section">
                <h6><i class="fas fa-tasks"></i> Project Progress</h6>
                <div class="project-gantt-track" style="margin-bottom: 0.75rem;">
                    <div class="project-gantt-bar project-gantt-bar-task" style="left: 0; width: ${Number(tasks.progress_percent || 0)}%;"></div>
                </div>
                <div class="project-context-metrics">
                    ${renderProjectMetric('Total Tasks', tasks.total)}
                    ${renderProjectMetric('Active', tasks.active)}
                    ${renderProjectMetric('Merged Features', `${tasks.merged_features || 0}/${tasks.total_features || 0}`)}
                    ${renderProjectMetric('Progress', `${Number(tasks.progress_percent || 0)}%`)}
                    ${renderProjectMetric('Blocked', tasks.blocked)}
                    ${renderProjectMetric('Overdue', tasks.overdue)}
                </div>
            </div>
        </div>
    `;
}

function renderProjectDetailsPanel(project, options = {}) {
    if (!project) {
        $('#projectDetailsPanel').html('');
        return;
    }

    $('#projectDetailsPanel').html(renderFullProjectPanel(project, options));
}
