<?php
$isSulRoute = str_contains($_SERVER['REQUEST_URI'] ?? '', '/modules/sul/');
?>

<?php if ($isSulRoute): ?>
<style>
    #chat-widget,
    #chat-widget-minimized {
        position: fixed !important;
        inset: auto 24px 24px auto !important;
        top: auto !important;
        right: 24px !important;
        bottom: 24px !important;
        left: auto !important;
        margin: 0 !important;
        transform: none !important;
        z-index: 5000 !important;
    }

    #chat-widget-minimized {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        width: 64px !important;
        height: 64px !important;
        opacity: 1 !important;
        visibility: visible !important;
        pointer-events: auto !important;
        box-shadow: 0 18px 36px rgba(10, 48, 79, 0.28) !important;
    }

    #chat-widget-minimized.chat-launcher-hidden {
        display: none !important;
    }

    #chat-bot-widget {
        position: fixed !important;
        inset: auto 24px 96px auto !important;
        top: auto !important;
        right: 24px !important;
        bottom: 96px !important;
        left: auto !important;
        width: 64px !important;
        height: 64px !important;
        z-index: 5001 !important;
    }

    #chat-widget.chat-widget-open {
        display: flex !important;
    }
</style>
<?php endif; ?>

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

<div class="modal fade create-group-modal add-member-modal" id="addGroupMemberModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Add Group Members</h5>
                    <p class="create-group-subtitle mb-0">Select one or more teammates to join this group.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-0">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <label class="form-label mb-0" for="addGroupMemberSearch">Members</label>
                        <span class="group-member-count" id="addGroupMemberCount">0 selected</span>
                    </div>
                    <div class="group-member-picker">
                        <div class="group-member-search">
                            <i class="fas fa-search"></i>
                            <input type="text" id="addGroupMemberSearch" class="form-control" placeholder="Search users">
                        </div>
                        <div id="addGroupMembers" class="group-member-list" aria-live="polite">
                            <div class="group-member-empty">
                                <i class="fas fa-spinner fa-spin"></i>
                                <span>Loading users...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="addGroupMemberSubmitBtn">Add Members</button>
            </div>
        </div>
    </div>
</div>

<button id="chat-bot-widget" class="chat-bot-widget" type="button" aria-label="Open chat" style="display: flex;">
    <span class="chat-bot-widget-speech" aria-hidden="true">
        <strong>Hi</strong>
        <span>I'm listening</span>
    </span>
    <span id="chatBotAnimation" class="chat-bot-animation" aria-hidden="true">
        <i class="fas fa-robot"></i>
    </span>
</button>

<div id="chat-bot-float" class="chat-bot-float" hidden aria-hidden="true">
    <div class="chat-bot-speech" aria-hidden="true">
        <strong data-chat-bot-type="title" data-chat-bot-text="Hi, hello"></strong>
        <span data-chat-bot-type="message" data-chat-bot-text="I'm listening."></span>
    </div>
    <div id="chatBotFloatAnimation" class="chat-bot-float-animation" aria-hidden="true">
        <i class="fas fa-robot"></i>
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
    const botWidget = document.getElementById('chat-bot-widget');
    const botFloat = document.getElementById('chat-bot-float');
    const minimizeBtn = document.getElementById('toggleChatMinimize');
    let botFloatPointer = null;
    let botTypeTimer = null;

    if (!widget || !minimized) return;

    document.body.appendChild(widget);
    document.body.appendChild(minimized);
    if (botWidget) {
        document.body.appendChild(botWidget);
    }
    if (botFloat) {
        document.body.appendChild(botFloat);
    }

    const setSharedChatOpen = function (isOpen) {
        widget.classList.toggle('chat-widget-open', isOpen);
        minimized.classList.toggle('chat-launcher-hidden', isOpen);
        botWidget?.classList.toggle('chat-bot-hidden', isOpen);
    };

    const openChatMessages = function () {
        setSharedChatOpen(true);
        widget.style.display = 'flex';
        minimized.style.display = 'none';
    };

    const typeBotSpeech = function () {
        if (!botFloat) {
            return;
        }

        const title = botFloat.querySelector('[data-chat-bot-type="title"]');
        const message = botFloat.querySelector('[data-chat-bot-type="message"]');
        if (!title || !message) {
            return;
        }

        clearTimeout(botTypeTimer);
        title.textContent = '';
        message.textContent = '';
        title.classList.add('is-typing');
        message.classList.remove('is-typing');

        const typeInto = function (element, text, index, done) {
            if (index <= text.length) {
                element.textContent = text.slice(0, index);
                botTypeTimer = setTimeout(function () {
                    typeInto(element, text, index + 1, done);
                }, 58);
                return;
            }

            element.classList.remove('is-typing');
            done?.();
        };

        typeInto(title, title.dataset.chatBotText || '', 0, function () {
            message.classList.add('is-typing');
            botTypeTimer = setTimeout(function () {
                typeInto(message, message.dataset.chatBotText || '', 0);
            }, 180);
        });
    };

    minimized.addEventListener('click', function () {
        openChatMessages();
    });

    botWidget?.addEventListener('click', function () {
        if (!botFloat) {
            return;
        }

        const shouldShow = botFloat.hidden;
        botFloat.hidden = !shouldShow;
        botFloat.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
        if (shouldShow) {
            typeBotSpeech();
        } else {
            clearTimeout(botTypeTimer);
        }
    });

    const clampBotFloatPosition = function (x, y) {
        const rect = botFloat.getBoundingClientRect();
        const padding = 10;
        return {
            x: Math.min(Math.max(padding, x), window.innerWidth - rect.width - padding),
            y: Math.min(Math.max(padding, y), window.innerHeight - rect.height - padding)
        };
    };

    botFloat?.addEventListener('pointerdown', function (event) {
        const rect = botFloat.getBoundingClientRect();
        botFloatPointer = {
            id: event.pointerId,
            offsetX: event.clientX - rect.left,
            offsetY: event.clientY - rect.top
        };
        botFloat.classList.add('is-dragging');
        botFloat.setPointerCapture?.(event.pointerId);
    });

    botFloat?.addEventListener('pointermove', function (event) {
        if (!botFloatPointer || botFloatPointer.id !== event.pointerId) {
            return;
        }

        const next = clampBotFloatPosition(
            event.clientX - botFloatPointer.offsetX,
            event.clientY - botFloatPointer.offsetY
        );
        botFloat.style.left = `${next.x}px`;
        botFloat.style.top = `${next.y}px`;
        botFloat.style.right = 'auto';
        botFloat.style.bottom = 'auto';
    });

    const finishBotFloatDrag = function (event) {
        if (!botFloatPointer || botFloatPointer.id !== event.pointerId) {
            return;
        }

        botFloatPointer = null;
        botFloat.classList.remove('is-dragging');
        botFloat.releasePointerCapture?.(event.pointerId);
    };

    botFloat?.addEventListener('pointerup', finishBotFloatDrag);
    botFloat?.addEventListener('pointercancel', finishBotFloatDrag);

    if (minimizeBtn) {
        minimizeBtn.addEventListener('click', function () {
            setSharedChatOpen(false);
            widget.style.display = 'none';
            minimized.style.display = 'block';
        });
    }

    const loadChatBotAnimation = function () {
        const bot = document.getElementById('chatBotAnimation');
        const floatingBot = document.getElementById('chatBotFloatAnimation');
        if (!window.lottie) {
            return;
        }

        const baseUrl = (window.BASE_URL || '/erni-pms/').replace(/\/?$/, '/');
        if (bot && !bot.dataset.lottieReady) {
            bot.dataset.lottieReady = 'true';
            bot.innerHTML = '';
            window.lottie.loadAnimation({
                container: bot,
                renderer: 'svg',
                loop: true,
                autoplay: true,
                path: `${baseUrl}assets/animations/Ai%20Robot%20Vector%20Art.json`
            });
        }

        if (floatingBot && !floatingBot.dataset.lottieReady) {
            floatingBot.dataset.lottieReady = 'true';
            floatingBot.innerHTML = '';
            window.lottie.loadAnimation({
                container: floatingBot,
                renderer: 'svg',
                loop: true,
                autoplay: true,
                path: `${baseUrl}assets/animations/Ai%20Robot%20Vector%20Art.json`
            });
        }
    };

    if (window.lottie) {
        loadChatBotAnimation();
    } else {
        const existingLottieScript = document.querySelector('script[data-lottie-web]');
        if (existingLottieScript) {
            existingLottieScript.addEventListener('load', loadChatBotAnimation, { once: true });
        } else {
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/lottie-web/5.12.2/lottie.min.js';
            script.dataset.lottieWeb = 'true';
            script.onload = loadChatBotAnimation;
            document.head.appendChild(script);
        }
    }
});
</script>
