<?php
$isSulRoute = str_contains($_SERVER['REQUEST_URI'] ?? '', '/modules/sul/');
$evortixProjectTeamNotification = null;
if (function_exists('isLoggedIn') && isLoggedIn()) {
    try {
        $evortixUser = getCurrentUser();
        $evortixUserId = (int) ($evortixUser['id'] ?? 0);
        if ($evortixUserId > 0) {
            $evortixStmt = getConnection()->prepare(
                "SELECT id, title, message, action_url, related_id
                 FROM notifications
                 WHERE user_id = ?
                   AND is_read = FALSE
                   AND related_table = 'projects'
                   AND title LIKE 'Project team message:%'
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1"
            );
            if ($evortixStmt) {
                $evortixStmt->bind_param('i', $evortixUserId);
                $evortixStmt->execute();
                $evortixProjectTeamNotification = $evortixStmt->get_result()->fetch_assoc() ?: null;
                if ($evortixProjectTeamNotification && (int) ($evortixProjectTeamNotification['related_id'] ?? 0) > 0) {
                    $evortixProjectTeamNotification['action_url'] = BASE_URL
                        . 'modules/roadmap/methodology.php?project_id='
                        . (int) $evortixProjectTeamNotification['related_id'];
                }
                $evortixStmt->close();
            }
        }
    } catch (Throwable $e) {
        error_log('Evortix project notification load failed: ' . $e->getMessage());
    }
}
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
    <span id="chatBotAnimation" class="chat-bot-animation" aria-hidden="true">
        <i class="fas fa-robot"></i>
    </span>
</button>

<div id="chat-bot-float" class="chat-bot-float" hidden aria-hidden="true">
    <div class="chat-bot-speech" aria-hidden="true">
        <strong data-chat-bot-type="title" data-chat-bot-text="Hi, good day!"></strong>
    </div>
    <div id="chatBotFloatAnimation" class="chat-bot-float-animation" aria-hidden="true">
        <i class="fas fa-robot"></i>
    </div>
</div>

<button id="chat-widget-minimized" class="chat-widget-minimized" type="button" title="Open chat" style="display: block;" onclick="document.getElementById('chat-widget').style.display='flex'; this.style.display='none';">
    <i class="fas fa-comments"></i>
    <span id="chatUnreadBadge" class="chat-unread-badge d-none" aria-live="polite">0</span>
</button>

<script src="<?php echo BASE_URL; ?>assets/js/evortix-assistant.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/evortix-assistant.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const evortixProjectTeamNotification = <?php echo json_encode($evortixProjectTeamNotification, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const widget = document.getElementById('chat-widget');
    const minimized = document.getElementById('chat-widget-minimized');
    const botWidget = document.getElementById('chat-bot-widget');
    const botFloat = document.getElementById('chat-bot-float');
    const minimizeBtn = document.getElementById('toggleChatMinimize');
    let botFloatPointer = null;
    let botTypeTimer = null;
    let botTransitionTimer = null;

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

    const resetBotSpeech = function () {
        if (!botFloat) {
            return;
        }

        const speech = botFloat.querySelector('.chat-bot-speech');
        speech?.classList.remove('evortix-project-notification');
        if (speech) speech.style.pointerEvents = '';
        speech?.querySelector('.evortix-project-notification-copy')?.remove();
        speech?.querySelector('.evortix-project-notification-action')?.remove();
        const title = botFloat.querySelector('[data-chat-bot-type="title"]');
        if (!title) {
            return;
        }

        clearTimeout(botTypeTimer);
        title.textContent = '';
        title.classList.remove('is-typing');
    };

    const typeBotSpeech = function (startDelay = 0) {
        if (!botFloat) {
            return;
        }

        const title = botFloat.querySelector('[data-chat-bot-type="title"]');
        if (!title) {
            return;
        }

        clearTimeout(botTypeTimer);
        title.textContent = '';

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

        const startTyping = function () {
            title.classList.add('is-typing');
            typeInto(title, title.dataset.chatBotText || '', 0);
        };

        if (startDelay > 0) {
            botTypeTimer = setTimeout(startTyping, startDelay);
            return;
        }

        startTyping();
    };

    window.evortixAnnounceNotification = function (heading, message, actionUrl) {
        if (!botFloat) return;
        const speech = botFloat.querySelector('.chat-bot-speech');
        if (!speech) return;

        speech.innerHTML = '';
        speech.classList.add('evortix-project-notification');
        speech.setAttribute('aria-hidden', 'false');
        speech.style.pointerEvents = 'auto';
        const title = document.createElement('strong');
        title.dataset.chatBotType = 'title';
        title.dataset.chatBotText = heading || 'Project update';
        speech.appendChild(title);
        const copy = document.createElement('span');
        copy.className = 'evortix-project-notification-copy';
        copy.textContent = message || '';
        speech.appendChild(copy);
        if (actionUrl) {
            const link = document.createElement('a');
            link.href = actionUrl;
            link.className = 'btn btn-primary evortix-project-notification-action';
            link.textContent = 'Open project';
            speech.appendChild(link);
        }
        botFloat.hidden = false;
        botFloat.setAttribute('aria-hidden', 'false');
        window.chatBotWidgetRobot?.wake?.();
        window.chatBotFloatRobot?.wink?.('right');
        typeBotSpeech(180);
    };

    if (evortixProjectTeamNotification) {
        const storageKey = `evortix.projectNotification.${evortixProjectTeamNotification.id}`;
        if (!sessionStorage.getItem(storageKey)) {
            sessionStorage.setItem(storageKey, '1');
            window.setTimeout(() => window.evortixAnnounceNotification(
                evortixProjectTeamNotification.title,
                evortixProjectTeamNotification.message,
                evortixProjectTeamNotification.action_url
            ), 700);
        }
    }

    minimized.addEventListener('click', function () {
        openChatMessages();
    });

    botWidget?.addEventListener('click', function () {
        if (!botFloat) {
            return;
        }

        const shouldShow = botFloat.hidden;
        clearTimeout(botTransitionTimer);
        botFloat.classList.remove('is-leaving-bed', 'is-returning-bed', 'is-preparing-speech', 'is-speaking');
        if (shouldShow) {
            resetBotSpeech();
            botFloat.hidden = false;
            botFloat.setAttribute('aria-hidden', 'false');
            botFloat.classList.add('is-leaving-bed', 'is-preparing-speech');
            window.chatBotWidgetRobot?.wake?.();
            window.setTimeout(function () {
                window.chatBotFloatRobot?.wink?.('right');
            }, 420);
            botTransitionTimer = window.setTimeout(function () {
                botFloat.classList.remove('is-leaving-bed');
                botFloat.classList.remove('is-preparing-speech');
                botFloat.classList.add('is-speaking');
                typeBotSpeech(260);
                botTransitionTimer = window.setTimeout(function () {
                    botFloat.classList.remove('is-speaking');
                }, 520);
            }, 760);
        } else {
            botFloat.setAttribute('aria-hidden', 'true');
            botFloat.classList.add('is-returning-bed');
            window.chatBotWidgetRobot?.sleep?.();
            clearTimeout(botTypeTimer);
            botTransitionTimer = window.setTimeout(function () {
                botFloat.hidden = true;
                botFloat.classList.remove('is-returning-bed');
            }, 660);
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
        if (event.target instanceof Element && event.target.closest('.chat-bot-speech')) {
            return;
        }

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

    let robotSvgBlueprint = null;
    let robotInstanceSequence = 0;
    const robotAnimatedAttributes = [
        'transform', 'opacity', 'd', 'fill', 'fill-opacity', 'stroke', 'stroke-opacity',
        'stroke-width', 'offset', 'stop-color', 'stop-opacity', 'x', 'y', 'x1', 'y1',
        'x2', 'y2', 'cx', 'cy', 'fx', 'fy', 'r', 'rx', 'ry', 'width', 'height'
    ];
    const normalizeRobotPart = function (name) {
        return String(name || '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    };
    const tagRobotParts = function (animation) {
        const elements = animation?.renderer?.elements || [];
        elements.forEach(function (element) {
            const layerName = String(element?.data?.nm || '');
            const layerNode = element?.baseElement || element?.layerElement;
            if (layerNode && layerName) {
                layerNode.setAttribute('data-robot-part', normalizeRobotPart(layerName));
                layerNode.setAttribute('data-robot-part-name', layerName);
            }

            if (layerName.toLowerCase() !== 'eyes') return;
            const features = {
                'group 1':'right-eye',
                'group 2':'mouth',
                'group 3':'right-eye',
                'group 4':'mouth',
                'group 5':'left-eye',
                'group 6':'left-eye'
            };
            const shapes = element?.data?.shapes || element?.shapesData || [];
            const items = element?.itemsData || [];
            shapes.forEach(function (shape, shapeIndex) {
                const feature = features[String(shape?.nm || '').toLowerCase()];
                const shapeNode = items[shapeIndex]?.gr || items[shapeIndex]?.baseElement;
                if (feature && shapeNode) {
                    shapeNode.setAttribute('data-robot-feature', feature);
                }
            });
        });
    };
    const readRobotNodeState = function (node) {
        return {
            attributes: robotAnimatedAttributes.map(function (attribute) {
                return node.getAttribute(attribute);
            }),
            styleTransform: node.style?.transform || '',
            styleOpacity: node.style?.opacity || '',
            styleDisplay: node.style?.display || ''
        };
    };
    const robotStatesMatch = function (left, right) {
        if (!left || !right || left.styleTransform !== right.styleTransform
            || left.styleOpacity !== right.styleOpacity || left.styleDisplay !== right.styleDisplay) {
            return false;
        }
        return left.attributes.every(function (value, index) {
            return value === right.attributes[index];
        });
    };
    const captureRobotBlueprint = function (animation, container) {
        tagRobotParts(animation);
        animation.goToAndStop(0, true);
        const sourceSvg = container.querySelector('svg');
        if (!sourceSvg) throw new Error('Robot SVG was not rendered.');

        const sourceNodes = [sourceSvg].concat(Array.from(sourceSvg.querySelectorAll('*')));
        sourceNodes.forEach(function (node, nodeIndex) {
            node.setAttribute('data-robot-node', String(nodeIndex));
        });

        const totalFrames = Math.max(1, Math.floor(Number(animation.totalFrames || animation.animationData?.op || 1)));
        const frameRate = Math.max(1, Number(animation.frameRate || animation.animationData?.fr || 60));
        const statesByNode = sourceNodes.map(function () { return []; });
        for (let frame = 0; frame < totalFrames; frame++) {
            animation.goToAndStop(frame, true);
            sourceNodes.forEach(function (node, nodeIndex) {
                statesByNode[nodeIndex].push(readRobotNodeState(node));
            });
        }
        animation.goToAndStop(0, true);

        const tracks = [];
        statesByNode.forEach(function (states, nodeIndex) {
            const first = states[0];
            if (!states.some(function (state) { return !robotStatesMatch(first, state); })) return;
            const attributeIndexes = robotAnimatedAttributes.map(function (_attribute, attributeIndex) {
                return states.some(function (state) {
                    return state.attributes[attributeIndex] !== first.attributes[attributeIndex];
                }) ? attributeIndex : -1;
            }).filter(function (attributeIndex) { return attributeIndex >= 0; });
            tracks.push({
                nodeId:String(nodeIndex),
                states,
                attributeIndexes,
                changesStyleTransform:states.some(function (state) { return state.styleTransform !== first.styleTransform; }),
                changesStyleOpacity:states.some(function (state) { return state.styleOpacity !== first.styleOpacity; }),
                changesStyleDisplay:states.some(function (state) { return state.styleDisplay !== first.styleDisplay; })
            });
        });

        return {
            svgTemplate:sourceSvg.cloneNode(true),
            totalFrames,
            frameRate,
            tracks
        };
    };
    const applyRobotState = function (track, state) {
        track.attributeIndexes.forEach(function (attributeIndex) {
            const attribute = robotAnimatedAttributes[attributeIndex];
            const value = state.attributes[attributeIndex];
            if (value === null) track.node.removeAttribute(attribute);
            else track.node.setAttribute(attribute, value);
        });
        if (track.changesStyleTransform) track.node.style.transform = state.styleTransform;
        if (track.changesStyleOpacity) track.node.style.opacity = state.styleOpacity;
        if (track.changesStyleDisplay) track.node.style.display = state.styleDisplay;
    };
    const namespaceRobotSvgIds = function (svg) {
        const suffix = `-erni-robot-${++robotInstanceSequence}`;
        const idMap = new Map();
        Array.from(svg.querySelectorAll('[id]')).forEach(function (node) {
            const oldId = node.id;
            const newId = `${oldId}${suffix}`;
            idMap.set(oldId, newId);
            node.id = newId;
        });
        if (!idMap.size) return;
        [svg].concat(Array.from(svg.querySelectorAll('*'))).forEach(function (node) {
            Array.from(node.attributes || []).forEach(function (attribute) {
                let value = attribute.value;
                idMap.forEach(function (newId, oldId) {
                    value = value.split(`url(#${oldId})`).join(`url(#${newId})`);
                    if (value === `#${oldId}`) value = `#${newId}`;
                });
                if (value !== attribute.value) node.setAttribute(attribute.name, value);
            });
        });
    };
    const prepareRobotFeatureControls = function (svg) {
        const eyeLayer = svg.querySelector('[data-robot-part="eyes"]');
        if (eyeLayer && !eyeLayer.querySelector('[data-robot-feature]')) {
            const layerRect = eyeLayer.getBoundingClientRect();
            const centerX = layerRect.left + layerRect.width / 2;
            Array.from(eyeLayer.querySelectorAll('path')).forEach(function (path) {
                const rect = path.getBoundingClientRect();
                const pathX = rect.left + rect.width / 2;
                const pathY = rect.top + rect.height / 2;
                const isEye = rect.width > 0 && rect.height > 0
                    && Math.abs(pathX - centerX) > layerRect.width * .16
                    && pathY < layerRect.top + layerRect.height * .72;
                if (isEye) {
                    path.setAttribute('data-robot-feature', pathX < centerX ? 'left-eye' : 'right-eye');
                }
            });
        }

        Array.from(svg.querySelectorAll('[data-robot-feature]')).forEach(function (feature) {
            if (feature.parentElement?.hasAttribute('data-robot-feature-control')) return;
            const wrapper = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            wrapper.setAttribute('data-robot-feature-control', feature.getAttribute('data-robot-feature'));
            wrapper.style.transformBox = 'fill-box';
            wrapper.style.transformOrigin = 'center';
            feature.parentNode?.insertBefore(wrapper, feature);
            wrapper.appendChild(feature);
        });

        ['l-hand', 'l-hand-2', 'r-hand', 'r-hand-2'].forEach(function (partName) {
            const part = svg.querySelector(`[data-robot-part="${partName}"]`);
            if (!part || part.parentElement?.hasAttribute('data-robot-part-control')) return;
            const wrapper = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            wrapper.setAttribute('data-robot-part-control', partName);
            wrapper.style.transformBox = 'fill-box';
            wrapper.style.transformOrigin = 'center';
            part.parentNode?.insertBefore(wrapper, part);
            wrapper.appendChild(part);
        });

        if (eyeLayer && !eyeLayer.querySelector('[data-robot-wink-mark]')) {
            const winkMarks = {
                right:'M 660 304 L 620 334 L 660 364',
                left:'M 420 304 L 460 334 L 420 364'
            };
            Object.entries(winkMarks).forEach(function ([side, pathData]) {
                const mark = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                mark.setAttribute('data-robot-wink-mark', side);
                mark.style.opacity = '0';
                mark.style.pointerEvents = 'none';
                mark.style.transformBox = 'fill-box';
                mark.style.transformOrigin = 'center';

                const glow = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                glow.setAttribute('d', pathData);
                glow.setAttribute('fill', 'none');
                glow.setAttribute('stroke', '#39eff4');
                glow.setAttribute('stroke-width', '28');
                glow.setAttribute('stroke-linecap', 'round');
                glow.setAttribute('stroke-linejoin', 'round');
                glow.setAttribute('opacity', '.2');

                const line = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                line.setAttribute('d', pathData);
                line.setAttribute('fill', 'none');
                line.setAttribute('stroke', '#62f4f5');
                line.setAttribute('stroke-width', '16');
                line.setAttribute('stroke-linecap', 'round');
                line.setAttribute('stroke-linejoin', 'round');

                mark.append(glow, line);
                eyeLayer.appendChild(mark);
            });
        }
    };
    const instantiateControllableRobot = function (blueprint, container) {
        const svg = blueprint.svgTemplate.cloneNode(true);
        namespaceRobotSvgIds(svg);
        svg.classList.add('erni-controllable-robot');
        container.replaceChildren(svg);
        prepareRobotFeatureControls(svg);

        const tracks = blueprint.tracks.map(function (track) {
            return {
                node:track.nodeId === '0' ? svg : svg.querySelector(`[data-robot-node="${track.nodeId}"]`),
                states:track.states,
                attributeIndexes:track.attributeIndexes,
                changesStyleTransform:track.changesStyleTransform,
                changesStyleOpacity:track.changesStyleOpacity,
                changesStyleDisplay:track.changesStyleDisplay
            };
        }).filter(function (track) { return Boolean(track.node); });
        let currentFrame = 0;
        let playing = true;
        let animationFrame = null;
        let startedAt = performance.now();
        let winkSequence = 0;
        let resumeRobotAfterWink = false;
        let winkContainerAnimationState = null;

        const applyFrame = function (frame) {
            currentFrame = ((Math.floor(frame) % blueprint.totalFrames) + blueprint.totalFrames) % blueprint.totalFrames;
            tracks.forEach(function (track) {
                applyRobotState(track, track.states[currentFrame]);
            });
        };
        const tick = function (now) {
            if (!playing) return;
            const elapsedFrames = (now - startedAt) * blueprint.frameRate / 1000;
            const nextFrame = Math.floor(elapsedFrames) % blueprint.totalFrames;
            if (nextFrame !== currentFrame) applyFrame(nextFrame);
            animationFrame = requestAnimationFrame(tick);
        };
        const controller = {
            root:svg,
            totalFrames:blueprint.totalFrames,
            frameRate:blueprint.frameRate,
            get isPaused() { return !playing; },
            get currentFrame() { return currentFrame; },
            getPart:function (name) {
                const partName = normalizeRobotPart(name);
                return svg.querySelector(`[data-robot-part-control="${partName}"]`)
                    || svg.querySelector(`[data-robot-part="${partName}"]`);
            },
            getFeatures:function (name) {
                const featureName = normalizeRobotPart(name);
                const controls = Array.from(svg.querySelectorAll(`[data-robot-feature-control="${featureName}"]`));
                return controls.length ? controls : Array.from(svg.querySelectorAll(`[data-robot-feature="${featureName}"]`));
            },
            animatePart:function (name, keyframes, options) {
                return this.getPart(name)?.animate?.(keyframes, options) || null;
            },
            animateFeatures:function (name, keyframes, options) {
                return this.getFeatures(name).map(function (feature) {
                    feature.style.transformBox = 'fill-box';
                    feature.style.transformOrigin = 'center';
                    return feature.animate?.(keyframes, options) || null;
                }).filter(Boolean);
            },
            wink:function (side = 'right') {
                const sequence = ++winkSequence;
                resumeRobotAfterWink = resumeRobotAfterWink || playing;
                this.pause();
                if (winkContainerAnimationState === null) {
                    winkContainerAnimationState = container.style.animationPlayState;
                }
                container.style.animationPlayState = 'paused';
                const featureName = side === 'left' ? 'left-eye' : 'right-eye';
                const duration = 920;
                const animations = this.animateFeatures(featureName, [
                    { opacity:1, offset:0 },
                    { opacity:1, offset:.22 },
                    { opacity:0, offset:.34 },
                    { opacity:0, offset:.72 },
                    { opacity:1, offset:.84 },
                    { opacity:1, offset:1 }
                ], { duration, easing:'cubic-bezier(.4,0,.2,1)' });

                const winkMark = svg.querySelector(`[data-robot-wink-mark="${side}"]`);
                if (winkMark?.animate) {
                    animations.push(winkMark.animate([
                        { opacity:0, transform:'scale(.9)', offset:0 },
                        { opacity:0, transform:'scale(.9)', offset:.22 },
                        { opacity:1, transform:'scale(1.06)', offset:.36 },
                        { opacity:1, transform:'scale(1)', offset:.72 },
                        { opacity:0, transform:'scale(.96)', offset:.86 },
                        { opacity:0, transform:'scale(.96)', offset:1 }
                    ], { duration, easing:'cubic-bezier(.2,.8,.2,1)' }));
                }

                const handKeyframes = [
                    { transform:'translateY(0)', offset:0 },
                    { transform:'translateY(0)', offset:.16 },
                    { transform:'translateY(-72px)', offset:.4 },
                    { transform:'translateY(-72px)', offset:.72 },
                    { transform:'translateY(0)', offset:1 }
                ];
                ['r hand', 'r hand 2'].forEach((handName) => {
                    const handAnimation = this.animatePart(handName, handKeyframes, {
                        duration,
                        easing:'cubic-bezier(.2,.8,.2,1)'
                    });
                    if (handAnimation) animations.push(handAnimation);
                });
                window.setTimeout(() => {
                    if (sequence !== winkSequence) return;
                    this.getFeatures(featureName).forEach(function (feature) {
                        feature.style.opacity = '';
                        feature.style.transform = '';
                    });
                    if (winkMark) {
                        winkMark.style.opacity = '0';
                        winkMark.style.transform = '';
                    }
                    container.style.animationPlayState = winkContainerAnimationState || '';
                    winkContainerAnimationState = null;
                    if (resumeRobotAfterWink) {
                        resumeRobotAfterWink = false;
                        this.play();
                    }
                }, duration + 30);
                return animations;
            },
            play:function () {
                if (playing) return;
                playing = true;
                startedAt = performance.now() - (currentFrame * 1000 / blueprint.frameRate);
                animationFrame = requestAnimationFrame(tick);
            },
            pause:function () {
                playing = false;
                if (animationFrame !== null) cancelAnimationFrame(animationFrame);
                animationFrame = null;
            },
            goToAndStop:function (frame) {
                this.pause();
                applyFrame(frame);
            },
            playSegments:function () {
                this.play();
            },
            destroy:function () {
                this.pause();
                svg.remove();
            }
        };
        applyFrame(0);
        animationFrame = requestAnimationFrame(tick);
        return controller;
    };
    const convertLottieRobot = function (animation, container, globalName) {
        try {
            if (!robotSvgBlueprint) {
                robotSvgBlueprint = captureRobotBlueprint(animation, container);
            }
            animation.destroy();
            const controller = instantiateControllableRobot(robotSvgBlueprint, container);
            window[globalName] = controller;
            document.dispatchEvent(new CustomEvent('chatbot:controllable-robot-ready', {
                detail:{ name:globalName, controller }
            }));
            return controller;
        } catch (error) {
            console.warn('Controllable robot fallback:', error);
            animation.loop = true;
            animation.play?.();
            window[globalName] = animation;
            return animation;
        }
    };

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
            const widgetRobotSource = window.lottie.loadAnimation({
                container: bot,
                renderer: 'svg',
                loop: false,
                autoplay: false,
                path: `${baseUrl}assets/animations/Ai%20Robot%20Vector%20Art.json?v=20260714-controllable-svg`
            });
            widgetRobotSource.addEventListener('DOMLoaded', function () {
                convertLottieRobot(widgetRobotSource, bot, 'chatBotWidgetRobot');
            });
        }

        if (floatingBot && !floatingBot.dataset.lottieReady) {
            floatingBot.dataset.lottieReady = 'true';
            floatingBot.innerHTML = '';
            const floatingRobotSource = window.lottie.loadAnimation({
                container: floatingBot,
                renderer: 'svg',
                loop: false,
                autoplay: false,
                path: `${baseUrl}assets/animations/Ai%20Robot%20Vector%20Art.json?v=20260714-controllable-svg`
            });
            window.chatBotFloatLottie = floatingRobotSource;
            floatingRobotSource.addEventListener('DOMLoaded', function () {
                const controller = convertLottieRobot(floatingRobotSource, floatingBot, 'chatBotFloatRobot');
                window.chatBotFloatLottie = controller;
            });
        }
    };

    if (window.EvortixAssistant) {
        window.chatBotWidgetRobot = window.EvortixAssistant.mount(botWidget?.querySelector('#chatBotAnimation'), { size:'launcher', mode:'sleeping' });
        window.chatBotFloatRobot = window.EvortixAssistant.mount(botFloat?.querySelector('#chatBotFloatAnimation'), { size:'full', mode:'standing' });
        window.chatBotFloatLottie = window.chatBotFloatRobot;
        document.dispatchEvent(new CustomEvent('chatbot:controllable-robot-ready', {
            detail:{ name:'chatBotFloatRobot', controller:window.chatBotFloatRobot }
        }));
    }
});
</script>
