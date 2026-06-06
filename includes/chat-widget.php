<?php
require_once __DIR__ . '/auth.php';
$user = getCurrentUser();
if (!$user) {
    return;
}
?>

<div id="chat-widget" class="chat-widget">
    <div class="chat-header">
        <div class="d-flex align-items-center gap-2">
            <strong id="chatHeader">Messages</strong>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-link btn-sm text-white p-0 chat-icon-btn" id="chatSearchBtn" title="Search">
                <i class="fas fa-search"></i>
            </button>
            <button type="button" class="btn btn-link btn-sm text-white p-0 chat-icon-btn" data-bs-toggle="modal" data-bs-target="#createGroupModal" title="Create group">
                <i class="fas fa-user-plus"></i>
            </button>
            <button type="button" class="btn btn-link btn-sm text-white p-0 chat-icon-btn" id="toggleChatMinimize" title="Minimize chat" onclick="document.getElementById('chat-widget').style.display='none'; document.getElementById('chat-widget-minimized').style.display='block';">
                <i class="fas fa-minus"></i>
            </button>
        </div>
    </div>

    <div class="chat-body" id="chatBody">
        <div id="chatInboxView" class="chat-inbox-view">
            <div class="chat-search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" id="chatSearchInput" class="form-control" placeholder="Search conversation">
            </div>
            <div class="chat-tabs">
                <button type="button" class="chat-tab active" data-chat-tab="chat">Chat</button>
                <button type="button" class="chat-tab" data-chat-tab="groups">Groups</button>
            </div>
            <div id="chatConversationList" class="chat-conversation-list">
                <div class="chat-list-empty">
                    <i class="fas fa-comments"></i>
                    <div>Loading conversations...</div>
                </div>
            </div>
        </div>

        <div id="chatThreadView" class="chat-thread-view d-none">
            <div class="chat-thread-top">
                <button type="button" class="btn btn-link p-0 chat-back-btn" id="chatBackBtn" title="Back">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="chat-thread-avatar" id="chatThreadAvatar">C</div>
                <div class="chat-thread-meta">
                    <strong id="chatThreadName">Conversation</strong>
                    <small id="chatThreadSubtitle">Select a conversation</small>
                </div>
                <button type="button" class="btn btn-link p-0 chat-thread-info-btn d-none" id="chatGroupInfoBtn" title="Group info" aria-label="Group info">
                    <i class="fas fa-users"></i>
                </button>
            </div>

            <div id="chatMessages" class="chat-messages">
                <div class="text-center text-muted py-5">
                    <i class="fas fa-inbox fa-2x mb-2"></i>
                    <div>Select a conversation to start chatting.</div>
                </div>
            </div>

            <div class="chat-input">
                <div class="input-group input-group-sm">
                    <input type="text" id="chatMessageInput" class="form-control" placeholder="Type your message...">
                    <button class="btn btn-primary" type="button" id="sendMessageBtn">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>

        <div id="chatGroupInfoView" class="chat-group-info-view d-none">
            <div class="chat-thread-top">
                <button type="button" class="btn btn-link p-0 chat-back-btn" id="chatGroupInfoBackBtn" title="Back">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="chat-thread-avatar" id="chatGroupInfoAvatar">G</div>
                <div class="chat-thread-meta">
                    <strong id="chatGroupInfoTitle">Group Info</strong>
                    <small id="chatGroupInfoSubtitle">Members and settings</small>
                </div>
            </div>

            <div id="chatGroupInfoContent" class="chat-group-info-content">
                <div class="chat-list-empty">
                    <i class="fas fa-spinner fa-spin"></i>
                    <div>Loading group info...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<button id="chat-widget-minimized" class="chat-widget-minimized" type="button" title="Open chat" style="display: block;" onclick="document.getElementById('chat-widget').style.display='flex'; this.style.display='none';">
    <i class="fas fa-comments"></i>
    <span id="chatUnreadBadge" class="chat-unread-badge d-none" aria-live="polite">0</span>
</button>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const widget = document.getElementById('chat-widget');
    const minimized = document.getElementById('chat-widget-minimized');
    const minimizeBtn = document.getElementById('toggleChatMinimize');

    if (!widget || !minimized) return;

    minimized.addEventListener('click', function () {
        widget.style.display = 'flex';
        minimized.style.display = 'none';
    });

    if (minimizeBtn) {
        minimizeBtn.addEventListener('click', function () {
            widget.style.display = 'none';
            minimized.style.display = 'block';
        });
    }
});
</script>
