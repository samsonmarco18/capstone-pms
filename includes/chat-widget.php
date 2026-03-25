<?php
require_once __DIR__ . '/../includes/auth.php';

$user = getCurrentUser();
if (!$user) return;
?>

<!-- Chat Widget (Sticky) -->
<div id="chat-widget" class="chat-widget" style="position: fixed; bottom: 20px; right: 20px; z-index: 1000; 
                                               width: 350px; max-width: calc(100vw - 40px); 
                                               box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15); 
                                               border-radius: 10px; overflow: hidden; 
                                               background: white; display: flex; flex-direction: column;">
    
    <!-- Chat Header -->
    <div class="chat-header" style="background: linear-gradient(135deg, #0d556b 0%, #1a7e8f 100%);
                                 color: white; padding: 15px; display: flex; justify-content: space-between; align-items: center;">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-comments" style="font-size: 18px;"></i>
            <h6 class="mb-0">Messages</h6>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-link text-white p-0" id="toggleChatNew" style="font-size: 14px;" title="New Message">
                <i class="fas fa-pen"></i>
            </button>
            <button class="btn btn-sm btn-link text-white p-0" id="toggleChatMinimize" style="font-size: 14px;" title="Minimize">
                <i class="fas fa-minus"></i>
            </button>
        </div>
    </div>

    <!-- Chat Body -->
    <div id="chatBody" class="chat-body" style="height: 400px; overflow-y: auto; padding: 15px; flex: 1; background: #f8f9fa;">
        <!-- Messages will be loaded here -->
        <div class="text-center text-muted py-5">
            <i class="fas fa-inbox" style="font-size: 30px; opacity: 0.5;"></i>
            <p class="mt-3 small">No messages yet</p>
        </div>
    </div>

    <!-- Chat Input -->
    <div class="chat-input" style="padding: 15px; border-top: 1px solid #e0e0e0;">
        <div class="d-flex gap-2 mb-2">
            <input type="text" id="chatGrpSelect" placeholder="Select chat group or user..." 
                   class="form-control form-control-sm" style="font-size: 12px;">
        </div>
        <div class="d-flex gap-2">
            <input type="text" id="chatInput" placeholder="Type a message..." 
                   class="form-control form-control-sm" style="font-size: 12px;">
            <button class="btn btn-sm btn-primary" id="sendMessageBtn" style="background: var(--primary-color); border-color: var(--primary-color);">
                <i class="fas fa-paper-plane"></i>
            </button>
        </div>
    </div>
</div>

<!-- Minimized Chat Widget -->
<div id="chat-widget-minimized" class="chat-widget-minimized" style="position: fixed; bottom: 20px; right: 20px; z-index: 999; 
                                                                    width: 80px; height: 80px; 
                                                                    background: linear-gradient(135deg, #0d556b 0%, #1a7e8f 100%);
                                                                    border-radius: 50%; display: none; 
                                                                    align-items: center; justify-content: center;
                                                                    cursor: pointer; box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2); flex-direction: column;"
               onclick="document.getElementById('chat-widget').style.display='flex'; document.getElementById('chat-widget-minimized').style.display='flex';">
    <div class="text-center text-white">
        <i class="fas fa-comments" style="font-size: 24px; display: block;"></i>
        <small style="font-size: 10px; margin-top: 5px; display: block;">Chat</small>
    </div>
</div>

<script>
    // Minimize chat
    document.getElementById('toggleChatMinimize')?.addEventListener('click', function(e) {
        e.preventDefault();
        document.getElementById('chat-widget').style.display = 'none';
        document.getElementById('chat-widget-minimized').style.display = 'flex';
    });

    // New message button
    document.getElementById('toggleChatNew')?.addEventListener('click', function(e) {
        e.preventDefault();
        // Open modal or dropdown to create new chat
        alert('New message feature - to be implemented');
    });

    // Send message
    document.getElementById('sendMessageBtn')?.addEventListener('click', function(e) {
        e.preventDefault();
        const message = document.getElementById('chatInput').value;
        const groupId = document.getElementById('chatGrpSelect').value;
        
        if (!message.trim()) {
            alert('Please enter a message');
            return;
        }

        if (!groupId) {
            alert('Please select a chat group or user');
            return;
        }

        // Send message via API
        fetch('<?php echo BASE_URL; ?>/api/chat/send-message.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                group_id: groupId,
                message: message
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('chatInput').value = '';
                loadChatMessages();
            } else {
                alert('Error sending message: ' + data.message);
            }
        })
        .catch(error => console.error('Error:', error));
    });

    // Load chat messages
    function loadChatMessages() {
        const groupId = document.getElementById('chatGrpSelect').value;
        if (!groupId) return;

        fetch('<?php echo BASE_URL; ?>/api/chat/get-messages.php?group_id=' + groupId)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.messages) {
                    let html = '';
                    data.messages.forEach(msg => {
                        html += '<div class="message mb-2"><small class="text-muted">' + msg.sender_name + '</small>' +
                                '<p class="mb-1">' + msg.message + '</p></div>';
                    });
                    document.getElementById('chatBody').innerHTML = html;
                }
            })
            .catch(error => console.error('Error:', error));
    }

    // Load chat groups into select on page load
    window.addEventListener('load', function() {
        fetch('<?php echo BASE_URL; ?>/api/chat/get-groups.php')
            .then(response => response.json())
            .then(data => {
                if (data.success && data.groups) {
                    let options = '<option value="">Select...</option>';
                    data.groups.forEach(group => {
                        options += '<option value="' + group.id + '">' + group.name + '</option>';
                    });
                    document.getElementById('chatGrpSelect').innerHTML = options;
                }
            })
            .catch(error => console.error('Error loading groups:', error));
    });
</script>
.chat-widget {
    position: fixed;
    bottom: 20px;
    right: 20px;
    width: 300px;
    background: white;
    border-radius: 10px;
    box-shadow: 0 0 10px rgba(0,0,0,0.1);
    z-index: 1000;
}

.chat-header {
    background: #0d6efd;
    color: white;
    padding: 10px;
    border-radius: 10px 10px 0 0;
    cursor: pointer;
}

.chat-body {
    height: 400px;
    display: flex;
    flex-direction: column;
}

.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 10px;
}

.chat-input {
    padding: 10px;
    border-top: 1px solid #dee2e6;
}

.message {
    margin-bottom: 10px;
    padding: 5px 10px;
    border-radius: 5px;
}

.message.sent {
    background: #0d6efd;
    color: white;
    text-align: right;
}

.message.received {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
}
</style>

<script>
let currentChatUser = null;
let currentChatGroup = null;
let chatInterval = null;

function toggleChat() {
    const chatBody = document.getElementById('chatBody');
    if (chatBody.style.display === 'none') {
        chatBody.style.display = 'flex';
        loadMessages();
        if (!chatInterval) {
            chatInterval = setInterval(loadMessages, 5000);
        }
    } else {
        chatBody.style.display = 'none';
        if (chatInterval) {
            clearInterval(chatInterval);
            chatInterval = null;
        }
    }
}

function loadMessages() {
    $.ajax({
        url: BASE_URL + '/api/chat/get-messages.php',
        method: 'GET',
        success: function(response) {
            const messages = JSON.parse(response);
            displayMessages(messages);
        }
    });
}

function sendMessage() {
    const message = $('#chatMessageInput').val();
    if (!message.trim()) return;
    
    $.ajax({
        url: BASE_URL + '/api/chat/send-message.php',
        method: 'POST',
        data: {
            message: message,
            receiver_id: currentChatUser,
            group_id: currentChatGroup
        },
        success: function() {
            $('#chatMessageInput').val('');
            loadMessages();
        }
    });
}

function showGroupChat() {
    // Load group chat interface
    $.ajax({
        url: BASE_URL + '/api/chat/get-groups.php',
        success: function(groups) {
            // Show groups list modal
        }
    });
}

function showCreateGroup() {
    // Show create group modal
    $('#createGroupModal').modal('show');
}
</script>