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
                form.requestSubmit();
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
    const helpText = customHelp
        || (subtitle ? subtitle.textContent.replace(/\s+/g, ' ').trim() : '')
        || `View information and actions available on ${title}.`;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'page-header-help-btn';
    button.setAttribute('aria-label', `Help for ${title}`);
    button.setAttribute('data-bs-toggle', 'tooltip');
    button.setAttribute('data-bs-placement', 'left');
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

    $(document).on('submit', 'form[data-app-loading]', function () {
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
