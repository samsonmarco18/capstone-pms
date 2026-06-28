let currentChatUser = null;
let currentChatGroup = null;
let currentChatTab = 'chat';
let chatPollInterval = null;
let chatData = {
    conversations: [],
    groups: []
};
let currentGroupDetails = null;
let groupUserOptions = [];
let groupSelectedMemberIds = new Set();
let groupAddMemberIds = new Set();

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

    $('#chatGroupInfoBtn').on('click', function () {
        showGroupInfoView();
    });

    $('#chatGroupInfoBackBtn').on('click', function () {
        showThreadView();
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

    $('#groupMemberSearch').on('input', debounce(function () {
        renderGroupMemberPicker();
    }, 120));

    $('#groupMembers').on('change', '.group-member-checkbox', function () {
        const memberId = Number($(this).val());
        if ($(this).is(':checked')) {
            groupSelectedMemberIds.add(memberId);
        } else {
            groupSelectedMemberIds.delete(memberId);
        }

        updateGroupMemberCount();
    });

    $('#chatGroupInfoContent').on('click', '[data-chat-remove-member]', function () {
        removeGroupMember(Number($(this).data('chatRemoveMember')));
    });

    $('#chatGroupInfoContent').on('click', '[data-chat-add-member]', function () {
        openAddMemberModal();
    });

    $('#chatGroupInfoContent').on('click', '[data-chat-leave-group]', function () {
        leaveCurrentGroup();
    });

    $('#addGroupMemberSearch').on('input', debounce(function () {
        renderAddGroupMemberPicker();
    }, 120));

    $('#addGroupMembers').on('change', '.group-member-checkbox', function () {
        const memberId = Number($(this).val());
        if ($(this).is(':checked')) {
            groupAddMemberIds.add(memberId);
        } else {
            groupAddMemberIds.delete(memberId);
        }

        updateAddGroupMemberCount();
    });

    $('#addGroupMemberSubmitBtn').on('click', addSelectedGroupMembers);

    $('#addGroupMemberModal').on('hidden.bs.modal', function () {
        resetAddGroupMemberForm();
    });
}

function applyChatVisibility() {
    const stored = localStorage.getItem(chatStateKey);
    const sessionKey = 'erni.chat.seen';

    if (stored === null) {
        localStorage.setItem(chatStateKey, 'false');
        setChatVisibility(false, false);
        return;
    }

    if (stored === 'true' && sessionStorage.getItem(sessionKey) !== 'true') {
        sessionStorage.setItem(sessionKey, 'true');
        localStorage.setItem(chatStateKey, 'false');
        setChatVisibility(false, false);
        return;
    }

    const isOpen = stored === 'true';
    setChatVisibility(isOpen, false);
}

function setChatVisibility(isOpen, persist = true) {
    $('#chat-widget').css('display', isOpen ? 'flex' : 'none');
    $('#chat-widget-minimized').css('display', isOpen ? 'none' : 'block');

    if (persist) {
        localStorage.setItem(chatStateKey, String(isOpen));
    }
}

function loadConversationData() {
    $.getJSON(`${BASE_URL}api/chat/get-conversations.php`, function (payload) {
        chatData.conversations = Array.isArray(payload.conversations) ? payload.conversations : [];
        chatData.groups = Array.isArray(payload.groups) ? payload.groups : [];
        updateChatUnreadBadge();
        renderConversationList();
    }).fail(function () {
        updateChatUnreadBadge(0);
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

function updateChatUnreadBadge(countOverride = null) {
    const badge = $('#chatUnreadBadge');
    if (!badge.length) {
        return;
    }

    const totalUnread = countOverride !== null
        ? Number(countOverride) || 0
        : [...chatData.conversations, ...chatData.groups].reduce(function (total, item) {
            return total + (Number(item.unread_count) || 0);
        }, 0);

    badge.text(totalUnread > 99 ? '99+' : totalUnread);
    badge.toggleClass('d-none', totalUnread <= 0);
}

function openConversation(type, id) {
    currentChatUser = null;
    currentChatGroup = null;
    currentGroupDetails = null;
    $('#chatGroupInfoContent').empty();

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
    $('#chatGroupInfoBtn').toggleClass('d-none', type !== 'group');

    showThreadView();
    if (type === 'group') {
        loadGroupDetails(id);
    }
    loadChatMessages();
    startChatPolling();
}

function showInboxView() {
    $('#chatHeader').text('Messages');
    $('#chatInboxView').removeClass('d-none');
    $('#chatThreadView').addClass('d-none');
    $('#chatGroupInfoView').addClass('d-none');
    $('#chatGroupInfoBtn').addClass('d-none');
    $('#chatGroupInfoContent').empty();
    currentChatUser = null;
    currentChatGroup = null;
    currentGroupDetails = null;

    if (chatPollInterval) {
        clearInterval(chatPollInterval);
        chatPollInterval = null;
    }
}

function showThreadView() {
    $('#chatInboxView').addClass('d-none');
    $('#chatGroupInfoView').addClass('d-none');
    $('#chatThreadView').removeClass('d-none');
}

function showGroupInfoView() {
    if (!currentChatGroup) {
        return;
    }

    $('#chatInboxView').addClass('d-none');
    $('#chatThreadView').addClass('d-none');
    $('#chatGroupInfoView').removeClass('d-none');

    if (currentGroupDetails) {
        renderGroupInfoView(currentGroupDetails);
    } else {
        renderGroupInfoLoading();
        loadGroupDetails(currentChatGroup);
    }
}

function loadGroupDetails(groupId) {
    $.getJSON(`${BASE_URL}api/chat/get-group-details.php?group_id=${groupId}`, function (payload) {
        currentGroupDetails = payload && payload.success ? payload : null;
        if (!$('#chatGroupInfoView').hasClass('d-none')) {
            renderGroupInfoView(currentGroupDetails);
        }
    }).fail(function () {
        $('#chatGroupInfoContent').html(`
            <div class="chat-group-info-empty">
                <i class="fas fa-triangle-exclamation"></i>
                <span>Unable to load group members.</span>
            </div>
        `);
    });
}

function renderGroupInfoLoading() {
    $('#chatGroupInfoContent').html(`
        <div class="chat-group-info-empty">
            <i class="fas fa-spinner fa-spin"></i>
            <span>Loading members...</span>
        </div>
    `);
}

function renderGroupInfoView(details) {
    const panel = $('#chatGroupInfoContent');
    if (!details || !details.group) {
        panel.html(`
            <div class="chat-group-info-empty">
                <i class="fas fa-triangle-exclamation"></i>
                <span>Unable to load group info.</span>
            </div>
        `);
        return;
    }

    const group = details.group;
    const members = Array.isArray(details.members) ? details.members : [];
    const currentUser = members.find(member => member.is_you);
    const canManageMembers = Boolean(currentUser && currentUser.is_creator);
    const description = group.description ? escapeHtml(group.description) : 'No description yet.';
    const createdLabel = group.creator_name
        ? `Created by ${escapeHtml(group.creator_name)}`
        : 'Group chat';

    const memberHtml = members.map(function (member) {
        const canRemove = canManageMembers && !member.is_you && !member.is_creator;
        const badges = [
            member.is_you ? '<span class="chat-member-pill">You</span>' : '',
            member.is_creator ? '<span class="chat-member-pill creator">Creator</span>' : ''
        ].join('');

        return `
            <div class="chat-group-member">
                <div class="chat-group-member-avatar">${escapeHtml(getConversationInitials(member.full_name || 'User', 'user'))}</div>
                <div class="chat-group-member-main">
                    <strong>${escapeHtml(member.full_name || 'User')}</strong>
                    <small>${escapeHtml(member.role_label || member.role || 'Team member')}</small>
                </div>
                <div class="chat-group-member-badges">${badges}</div>
                ${canRemove ? `
                    <button type="button" class="chat-member-action danger" data-chat-remove-member="${Number(member.id)}" title="Remove member">
                        <i class="fas fa-user-minus"></i>
                    </button>
                ` : ''}
            </div>
        `;
    }).join('');

    $('#chatGroupInfoAvatar').text(getConversationInitials(group.name || 'Group', 'group'));
    $('#chatGroupInfoTitle').text(group.name || 'Group Info');
    $('#chatGroupInfoSubtitle').text(`${Number(group.member_count) || members.length} members`);

    panel.html(`
        <div class="chat-group-info-hero">
            <div class="chat-group-info-hero-avatar">${escapeHtml(getConversationInitials(group.name || 'Group', 'group'))}</div>
            <strong>${escapeHtml(group.name || 'Group')}</strong>
            <span>${description}</span>
            <small>${createdLabel} · ${Number(group.member_count) || members.length} members</small>
        </div>
        <div class="chat-group-actions">
            ${canManageMembers ? `
            <button type="button" class="chat-group-action primary" data-chat-add-member>
                <i class="fas fa-user-plus"></i>
                <span>Add Member</span>
            </button>
            ` : ''}
            <button type="button" class="chat-group-action danger" data-chat-leave-group>
                <i class="fas fa-right-from-bracket"></i>
                <span>Leave Group</span>
            </button>
        </div>
        <div class="chat-group-section-title">Members</div>
        <div class="chat-group-info-members">
            ${memberHtml || `
                <div class="chat-group-info-empty">
                    <i class="fas fa-user-slash"></i>
                    <span>No members found.</span>
                </div>
            `}
        </div>
    `);
}

function removeGroupMember(memberId) {
    if (!currentChatGroup || !memberId) {
        return;
    }

    if (!window.confirm('Remove this member from the group?')) {
        return;
    }

    $.ajax({
        url: `${BASE_URL}api/chat/remove-group-member.php`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({ group_id: currentChatGroup, user_id: memberId }),
        success: function (response) {
            if (response.success) {
                showToast('Group Chat', 'Member removed.', 'success');
                currentGroupDetails = null;
                loadGroupDetails(currentChatGroup);
                loadConversationData();
            } else {
                showToast('Group Chat', response.error || 'Unable to remove member.', 'error');
            }
        },
        error: function () {
            showToast('Group Chat', 'Unable to remove member.', 'error');
        }
    });
}

function openAddMemberModal() {
    if (!currentChatGroup) {
        return;
    }

    if (!currentGroupDetails || !Array.isArray(currentGroupDetails.members)) {
        showToast('Group Chat', 'Group details are still loading.', 'warning');
        return;
    }

    resetAddGroupMemberForm();
    renderAddGroupMemberPicker();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('addGroupMemberModal')).show();
}

function getCurrentGroupMemberIds() {
    const members = currentGroupDetails && Array.isArray(currentGroupDetails.members)
        ? currentGroupDetails.members
        : [];

    return new Set(members.map(function (member) {
        return Number(member.id);
    }));
}

function renderAddGroupMemberPicker() {
    const container = $('#addGroupMembers');
    if (!container.length) {
        return;
    }

    const search = ($('#addGroupMemberSearch').val() || '').trim().toLowerCase();
    const currentMemberIds = getCurrentGroupMemberIds();
    const users = groupUserOptions.filter(function (user) {
        const userId = Number(user.id);
        if (currentMemberIds.has(userId) || (currentChatUser && userId === Number(currentChatUser))) {
            return false;
        }

        if (!search) {
            return true;
        }

        return [user.full_name, user.role, user.email]
            .filter(Boolean)
            .join(' ')
            .toLowerCase()
            .includes(search);
    });

    if (!users.length) {
        container.html(`
            <div class="group-member-empty">
                <i class="fas fa-user-slash"></i>
                <span>No available users found.</span>
            </div>
        `);
        updateAddGroupMemberCount();
        return;
    }

    container.html(users.map(function (user) {
        const userId = Number(user.id);
        const initials = getConversationInitials(user.full_name || 'User', 'user');
        const checked = groupAddMemberIds.has(userId) ? 'checked' : '';

        return `
            <label class="group-member-option">
                <input type="checkbox" class="group-member-checkbox" value="${userId}" ${checked}>
                <span class="group-member-avatar">${escapeHtml(initials)}</span>
                <span class="group-member-main">
                    <strong>${escapeHtml(user.full_name || 'User')}</strong>
                    <small>${escapeHtml(user.role || 'Team member')}</small>
                </span>
                <span class="group-member-check"><i class="fas fa-check"></i></span>
            </label>
        `;
    }).join(''));

    updateAddGroupMemberCount();
}

function updateAddGroupMemberCount(countOverride = null) {
    const count = countOverride === null ? groupAddMemberIds.size : countOverride;
    $('#addGroupMemberCount').text(`${count} selected`);
}

function resetAddGroupMemberForm() {
    groupAddMemberIds = new Set();
    $('#addGroupMemberSearch').val('');
    updateAddGroupMemberCount(0);
}

function setAddGroupMemberLoading(isLoading) {
    const button = $('#addGroupMemberSubmitBtn');
    if (!button.length) {
        return;
    }

    button.prop('disabled', isLoading);
    button.html(isLoading
        ? '<i class="fas fa-spinner fa-spin me-1"></i> Adding...'
        : 'Add Members');
}

function addSelectedGroupMembers() {
    const memberIds = Array.from(groupAddMemberIds);

    if (!currentChatGroup) {
        return;
    }

    if (!memberIds.length) {
        showToast('Chat', 'Please choose at least one member.', 'warning');
        return;
    }

    setAddGroupMemberLoading(true);

    $.ajax({
        url: `${BASE_URL}api/chat/add-group-member.php`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({ group_id: currentChatGroup, user_ids: memberIds }),
        success: function (response) {
            if (response.success) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('addGroupMemberModal')).hide();
                currentGroupDetails = null;
                loadGroupDetails(currentChatGroup);
                loadConversationData();
                showToast('Group Chat', response.message || 'Member(s) added.', 'success');
            } else {
                showToast('Group Chat', response.error || 'Unable to add member.', 'error');
            }
        },
        error: function () {
            showToast('Group Chat', 'Unable to add member.', 'error');
        },
        complete: function () {
            setAddGroupMemberLoading(false);
        }
    });
}

function leaveCurrentGroup() {
    if (!currentChatGroup) {
        return;
    }

    if (!window.confirm('Leave this group? You will no longer see it in your group chats.')) {
        return;
    }

    $.ajax({
        url: `${BASE_URL}api/chat/leave-group.php`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({ group_id: currentChatGroup }),
        success: function (response) {
            if (response.success) {
                showToast('Group Chat', 'You left the group.', 'success');
                showInboxView();
                currentChatTab = 'groups';
                $('.chat-tab').removeClass('active');
                $('.chat-tab[data-chat-tab="groups"]').addClass('active');
                loadConversationData();
            } else {
                showToast('Group Chat', response.error || 'Unable to leave group.', 'error');
            }
        },
        error: function () {
            showToast('Group Chat', 'Unable to leave group.', 'error');
        }
    });
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
        groupUserOptions = Array.isArray(payload) ? payload : (payload.users || []);
        renderGroupMemberPicker();
    }).fail(function () {
        $('#groupMembers').html(`
            <div class="group-member-empty">
                <i class="fas fa-triangle-exclamation"></i>
                <span>Unable to load users.</span>
            </div>
        `);
    });
}

function renderGroupMemberPicker() {
    const container = $('#groupMembers');
    if (!container.length) {
        return;
    }

    const search = ($('#groupMemberSearch').val() || '').trim().toLowerCase();
    const users = groupUserOptions.filter(function (user) {
        if (!search) {
            return true;
        }

        return [user.full_name, user.role, user.email]
            .filter(Boolean)
            .join(' ')
            .toLowerCase()
            .includes(search);
    });

    if (!users.length) {
        container.html(`
            <div class="group-member-empty">
                <i class="fas fa-user-slash"></i>
                <span>No users found.</span>
            </div>
        `);
        updateGroupMemberCount();
        return;
    }

    container.html(users.map(function (user) {
        const userId = Number(user.id);
        const initials = getConversationInitials(user.full_name || 'User', 'user');
        const checked = groupSelectedMemberIds.has(userId) ? 'checked' : '';

        return `
            <label class="group-member-option">
                <input type="checkbox" class="group-member-checkbox" value="${userId}" ${checked}>
                <span class="group-member-avatar">${escapeHtml(initials)}</span>
                <span class="group-member-main">
                    <strong>${escapeHtml(user.full_name || 'User')}</strong>
                    <small>${escapeHtml(user.role || 'Team member')}</small>
                </span>
                <span class="group-member-check"><i class="fas fa-check"></i></span>
            </label>
        `;
    }).join(''));

    updateGroupMemberCount();
}

function getSelectedGroupMemberIds() {
    return Array.from(groupSelectedMemberIds);
}

function updateGroupMemberCount(countOverride = null) {
    const count = countOverride === null ? getSelectedGroupMemberIds().length : countOverride;
    $('#groupMemberCount').text(`${count} selected`);
}

function resetGroupForm() {
    $('#groupName').val('');
    $('#groupDescription').val('');
    $('#groupMemberSearch').val('');
    groupSelectedMemberIds = new Set();
    renderGroupMemberPicker();
}

function setGroupCreateLoading(isLoading) {
    const button = $('#createGroupSubmitBtn');
    if (!button.length) {
        return;
    }

    button.prop('disabled', isLoading);
    button.html(isLoading
        ? '<i class="fas fa-spinner fa-spin me-1"></i> Creating...'
        : 'Create Group');
}

function createGroup() {
    const name = $('#groupName').val().trim();
    const description = $('#groupDescription').val().trim();
    const members = getSelectedGroupMemberIds();

    if (!name) {
        showToast('Chat', 'Please enter a group name.', 'warning');
        return;
    }

    if (!members.length) {
        showToast('Chat', 'Please choose at least one member.', 'warning');
        return;
    }

    setGroupCreateLoading(true);

    $.ajax({
        url: `${BASE_URL}api/chat/create-group.php`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({ name, description, members }),
        success: function (response) {
            if (response.success) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('createGroupModal')).hide();
                resetGroupForm();
                loadConversationData();
                showToast('Chat', 'Group created successfully.', 'success');
            } else {
                showToast('Chat', response.error || 'Unable to create group.', 'error');
            }
        },
        error: function () {
            showToast('Chat', 'Unable to create group.', 'error');
        },
        complete: function () {
            setGroupCreateLoading(false);
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
