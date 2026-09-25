$(function () {
    initSidebar();
    initDarkMode();
    initNotifications();
    initPageHeaderHelp();
    initAppWide3d();
    initTooltips();
    initModalBackdropRecovery();
    initAppActionHelpers();
    initRoleDashboard3d();
    initSystemLoadAnimations();
    initGenericFormDrafts();
    initFormSubmitConfirmations();
    initPasswordToggles();
});

function initPasswordToggles() {
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        if (button.dataset.passwordToggleInitialized === '1') {
            return;
        }

        const input = button.closest('.password-field')?.querySelector('input[type="password"], input[type="text"]');
        if (!input) {
            return;
        }

        button.dataset.passwordToggleInitialized = '1';
        button.setAttribute('aria-label', 'Show password');

        button.addEventListener('click', () => {
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            button.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            button.setAttribute('title', isHidden ? 'Hide password' : 'Show password');
            button.querySelector('i')?.classList.toggle('fa-eye', !isHidden);
            button.querySelector('i')?.classList.toggle('fa-eye-slash', isHidden);
        });
    });
}

function initRoleDashboard3d() {
    const canTilt = window.matchMedia('(hover: hover) and (pointer: fine)').matches
        && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!canTilt) {
        return;
    }

    document.querySelectorAll('.role-dashboard-hero').forEach((card) => {
        if (card.dataset.roleHeroTiltInitialized === '1') {
            return;
        }

        card.dataset.roleHeroTiltInitialized = '1';
        let animationFrame = null;

        card.addEventListener('pointermove', (event) => {
            if (animationFrame !== null) {
                cancelAnimationFrame(animationFrame);
            }

            animationFrame = requestAnimationFrame(() => {
                const bounds = card.getBoundingClientRect();
                const pointerX = Math.min(1, Math.max(0, (event.clientX - bounds.left) / bounds.width));
                const pointerY = Math.min(1, Math.max(0, (event.clientY - bounds.top) / bounds.height));

                card.style.setProperty('--role-hero-tilt-x', `${((0.5 - pointerY) * 2.4).toFixed(2)}deg`);
                card.style.setProperty('--role-hero-tilt-y', `${((pointerX - 0.5) * 2.4).toFixed(2)}deg`);
                card.style.setProperty('--role-hero-pointer-x', `${(pointerX * 100).toFixed(1)}%`);
                card.style.setProperty('--role-hero-pointer-y', `${(pointerY * 100).toFixed(1)}%`);
                card.classList.add('is-role-hero-tilting');
                animationFrame = null;
            });
        });

        const resetTilt = () => {
            if (animationFrame !== null) {
                cancelAnimationFrame(animationFrame);
                animationFrame = null;
            }

            card.style.setProperty('--role-hero-tilt-x', '0deg');
            card.style.setProperty('--role-hero-tilt-y', '0deg');
            card.style.setProperty('--role-hero-pointer-x', '50%');
            card.style.setProperty('--role-hero-pointer-y', '50%');
            card.classList.remove('is-role-hero-tilting');
        };

        card.addEventListener('pointerleave', resetTilt);
        card.addEventListener('pointercancel', resetTilt);
    });
}

function initSystemLoadAnimations() {
    const pageRoot = document.getElementById('page-content-wrapper');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!pageRoot || reduceMotion || pageRoot.dataset.systemAnimationInitialized === '1') {
        return;
    }

    pageRoot.dataset.systemAnimationInitialized = '1';

    const revealSelectors = [
        '.role-dashboard-hero',
        '.sales-dashboard-hero',
        '.stat-card-modern',
        '.stat-card',
        '.chart-card',
        '.list-card',
        '.card',
        '.main-card',
        '.info-card',
        '.form-card',
        '.summary-card',
        '.report-card',
        '.project-card',
        '.task-card',
        '.review-card',
        '.metric-card',
        '.alert',
        '.table-responsive',
        '.nav-tabs',
        '.pagination'
    ].join(', ');

    const revealElements = Array.from(pageRoot.querySelectorAll(revealSelectors))
        .filter((element) => !element.closest('.modal') && !element.parentElement?.closest(revealSelectors));

    revealElements.forEach((element, index) => {
        element.classList.add('app-load-reveal');
        element.style.setProperty('--app-reveal-delay', `${Math.min(index * 65, 650)}ms`);
    });

    const progressFills = pageRoot.querySelectorAll([
        '.progress-bar',
        '.progress-bar-custom',
        '.qa-progress-bar',
        '.release-progress-bar',
        '.project-progress-fill'
    ].join(', '));

    progressFills.forEach((fill, index) => {
        fill.classList.add('app-load-progress');
        fill.style.setProperty('--app-progress-delay', `${Math.min(index * 90, 540)}ms`);
    });

    const chartCanvases = Array.from(pageRoot.querySelectorAll('canvas'));
    chartCanvases.forEach((canvas, index) => {
        canvas.classList.add('app-load-chart');
        canvas.style.setProperty('--app-chart-delay', `${Math.min(index * 110, 550)}ms`);
    });

    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            pageRoot.classList.add('app-load-ready');
        });
    });

    if (!window.Chart || chartCanvases.length === 0) {
        return;
    }

    const replayCharts = (attempt = 0) => {
        let pendingCharts = 0;
        const chartInstances = window.Chart.instances
            ? Object.values(window.Chart.instances)
            : [];

        chartCanvases.forEach((canvas, index) => {
            if (canvas.dataset.appChartAnimated === '1') {
                return;
            }

            const chart = typeof window.Chart.getChart === 'function'
                ? window.Chart.getChart(canvas)
                : chartInstances.find((instance) => instance && instance.canvas === canvas);

            if (!chart || typeof chart.reset !== 'function' || typeof chart.update !== 'function') {
                pendingCharts++;
                return;
            }

            canvas.dataset.appChartAnimated = '1';
            window.setTimeout(() => {
                try {
                    chart.stop?.();
                    const currentAnimation = chart.options.animation;
                    chart.options.animation = {
                        ...(currentAnimation && typeof currentAnimation === 'object' ? currentAnimation : {}),
                        duration: 1200,
                        easing: 'easeOutQuart'
                    };
                    chart.reset();
                    chart.update();
                } catch (error) {
                    console.warn('Chart entrance animation could not be replayed.', error);
                }
            }, 120 + (index * 110));
        });

        if (pendingCharts > 0 && attempt < 6) {
            window.setTimeout(() => replayCharts(attempt + 1), 180);
        }
    };

    replayCharts();
}

function initAppWide3d() {
    const pageRoot = document.getElementById('page-content-wrapper');
    if (!pageRoot || pageRoot.querySelector('.sales-dashboard-shell, .role-dashboard-shell')) {
        return;
    }

    const surfaceSelector = [
        '.card',
        '.main-card',
        '.info-card',
        '.form-card',
        '.checklist-card',
        '.summary-card',
        '.report-card',
        '.calendar-card',
        '.calendar-panel',
        '.calendar-stat',
        '.notification-card',
        '.folder-tree-card',
        '.vc-card',
        '.process-filter-card',
        '.settings-nav-card',
        '.settings-card',
        '.project-card',
        '.task-card',
        '.review-card',
        '.metric-card',
        '.client-card'
    ].join(', ');

    pageRoot.querySelectorAll(surfaceSelector).forEach((surface) => {
        if (surface.closest('.modal, .offcanvas, .dropdown-menu, .chat-widget, .app-confirm-modal')) {
            return;
        }

        surface.classList.add('app-3d-surface');
    });
}

function initGenericFormDrafts() {
    $('form[method="POST"], form[method="post"]').each(function (formIndex) {
        const form = this;
        const $form = $(form);
        if ($form.data('genericDraftDisabled') || $form.find('[data-save-draft]').length || $form.find('input[type="password"]').length) {
            return;
        }

        const formId = form.id || form.getAttribute('name') || `form-${formIndex}`;
        const draftKey = `erniPms.formDraft.${location.pathname}.${formId}`;
        let saveTimer = null;

        function readFormData() {
            const data = {};
            $(form).find('input, textarea, select').each(function () {
                const field = this;
                const name = field.name;
                if (!name || field.type === 'file' || field.type === 'password' || field.type === 'hidden') {
                    return;
                }
                if (!data[name]) data[name] = [];
                data[name].push({
                    value: $(field).val(),
                    checked: field.type === 'checkbox' || field.type === 'radio' ? field.checked : null
                });
            });
            return data;
        }

        function saveDraft() {
            const data = readFormData();
            try {
                localStorage.setItem(draftKey, JSON.stringify({ savedAt: new Date().toISOString(), data }));
            } catch (error) {
                // Forms must remain usable when browser storage is unavailable.
            }
        }

        function queueSave() {
            window.clearTimeout(saveTimer);
            saveTimer = window.setTimeout(saveDraft, 250);
        }

        function loadDraft() {
            const raw = localStorage.getItem(draftKey);
            if (!raw) {
                return;
            }
            try {
                const payload = JSON.parse(raw);
                Object.entries(payload.data || {}).forEach(([name, savedFields]) => {
                    const fields = form.elements[name];
                    if (!fields) return;
                    const list = fields instanceof RadioNodeList ? Array.from(fields) : [fields];
                    const values = Array.isArray(savedFields) ? savedFields : [{ value: savedFields, checked: null }];
                    list.forEach((field, index) => {
                        const saved = values[index] || values.find((item) => String(item.value) === String(field.value));
                        if (!saved) return;
                        if (field.type === 'checkbox' || field.type === 'radio') {
                            field.checked = saved.checked === null
                                ? String(field.value) === String(saved.value)
                                : Boolean(saved.checked);
                        } else {
                            $(field).val(saved.value);
                        }
                    });
                });
            } catch (error) {
                localStorage.removeItem(draftKey);
            }
        }

        $form.on('input.genericDraft change.genericDraft', 'input, textarea, select', queueSave);
        window.addEventListener('pagehide', saveDraft);
        $form.on('submit', function () {
            if (form.dataset.confirmedSubmit === '1' || form.id === 'addClientForm') {
                window.clearTimeout(saveTimer);
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
            const confirmOptions = getFormSubmitConfirmOptions(form, submitter);

            event.preventDefault();
            const confirmed = typeof window.appConfirm === 'function'
                ? await window.appConfirm(confirmOptions)
                : false;

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

function getFormSubmitConfirmOptions(form, submitter) {
    const source = submitter || form;
    const visibleAction = (
        source?.textContent
        || source?.getAttribute?.('aria-label')
        || source?.getAttribute?.('title')
        || ''
    ).replace(/\s+/g, ' ').trim();
    const rawAction = (
        source?.getAttribute?.('data-confirm-action')
        || visibleAction
        || source?.getAttribute?.('data-action')
        || source?.getAttribute?.('value')
        || source?.getAttribute?.('name')
        || source?.textContent
        || form?.getAttribute?.('aria-label')
        || ''
    ).replace(/\s+/g, ' ').trim();

    const action = humanizeActionLabel(rawAction || 'Submit');
    const normalized = action.toLowerCase();
    const formLabel = (
        form?.getAttribute?.('data-confirm-item')
        || form?.getAttribute?.('data-form-label')
        || form?.querySelector?.('h1, h2, h3, h4, h5, legend, .modal-title')?.textContent
        || 'this form'
    ).replace(/\s+/g, ' ').trim();

    const presets = [
        {
            match: /delete|remove|cancel|void|archive|restore/i,
            title: 'Confirm Removal',
            subject: `${action} ${formLabel}?`,
            message: 'This action changes or removes saved system data. Please confirm before continuing.',
            icon: 'fas fa-trash-alt fa-lg',
            confirmIcon: 'fas fa-trash-alt',
            variant: 'danger'
        },
        {
            match: /reject|decline|return|reopen/i,
            title: 'Confirm Review Action',
            subject: `${action} ${formLabel}?`,
            message: 'The item will be sent back with this review decision.',
            icon: 'fas fa-reply fa-lg',
            confirmIcon: 'fas fa-reply',
            variant: /reject|decline/i.test(normalized) ? 'danger' : ''
        },
        {
            match: /approve|accept|pass|complete|merge|release/i,
            title: 'Confirm Approval',
            subject: `${action} ${formLabel}?`,
            message: 'This will move the workflow forward using the details currently shown.',
            icon: 'fas fa-circle-check fa-lg',
            confirmIcon: 'fas fa-check'
        },
        {
            match: /upload|attach|import/i,
            title: 'Confirm Upload',
            subject: `${action} files for ${formLabel}?`,
            message: 'Selected files will be uploaded and linked to this record.',
            icon: 'fas fa-cloud-arrow-up fa-lg',
            confirmIcon: 'fas fa-upload'
        },
        {
            match: /assign|allocate/i,
            title: 'Confirm Assignment',
            subject: `${action} ${formLabel}?`,
            message: 'The selected person or team will receive this responsibility.',
            icon: 'fas fa-user-check fa-lg',
            confirmIcon: 'fas fa-user-check'
        },
        {
            match: /generate|export|print|download/i,
            title: 'Confirm Output',
            subject: `${action} ${formLabel}?`,
            message: 'The system will prepare the requested output from the current details.',
            icon: 'fas fa-file-export fa-lg',
            confirmIcon: 'fas fa-file-export'
        },
        {
            match: /save|update|edit|change|create|add|submit/i,
            title: /create|add/i.test(normalized) ? 'Confirm Creation' : 'Confirm Save',
            subject: `${action} ${formLabel}?`,
            message: 'Your current entries will be saved and used by the related workflow.',
            icon: /create|add/i.test(normalized) ? 'fas fa-plus-circle fa-lg' : 'fas fa-floppy-disk fa-lg',
            confirmIcon: /create|add/i.test(normalized) ? 'fas fa-plus' : 'fas fa-save'
        }
    ];

    const preset = presets.find(item => item.match.test(normalized)) || {
        title: 'Confirm Action',
        subject: `${action} ${formLabel}?`,
        message: 'Please confirm that you want to continue with this action.',
        icon: 'fas fa-circle-info fa-lg',
        confirmIcon: 'fas fa-check'
    };

    return {
        ...preset,
        title: source?.getAttribute?.('data-confirm-title') || form?.getAttribute?.('data-confirm-title') || preset.title,
        subject: source?.getAttribute?.('data-confirm-subject') || form?.getAttribute?.('data-confirm-subject') || preset.subject,
        message: source?.getAttribute?.('data-confirm-message') || form?.getAttribute?.('data-confirm-message') || preset.message,
        confirmText: source?.getAttribute?.('data-confirm-text') || action,
        cancelText: source?.getAttribute?.('data-cancel-text') || form?.getAttribute?.('data-cancel-text') || 'Cancel'
    };
}

function humanizeActionLabel(value) {
    const text = String(value || '')
        .replace(/[_-]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    if (!text) {
        return 'Submit';
    }

    return text.replace(/\b\w/g, letter => letter.toUpperCase());
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
    const body = $('body');
    const serverPreference = String(window.APP_USER_PREFERENCES?.theme || 'default');
    const visualTheme = ['tropical', 'futuristic'].includes(serverPreference) ? serverPreference : '';
    let defaultColorMode = 'light';

    try {
        if (serverPreference === 'default') {
            defaultColorMode = localStorage.getItem('theme') === 'dark' ? 'dark' : 'light';
        } else {
            localStorage.removeItem('theme');
        }
    } catch (error) {
        // Theme selection still works when browser storage is unavailable.
    }

    const applyTheme = theme => {
        root.attr('data-theme', theme);
        body.attr('data-theme', theme);
        if (visualTheme) {
            root.attr('data-app-theme', visualTheme);
            body.attr('data-app-theme', visualTheme);
        } else {
            root.removeAttr('data-app-theme');
            body.removeAttr('data-app-theme');
        }
        const icon = $('#darkModeToggle i');
        icon.removeClass('fa-moon fa-sun').addClass(theme === 'dark' ? 'fa-sun' : 'fa-moon');
    };

    applyTheme(serverPreference === 'futuristic'
        ? 'dark'
        : (serverPreference === 'default' ? defaultColorMode : 'light'));

    if (serverPreference === 'default') {
        $('#darkModeToggle').on('click', function (event) {
            event.preventDefault();
            const nextTheme = (root.attr('data-theme') || 'light') === 'dark' ? 'light' : 'dark';
            applyTheme(nextTheme);
            try {
                localStorage.setItem('theme', nextTheme);
            } catch (error) {
                // The mode still changes for the current page.
            }
        });
    }
}

function initNotifications() {
    if (!$('#notificationsDropdown').length) {
        return;
    }
    if (window.APP_USER_PREFERENCES?.inAppNotifications === false) {
        return;
    }

    loadNotifications();
    setInterval(loadNotifications, 5000);
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
            announceProjectTeamNotification(notifications);
        }
    });
}

const announcedProjectTeamNotifications = new Set();
let projectTeamAnnouncementAvailableAt = 0;

function announceProjectTeamNotification(notifications) {
    if (typeof window.evortixAnnounceNotification !== 'function'
        || window.evortixDashboardWelcomeActive
        || Date.now() < projectTeamAnnouncementAvailableAt) return;

    const notification = notifications.find(item => {
        if (Number(item.is_read)
            || String(item.related_table || '') !== 'projects'
            || !String(item.title || '').toLowerCase().startsWith('project team message:')) return false;
        const key = `evortix.projectNotification.${item.id}`;
        if (announcedProjectTeamNotifications.has(key)) return false;
        try { return !sessionStorage.getItem(key); } catch (error) { return true; }
    });
    if (!notification) return;

    const projectUrl = Number(notification.related_id || 0) > 0
        ? `${BASE_URL}modules/roadmap/methodology.php?project_id=${encodeURIComponent(notification.related_id)}`
        : notification.action_url;
    if (window.evortixAnnounceNotification(notification.title, notification.message, projectUrl) !== true) return;

    const storageKey = `evortix.projectNotification.${notification.id}`;
    announcedProjectTeamNotifications.add(storageKey);
    try { sessionStorage.setItem(storageKey, '1'); } catch (error) { /* Keep in-memory deduplication. */ }
    // Leave time to read each message before polling displays the next one.
    projectTeamAnnouncementAvailableAt = Date.now() + 15000;
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
        const actionUrl = escapeHtml(normalizeNotificationActionUrl(notification.action_url));
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

function normalizeNotificationActionUrl(actionUrl) {
    const rawUrl = String(actionUrl || '').trim();
    if (!rawUrl) {
        return `${BASE_URL}notifications.php`;
    }

    if (/^(?:https?:)?\/\//i.test(rawUrl) || rawUrl.startsWith('/') || rawUrl.startsWith('#')) {
        return rawUrl;
    }

    return `${BASE_URL}${rawUrl.replace(/^\/+/, '')}`;
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
        bootstrap.Tooltip.getOrCreateInstance(element, { trigger: 'hover' });
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
    window.appPrompt = showAppPrompt;
    window.appToast = showToast;

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

function initModalBackdropRecovery() {
    document.addEventListener('hidden.bs.modal', function () {
        window.setTimeout(function () {
            if (document.querySelector('.modal.show')) {
                return;
            }

            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
            document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
                backdrop.remove();
            });
        }, 80);
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
        return Promise.resolve(false);
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
        let confirmed = false;

        function cleanup() {
            submitBtn.removeEventListener('click', onConfirm);
            modalElement.removeEventListener('hidden.bs.modal', onHidden);
        }

        function onConfirm() {
            confirmed = true;
            modal.hide();
        }

        function onHidden() {
            cleanup();
            resolve(confirmed);
        }

        submitBtn.addEventListener('click', onConfirm);
        modalElement.addEventListener('hidden.bs.modal', onHidden);
        modal.show();
    });
}

function showAppPrompt(options = {}) {
    if (!window.bootstrap) {
        return Promise.resolve(null);
    }

    let modalElement = document.getElementById('appPromptModal');
    if (!modalElement) {
        modalElement = document.createElement('div');
        modalElement.className = 'modal fade app-confirm-modal';
        modalElement.id = 'appPromptModal';
        modalElement.tabIndex = -1;
        modalElement.setAttribute('data-bs-backdrop', 'static');
        modalElement.setAttribute('data-bs-keyboard', 'false');
        modalElement.setAttribute('aria-hidden', 'true');
        modalElement.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="appPromptTitle">
                            <i class="fas fa-pen me-2"></i> Enter Value
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label fw-semibold" id="appPromptLabel" for="appPromptInput">Value</label>
                        <input type="text" class="form-control" id="appPromptInput">
                        <div class="small text-muted mt-2" id="appPromptMessage"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="app-confirm-cancel" data-bs-dismiss="modal" id="appPromptCancelBtn">Cancel</button>
                        <button type="button" class="app-confirm-submit" id="appPromptSubmitBtn">
                            <i class="fas fa-check me-1"></i> Continue
                        </button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modalElement);
    }

    const titleEl = document.getElementById('appPromptTitle');
    const labelEl = document.getElementById('appPromptLabel');
    const messageEl = document.getElementById('appPromptMessage');
    const inputEl = document.getElementById('appPromptInput');
    const submitBtn = document.getElementById('appPromptSubmitBtn');
    const cancelBtn = document.getElementById('appPromptCancelBtn');

    if (!inputEl || !submitBtn) {
        return Promise.resolve(null);
    }

    if (titleEl) {
        titleEl.innerHTML = `<i class="${escapeHtml(options.titleIcon || 'fas fa-pen')} me-2"></i>${escapeHtml(options.title || 'Enter Value')}`;
    }
    if (labelEl) labelEl.textContent = options.label || options.subject || 'Value';
    if (messageEl) messageEl.textContent = options.message || '';
    if (submitBtn) {
        submitBtn.innerHTML = `<i class="${escapeHtml(options.confirmIcon || 'fas fa-check')} me-1"></i>${escapeHtml(options.confirmText || 'Continue')}`;
    }
    if (cancelBtn) cancelBtn.textContent = options.cancelText || 'Cancel';

    inputEl.value = options.value || '';
    inputEl.placeholder = options.placeholder || '';

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);

    return new Promise((resolve) => {
        let submitted = false;

        function cleanup() {
            submitBtn.removeEventListener('click', onSubmit);
            inputEl.removeEventListener('keydown', onKeydown);
            modalElement.removeEventListener('shown.bs.modal', onShown);
            modalElement.removeEventListener('hidden.bs.modal', onHidden);
        }

        function onSubmit() {
            submitted = true;
            modal.hide();
        }

        function onKeydown(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                onSubmit();
            }
        }

        function onShown() {
            inputEl.focus();
            inputEl.select();
        }

        function onHidden() {
            const value = submitted ? inputEl.value : null;
            cleanup();
            resolve(value);
        }

        submitBtn.addEventListener('click', onSubmit);
        inputEl.addEventListener('keydown', onKeydown);
        modalElement.addEventListener('shown.bs.modal', onShown);
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
