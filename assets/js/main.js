$(function () {
    initSidebar();
    initDarkMode();
    initNotifications();
    initPageHeaderHelp();
    initTooltips();
    initAppActionHelpers();
    initGenericFormDrafts();
    initFormSubmitConfirmations();
});

function initGenericFormDrafts() {
    $('form[method="POST"], form[method="post"]').each(function (formIndex) {
        const form = this;
        const $form = $(form);
        if ($form.data('genericDraftDisabled') || $form.find('[data-save-draft]').length || $form.find('input[type="password"]').length) {
            return;
        }

        const formId = form.id || form.getAttribute('name') || `form-${formIndex}`;
        const draftKey = `erniPms.formDraft.${location.pathname}.${formId}`;
        const $submit = $form.find('button[type="submit"], input[type="submit"]').last();
        const $draftButton = $('<button type="button" class="btn btn-outline-primary btn-sm ms-2" data-generic-save-draft><i class="fas fa-save me-1"></i> Save Draft</button>');
        const $draftStatus = $('<span class="text-muted small ms-2" data-generic-draft-status></span>');

        function readFormData() {
            const data = {};
            $(form).find('input, textarea, select').each(function () {
                const field = this;
                const name = field.name;
                if (!name || field.type === 'file' || field.type === 'password' || field.type === 'hidden') {
                    return;
                }
                if (field.type === 'checkbox' || field.type === 'radio') {
                    data[name] = field.checked ? field.value : '';
                    return;
                }
                data[name] = $(field).val();
            });
            return data;
        }

        function hasDraftData(data) {
            return Object.values(data).some((value) => String(value || '').trim() !== '');
        }

        function saveDraft() {
            const data = readFormData();
            if (!hasDraftData(data)) {
                localStorage.removeItem(draftKey);
                $draftStatus.text('Draft cleared');
                return;
            }
            localStorage.setItem(draftKey, JSON.stringify({ savedAt: new Date().toISOString(), data }));
            $draftStatus.text('Draft saved');
        }

        function loadDraft() {
            const raw = localStorage.getItem(draftKey);
            if (!raw) {
                return;
            }
            try {
                const payload = JSON.parse(raw);
                Object.entries(payload.data || {}).forEach(([name, value]) => {
                    const fields = form.elements[name];
                    if (!fields) return;
                    const list = fields instanceof RadioNodeList ? Array.from(fields) : [fields];
                    list.forEach((field) => {
                        if (field.type === 'checkbox' || field.type === 'radio') {
                            field.checked = String(field.value) === String(value);
                        } else {
                            field.value = value;
                        }
                    });
                });
                $draftStatus.text('Draft restored');
            } catch (error) {
                localStorage.removeItem(draftKey);
            }
        }

        if ($submit.length) {
            $submit.after($draftStatus).after($draftButton);
        } else {
            $form.append($('<div class="mt-3"></div>').append($draftButton, $draftStatus));
        }

        $draftButton.on('click', saveDraft);
        $form.on('submit', function () {
            if (form.dataset.confirmedSubmit === '1' || form.id === 'addClientForm') {
                localStorage.removeItem(draftKey);
            }
        });
        loadDraft();
    });
}

function initFormSubmitConfirmations() {
    $('form[method="POST"], form[method="post"]').each(function () {
        const form = this;
        const $form = $(form);
        if (form.id === 'addClientForm' || $form.data('confirmSkip')) {
            return;
        }

        $form.on('submit.genericConfirm', async function (event) {
            if (form.dataset.confirmedSubmit === '1') {
                form.dataset.confirmedSubmit = '0';
                return;
            }

            const submitter = event.originalEvent?.submitter
                || form.querySelector('button[type="submit"]:not([disabled]), input[type="submit"]:not([disabled])');

            event.preventDefault();
            const confirmed = typeof window.appConfirm === 'function'
                ? await window.appConfirm({
                    title: 'Confirm Submission',
                    subject: 'Submit this form?',
                    message: 'The workflow will continue after this action is saved.',
                    icon: 'fas fa-circle-check fa-lg',
                    confirmIcon: 'fas fa-check',
                    confirmText: 'Submit'
                })
                : window.confirm('Submit this form?');

            if (confirmed) {
                form.dataset.confirmedSubmit = '1';
                if (submitter && submitter.form === form) {
                    form.requestSubmit(submitter);
                } else {
                    form.requestSubmit();
                }
            }
        });
    });
}

function initSidebar() {
    const sidebar = $('#sidebar-wrapper');
    const content = $('#page-content-wrapper');
    const overlay = $('.sidebar-overlay');
    const toggle = $('.sidebar-toggle-btn');
    if (!sidebar.length || !content.length || !toggle.length) {
        return;
    }

    const storageScope = getSidebarStateScope();
    const desktopStateKey = `erni.sidebar.${storageScope}.collapsed`;
    const mobileStateKey = `erni.sidebar.${storageScope}.mobile_open`;

    function updateToggleState() {
        const isActive = window.innerWidth <= 992 ? sidebar.hasClass('active') : sidebar.hasClass('collapsed');
        toggle.toggleClass('is-active', isActive);
    }

    function applySidebarState() {
        if (window.innerWidth <= 992) {
            const isMobileOpen = getStoredSidebarValue(mobileStateKey) === 'true';
            sidebar.removeClass('collapsed');
            content.removeClass('sidebar-collapsed');
            sidebar.toggleClass('active', isMobileOpen);
            overlay.toggleClass('active', isMobileOpen);
            updateToggleState();
            return;
        }

        const isCollapsed = getStoredSidebarValue(desktopStateKey) === 'true';
        sidebar.removeClass('active');
        overlay.removeClass('active');
        sidebar.toggleClass('collapsed', isCollapsed);
        content.toggleClass('sidebar-collapsed', isCollapsed);
        updateToggleState();
    }

    applySidebarState();

    toggle.on('click', function (event) {
        event.preventDefault();

        if (window.innerWidth <= 992) {
            const nextOpenState = !sidebar.hasClass('active');
            sidebar.toggleClass('active', nextOpenState);
            overlay.toggleClass('active', nextOpenState);
            setStoredSidebarValue(mobileStateKey, String(nextOpenState));
            updateToggleState();
            return;
        }

        const nextCollapsedState = !sidebar.hasClass('collapsed');
        sidebar.toggleClass('collapsed', nextCollapsedState);
        content.toggleClass('sidebar-collapsed', nextCollapsedState);
        setStoredSidebarValue(desktopStateKey, String(nextCollapsedState));
        updateToggleState();
    });

    overlay.on('click', function () {
        sidebar.removeClass('active');
        overlay.removeClass('active');
        setStoredSidebarValue(mobileStateKey, 'false');
        updateToggleState();
    });

    $(window).on('resize', debounce(function () {
        applySidebarState();
    }, 150));

    window.addEventListener('pageshow', function () {
        applySidebarState();
    });
}

function getSidebarStateScope() {
    const context = window.APP_USER_CONTEXT || {};
    const userId = Number(context.id || 0);

    if (Number.isFinite(userId) && userId > 0) {
        return `user-${userId}`;
    }

    const role = String(context.role || '').trim().toLowerCase();
    if (role) {
        return `role-${role}`;
    }

    const path = String(window.location.pathname || '').replace(/[^a-z0-9]+/gi, '-').replace(/^-+|-+$/g, '').toLowerCase();
    return path ? `page-${path}` : 'guest';
}

function getStoredSidebarValue(key) {
    try {
        return window.localStorage.getItem(key);
    } catch (error) {
        return null;
    }
}

function setStoredSidebarValue(key, value) {
    try {
        window.localStorage.setItem(key, value);
    } catch (error) {
        // Ignore storage failures so the toggle still works in restricted browsers.
    }
}

function initDarkMode() {
    const root = $('html');
    const savedTheme = localStorage.getItem('theme') || 'light';
    root.attr('data-theme', savedTheme);
    updateThemeIcon(savedTheme);

    $('#darkModeToggle').on('click', function (event) {
        event.preventDefault();
        const nextTheme = (root.attr('data-theme') || 'light') === 'dark' ? 'light' : 'dark';
        root.attr('data-theme', nextTheme);
        localStorage.setItem('theme', nextTheme);
        updateThemeIcon(nextTheme);
    });
}

function updateThemeIcon(theme) {
    const icon = $('#darkModeToggle i');
    icon.removeClass('fa-moon fa-sun').addClass(theme === 'dark' ? 'fa-sun' : 'fa-moon');
}

function initNotifications() {
    if (!$('#notificationsDropdown').length) {
        return;
    }

    loadNotifications();
    setInterval(loadNotifications, 15000);
}

function loadNotifications() {
    $.ajax({
        url: `${BASE_URL}api/notifications/get-notifications.php`,
        method: 'GET',
        dataType: 'json',
        success: function (payload) {
            const notifications = Array.isArray(payload) ? payload : (payload.notifications || []);
            const unreadCount = Array.isArray(payload) ? notifications.filter(n => !Number(n.is_read)).length : (payload.unread_count || 0);

            updateNotificationBadge(unreadCount);
            updateNotificationList(notifications);
        }
    });
}

function updateNotificationBadge(count) {
    const badge = $('#notificationBadge');
    badge.text(count);
    badge.toggleClass('d-none', count <= 0);
}

function updateNotificationList(notifications) {
    const list = $('#notificationsList');
    if (!list.length) {
        return;
    }

    list.empty();

    if (!notifications.length) {
        list.append('<li><span class="dropdown-item text-muted">No notifications yet.</span></li>');
        return;
    }

    const sortedNotifications = notifications.slice().sort(function (a, b) {
        const readDiff = Number(a.is_read) - Number(b.is_read);
        if (readDiff !== 0) {
            return readDiff;
        }

        return new Date(b.created_at || 0) - new Date(a.created_at || 0);
    });

    sortedNotifications.slice(0, 8).forEach(function (notification) {
        const unreadClass = Number(notification.is_read) ? '' : 'bg-light';
        const safeTitle = escapeHtml(notification.title || 'Notification');
        const safeMessage = escapeHtml(notification.message || '');
        const actionUrl = escapeHtml(notification.action_url || `${BASE_URL}notifications.php`);
        const safeTimeAgo = escapeHtml(notification.time_ago || 'just now');
        const safeCreatedAt = escapeHtml(notification.formatted_created_at || notification.created_at || '');

        list.append(`
            <li>
                <a class="dropdown-item py-2 ${unreadClass}" href="${actionUrl}" onclick="markNotificationRead(${notification.id})">
                    <div class="notification-item-title fw-semibold small">${safeTitle}</div>
                    <div class="notification-item-message text-muted small">${safeMessage}</div>
                    <div class="notification-item-time text-muted small mt-1" title="${safeCreatedAt}">
                        <i class="far fa-clock me-1"></i>${safeTimeAgo}
                    </div>
                </a>
            </li>
        `);
    });
}

function markNotificationRead(id) {
    $.post(`${BASE_URL}api/notifications/mark-read.php`, { id: id }).done(loadNotifications);
}

function getPageProcessHelp() {
    const helpByRoute = {
        '/modules/admin/dashboard.php': 'Review system health, pending requests, user activity, and governance signals, then open the area that needs attention.',
        '/modules/admin/techstack.php': 'Review the approved technology catalog, then add or update technologies and verify their status before saving.',
        '/modules/admin/user-management.php': 'Search or filter users, then add, view, edit, or manage access for the selected account.',
        '/modules/admin/edit-user.php': 'Update the user profile, role, department, or account status, then save the changes.',
        '/modules/admin/view-user.php': 'Review the user account details, then use the available action to edit or manage the account.',
        '/modules/admin/password-reset-requests.php': 'Review each pending password request, verify the user, then approve or reject the request.',
        '/modules/admin/audit-log.php': 'Filter activity by user, action, or date, inspect the record details, then export or restore data when appropriate.',
        '/modules/admin/settings.php': 'Choose a settings category, update the required values, test integrations when available, then save the configuration.',
        '/modules/sales/dashboard.php': 'Review the client pipeline, deadlines, estimates, and release signals, then open the item that needs follow-up.',
        '/modules/sales/clients.php': 'Add a client, complete each intake step, save a draft if needed, then submit the project for Architect review.',
        '/modules/sales/estimation-approval.php': 'Review the submitted timeline and cost estimate, compare the details, then approve it or request changes.',
        '/modules/sales/release-status.php': 'Review release readiness and delivery progress, then update or communicate the current release status.',
        '/modules/sales/report.php': 'Choose the report scope and period, review the calculated totals, then generate or export the sales report.',
        '/modules/sales/receipt.php': 'Select the approved billing data, verify the charges and client details, then generate the receipt.',
        '/modules/architect/dashboard.php': 'Review projects entering technical evaluation and open the project that needs the next architecture action.',
        '/modules/architect/projects.php': 'Select a project awaiting technical evaluation, review its intake, then open the required architecture step.',
        '/modules/architect/project-review.php': 'Review the client requirements and intake details, record technical findings, then continue to feature definition.',
        '/modules/architect/features.php': 'Define the required project features and acceptance details, then save them for estimation.',
        '/modules/architect/tech-recommendation.php': 'Choose suitable technologies, document the architecture guidance and rationale, then save the recommendation.',
        '/modules/architect/developer-allocation.php': 'Review approved estimates and available resources, then assign suitable developers to the project.',
        '/modules/architect/upload-diagram.php': 'Upload architecture diagrams or supporting documents, add their descriptions, then save them to the project.',
        '/modules/architect/summary.php': 'Review the features, technology choices, estimates, team plan, and artifacts, then resolve any missing items.',
        '/modules/architect/final-approval.php': 'Review completed work, QA evidence, and merge readiness, then approve the merge or return it for revision.',
        '/modules/roadmap/methodology.php': 'Select a project, organize its phases and tasks, set dates or dependencies, then save the delivery roadmap.',
        '/modules/version-control/repositories.php': 'Select a project and repository, then create or synchronize branches, commits, pushes, pulls, and merges.',
        '/modules/sul/dashboard.php': 'Review team workload, task flow, deadlines, and delivery risks, then open the area that needs intervention.',
        '/modules/sul/task-allocation.php': 'Choose the Expert or Developer workflow, review pending tasks, then assign or update the responsible team member.',
        '/modules/sul/resource-management.php': 'Review availability and workload, then add, update, or rebalance resources and assignments.',
        '/modules/sul/performance-report.php': 'Set the reporting period and filters, review team performance metrics, then export or print the report.',
        '/modules/qa/dashboard.php': 'Review tasks ready for testing, record the test result and defects, then pass the task or return it for fixes.',
        '/modules/expert/dashboard.php': 'Review projects awaiting estimation, then open the next feature, cost, or team-planning activity.',
        '/modules/expert/feature-estimation.php': 'Select a project, estimate the effort and complexity of each feature, then continue to cost and team planning.',
        '/modules/expert/cost-estimation.php': 'Review the feature effort, enter the cost assumptions and rates, then save the project cost estimate.',
        '/modules/expert/team-composition.php': 'Choose the roles and team size needed for the estimated work, then submit the proposed team.',
        '/modules/developer/dashboard.php': 'Review assigned tasks, deadlines, and status summaries, then open the task that needs work.',
        '/modules/developer/task.php': 'Open an assigned task, review its requirements, complete the work, then update the task status.',
        '/modules/insights/decision-center.php': 'Choose a project, review role-specific risks and delivery signals, then use the evidence to make the next decision.',
        '/calendar.php': 'Add or select an event, set its date, time, and details, then save or update the schedule.',
        '/folders.php': 'Open a project folder, upload or organize files, then use the available actions to share, download, or remove items.',
        '/notifications.php': 'Review system workflow notifications, open the related item, then mark notifications as read or remove them.',
        '/profile.php': 'Review your account details, then use the available actions to update your profile or password.',
        '/settings.php': 'Update your account settings or password, then save the changes; administrators can also open system settings.'
    };
    const pathname = String(window.location.pathname || '').replace(/\\/g, '/').toLowerCase();
    const modulesIndex = pathname.lastIndexOf('/modules/');
    const route = modulesIndex >= 0
        ? pathname.slice(modulesIndex)
        : `/${pathname.split('/').filter(Boolean).pop() || ''}`;

    return helpByRoute[route] || '';
}

function initPageHeaderHelp() {
    const pageRoot = document.getElementById('page-content-wrapper');
    if (!pageRoot || pageRoot.querySelector('.page-header-help-btn')) {
        return;
    }

    const preferredSelectors = [
        '[data-page-header]',
        '.page-header-modern',
        '.dashboard-header',
        '.content-header',
        '.roadmap-header',
        '.vc-header',
        '.insights-hero',
        '.profile-header',
        '.notification-card > .card-header'
    ];
    let header = null;

    for (const selector of preferredSelectors) {
        const candidate = pageRoot.querySelector(selector);
        if (candidate && !candidate.closest('.modal, .offcanvas, .chat-widget')) {
            header = candidate;
            break;
        }
    }

    const headingSelector = 'h1, h2, h3, h4, h5, .page-title, .vc-header-title, [class$="-header-title"]';
    let heading = header ? header.querySelector(headingSelector) : null;
    if (!header || !heading) {
        heading = Array.from(pageRoot.querySelectorAll(headingSelector)).find(function (candidate) {
            return !candidate.closest('nav, .modal, .offcanvas, .accordion, .dropdown-menu, .chat-widget');
        }) || null;

        if (heading) {
            header = heading.closest('.card-header, .card-body, header, .row') || heading.parentElement;
        }
    }

    if (!header || !heading) {
        return;
    }

    const title = heading.textContent.replace(/\s+/g, ' ').trim() || 'this page';
    const subtitle = header.querySelector('p');
    const customHelp = header.getAttribute('data-page-help');
    const processHelp = getPageProcessHelp();
    const subtitleText = subtitle ? subtitle.textContent.replace(/\s+/g, ' ').trim() : '';
    const helpText = customHelp
        || processHelp
        || (subtitleText
            ? `${subtitleText} Complete the required fields or actions, then save or continue to the next step.`
            : `Review ${title}, complete the required fields or actions, then save or continue to the next step.`);

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'page-header-help-btn';
    button.setAttribute('aria-label', `Help for ${title}`);
    button.setAttribute('data-bs-toggle', 'tooltip');
    button.setAttribute('data-bs-placement', 'left');
    button.setAttribute('data-bs-custom-class', 'page-process-tooltip');
    button.setAttribute('title', helpText);
    button.innerHTML = '<i class="fas fa-circle-question" aria-hidden="true"></i>';

    header.classList.add('page-help-host');
    header.appendChild(button);
}

function initTooltips() {
    if (!window.bootstrap || !bootstrap.Tooltip) {
        return;
    }

    document.querySelectorAll('[title]').forEach(function (element) {
        bootstrap.Tooltip.getOrCreateInstance(element, { trigger: 'hover focus' });
    });
}

function showToast(title, message, type = 'success') {
    const mappedType = type === 'error' ? 'danger' : type;
    const toastId = `toast-${Date.now()}`;

    $('body').append(`
        <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1100;">
            <div id="${toastId}" class="toast align-items-center text-white bg-${mappedType} border-0" role="alert" data-bs-delay="3000">
                <div class="d-flex">
                    <div class="toast-body">
                        <strong>${escapeHtml(title)}</strong><br>
                        ${escapeHtml(message)}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        </div>
    `);

    const toastEl = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastEl);
    toast.show();
    toastEl.addEventListener('hidden.bs.toast', function () {
        $(toastEl).closest('.toast-container').remove();
    });
}

function initAppActionHelpers() {
    window.appShowLoading = showAppLoading;
    window.appHideLoading = hideAppLoading;
    window.appConfirm = showAppConfirm;

    $(document).on('submit', 'form[data-app-loading]', function (event) {
        if (event.isDefaultPrevented()) {
            return;
        }

        const form = this;
        const title = form.getAttribute('data-loading-title') || 'Saving changes';
        const text = form.getAttribute('data-loading-text') || 'Please wait while the system finishes this action.';
        showAppLoading(title, text);
    });
}

function showAppLoading(title = 'Working on it', text = 'Please wait while the system finishes this action.') {
    const overlay = document.getElementById('appGlobalLoadingOverlay');
    if (!overlay) {
        return;
    }

    const titleEl = document.getElementById('appGlobalLoadingTitle');
    const textEl = document.getElementById('appGlobalLoadingText');
    if (titleEl) titleEl.textContent = title;
    if (textEl) textEl.textContent = text;

    overlay.classList.add('is-visible');
    overlay.setAttribute('aria-hidden', 'false');
}

function hideAppLoading() {
    const overlay = document.getElementById('appGlobalLoadingOverlay');
    if (!overlay) {
        return;
    }

    overlay.classList.remove('is-visible');
    overlay.setAttribute('aria-hidden', 'true');
}

function showAppConfirm(options = {}) {
    const modalElement = document.getElementById('appConfirmModal');
    const submitBtn = document.getElementById('appConfirmSubmitBtn');
    if (!modalElement || !submitBtn || !window.bootstrap) {
        return Promise.resolve(window.confirm(options.message || options.subject || 'Continue?'));
    }

    const titleEl = document.getElementById('appConfirmTitle');
    const subjectEl = document.getElementById('appConfirmSubject');
    const messageEl = document.getElementById('appConfirmMessage');
    const iconEl = document.getElementById('appConfirmIcon');
    const cancelBtn = document.getElementById('appConfirmCancelBtn');

    if (titleEl) {
        titleEl.innerHTML = `<i class="${escapeHtml(options.titleIcon || 'fas fa-triangle-exclamation')} me-2"></i>${escapeHtml(options.title || 'Confirm Action')}`;
    }
    if (subjectEl) subjectEl.textContent = options.subject || 'Please confirm this action.';
    if (messageEl) messageEl.textContent = options.message || 'This action may affect the system workflow.';
    if (iconEl) iconEl.className = options.icon || 'fas fa-circle-info fa-lg';
    if (submitBtn) {
        submitBtn.innerHTML = `<i class="${escapeHtml(options.confirmIcon || 'fas fa-check')} me-1"></i>${escapeHtml(options.confirmText || 'Continue')}`;
        submitBtn.classList.toggle('is-danger', options.variant === 'danger');
    }
    if (cancelBtn) cancelBtn.textContent = options.cancelText || 'Cancel';

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);

    return new Promise((resolve) => {
        let settled = false;

        function cleanup() {
            submitBtn.removeEventListener('click', onConfirm);
            modalElement.removeEventListener('hidden.bs.modal', onHidden);
        }

        function onConfirm() {
            settled = true;
            cleanup();
            modal.hide();
            resolve(true);
        }

        function onHidden() {
            cleanup();
            if (!settled) {
                resolve(false);
            }
        }

        submitBtn.addEventListener('click', onConfirm);
        modalElement.addEventListener('hidden.bs.modal', onHidden);
        modal.show();
    });
}

function debounce(fn, wait) {
    let timeoutId;

    return function (...args) {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => fn.apply(this, args), wait);
    };
}

function escapeHtml(text) {
    return String(text ?? '').replace(/[&<>"']/g, function (character) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[character];
    });
}
