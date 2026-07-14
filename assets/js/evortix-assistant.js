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
                <linearGradient id="evx-shell-${id}" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#ffffff"/>
                    <stop offset=".55" stop-color="#edf4fb"/>
                    <stop offset="1" stop-color="#b9c9d9"/>
                </linearGradient>
                <linearGradient id="evx-shell-shadow-${id}" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#eaf3fb"/>
                    <stop offset="1" stop-color="#8fa7ba"/>
                </linearGradient>
                <linearGradient id="evx-blue-${id}" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#4ecbff"/>
                    <stop offset=".48" stop-color="#0b8ef0"/>
                    <stop offset="1" stop-color="#0862c6"/>
                </linearGradient>
                <linearGradient id="evx-logo-blue-${id}" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#39a9ff"/>
                    <stop offset=".45" stop-color="#0878df"/>
                    <stop offset="1" stop-color="#06469d"/>
                </linearGradient>
                <symbol id="evx-system-logo-${id}" viewBox="0 0 100 100">
                    <path d="M18 27 L69 2 L87 11 L24 44 L18 40Z" fill="url(#evx-logo-blue-${id})" stroke="#062f6d" stroke-width="2.4" stroke-linejoin="round"/>
                    <path d="M8 61 L57 36 L57 54 L22 73 L8 67Z" fill="url(#evx-logo-blue-${id})" stroke="#062f6d" stroke-width="2.4" stroke-linejoin="round"/>
                    <path d="M41 82 L91 57 L91 76 L57 96 L41 89Z" fill="url(#evx-logo-blue-${id})" stroke="#062f6d" stroke-width="2.4" stroke-linejoin="round"/>
                    <path d="M21 28 L69 5 M11 61 L55 39 M44 82 L89 60" stroke="#67bdff" stroke-width="2" stroke-linecap="round" opacity=".62"/>
                </symbol>
                <radialGradient id="evx-face-${id}" cx="50%" cy="35%" r="80%">
                    <stop offset="0" stop-color="#123a62"/>
                    <stop offset=".58" stop-color="#061d38"/>
                    <stop offset="1" stop-color="#020d1c"/>
                </radialGradient>
                <radialGradient id="evx-eye-${id}" cx="50%" cy="40%" r="65%">
                    <stop offset="0" stop-color="#d5ffff"/>
                    <stop offset=".3" stop-color="#65f6ff"/>
                    <stop offset="1" stop-color="#09aef1"/>
                </radialGradient>
                <filter id="evx-shadow-${id}" x="-40%" y="-40%" width="180%" height="180%">
                    <feGaussianBlur stdDeviation="5"/>
                </filter>
                <filter id="evx-glow-${id}" x="-100%" y="-100%" width="300%" height="300%">
                    <feGaussianBlur stdDeviation="2.6" result="blur"/>
                    <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
                </filter>
            </defs>

            <ellipse class="evx-ground-shadow" cx="110" cy="229" rx="47" ry="8" fill="#4b789a" opacity=".22" filter="url(#evx-shadow-${id})"/>

            <g class="evx-sleep-scene" data-robot-part="bed" aria-label="Evortix sleeping in bed">
                <ellipse cx="110" cy="220" rx="92" ry="10" fill="#255984" opacity=".22" filter="url(#evx-shadow-${id})"/>
                <path d="M31 112 Q31 96 47 96 H166 Q184 96 184 114 V193 H31Z" fill="url(#evx-shell-${id})" stroke="#557c9d" stroke-width="3.5"/>
                <path d="M39 122 Q42 108 57 108 H160 Q174 108 177 122 L174 181 H38Z" fill="#168bea" stroke="#075ba9" stroke-width="3"/>
                <path class="evx-bed-pillow" d="M65 112 Q107 99 150 112 L148 139 Q107 151 65 139Z" fill="#e4f2ff" stroke="#72a8d4" stroke-width="2.4"/>
                <path d="M30 177 H191 V207 Q191 217 181 217 H37 Q26 217 26 206 V191 Q26 181 30 177Z" fill="url(#evx-shell-${id})" stroke="#4c7394" stroke-width="3.5"/>
                <path d="M35 184 H183 V207 Q183 211 178 211 H40 Q34 211 34 205Z" fill="url(#evx-blue-${id})" stroke="#064f99" stroke-width="2.5"/>
                <rect x="51" y="190" width="116" height="16" rx="8" fill="#071d39" stroke="#5ee8ff" stroke-width="2"/>
                <use href="#evx-system-logo-${id}" x="101" y="189" width="18" height="18"/>
                <g class="evx-sleeper" data-robot-part="sleeper">
                    <path d="M54 99 Q58 54 107 51 Q156 54 161 99 L154 131 Q107 147 61 131Z" fill="url(#evx-shell-${id})" stroke="#6889a5" stroke-width="2.6"/>
                    <use href="#evx-system-logo-${id}" x="99" y="57" width="17" height="17"/>
                    <path d="M55 92 Q44 94 46 113 Q48 125 60 122Z" fill="url(#evx-shell-${id})" stroke="#6889a5" stroke-width="2"/>
                    <path d="M159 92 Q170 94 168 113 Q166 125 154 122Z" fill="url(#evx-shell-${id})" stroke="#6889a5" stroke-width="2"/>
                    <rect x="62" y="76" width="91" height="55" rx="25" fill="url(#evx-face-${id})" stroke="#041426" stroke-width="3"/>
                    <path d="M77 104 Q86 113 95 104 M119 104 Q128 113 137 104" fill="none" stroke="#51f2ff" stroke-width="6" stroke-linecap="round"/>
                    <path d="M99 116 Q107 123 115 116" fill="none" stroke="#51f2ff" stroke-width="4" stroke-linecap="round"/>
                    <path d="M73 117 l5 -2 M137 115 l5 2" stroke="#ff86a8" stroke-width="2.5" stroke-linecap="round"/>
                </g>
                <path class="evx-sleep-blanket" d="M36 128 Q75 116 113 122 Q151 117 180 134 L177 181 H36Z" fill="url(#evx-blue-${id})" stroke="#075eaf" stroke-width="3"/>
                <path class="evx-sleep-blanket" d="M45 144 Q108 131 173 146 M45 162 Q109 149 175 163" fill="none" stroke="#78ccff" stroke-width="1.7" opacity=".5"/>
                <path d="M58 151 l4 7 8 1-6 5 2 8-8-4-7 4 2-8-6-5 8-1Z M131 143 l3 5 6 1-5 4 2 6-6-3-5 3 1-6-4-4 6-1Z" fill="#a7dcff" opacity=".58"/>
                <g class="evx-sleep-z" aria-hidden="true">
                    <text x="169" y="64" fill="#168ff0" font-family="Arial, sans-serif" font-size="20" font-weight="900">Z</text>
                    <text x="188" y="42" fill="#54bdff" font-family="Arial, sans-serif" font-size="14" font-weight="900">Z</text>
                </g>
            </g>

            <g class="evx-character" data-robot-part="character">
                <g class="evx-legs" data-robot-part="legs">
                    <g class="evx-leg evx-left-leg" data-robot-part="left-leg">
                        <path d="M79 178 C70 187 68 204 72 216 L91 216 C95 201 95 187 91 178Z" fill="url(#evx-shell-${id})" stroke="#7894ac" stroke-width="2"/>
                        <path d="M67 212 Q82 207 97 214 L96 224 Q79 230 64 221Z" fill="url(#evx-shell-${id})" stroke="#173c5e" stroke-width="2.4"/>
                        <path d="M70 216 Q82 214 94 217" fill="none" stroke="#178bea" stroke-width="4" stroke-linecap="round"/>
                    </g>
                    <g class="evx-leg evx-right-leg" data-robot-part="right-leg">
                        <path d="M129 178 C125 188 125 202 129 216 L148 216 C152 203 150 187 141 178Z" fill="url(#evx-shell-${id})" stroke="#7894ac" stroke-width="2"/>
                        <path d="M123 214 Q139 207 153 212 L156 221 Q141 230 124 224Z" fill="url(#evx-shell-${id})" stroke="#173c5e" stroke-width="2.4"/>
                        <path d="M128 217 Q140 214 151 216" fill="none" stroke="#178bea" stroke-width="4" stroke-linecap="round"/>
                    </g>
                </g>

                <g class="evx-body" data-robot-part="body">
                    <path d="M68 113 Q110 92 152 113 L147 176 Q110 198 73 176Z" fill="url(#evx-shell-${id})" stroke="#7894ac" stroke-width="2.2"/>
                    <path d="M77 122 Q110 106 143 122 L138 162 Q110 174 82 162Z" fill="#f8fbfe" stroke="#abc0d2" stroke-width="1.8"/>
                    <path d="M85 153 Q110 165 135 153 L132 176 Q110 187 88 176Z" fill="url(#evx-shell-shadow-${id})" opacity=".7"/>
                    <path d="M88 120 L97 109 H123 L132 120 L124 136 H96Z" fill="#102d49" stroke="#07213b" stroke-width="2"/>
                    <path d="M97 118 H123 L119 130 H101Z" fill="url(#evx-blue-${id})" stroke="#5bdfff" stroke-width="1.6"/>
                    <text x="110" y="149" text-anchor="middle" fill="#123c60" font-family="Arial, sans-serif" font-size="8.5" font-weight="900" letter-spacing=".3">EVORTIX</text>
                    <path d="M82 137 L72 145 L73 160 L83 157Z" fill="#128fee" stroke="#0b66bb" stroke-width="1.5"/>
                    <path d="M138 137 L148 145 L147 160 L137 157Z" fill="#128fee" stroke="#0b66bb" stroke-width="1.5"/>
                </g>

                <g class="evx-arm evx-left-arm" data-robot-part="left-arm">
                    <circle cx="67" cy="120" r="12" fill="url(#evx-blue-${id})" stroke="#0b4f93" stroke-width="2"/>
                    <path d="M61 121 Q44 132 42 153 Q43 167 53 173 Q62 171 64 162 L67 136Z" fill="url(#evx-shell-${id})" stroke="#7894ac" stroke-width="2.2"/>
                    <path d="M45 151 Q38 169 47 181 Q54 187 61 180 Q67 172 61 163Z" fill="url(#evx-shell-shadow-${id})" stroke="#173c5e" stroke-width="2"/>
                    <g class="evx-hand evx-left-hand" data-robot-part="left-hand">
                        <path class="evx-wrist-cuff" d="M43 173 Q51 177 60 174 L61 181 Q51 185 42 180Z" fill="url(#evx-blue-${id})" stroke="#084b88" stroke-width="1.8"/>
                        <rect x="39" y="187" width="5" height="15" rx="2.5" fill="#172938" stroke="#071522" stroke-width="1.4"/>
                        <rect x="44.5" y="189" width="5" height="17" rx="2.5" fill="#172938" stroke="#071522" stroke-width="1.4"/>
                        <rect x="50" y="189" width="5" height="16" rx="2.5" fill="#172938" stroke="#071522" stroke-width="1.4"/>
                        <rect x="55.5" y="187" width="5" height="15" rx="2.5" fill="#172938" stroke="#071522" stroke-width="1.4"/>
                        <path class="evx-thumb" d="M41 182 Q36 179 33 182 Q30 185 33 189 L40 194 Q43 192 43 188Z" fill="#172938" stroke="#071522" stroke-width="1.6"/>
                        <path class="evx-palm" d="M41 179 Q50 175 59 179 Q62 183 61 190 Q58 196 50 196 Q42 196 39 190 Q38 184 41 179Z" fill="#172938" stroke="#071522" stroke-width="1.8"/>
                        <path d="M43 184 Q50 181 58 184 M43 189 Q50 187 58 189" fill="none" stroke="#7690a2" stroke-width="1.25" stroke-linecap="round" opacity=".92"/>
                    </g>
                </g>

                <g class="evx-arm evx-right-arm" data-robot-part="right-arm">
                    <circle cx="153" cy="120" r="12" fill="url(#evx-blue-${id})" stroke="#0b4f93" stroke-width="2"/>
                    <path d="M159 121 Q176 132 178 153 Q177 167 167 173 Q158 171 156 162 L153 136Z" fill="url(#evx-shell-${id})" stroke="#7894ac" stroke-width="2.2"/>
                    <path d="M175 151 Q182 169 173 181 Q166 187 159 180 Q153 172 159 163Z" fill="url(#evx-shell-shadow-${id})" stroke="#173c5e" stroke-width="2"/>
                    <g class="evx-hand evx-right-hand" data-robot-part="right-hand">
                        <path class="evx-wrist-cuff" d="M160 174 Q169 177 177 173 L178 180 Q169 185 159 181Z" fill="url(#evx-blue-${id})" stroke="#084b88" stroke-width="1.8"/>
                        <rect x="176" y="187" width="5" height="15" rx="2.5" fill="#172938" stroke="#071522" stroke-width="1.4"/>
                        <rect x="170.5" y="189" width="5" height="17" rx="2.5" fill="#172938" stroke="#071522" stroke-width="1.4"/>
                        <rect x="165" y="189" width="5" height="16" rx="2.5" fill="#172938" stroke="#071522" stroke-width="1.4"/>
                        <rect x="159.5" y="187" width="5" height="15" rx="2.5" fill="#172938" stroke="#071522" stroke-width="1.4"/>
                        <path class="evx-thumb" d="M179 182 Q184 179 187 182 Q190 185 187 189 L180 194 Q177 192 177 188Z" fill="#172938" stroke="#071522" stroke-width="1.6"/>
                        <path class="evx-palm" d="M161 179 Q170 175 179 179 Q182 183 181 190 Q178 196 170 196 Q162 196 159 190 Q158 184 161 179Z" fill="#172938" stroke="#071522" stroke-width="1.8"/>
                        <path d="M162 184 Q170 181 177 184 M162 189 Q170 187 177 189" fill="none" stroke="#7690a2" stroke-width="1.25" stroke-linecap="round" opacity=".92"/>
                    </g>
                </g>

                <g class="evx-head" data-robot-part="head">
                    <path d="M49 62 Q52 19 110 13 Q168 19 171 62 L164 104 Q110 126 56 104Z" fill="url(#evx-shell-${id})" stroke="#718ba2" stroke-width="2.4"/>
                    <path d="M72 24 Q110 7 148 24" fill="none" stroke="#ffffff" stroke-width="5" stroke-linecap="round" opacity=".8"/>
                    <use href="#evx-system-logo-${id}" x="100" y="15" width="20" height="20"/>
                    <g class="evx-ear evx-left-ear"><path d="M48 54 Q36 57 38 79 Q40 94 53 92Z" fill="url(#evx-shell-${id})" stroke="#718ba2" stroke-width="2"/><path d="M45 60 V87" stroke="#118de9" stroke-width="5" stroke-linecap="round"/></g>
                    <g class="evx-ear evx-right-ear"><path d="M172 54 Q184 57 182 79 Q180 94 167 92Z" fill="url(#evx-shell-${id})" stroke="#718ba2" stroke-width="2"/><path d="M175 60 V87" stroke="#118de9" stroke-width="5" stroke-linecap="round"/></g>
                    <rect class="evx-face" data-robot-part="face" x="54" y="38" width="112" height="68" rx="31" fill="url(#evx-face-${id})" stroke="#06172a" stroke-width="3"/>
                    <path d="M68 47 Q110 31 152 47" fill="none" stroke="#5c91b5" stroke-width="2" opacity=".25"/>

                    <g class="evx-face-features" data-robot-part="eyes">
                        <g class="evx-eye-track" data-robot-part="eye-track">
                            <g class="evx-eye evx-left-eye" data-robot-feature="left-eye">
                                <ellipse class="evx-eye-open" cx="84" cy="69" rx="10" ry="14" fill="url(#evx-eye-${id})" filter="url(#evx-glow-${id})"/>
                                <ellipse class="evx-pupil" cx="84" cy="70" rx="4.2" ry="6" fill="#04396f"/>
                                <circle class="evx-pupil-glint" cx="82.5" cy="67.5" r="1.7" fill="#dfffff"/>
                                <path class="evx-eye-happy" d="M74 73 Q84 59 94 73" fill="none" stroke="#51f2ff" stroke-width="7" stroke-linecap="round"/>
                                <path class="evx-eye-sad" d="M74 65 Q84 75 94 65" fill="none" stroke="#51f2ff" stroke-width="6" stroke-linecap="round"/>
                                <path class="evx-eye-wink" d="M74 69 L83 76 L94 64" fill="none" stroke="#51f2ff" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>
                            </g>
                            <g class="evx-eye evx-right-eye" data-robot-feature="right-eye">
                                <ellipse class="evx-eye-open" cx="136" cy="69" rx="10" ry="14" fill="url(#evx-eye-${id})" filter="url(#evx-glow-${id})"/>
                                <ellipse class="evx-pupil" cx="136" cy="70" rx="4.2" ry="6" fill="#04396f"/>
                                <circle class="evx-pupil-glint" cx="134.5" cy="67.5" r="1.7" fill="#dfffff"/>
                                <path class="evx-eye-happy" d="M126 73 Q136 59 146 73" fill="none" stroke="#51f2ff" stroke-width="7" stroke-linecap="round"/>
                                <path class="evx-eye-sad" d="M126 65 Q136 75 146 65" fill="none" stroke="#51f2ff" stroke-width="6" stroke-linecap="round"/>
                                <path class="evx-eye-wink" d="M126 64 L137 76 L146 66" fill="none" stroke="#51f2ff" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>
                            </g>
                        </g>
                        <g class="evx-cheeks">
                            <path d="M68 84 l5 -2 M70 89 l5 -2" stroke="#ff86a8" stroke-width="2.4" stroke-linecap="round"/>
                            <path d="M147 82 l5 2 M145 87 l5 2" stroke="#ff86a8" stroke-width="2.4" stroke-linecap="round"/>
                        </g>
                        <path class="evx-mouth evx-mouth-smile" d="M99 86 Q110 98 121 86" fill="#40eaff" stroke="#40eaff" stroke-width="3" stroke-linecap="round"/>
                        <path class="evx-mouth evx-mouth-sad" d="M100 94 Q110 84 120 94" fill="none" stroke="#40eaff" stroke-width="4" stroke-linecap="round"/>
                        <ellipse class="evx-mouth evx-mouth-amazed" cx="110" cy="89" rx="6" ry="8" fill="#40eaff"/>
                        <path class="evx-tear" d="M146 80 Q152 88 146 93 Q140 88 146 80Z" fill="#4fcfff"/>
                    </g>
                </g>
            </g>

            <g class="evx-emotion-sparks" aria-hidden="true">
                <path d="M28 86 L16 79 M32 72 L25 59 M190 76 L203 65 M192 91 L207 89" stroke="#ffc84b" stroke-width="5" stroke-linecap="round"/>
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
                    && !['amazed', 'winking', 'sad'].includes(root.dataset.emotion || '')) {
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
                const nextEmotion = emotion === 'happy' ? 'happy'
                    : emotion === 'sad' ? 'sad'
                    : emotion === 'amazed' ? 'amazed'
                    : emotion === 'winking' || emotion === 'wink' ? 'winking'
                    : emotion === 'thinking' ? 'thinking'
                    : '';
                root.dataset.emotion = nextEmotion;
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
            wink(side = 'right') {
                root.dataset.winkSide = side === 'left' ? 'left' : 'right';
                this.setEmotion('winking', 1080);
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
