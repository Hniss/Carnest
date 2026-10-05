@php
    // D4 (v3) — avatar fixe de Care : en-tête pour tous, vignette dans les bulles
    // pour 5-7 et 8-11 uniquement (discret pour 12-18).
    $ageGroup = auth('child')->user()->age_group;
    $isTeen = $ageGroup === '12-18';
    $avatarUrl = asset('img/care/care-avatar.png');
@endphp
<div class="flex flex-col min-h-screen bg-gradient-to-b from-brand-50/40 via-stone-50 to-stone-50">

    {{-- Header --}}
    <header class="bg-white/80 backdrop-blur border-b border-stone-200 px-4 sm:px-5 py-2.5 sm:py-3 flex items-center justify-between gap-3 sticky top-0 z-10">
        <a href="{{ route('child.chat') }}" class="flex items-center gap-2.5 min-w-0">
            <x-carenest-logo variant="full" class="h-8 w-auto shrink-0" />
            <span class="hidden sm:inline-block text-[11px] text-stone-500 border-l border-stone-200 pl-2.5">Avec Care</span>
        </a>
        <div class="flex items-center gap-2 sm:gap-3 min-w-0">
            <span class="care-avatar care-avatar-greet shrink-0" wire:ignore
                  style="height: {{ $isTeen ? 40 : 64 }}px; width: {{ $isTeen ? 33 : 52 }}px;">
                <img src="{{ $avatarUrl }}"
                     alt="Care"
                     data-care-avatar="header"
                     class="select-none"
                     style="height: {{ $isTeen ? 40 : 64 }}px; width: auto; object-fit: contain;"
                     height="{{ $isTeen ? 40 : 64 }}"
                     draggable="false">
                <span class="care-eyelid care-eyelid-left" aria-hidden="true"></span>
                <span class="care-eyelid care-eyelid-right" aria-hidden="true"></span>
            </span>
            <span class="text-sm text-stone-500 hidden sm:inline">
                Bonjour, <span class="text-stone-900 font-medium">{{ auth('child')->user()->name }}</span>
            </span>
            <form action="{{ route('child.logout') }}" method="POST">
                @csrf
                <button class="btn-ghost btn-sm" type="submit">
                    <x-icon name="log-out" size="14" />
                    Quitter
                </button>
            </form>
        </div>
    </header>

    {{-- Messages --}}
    <div id="messages" class="flex-1 overflow-y-auto px-4 py-6 max-w-2xl mx-auto w-full pb-40 space-y-4">
        @foreach ($messages as $msg)
            <div class="flex {{ $msg['role'] === 'user' ? 'flex-row-reverse' : '' }} items-end gap-2 animate-fade-up">
                @if ($msg['role'] === 'assistant' && ! $isTeen)
                    <img src="{{ $avatarUrl }}"
                         alt="Care"
                         data-care-avatar="bubble"
                         class="flex-shrink-0 select-none"
                         style="height: 28px; width: auto; object-fit: contain;"
                         height="28" draggable="false">
                @endif
                <div class="max-w-xs md:max-w-md px-4 py-3 text-[15px] leading-relaxed
                    {{ $msg['role'] === 'user'
                        ? 'bg-brand-700 text-white rounded-2xl rounded-br-md shadow-sm'
                        : 'bg-white text-stone-800 rounded-2xl rounded-bl-md shadow-card border border-stone-100' }}">
                    {{ $msg['content'] }}
                </div>
                @if ($msg['role'] === 'user')
                    <div class="w-9 h-9 rounded-full bg-brand-100 text-brand-900 flex items-center justify-center text-sm font-bold flex-shrink-0">
                        {{ strtoupper(substr(auth('child')->user()->name, 0, 1)) }}
                    </div>
                @endif
            </div>

            @if (($msg['exercise'] ?? null) && isset(\App\Livewire\Child\ChatInterface::BREATHING_PHASES[$msg['exercise']]))
                <div wire:key="breathing-{{ $loop->index }}" wire:ignore
                     class="care-breathing animate-fade-up"
                     data-breathing="{{ $msg['exercise'] }}"
                     data-breathing-phases="{{ json_encode(\App\Livewire\Child\ChatInterface::BREATHING_PHASES[$msg['exercise']]) }}"
                     x-data="{
                        phases: JSON.parse($el.dataset.breathingPhases), cycles: 4,
                        cycle: 1, index: 0, left: 0, running: false, done: false, timer: null,
                        get phase() { return this.phases[this.index]; },
                        get big() { return this.running && ['in', 'hold'].includes(this.phase.scale); },
                        enter(i) { this.index = i; this.left = this.phases[i].seconds; },
                        start() {
                            clearInterval(this.timer);
                            window.dispatchEvent(new CustomEvent('care-breathing-start', { detail: this.$el }));
                            this.cycle = 1; this.done = false; this.running = true; this.enter(0);
                            this.timer = setInterval(() => this.tick(), 1000);
                        },
                        tick() {
                            if (--this.left > 0) return;
                            if (this.index + 1 < this.phases.length) return this.enter(this.index + 1);
                            if (this.cycle < this.cycles) { this.cycle++; return this.enter(0); }
                            this.stop(); this.done = true;
                        },
                        stop() { clearInterval(this.timer); this.running = false; },
                     }"
                     x-init="start()"
                     x-on:care-breathing-start.window="$event.detail !== $el && running && stop()">
                    <div class="care-breathing-stage">
                        <img src="{{ $avatarUrl }}" alt="Care respire avec toi" class="care-breathing-avatar select-none"
                             :class="big ? 'is-big' : ''"
                             :style="running ? `transition-duration: ${phase.seconds}s` : ''"
                             height="120" draggable="false">
                    </div>
                    <div class="care-breathing-text" aria-live="polite">
                        <p class="care-breathing-label" x-text="running ? phase.label : (done ? 'Terminé, tu peux recommencer quand tu veux' : 'En pause')"></p>
                        <p class="care-breathing-count" x-show="running"><span x-text="left"></span> s · tour <span x-text="cycle"></span> sur <span x-text="cycles"></span></p>
                        <button type="button" class="care-breathing-btn" x-on:mousedown.prevent
                                x-on:click="running ? stop() : start()"
                                x-text="running ? 'Arrêter' : 'Recommencer'"></button>
                    </div>
                </div>
            @endif
        @endforeach

        @if ($isTyping)
            <div class="flex items-end gap-2 animate-fade-up" wire:poll.600ms="fetchReply">
                @if (! $isTeen)
                    <img src="{{ $avatarUrl }}"
                         alt="Care"
                         data-care-avatar="bubble"
                         class="flex-shrink-0 select-none"
                         style="height: 28px; width: auto; object-fit: contain;"
                         height="28" draggable="false">
                @endif
                <div class="bg-white border border-stone-100 px-4 py-3 rounded-2xl rounded-bl-md shadow-card">
                    <div class="flex gap-1.5">
                        <span class="w-2 h-2 bg-brand-400 rounded-full animate-bounce" style="animation-delay:0s"></span>
                        <span class="w-2 h-2 bg-brand-400 rounded-full animate-bounce" style="animation-delay:.15s"></span>
                        <span class="w-2 h-2 bg-brand-400 rounded-full animate-bounce" style="animation-delay:.3s"></span>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Input bar --}}
    @if (! $sessionClosed)
    <div class="fixed bottom-0 left-0 right-0 px-4 pb-5 pt-3 bg-gradient-to-t from-stone-50 via-stone-50 to-transparent">
        <div class="max-w-2xl mx-auto">
            <div class="bg-white rounded-2xl shadow-elevated border border-stone-200 p-2 flex gap-2 items-center">
                {{-- Le curseur ne quitte jamais la case : elle n'est ni désactivée ni mise en lecture
                     seule pendant que Care répond (un champ désactivé perd le focus et ferme le clavier
                     du téléphone) ; seul l'envoi est bloqué. Pas de wire:submit, qui passe la case en
                     lecture seule pendant chaque envoi ; mousedown.prevent garde le focus dans la case
                     quand on appuie sur le bouton. --}}
                <form x-on:submit.prevent="$wire.sendMessage()" class="flex-1 flex gap-2 items-center">
                    <input wire:model="input" type="text"
                           data-chat-input
                           placeholder="Écris ce que tu ressens…"
                           class="flex-1 px-4 py-2.5 bg-transparent border-0 focus:ring-0 focus:outline-none text-[15px] placeholder:text-stone-400">
                    <button type="submit"
                            data-chat-send
                            x-on:mousedown.prevent
                            class="w-11 h-11 rounded-xl bg-brand-700 hover:bg-brand-800 text-white flex items-center justify-center transition-all active:scale-95 disabled:opacity-40"
                            {{ $isTyping ? 'disabled' : '' }}>
                        <x-icon name="send" size="16" />
                    </button>
                </form>
            </div>
            <div class="flex justify-center mt-3">
                <button type="button"
                        wire:click="endSession"
                        wire:loading.attr="disabled"
                        wire:target="endSession"
                        class="relative inline-flex items-center gap-2 px-5 py-2.5 rounded-full
                               bg-white border border-stone-200 text-stone-600
                               hover:bg-brand-50 hover:border-brand-200 hover:text-brand-800
                               active:scale-95 transition-all duration-150
                               shadow-sm hover:shadow-card
                               text-sm font-medium
                               disabled:opacity-60 disabled:cursor-wait
                               focus:outline-none focus:ring-4 focus:ring-brand-700/15
                               z-10">
                    <span wire:loading.remove wire:target="endSession" class="inline-flex items-center gap-2">
                        <x-icon name="check-circle" size="16" />
                        J'ai fini ma session
                    </span>
                    <span wire:loading wire:target="endSession" class="inline-flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4 text-brand-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="9" stroke-opacity="0.25"/>
                            <path d="M21 12a9 9 0 0 0-9-9" stroke-linecap="round"/>
                        </svg>
                        Je clôture…
                    </span>
                </button>
            </div>
        </div>
    </div>
    @endif

    <style>
        .care-avatar { position: relative; display: inline-block; transform-origin: 50% 100%; }
        .care-avatar img { display: block; }
        .care-avatar-greet { animation: care-greet 1.6s ease-out 0.3s 1 both; }
        @keyframes care-greet {
            0%   { transform: translateY(0) rotate(0); }
            15%  { transform: translateY(-6%) rotate(-9deg); }
            35%  { transform: translateY(0) rotate(8deg); }
            55%  { transform: translateY(-4%) rotate(-7deg); }
            75%  { transform: translateY(0) rotate(4deg); }
            100% { transform: translateY(0) rotate(0); }
        }
        .care-eyelid {
            position: absolute; width: 19.5%; height: 17%; top: 47.4%;
            background: #147b6b; border-radius: 50%; box-shadow: inset 0 -2px 0 rgba(5, 47, 42, .55);
            transform: scaleY(0); transform-origin: 50% 0;
            animation: care-blink 5.2s ease-in-out 2.2s infinite;
        }
        .care-eyelid-left  { left: 21.8%; }
        .care-eyelid-right { left: 54.1%; }
        @keyframes care-blink {
            0%, 93%, 100% { transform: scaleY(0); }
            95.5%, 96.5%  { transform: scaleY(1); }
        }
        .care-breathing {
            display: flex; align-items: center; gap: 1rem;
            margin-left: 2.25rem; max-width: 26rem; padding: 1rem 1.25rem;
            background: #fff; border: 1px solid #e7e5e4; border-radius: 1rem;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .06);
        }
        .care-breathing-stage { flex-shrink: 0; width: 96px; height: 118px; display: flex; align-items: flex-end; justify-content: center; }
        .care-breathing-avatar {
            height: 96px; width: auto; transform: scale(1); transform-origin: 50% 100%;
            transition-property: transform; transition-timing-function: ease-in-out; transition-duration: .6s;
        }
        .care-breathing-avatar.is-big { transform: scale(1.22); }
        .care-breathing-text { min-width: 0; }
        .care-breathing-label { font-size: 1.375rem; font-weight: 700; color: #0f5f52; line-height: 1.3; }
        .care-breathing-count { margin-top: .125rem; font-size: .875rem; color: #57534e; font-variant-numeric: tabular-nums; }
        .care-breathing-btn {
            margin-top: .625rem; min-height: 44px; padding: .5rem 1rem; border-radius: 9999px;
            border: 1px solid #d6d3d1; background: #fff; color: #44403c; font-size: .875rem; font-weight: 600;
        }
        .care-breathing-btn:focus-visible { outline: 3px solid rgba(20, 123, 107, .35); outline-offset: 2px; }
        @media (max-width: 480px) {
            .care-breathing { margin-left: 0; gap: .75rem; padding: .875rem 1rem; }
            .care-breathing-stage { width: 80px; height: 100px; }
            .care-breathing-avatar { height: 80px; }
            .care-breathing-label { font-size: 1.2rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            .care-avatar-greet, .care-eyelid { animation: none !important; }
            .care-breathing-avatar, .care-breathing-avatar.is-big { transition: none !important; transform: none !important; }
        }
    </style>
</div>

{{--
    Script du chat — les commentaires vivent ICI, en commentaire Blade, jamais dans le
    <script> : ce qui est écrit dans le script part dans le navigateur de l'enfant, et ne doit
    rien lui apprendre du fonctionnement (correction du 2026-10-02).

    - Le bloc entier est UNE expression (fonction auto-exécutée) : Alpine le compile en
      « __self.result = contenu » ; un contenu qui commence par une déclaration const est une
      erreur de syntaxe et tout le bloc est abandonné sans bruit (cause du focus jamais rendu,
      constatée le 2026-10-02). Ne rien écrire hors de cette fonction.
    - #4 (V5) — défilement automatique : tout ajout dans #messages (réponse, indicateur de
      saisie) fait défiler en bas, quel que soit le moment où Livewire met la page à jour.
    - #5 (V5) — le curseur revient dans la case après chaque envoi et chaque réponse de Care.
    - #1 (V5) — fermeture ou actualisation de la fenêtre sans « J'ai fini ma session » :
      la session est clôturée par navigator.sendBeacon (POST /chat/close, contrôle
      d'appartenance côté serveur), une seule fois.
--}}
@script
<script>
    (() => {
    const messagesEl = document.getElementById('messages');
    const scrollDown = () => requestAnimationFrame(() => {
        if (messagesEl) messagesEl.scrollTop = messagesEl.scrollHeight;
        const page = document.scrollingElement || document.documentElement;
        page.scrollTop = page.scrollHeight;
    });

    if (messagesEl) {
        new MutationObserver((mutations) => {
            if (mutations.some((m) => !(m.target.closest && m.target.closest('[data-breathing]')))) scrollDown();
        }).observe(messagesEl, { childList: true, subtree: true });
        window.addEventListener('load', scrollDown);
        scrollDown();
    }
    $wire.on('scroll-bottom', scrollDown);

    $wire.on('focus-input', () => {
        requestAnimationFrame(() => {
            const input = document.querySelector('[data-chat-input]');
            if (input && document.activeElement !== input) input.focus({ preventScroll: true });
        });
    });

    let beaconSent = false;
    const sendClose = () => {
        if (beaconSent) return;
        const sessionId = $wire.get('sessionId');
        if (!sessionId || $wire.get('sessionClosed')) return;
        beaconSent = true;
        const payload = JSON.stringify({
            session_id: sessionId,
            messages: $wire.get('messages') || [],
        });
        navigator.sendBeacon(
            @js(route('child.chat.close')),
            new Blob([payload], { type: 'application/json' })
        );
    };
    window.addEventListener('pagehide', sendClose);
    window.addEventListener('beforeunload', sendClose);
    })();
</script>
@endscript
