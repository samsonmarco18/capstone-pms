// Chat System JavaScript

let currentChatUser = null;
let currentChatGroup = null;
let chatPollInterval = null;

// Toggle chat widget
function toggleChat() {
    const chatBody = document.getElementById('chatBody');
    if (chatBody.style.display === 'none' || chatBody.style.display === '') {
        chatBody.style.display = 'flex';
        loadChatMessages();
        if (!chatPollInterval) {
            chatPollInterval = setInterval(loadChatMessages, 3000);
        }
    } else {
        chatBody.style.display = 'none';
        if (chatPollInterval) {
            clearInterval(chatPollInterval);
            chatPollInterval = null;
        }
    }
}

// Load chat messages
function loadChatMessages() {
    if (currentChatUser || currentChatGroup) {
        let url = BASE_URL + '/api/chat/get-messages.php?';
        if (currentChatUser) {
            url += 'receiver_id=' + currentChatUser;
        } else if (currentChatGroup) {
            url += 'group_id=' + currentChatGroup;
        }
        
        $.ajax({
            url: url,
            method: 'GET',
            success: function(response) {
                const messages = JSON.parse(response);
                displayChatMessages(messages);
            }
        });
    }
}

// Display messages in chat widget
function displayChatMessages(messages) {
    const container = document.getElementById('chatMessages');
    if (!container) return;
    
    const oldScrollHeight = container.scrollHeight;
    const wasAtBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 100;
    
    let html = '';
    messages.forEach(msg => {
        const time = new Date(msg.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        if (msg.is_mine) {
            html += `
                <div class="message sent mb-2">
                    <div class="d-flex justify-content-end">
                        <div class="bg-primary text-white p-2 rounded" style="max-width: 80%;">
                            <small class="d-block">${escapeHtml(msg.message)}</small>
                            <small class="text-white-50">${time}</small>
                        </div>
                    </div>
                </div>
            `;
        } else {
            html += `
                <div class="message received mb-2">
                    <div class="d-flex">
                        <div class="bg-light p-2 rounded" style="max-width: 80%;">
                            <strong class="d-block small">${escapeHtml(msg.sender_name)}</strong>
                            <small class="d-block">${escapeHtml(msg.message)}</small>
                            <small class="text-muted">${time}</small>
                        </div>
                    </div>
                </div>
            `;
        }
    });
    
    container.innerHTML = html;
    
    // Auto-scroll if was at bottom
    if (wasAtBottom) {
        container.scrollTop = container.scrollHeight;
    }
}

// Send message
function sendMessage() {
    const input = document.getElementById('chatMessageInput');
    const message = input.value.trim();
    
    if (!message) return;
    
    const data = {
        message: message
    };
    
    if (currentChatUser) {
        data.receiver_id = currentChatUser;
    } else if (currentChatGroup) {
        data.group_id = currentChatGroup;
    } else {
        showToast('Error', 'No chat selected', 'danger');
        return;
    }
    
    $.ajax({
        url: BASE_URL + '/api/chat/send-message.php',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(data),
        success: function() {
            input.value = '';
            loadChatMessages();
        },
        error: function() {
            showToast('Error', 'Failed to send message', 'danger');
        }
    });
}

// Show group chat
function showGroupChat() {
    $.ajax({
        url: BASE_URL + '/api/chat/get-groups.php',
        method: 'GET',
        success: function(response) {
            const groups = JSON.parse(response);
            let html = '<div class="list-group">';
            groups.forEach(group => {
                html += `
                    <a href="#" class="list-group-item list-group-item-action" onclick="selectGroup(${group.id})">
                        <i class="fas fa-users me-2"></i>
                        ${escapeHtml(group.name)}
                        <small class="text-muted">(${group.member_count} members)</small>
                    </a>
                `;
            });
            html += '</div>';
            
            // Show in modal or dropdown
            $('#chatMessages').html(html);
        }
    });
}

// Select group for chat
function selectGroup(groupId) {
    currentChatGroup = groupId;
    currentChatUser = null;
    document.getElementById('chatHeader').innerHTML = '<i class="fas fa-users"></i> Group Chat';
    loadChatMessages();
    $('#groupChatModal').modal('hide');
}

// Load users for group creation
function loadUsersForGroup() {
    $.ajax({
        url: BASE_URL + '/api/chat/get-users.php',
        method: 'GET',
        success: function(response) {
            const users = JSON.parse(response);
            const select = document.getElementById('groupMembers');
            if (select) {
                select.innerHTML = '';
                users.forEach(user => {
                    select.innerHTML += `<option value="${user.id}">${escapeHtml(user.full_name)} (${user.role})</option>`;
                });
            }
        }
    });
}

// Create chat group
function createGroup() {
    const name = document.getElementById('groupName').value.trim();
    const members = Array.from(document.getElementById('groupMembers').selectedOptions).map(opt => opt.value);
    
    if (!name) {
        showToast('Error', 'Please enter group name', 'danger');
        return;
    }
    
    if (members.length === 0) {
        showToast('Error', 'Please select at least one member', 'danger');
        return;
    }
    
    $.ajax({
        url: BASE_URL + '/api/chat/create-group.php',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            name: name,
            members: members
        }),
        success: function(response) {
            const result = JSON.parse(response);
            if (result.success) {
                showToast('Success', 'Group created successfully');
                $('#createGroupModal').modal('hide');
                document.getElementById('groupName').value = '';
            } else {
                showToast('Error', result.error, 'danger');
            }
        },
        error: function() {
            showToast('Error', 'Failed to create group', 'danger');
        }
    });
}

// Send group message
function sendGroupMessage() {
    if (!currentChatGroup) return;
    
    const input = document.getElementById('groupChatInput');
    const message = input.value.trim();
    
    if (!message) return;
    
    $.ajax({
        url: BASE_URL + '/api/chat/send-message.php',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            message: message,
            group_id: currentChatGroup
        }),
        success: function() {
            input.value = '';
            loadGroupMessages();
        }
    });
}

// Load group messages
function loadGroupMessages() {
    if (!currentChatGroup) return;
    
    $.ajax({
        url: BASE_URL + '/api/chat/get-messages.php?group_id=' + currentChatGroup,
        method: 'GET',
        success: function(response) {
            const messages = JSON.parse(response);
            const container = document.getElementById('groupChatMessages');
            let html = '';
            
            messages.forEach(msg => {
                const time = new Date(msg.created_at).toLocaleTimeString();
                if (msg.is_mine) {
                    html += `
                        <div class="text-end mb-2">
                            <div class="d-inline-block bg-primary text-white p-2 rounded">
                                <small>${escapeHtml(msg.message)}</small>
                                <br>
                                <small class="text-white-50">${time}</small>
                            </div>
                        </div>
                    `;
                } else {
                    html += `
                        <div class="mb-2">
                            <strong class="small">${escapeHtml(msg.sender_name)}</strong>
                            <div class="d-inline-block bg-light p-2 rounded">
                                <small>${escapeHtml(msg.message)}</small>
                                <br>
                                <small class="text-muted">${time}</small>
                            </div>
                        </div>
                    `;
                }
            });
            
            container.innerHTML = html;
            container.scrollTop = container.scrollHeight;
        }
    });
}

// Start private chat with user
function startPrivateChat(userId, userName) {
    currentChatUser = userId;
    currentChatGroup = null;
    document.getElementById('chatHeader').innerHTML = '<i class="fas fa-user"></i> Chat with ' + escapeHtml(userName);
    loadChatMessages();
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}