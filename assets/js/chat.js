let currentChatUser = null;
let currentChatGroup = null;
let currentChatTab = 'chat';
let chatPollInterval = null;
let chatData = {
    conversations: [],
    groups: []
};

const chatStateKey = 'erni.chat.open';

$(function () {
    initChatWidget();
});

function initChatWidget() {
    if (!$('#chat-widget').length) {
        return;
    }

    applyChatVisibility();
    loadConversationData();
    loadUsersForGroup();

    $('.chat-tab').on('click', function () {
        const tab = $(this).data('chatTab');
        if (!tab || $(this).prop('disabled')) {
            return;
        }

        currentChatTab = tab;
        $('.chat-tab').removeClass('active');
        $(this).addClass('active');
        renderConversationList();
    });

    $('#chatSearchBtn').on('click', function () {
        $('#chatSearchInput').trigger('focus');
    });

    $('#chatSearchInput').on('input', debounce(function () {
        renderConversationList();
    }, 120));

    $('#chatConversationList').on('click', '.conversation-item', function () {
        openConversation($(this).data('type'), Number($(this).data('id')));
    });

    $('#chatBackBtn').on('click', function () {
        showInboxView();
    });

    $('#sendMessageBtn').on('click', sendMessage);
    $('#chatMessageInput').on('keypress', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            sendMessage();
        }
    });

    $('#toggleChatMinimize').on('click', function () {
        setChatVisibility(false);
    });

    $('#chat-widget-minimized').on('click', function () {
        setChatVisibility(true);
    });
}

function applyChatVisibility() {
    const isOpen = localStorage.getItem(chatStateKey) !== 'false';
    setChatVisibility(isOpen, false);
}

function setChatVisibility(isOpen, persist = true) {
    $('#chat-widget').toggle(isOpen);
    $('#chat-widget-minimized').toggle(!isOpen);

    if (persist) {
        localStorage.setItem(chatStateKey, String(isOpen));
    }
}

function loadConversationData() {
    $.getJSON(`${BASE_URL}api/chat/get-conversations.php`, function (payload) {
        chatData.conversations = Array.isArray(payload.conversations) ? payload.conversations : [];
        chatData.groups = Array.isArray(payload.groups) ? payload.groups : [];
        renderConversationList();
    }).fail(function () {
        $('#chatConversationList').html(`
            <div class="chat-list-empty">
                <i class="fas fa-triangle-exclamation"></i>
                <div>Unable to load conversations.</div>
            </div>
        `);
    });
}

function renderConversationList() {
    const container = $('#chatConversationList');
    const search = ($('#chatSearchInput').val() || '').trim().toLowerCase();
    let items = currentChatTab === 'groups' ? chatData.groups : chatData.conversations;

    if (currentChatTab === 'calls') {
        container.html(`
            <div class="chat-list-empty">
                <i class="fas fa-phone-slash"></i>
                <div>Calls are not available yet.</div>
            </div>
        `);
        return;
    }

    if (search) {
        items = items.filter(function (item) {
            return [item.name, item.subtitle, item.last_message]
                .filter(Boolean)
                .join(' ')
                .toLowerCase()
                .includes(search);
        });
    }

    if (!items.length) {
        const message = search ? 'No matching conversations found.' : 'No conversations available yet.';
        container.html(`
            <div class="chat-list-empty">
                <i class="fas fa-comments"></i>
                <div>${escapeHtml(message)}</div>
            </div>
        `);
        return;
    }

    let html = '';
    items.forEach(function (item) {
        const initials = getConversationInitials(item.name, item.type);
        const preview = item.last_message || (item.type === 'group' ? 'No group messages yet.' : 'No messages yet.');
        const timeLabel = formatConversationTime(item.last_message_at);
        const unreadMarkup = Number(item.unread_count) > 0
            ? `<span class="conversation-badge">${item.unread_count}</span>`
            : '';

        html += `
            <button type="button" class="conversation-item" data-type="${escapeHtml(item.type)}" data-id="${item.id}">
                <div class="conversation-avatar ${item.type === 'group' ? 'group' : ''}">${escapeHtml(initials)}</div>
                <div class="conversation-main">
                    <div class="conversation-topline">
                        <strong>${escapeHtml(item.name)}</strong>
                        <span>${escapeHtml(timeLabel)}</span>
                    </div>
                    <div class="conversation-preview-row">
                        <span class="conversation-preview">${escapeHtml(preview)}</span>
                        ${unreadMarkup}
                    </div>
                </div>
            </button>
        `;
    });

    container.html(html);
}

function openConversation(type, id) {
    currentChatUser = null;
    currentChatGroup = null;

    const collection = type === 'group' ? chatData.groups : chatData.conversations;
    const item = collection.find(entry => Number(entry.id) === Number(id));
    if (!item) {
        return;
    }

    if (type === 'group') {
        currentChatGroup = id;
    } else {
        currentChatUser = id;
    }

    $('#chatHeader').text(type === 'group' ? 'Group Chat' : 'Messages');
    $('#chatThreadName').text(item.name);
    $('#chatThreadSubtitle').text(item.subtitle || '');
    $('#chatThreadAvatar').text(getConversationInitials(item.name, item.type));

    showThreadView();
    loadChatMessages();
    startChatPolling();
}

function showInboxView() {
    $('#chatHeader').text('Messages');
    $('#chatInboxView').removeClass('d-none');
    $('#chatThreadView').addClass('d-none');
    currentChatUser = null;
    currentChatGroup = null;

    if (chatPollInterval) {
        clearInterval(chatPollInterval);
        chatPollInterval = null;
    }
}

function showThreadView() {
    $('#chatInboxView').addClass('d-none');
    $('#chatThreadView').removeClass('d-none');
}

function startChatPolling() {
    if (chatPollInterval) {
        clearInterval(chatPollInterval);
    }

    if (currentChatUser || currentChatGroup) {
        chatPollInterval = setInterval(function () {
            loadChatMessages();
            loadConversationData();
        }, 5000);
    }
}

function loadChatMessages() {
    if (!currentChatUser && !currentChatGroup) {
        return;
    }

    const url = currentChatGroup
        ? `${BASE_URL}api/chat/get-messages.php?group_id=${currentChatGroup}`
        : `${BASE_URL}api/chat/get-messages.php?receiver_id=${currentChatUser}`;

    $.getJSON(url, function (payload) {
        const messages = Array.isArray(payload) ? payload : (payload.messages || []);
        renderChatMessages(messages);
        loadConversationData();
    });
}

function renderChatMessages(messages) {
    const container = $('#chatMessages');
    if (!messages.length) {
        container.html(`
            <div class="text-center text-muted py-5">
                <i class="fas fa-comment-slash fa-2x mb-2"></i>
                <div>No messages yet.</div>
            </div>
        `);
        return;
    }

    let html = '';
    messages.forEach(function (message) {
        const time = formatMessageTime(message.created_at);
        const mineClass = message.is_mine ? 'sent' : 'received';
        const sender = message.is_mine ? 'You' : escapeHtml(message.sender_name || 'User');

        html += `
            <div class="message ${mineClass}">
                <div class="small fw-semibold mb-1">${sender}</div>
                <div>${escapeHtml(message.message)}</div>
                <div class="small text-muted mt-1">${escapeHtml(time)}</div>
            </div>
        `;
    });

    container.html(html);
    container.scrollTop(container.prop('scrollHeight'));
}

function sendMessage() {
    const input = $('#chatMessageInput');
    const message = input.val().trim();

    if (!message) {
        return;
    }

    if (!currentChatUser && !currentChatGroup) {
        showToast('Chat', 'Please select a conversation first.', 'warning');
        return;
    }

    const payload = { message: message };
    if (currentChatGroup) {
        payload.group_id = currentChatGroup;
    } else {
        payload.receiver_id = currentChatUser;
    }

    $.ajax({
        url: `${BASE_URL}api/chat/send-message.php`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(payload),
        success: function (response) {
            if (response.success) {
                input.val('');
                loadChatMessages();
                loadNotifications();
                loadConversationData();
            } else {
                showToast('Chat', response.error || 'Unable to send message.', 'error');
            }
        }
    });
}

function loadUsersForGroup() {
    $.getJSON(`${BASE_URL}api/chat/get-users.php`, function (payload) {
        const users = Array.isArray(payload) ? payload : (payload.users || []);
        const select = $('#groupMembers');
        if (!select.length) {
            return;
        }

        select.empty();
        users.forEach(function (user) {
            select.append(`<option value="${user.id}">${escapeHtml(user.full_name)} (${escapeHtml(user.role)})</option>`);
        });
    });
}

function createGroup() {
    const name = $('#groupName').val().trim();
    const description = $('#groupDescription').val().trim();
    const members = ($('#groupMembers').val() || []).map(Number);

    if (!name) {
        showToast('Chat', 'Please enter a group name.', 'warning');
        return;
    }

    $.ajax({
        url: `${BASE_URL}api/chat/create-group.php`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({ name, description, members }),
        success: function (response) {
            if (response.success) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('createGroupModal')).hide();
                $('#groupName').val('');
                $('#groupDescription').val('');
                $('#groupMembers').val([]);
                loadConversationData();
                showToast('Chat', 'Group created successfully.', 'success');
            } else {
                showToast('Chat', response.error || 'Unable to create group.', 'error');
            }
        }
    });
}

function formatConversationTime(value) {
    if (!value) {
        return '';
    }

    const date = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const now = new Date();
    const diffMs = now - date;
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffDays <= 0) {
        return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    if (diffDays === 1) {
        return 'Yesterday';
    }

    if (diffDays < 7) {
        return `${diffDays}d`;
    }

    return date.toLocaleDateString([], { month: 'short', day: 'numeric' });
}

function formatMessageTime(value) {
    const date = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleString([], {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit'
    });
}

function getConversationInitials(name, type) {
    if (type === 'group') {
        return 'G';
    }

    const parts = String(name || '')
        .trim()
        .split(/\s+/)
        .filter(Boolean);

    if (!parts.length) {
        return 'U';
    }

    return parts.slice(0, 2).map(part => part.charAt(0).toUpperCase()).join('');
}
