// ERNI-PMS Main JavaScript - Enhanced Collapsible Sidebar & Dark Mode
// =====================================================

$(document).ready(function() {
    initSidebar();
    initDarkMode();
    initNotifications();
    initTooltips();
});

// =====================================================
// SIDEBAR - COLLAPSIBLE & RESPONSIVE
// =====================================================
function initSidebar() {
    const sidebar = $('#sidebar-wrapper');
    const content = $('#page-content-wrapper');
    const toggleBtn = $('#menu-toggle');
    const overlay = $('.sidebar-overlay');

    // Desktop collapse toggle (double-click or long press simulation)
    toggleBtn.on('click', function(e) {
        e.preventDefault();
        if (window.innerWidth > 992) {
            toggleDesktopSidebar();
        } else {
            toggleMobileSidebar();
        }
    });

    // Window resize handler
    $(window).resize(debounce(function() {
        handleResize();
    }, 250));

    // Menu item click handlers
    sidebar.find('.menu-item').on('click', function() {
        if (window.innerWidth <= 992) {
            toggleMobileSidebar();
        }
    });

    // Initial state
    handleResize();
}

function toggleDesktopSidebar() {
    const sidebar = $('#sidebar-wrapper');
    const content = $('#page-content-wrapper');
    
    sidebar.toggleClass('collapsed');
    content.toggleClass('sidebar-collapsed');
    
    // Animate labels
    sidebar.find('.sidebar-label').css('transition', 'opacity 0.2s ease, transform 0.2s ease');
}

function toggleMobileSidebar() {
    const sidebar = $('#sidebar-wrapper');
    const overlay = $('.sidebar-overlay');
    
    sidebar.toggleClass('active');
    overlay.toggleClass('active');
    
    // Close overlay click
    overlay.off('click.sidebar').on('click.sidebar', function() {
        toggleMobileSidebar();
    });
}

function handleResize() {
    const sidebar = $('#sidebar-wrapper');
    const content = $('#page-content-wrapper');
    const w = window.innerWidth;
    
    if (w > 992) {
        // Desktop: remove mobile classes, restore full sidebar
        sidebar.removeClass('active').addClass('collapsed'); // Default collapsed on desktop?
        content.removeClass('sidebar-collapsed');
        $('.sidebar-overlay').removeClass('active');
    } else {
        // Mobile: collapse sidebar
        sidebar.removeClass('collapsed sidebar-collapsed');
        content.removeClass('sidebar-collapsed');
    }
}

// =====================================================
// DARK MODE TOGGLE
// =====================================================
function initDarkMode() {
    const toggle = $('#darkModeToggle');
    const html = $('html');
    
    // Load saved preference
    const savedTheme = localStorage.getItem('theme') || 'light';
    html.attr('data-theme', savedTheme);
    
    toggle.find('i').toggleClass('fa-moon fa-sun', savedTheme === 'dark');
    
    // Toggle handler
    toggle.on('click', function(e) {
        e.preventDefault();
        toggleTheme();
    });
    
    // System preference
    if (!savedTheme || savedTheme === 'auto') {
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        html.attr('data-theme', prefersDark ? 'dark' : 'light');
    }
}

function toggleTheme() {
    const body = $('html');
    const currentTheme = body.attr('data-theme') || 'light';
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';
    
    body.attr('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    
    // Update icon
    $('#darkModeToggle i').toggleClass('fa-moon fa-sun', newTheme === 'dark');
    
    showToast('Theme', `Switched to ${newTheme} mode`, newTheme === 'dark' ? 'dark' : 'info');
}

// =====================================================
// NOTIFICATIONS - REAL-TIME POLLING
// =====================================================
function initNotifications() {
    loadNotifications();
    setInterval(loadNotifications, 15000); // Poll every 15s
}

function loadNotifications() {
    $.ajax({
        url: `${BASE_URL}api/notifications/get-notifications.php`,
        method: 'GET',
        success: function(response) {
            try {
                const data = JSON.parse(response);
                updateNotificationBadge(data.unread_count || 0);
                updateNotificationList(data.notifications || []);
            } catch(e) {
                console.error('Notification parse error:', e);
            }
        },
        error: function() {
            console.warn('Notifications load failed');
        }
    });
}

function updateNotificationBadge(count) {
    const badge = $('#notificationBadge, .notification-badge');
    if (count > 0) {
        badge.text(count > 99 ? '99+' : count).removeClass('d-none');
    } else {
        badge.addClass('d-none');
    }
}

function updateNotificationList(notifications) {
    const list = $('#notificationsDropdown .dropdown-menu');
    list.empty();

    if (notifications.length === 0) {
        list.html('<li><span class="dropdown-item text-center text-muted py-3">No notifications</span></li>');
        return;
    }

    notifications.slice(0, 5).forEach(notif => {
        const unreadClass = !notif.is_read ? 'bg-light border-start border-primary border-2' : '';
        list.append(`
            <li><a class="dropdown-item py-2 ${unreadClass}" href="${notif.action_url || '#'}" onclick="markNotificationRead(${notif.id})">
                <div class="d-flex">
                    <i class="fas fa-circle text-${getNotifIcon(notif.notification_type)} me-2 mt-1" style="font-size: 8px;"></i>
                    <div>
                        <strong>${escapeHtml(notif.title)}</strong>
                        <br><small class="text-muted">${escapeHtml(notif.message)}</small>
                        <br><small class="text-muted">${formatRelativeTime(notif.created_at)}</small>
                    </div>
                </div>
            </a></li>
        `);
    });

    list.append('<li><hr class="dropdown-divider"></li>');
    list.append('<li><a class="dropdown-item text-center small py-3 text-primary" href="/notifications.php">View All Notifications</a></li>');
}

function markNotificationRead(id) {
    $.post(`${BASE_URL}api/notifications/mark-read.php`, { id: id })
        .done(function() {
            $(`[onclick="markNotificationRead(${id})"]`).removeClass('bg-light border-start border-primary border-2');
        });
}

function getNotifIcon(type) {
    const icons = {
        'task': 'info',
        'project': 'project-diagram',
        'deadline': 'clock',
        'system': 'cog',
        'approval': 'check-circle',
        'message': 'comment'
    };
    return icons[type] || 'circle';
}

// =====================================================
// UTILITY FUNCTIONS
// =====================================================
function initTooltips() {
    $('[title]').tooltip({ trigger: 'hover focus', placement: 'bottom' });
}

function showLoading(show = true) {
    if (show) {
        if (!$('#globalLoader').length) {
            $('body').append('<div id="globalLoader" class="spinner-overlay"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>');
        }
    } else {
        $('#globalLoader').fadeOut(200, function() { $(this).remove(); });
    }
}

function showToast(title, message, type = 'success') {
    type = type === 'success' ? 'success' : type === 'error' ? 'danger' : type === 'warning' ? 'warning' : 'info';
    
    const toastId = 'toast-' + Date.now();
    const toastHTML = `
        <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1090">
            <div id="${toastId}" class="toast align-items-center text-bg-${type} border-0 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="true" data-bs-delay="4000">
                <div class="d-flex">
                    <div class="toast-body">
                        <strong class="me-2">${title}</strong>
                        <div>${message}</div>
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        </div>
    `;
    
    $('body').append(toastHTML);
    
    const toastEl = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastEl);
    toast.show();
    
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.parentElement.remove());
}

function debounce(func, wait) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

function formatRelativeTime(dateStr) {
    const date = new Date(dateStr);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    const diffHours = Math.floor(diffMins / 60);
    if (diffHours < 24) return `${diffHours}h ago`;
    return date.toLocaleDateString();
}

function escapeHtml(text) {
    const map = { '&': '&amp;', '<': '<', '>': '>', '"': '"', "'": '&#039;' };
    return text.replace(/[&<>"']/g, m => map[m]);
}

// =====================================================
// AZURE PRICING CALCULATOR STYLES (INLINE FOR MODULES)
// =====================================================
function initAzurePricing() {
    // Will be used in expert/cost-estimation.php and admin/techstack.php
    $('.azure-tier').on('change', function() {
        calculateAzureCost($(this).closest('.azure-card'));
    });
}

function calculateAzureCost(card) {
    const tier = card.find('.azure-tier').val();
    const quantity = parseFloat(card.find('.azure-quantity').val()) || 1;
    const tiers = {
        'basic': 100, 'standard': 250, 'premium': 500
    };
    const monthlyCost = tiers[tier] * quantity;
    card.find('.azure-monthly').text(`$${monthlyCost.toLocaleString()}`);
    card.find('.azure-annual').text(`$${(monthlyCost * 12).toLocaleString()}`);
}

// Global AJAX error handler
$(document).ajaxError(function(event, xhr, settings) {
    if (xhr.status !== 401 && xhr.status !== 403) {
        showToast('Error', 'Request failed. Please try again.', 'error');
    }
});

// Confirm destructive actions
$(document).on('click', '[data-confirm]', function(e) {
    e.preventDefault();
    const msg = $(this).data('confirm') || 'Are you sure?';
    if (confirm(msg)) {
        window.location = $(this).attr('href');
    }
});

