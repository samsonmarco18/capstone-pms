$(function () {
    initSidebar();
    initDarkMode();
    initNotifications();
    initTooltips();
});

function initSidebar() {
    const sidebar = $('#sidebar-wrapper');
    const content = $('#page-content-wrapper');
    const overlay = $('.sidebar-overlay');
    const toggle = $('#menu-toggle');
    const desktopStateKey = 'erni.sidebar.collapsed';
    const mobileStateKey = 'erni.sidebar.mobile_open';

    function updateToggleState() {
        const isActive = window.innerWidth <= 992 ? sidebar.hasClass('active') : sidebar.hasClass('collapsed');
        toggle.toggleClass('is-active', isActive);
    }

    function applySidebarState() {
        if (window.innerWidth <= 992) {
            const isMobileOpen = localStorage.getItem(mobileStateKey) === 'true';
            sidebar.removeClass('collapsed');
            content.removeClass('sidebar-collapsed');
            sidebar.toggleClass('active', isMobileOpen);
            overlay.toggleClass('active', isMobileOpen);
            updateToggleState();
            return;
        }

        const isCollapsed = localStorage.getItem(desktopStateKey) === 'true';
        sidebar.removeClass('active');
        overlay.removeClass('active');
        sidebar.toggleClass('collapsed', isCollapsed);
        content.toggleClass('sidebar-collapsed', isCollapsed);
        updateToggleState();
    }

    applySidebarState();

    $('#menu-toggle').on('click', function (event) {
        event.preventDefault();

        if (window.innerWidth <= 992) {
            const nextOpenState = !sidebar.hasClass('active');
            sidebar.toggleClass('active', nextOpenState);
            overlay.toggleClass('active', nextOpenState);
            localStorage.setItem(mobileStateKey, String(nextOpenState));
            updateToggleState();
            return;
        }

        const nextCollapsedState = !sidebar.hasClass('collapsed');
        sidebar.toggleClass('collapsed', nextCollapsedState);
        content.toggleClass('sidebar-collapsed', nextCollapsedState);
        localStorage.setItem(desktopStateKey, String(nextCollapsedState));
        updateToggleState();
    });

    overlay.on('click', function () {
        sidebar.removeClass('active');
        overlay.removeClass('active');
        localStorage.setItem(mobileStateKey, 'false');
        updateToggleState();
    });

    $(window).on('resize', debounce(function () {
        applySidebarState();
    }, 150));
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

    notifications.slice(0, 8).forEach(function (notification) {
        const unreadClass = Number(notification.is_read) ? '' : 'bg-light';
        const safeTitle = escapeHtml(notification.title || 'Notification');
        const safeMessage = escapeHtml(notification.message || '');
        const actionUrl = notification.action_url || `${BASE_URL}notifications.php`;

        list.append(`
            <li>
                <a class="dropdown-item py-2 ${unreadClass}" href="${actionUrl}" onclick="markNotificationRead(${notification.id})">
                    <div class="fw-semibold small">${safeTitle}</div>
                    <div class="text-muted small">${safeMessage}</div>
                </a>
            </li>
        `);
    });
}

function markNotificationRead(id) {
    $.post(`${BASE_URL}api/notifications/mark-read.php`, { id: id }).done(loadNotifications);
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
