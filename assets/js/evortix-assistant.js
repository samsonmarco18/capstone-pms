(function () {
    'use strict';

    let assistantSequence = 0;

    const normalizePart = value => String(value || '')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-');

    const clamp = (value, minimum, maximum) => Math.min(maximum, Math.max(minimum, value));

    const assistantMarkup = id => `
        <svg class="evx-assistant" viewBox="0 0 220 250" role="img" aria-label="Evortix assistant">
            <defs>
                <linearGradient id="evx-shell-${id}" x1=".12" y1=".05" x2=".86" y2=".96">
                    <stop offset="0" stop-color="#ffffff"/>
                    <stop offset=".34" stop-color="#fbfcfc"/>
                    <stop offset=".7" stop-color="#e1e5e4"/>
                    <stop offset="1" stop-color="#aeb5b4"/>
                </linearGradient>
                <linearGradient id="evx-shell-side-${id}" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#f8faf9"/>
                    <stop offset=".55" stop-color="#d7dcda"/>
                    <stop offset="1" stop-color="#969e9d"/>
                </linearGradient>
                <linearGradient id="evx-shell-lower-${id}" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#ffffff"/>
                    <stop offset=".52" stop-color="#e8ebea"/>
                    <stop offset="1" stop-color="#a8afad"/>
                </linearGradient>
                <radialGradient id="evx-face-${id}" cx="43%" cy="24%" r="88%">
                    <stop offset="0" stop-color="#202322"/>
                    <stop offset=".46" stop-color="#0b0e0d"/>
                    <stop offset="1" stop-color="#010202"/>
                </radialGradient>
                <radialGradient id="evx-eye-${id}" cx="38%" cy="30%" r="72%">
                    <stop offset="0" stop-color="#8ff5ff"/>
                    <stop offset=".28" stop-color="#35d5f5"/>
                    <stop offset="1" stop-color="#0098cf"/>
                </radialGradient>
                <linearGradient id="evx-neck-light-${id}" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0" stop-color="#087eaa" stop-opacity=".12"/>
                    <stop offset=".5" stop-color="#79ecff"/>
                    <stop offset="1" stop-color="#087eaa" stop-opacity=".12"/>
                </linearGradient>
                <pattern id="evx-led-${id}" width="3.2" height="3.2" patternUnits="userSpaceOnUse">
                    <circle cx="1" cy="1" r=".62" fill="#d1fbff" opacity=".4"/>
                </pattern>
                <filter id="evx-shadow-${id}" x="-50%" y="-50%" width="200%" height="200%">
                    <feGaussianBlur stdDeviation="5"/>
                </filter>
                <filter id="evx-glow-${id}" x="-100%" y="-100%" width="300%" height="300%">
                    <feGaussianBlur stdDeviation="2.2" result="blur"/>
                    <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
                </filter>
            </defs>

            <ellipse class="evx-ground-shadow" cx="110" cy="229" rx="43" ry="7" fill="#80908f" opacity=".2" filter="url(#evx-shadow-${id})"/>

            <g class="evx-sleep-scene" data-robot-part="bed" aria-label="Evortix charging">
                <ellipse class="evx-sleep-dock-shadow" cx="110" cy="222" rx="58" ry="8" fill="#697675" opacity=".2" filter="url(#evx-shadow-${id})"/>
                <ellipse class="evx-sleep-dock" cx="110" cy="216" rx="55" ry="13" fill="url(#evx-shell-side-${id})" stroke="#aeb6b4" stroke-width="1.5"/>
                <ellipse class="evx-sleep-dock-light" cx="110" cy="214" rx="39" ry="7" fill="#081111" stroke="#38cceb" stroke-width="2"/>
                <g class="evx-sleeper" data-robot-part="sleeper">
                    <path d="M84 140 C75 148 72 161 76 176 C80 193 93 206 110 211 C127 206 140 193 144 176 C148 161 145 148 136 140 C129 135 119 132 110 132 C101 132 91 135 84 140Z" fill="url(#evx-shell-lower-${id})" stroke="#b0b6b5" stroke-width="1.7"/>
                    <ellipse cx="110" cy="134" rx="20" ry="5" fill="url(#evx-neck-light-${id})" opacity=".8"/>
                    <path class="evx-antenna evx-left-antenna" d="M41 72 C40.5 55 42 32 45 14 C48 33 50 55 49 72Z" fill="url(#evx-shell-side-${id})" stroke="#adb3b1" stroke-width="1" stroke-linejoin="round"/>
                    <path class="evx-antenna evx-right-antenna" d="M179 72 C179.5 55 178 32 175 14 C172 33 170 55 171 72Z" fill="url(#evx-shell-side-${id})" stroke="#adb3b1" stroke-width="1" stroke-linejoin="round"/>
                    <g class="evx-ear evx-left-ear">
                        <path d="M45 63 C40 63 37 70 37 82.5 C37 95 40 102 45 102 C50 102 52 95 52 82.5 C52 70 50 63 45 63Z" fill="url(#evx-shell-side-${id})" stroke="#b2b8b6" stroke-width="1"/>
                        <path d="M45 72 C41 73 39 77 39.5 82 C40 87 42 90 45 91" fill="none" stroke="#ffffff" stroke-width="1.7" stroke-linecap="round" opacity=".56"/>
                        <path d="M45 75.5 C42.7 76.5 41.5 79 42 82 C42.3 85 43.3 87 45 88" fill="none" stroke="#aab1af" stroke-width="1" stroke-linecap="round" opacity=".72"/>
                    </g>
                    <g class="evx-ear evx-right-ear">
                        <path d="M175 63 C180 63 183 70 183 82.5 C183 95 180 102 175 102 C170 102 168 95 168 82.5 C168 70 170 63 175 63Z" fill="url(#evx-shell-side-${id})" stroke="#b2b8b6" stroke-width="1"/>
                        <path d="M175 72 C179 73 181 77 180.5 82 C180 87 178 90 175 91" fill="none" stroke="#ffffff" stroke-width="1.7" stroke-linecap="round" opacity=".56"/>
                        <path d="M175 75.5 C177.3 76.5 178.5 79 178 82 C177.7 85 176.7 87 175 88" fill="none" stroke="#aab1af" stroke-width="1" stroke-linecap="round" opacity=".72"/>
                    </g>
                    <path d="M45 77 C46 38 84 17 110 17 C136 17 174 38 175 77 L171 104 C166 128 142 139 110 139 C78 139 54 128 49 104Z" fill="url(#evx-shell-${id})" stroke="#adb4b2" stroke-width="2"/>
                    <path d="M53 76 C55 49 77 38 110 38 C143 38 165 49 167 76 L164 98 C160 116 140 124 110 124 C80 124 60 116 56 98Z" fill="url(#evx-face-${id})" stroke="#262b2a" stroke-width="1.7"/>
                    <path d="M75 84 Q84 94 94 84 M126 84 Q136 94 145 84" fill="none" stroke="#2ec8ee" stroke-width="6" stroke-linecap="round" filter="url(#evx-glow-${id})"/>
                </g>
                <g class="evx-sleep-z" aria-hidden="true">
                    <text x="171" y="65" fill="#25bee9" font-family="Arial, sans-serif" font-size="20" font-weight="800">Z</text>
                    <text x="190" y="42" fill="#68ddf6" font-family="Arial, sans-serif" font-size="14" font-weight="800">Z</text>
                </g>
            </g>

            <g class="evx-character" data-robot-part="character">
                <g class="evx-body" data-robot-part="body">
                    <path d="M84 140 C75 148 72 162 76 179 C80 197 93 212 110 218 C127 212 140 197 144 179 C148 162 145 148 136 140 C129 135 119 132 110 132 C101 132 91 135 84 140Z" fill="url(#evx-shell-lower-${id})" stroke="#aeb5b3" stroke-width="1.8"/>
                    <path d="M90 143 C84 155 85 178 91 194 C95 204 102 211 110 215 C99 207 96 192 96 177 C96 159 100 144 108 135 C100 135 94 138 90 143Z" fill="#ffffff" opacity=".48"/>
                    <path d="M75 174 Q110 184 145 174" fill="none" stroke="#aeb4b2" stroke-width="1.5" opacity=".75"/>
                    <path d="M76 178 Q110 189 144 178" fill="none" stroke="#ffffff" stroke-width="1" opacity=".65"/>
                </g>

                <ellipse class="evx-neck-glow" cx="110" cy="140" rx="23" ry="6.5" fill="#31c6ec" opacity=".34" filter="url(#evx-glow-${id})"/>
                <ellipse class="evx-neck-ring" cx="110" cy="139" rx="18" ry="4.6" fill="url(#evx-neck-light-${id})" stroke="#d9faff" stroke-width="1.15"/>
                <ellipse class="evx-neck-shine" cx="106" cy="137.8" rx="8" ry="1.4" fill="#efffff" opacity=".48"/>

                <g class="evx-head" data-robot-part="head">
                    <path class="evx-antenna evx-left-antenna" d="M38 68 C37.5 51 39 27 42 10 C45 29 47 51 46 68Z" fill="url(#evx-shell-side-${id})" stroke="#adb3b1" stroke-width="1" stroke-linejoin="round"/>
                    <path class="evx-antenna evx-right-antenna" d="M182 68 C182.5 51 181 27 178 10 C175 29 173 51 174 68Z" fill="url(#evx-shell-side-${id})" stroke="#adb3b1" stroke-width="1" stroke-linejoin="round"/>
                    <g class="evx-ear evx-left-ear">
                        <path d="M40 59 C33 59 29 68 29 81.5 C29 95 33 104 40 104 C47 104 50 95 50 81.5 C50 68 47 59 40 59Z" fill="url(#evx-shell-side-${id})" stroke="#b2b8b6" stroke-width="1.15"/>
                        <path d="M40 70 C35 71 32.5 76 33 82 C33.5 88 36 92 40 93" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" opacity=".56"/>
                        <path d="M40 74 C37 75 35.5 78 36 82 C36.3 85.5 37.8 88 40 89" fill="none" stroke="#aab1af" stroke-width="1.15" stroke-linecap="round" opacity=".72"/>
                    </g>
                    <g class="evx-ear evx-right-ear">
                        <path d="M180 59 C187 59 191 68 191 81.5 C191 95 187 104 180 104 C173 104 170 95 170 81.5 C170 68 173 59 180 59Z" fill="url(#evx-shell-side-${id})" stroke="#b2b8b6" stroke-width="1.15"/>
                        <path d="M180 70 C185 71 187.5 76 187 82 C186.5 88 184 92 180 93" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" opacity=".56"/>
                        <path d="M180 74 C183 75 184.5 78 184 82 C183.7 85.5 182.2 88 180 89" fill="none" stroke="#aab1af" stroke-width="1.15" stroke-linecap="round" opacity=".72"/>
                    </g>
                    <path class="evx-head-shell" d="M37 72 C38 31 84 8 110 8 C136 8 182 31 183 72 L179 102 C175 126 148 138 110 138 C72 138 45 126 41 102Z" fill="url(#evx-shell-${id})" stroke="#aeb5b3" stroke-width="2.1"/>
                    <path d="M51 43 C64 20 86 13 107 13" fill="none" stroke="#ffffff" stroke-width="7" stroke-linecap="round" opacity=".72"/>
                    <path class="evx-face" data-robot-part="face" d="M50 70 C52 43 76 33 110 33 C144 33 168 43 170 70 L167 96 C164 116 143 124 110 124 C77 124 56 116 53 96Z" fill="url(#evx-face-${id})" stroke="#252a29" stroke-width="2"/>
                    <path d="M59 56 C79 39 116 36 146 45" fill="none" stroke="#ffffff" stroke-width="3" stroke-linecap="round" opacity=".055"/>

                    <g class="evx-face-features" data-robot-part="eyes">
                        <g class="evx-eye-track" data-robot-part="eye-track">
                            <g class="evx-eye evx-left-eye" data-robot-feature="left-eye">
                                <ellipse class="evx-eye-open" cx="84" cy="78" rx="11" ry="14" fill="url(#evx-eye-${id})" filter="url(#evx-glow-${id})"/>
                                <ellipse class="evx-eye-led" cx="84" cy="78" rx="9" ry="12" fill="url(#evx-led-${id})"/>
                                <path class="evx-eye-laugh" d="M72 84 Q84 65 96 84"/>
                                <path class="evx-eye-suspicious" d="M72 79 Q84 80 96 79"/>
                                <path class="evx-eye-cry" d="M72 74 Q84 87 96 74"/>
                                <path class="evx-eye-wink" d="M72 82 Q84 65 96 82"/>
                                <path class="evx-eye-heart" d="M84 92 C80 87 69 80 69 71 C69 62 80 60 84 68 C88 60 99 62 99 71 C99 80 88 87 84 92Z"/>
                                <path class="evx-eye-dead" d="M73 68 L95 88 M95 68 L73 88"/>
                                <path class="evx-eye-angry" d="M70 68 L98 76 L94 91 Q82 94 73 85Z"/>
                                <path class="evx-eye-click" d="M72 69 L88 78 L72 87"/>
                            </g>
                            <g class="evx-eye evx-right-eye" data-robot-feature="right-eye">
                                <ellipse class="evx-eye-open" cx="136" cy="78" rx="11" ry="14" fill="url(#evx-eye-${id})" filter="url(#evx-glow-${id})"/>
                                <ellipse class="evx-eye-led" cx="136" cy="78" rx="9" ry="12" fill="url(#evx-led-${id})"/>
                                <path class="evx-eye-laugh" d="M124 84 Q136 65 148 84"/>
                                <path class="evx-eye-suspicious" d="M124 79 Q136 80 148 79"/>
                                <path class="evx-eye-cry" d="M124 74 Q136 87 148 74"/>
                                <path class="evx-eye-wink" d="M124 82 Q136 65 148 82"/>
                                <path class="evx-eye-heart" d="M136 92 C132 87 121 80 121 71 C121 62 132 60 136 68 C140 60 151 62 151 71 C151 80 140 87 136 92Z"/>
                                <path class="evx-eye-dead" d="M125 68 L147 88 M147 68 L125 88"/>
                                <path class="evx-eye-angry" d="M122 76 L150 68 L147 85 Q138 94 126 91Z"/>
                                <path class="evx-eye-click" d="M148 69 L132 78 L148 87"/>
                            </g>
                        </g>
                        <g class="evx-laugh-tears" aria-hidden="true">
                            <path d="M67 83 C61 90 61 96 66 98 C72 96 72 90 67 83Z"/>
                            <path d="M153 83 C148 90 148 96 154 98 C159 96 159 90 153 83Z"/>
                        </g>
                        <g class="evx-cry-tears" aria-hidden="true">
                            <path d="M70 83 C62 94 62 104 70 107 C78 104 78 94 70 83Z"/>
                            <path d="M150 83 C142 94 142 104 150 107 C158 104 158 94 150 83Z"/>
                        </g>
                        <path class="evx-happy-mouth" d="M99 99 Q110 104 121 99 Q119 111 110 114 Q101 111 99 99Z"/>
                        <path class="evx-sad-mouth" d="M99 110 Q110 98 121 110"/>
                        <path class="evx-click-mouth" d="M94 96 Q110 101 126 96 Q121 110 110 115 Q99 110 94 96Z"/>
                    </g>
                </g>

                <g class="evx-arm evx-left-arm" data-robot-part="left-arm">
                    <g class="evx-hand evx-left-hand" data-robot-part="left-hand">
                        <path class="evx-hand-shell" d="M59 145 C66 145 70 151 69 159 C67 176 58 200 50 210 C46 215 40 212 40 205 C40 190 47 161 52 151 C54 147 56 145 59 145Z" fill="url(#evx-shell-side-${id})" stroke="#a9b0ae" stroke-width="1.5"/>
                        <path class="evx-hand-shade" d="M66 153 C68 167 58 198 48 209 C54 201 64 174 66 153Z" fill="#77807e" opacity=".13"/>
                        <path class="evx-hand-highlight" d="M57 150 C61 148 64 151 63 157 C61 172 54 194 47 203" fill="none" stroke="#ffffff" stroke-width="3" stroke-linecap="round" opacity=".46"/>
                    </g>
                </g>
                <g class="evx-arm evx-right-arm" data-robot-part="right-arm">
                    <g class="evx-hand evx-right-hand" data-robot-part="right-hand">
                        <path class="evx-hand-shell" d="M158 150 C163 145 171 145 175 151 C180 158 176 171 169 184 C164 194 157 202 151 200 C145 197 145 189 148 178 L153 157 C154 154 156 152 158 150Z" fill="url(#evx-shell-side-${id})" stroke="#a9b0ae" stroke-width="1.5"/>
                        <path class="evx-hand-shade" d="M174 153 C178 163 169 189 154 199 C162 189 173 165 174 153Z" fill="#77807e" opacity=".14"/>
                        <path class="evx-hand-highlight" d="M159 153 C164 149 170 149 173 154 C174 157 174 160 173 164" fill="none" stroke="#ffffff" stroke-width="3" stroke-linecap="round" opacity=".48"/>
                    </g>
                </g>
            </g>

            <g class="evx-emotion-sparks" aria-hidden="true">
                <path d="M26 83 L14 77 M31 67 L24 54 M189 69 L199 58 M191 86 L205 84"/>
            </g>
        </svg>`;

    function mountAssistant(container, options = {}) {
        if (!container) return null;

        const id = ++assistantSequence;
        container.innerHTML = assistantMarkup(id);
        const root = container.querySelector('.evx-assistant');
        const eyeTrack = root.querySelector('.evx-eye-track');
        let emotionTimer = 0;
        let blinkTimer = 0;
        let idleBlinkTimer = 0;
        let modeTimer = 0;
        let paused = false;

        const partAliases = {
            'l-hand':'left-arm',
            'l-hand-2':'left-hand',
            'r-hand':'right-arm',
            'r-hand-2':'right-hand',
            'eyes':'eyes',
            'face':'face',
            'body':'body',
            'head':'head'
        };

        const scheduleBlink = () => {
            window.clearTimeout(idleBlinkTimer);
            idleBlinkTimer = window.setTimeout(() => {
                if (!paused && root.isConnected && root.dataset.mode === 'standing'
                    && !(root.dataset.emotion || '')) {
                    controller.blink();
                }
                scheduleBlink();
            }, 2600 + Math.round(Math.random() * 2600));
        };

        const controller = {
            root,
            get isPaused() { return paused; },
            getPart(name) {
                const normalized = normalizePart(name);
                const part = partAliases[normalized] || normalized;
                return root.querySelector(`[data-robot-part="${part}"]`);
            },
            getFeatures(name) {
                const normalized = normalizePart(name);
                return Array.from(root.querySelectorAll(`[data-robot-feature="${normalized}"]`));
            },
            animatePart(name, keyframes, animationOptions) {
                return this.getPart(name)?.animate?.(keyframes, animationOptions) || null;
            },
            animateFeatures(name, keyframes, animationOptions) {
                return this.getFeatures(name).map(feature => feature.animate?.(keyframes, animationOptions)).filter(Boolean);
            },
            setEmotion(emotion = '', duration = 0) {
                window.clearTimeout(emotionTimer);
                const emotionAliases = {
                    happy: 'happy',
                    laugh: 'laugh',
                    sad: 'sad',
                    cry: 'cry',
                    amazed: 'amazed',
                    winking: 'winking',
                    wink: 'winking',
                    thinking: 'thinking',
                    suspicious: 'suspicious',
                    pointing: 'pointing',
                    point: 'pointing',
                    love: 'love',
                    death: 'death',
                    dead: 'death',
                    angry: 'angry',
                    clicked: 'clicked',
                    click: 'clicked',
                    excited: 'clicked'
                };
                const nextEmotion = emotionAliases[String(emotion || '').toLowerCase()] || '';
                root.dataset.emotion = nextEmotion;
                if (nextEmotion !== 'pointing') delete root.dataset.pointSide;
                if (nextEmotion !== 'amazed') this.resetLook();
                if (duration > 0) {
                    emotionTimer = window.setTimeout(() => this.setEmotion(''), duration);
                }
                return nextEmotion;
            },
            blink() {
                window.clearTimeout(blinkTimer);
                root.classList.remove('is-blinking');
                void root.getBoundingClientRect();
                root.classList.add('is-blinking');
                blinkTimer = window.setTimeout(() => root.classList.remove('is-blinking'), 250);
            },
            wink(side = 'right', duration = 1080) {
                root.dataset.winkSide = side === 'left' ? 'left' : 'right';
                this.setEmotion('winking', Math.max(0, Number(duration) || 0));
                return [root];
            },
            point(side = 'right', duration = 0) {
                root.dataset.pointSide = side === 'left' ? 'left' : 'right';
                this.setEmotion('pointing', Math.max(0, Number(duration) || 0));
                return [root];
            },
            lookAt(clientX, clientY) {
                const face = this.getPart('face');
                if (!face || !eyeTrack) return;
                const rect = face.getBoundingClientRect();
                if (!rect.width || !rect.height) return;
                const centerX = rect.left + rect.width / 2;
                const centerY = rect.top + rect.height / 2;
                const normalizedX = clamp((clientX - centerX) / Math.max(70, rect.width * .9), -1, 1);
                const normalizedY = clamp((clientY - centerY) / Math.max(55, rect.height * 1.1), -1, 1);
                eyeTrack.style.setProperty('--evx-look-x', `${(normalizedX * 5.1).toFixed(2)}px`);
                eyeTrack.style.setProperty('--evx-look-y', `${(normalizedY * 3.4).toFixed(2)}px`);
            },
            resetLook() {
                eyeTrack?.style.setProperty('--evx-look-x', '0px');
                eyeTrack?.style.setProperty('--evx-look-y', '0px');
            },
            setMode(mode = 'standing') {
                window.clearTimeout(modeTimer);
                root.dataset.mode = ['sleeping', 'waking', 'returning', 'empty-bed'].includes(mode) ? mode : 'standing';
                return root.dataset.mode;
            },
            wake() {
                if (root.dataset.mode === 'standing' || root.dataset.mode === 'empty-bed') return;
                this.setMode('waking');
                modeTimer = window.setTimeout(() => this.setMode('empty-bed'), 720);
            },
            sleep() {
                if (root.dataset.mode === 'sleeping') return;
                this.setMode('returning');
                modeTimer = window.setTimeout(() => this.setMode('sleeping'), 760);
            },
            play() { paused = false; },
            pause() { paused = true; },
            playSegments() { paused = false; },
            goToAndStop() { paused = true; },
            destroy() {
                window.clearTimeout(emotionTimer);
                window.clearTimeout(blinkTimer);
                window.clearTimeout(idleBlinkTimer);
                window.clearTimeout(modeTimer);
                root.remove();
            }
        };

        root.dataset.size = options.size || 'full';
        root.dataset.mode = options.mode || 'standing';
        scheduleBlink();
        return controller;
    }

    window.EvortixAssistant = { mount: mountAssistant };
    window.ErniAssistant = window.EvortixAssistant;
})();
