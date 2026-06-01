<div class="flex flex-col min-h-screen bg-gradient-to-b from-brand-50/40 via-stone-50 to-stone-50">

    {{-- Header --}}
    <header class="bg-white/80 backdrop-blur border-b border-stone-200 px-5 py-3.5 flex items-center justify-between sticky top-0 z-10">
        <a href="{{ route('child.chat') }}" class="flex items-center gap-2.5">
            <x-carenest-logo variant="full" class="h-8 w-auto" />
            <span class="hidden sm:inline-block text-[11px] text-stone-500 border-l border-stone-200 pl-2.5">Avec Care</span>
        </a>
        <div class="flex items-center gap-3">
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
                @if ($msg['role'] === 'assistant')
                    <div class="w-9 h-9 rounded-full bg-brand-700 text-white flex items-center justify-center flex-shrink-0 shadow-sm">
                        <x-icon name="leaf" size="16" />
                    </div>
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
        @endforeach

        @if ($isTyping)
            <div class="flex items-end gap-2 animate-fade-up" wire:poll.600ms="fetchReply">
                <div class="w-9 h-9 rounded-full bg-brand-700 text-white flex items-center justify-center shadow-sm">
                    <x-icon name="leaf" size="16" />
                </div>
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
                <form wire:submit="sendMessage" class="flex-1 flex gap-2 items-center">
                    <input wire:model="input" type="text"
                           data-chat-input
                           placeholder="Écris ce que tu ressens…"
                           class="flex-1 px-4 py-2.5 bg-transparent border-0 focus:ring-0 focus:outline-none text-[15px] placeholder:text-stone-400"
                           {{ $isTyping ? 'disabled' : '' }}>
                    <button type="submit"
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
</div>

@script
<script>
    // JS scopé au composant Livewire ($wire dispo). On gère ici trois retours
    // de tests V5 : auto-scroll (#4), focus input (#5), beacon de clôture (#1).
    const messagesEl = document.getElementById('messages');
    const scrollDown = () => requestAnimationFrame(() => {
        if (messagesEl) messagesEl.scrollTop = messagesEl.scrollHeight;
    });

    // #4 — Auto-scroll fiable : tout ajout de nœud (réponse IA, indicateur de
    // saisie) déclenche un scroll en bas. Couvre le timing de morph Livewire.
    if (messagesEl) {
        new MutationObserver(scrollDown).observe(messagesEl, { childList: true, subtree: true });
        scrollDown();
    }
    $wire.on('scroll-bottom', scrollDown);

    // #5 — Focus rendu à l'enfant une fois la réponse arrivée (champ ré-activé).
    $wire.on('focus-input', () => {
        requestAnimationFrame(() => {
            const input = document.querySelector('[data-chat-input]');
            if (input && !input.disabled) input.focus();
        });
    });

    // #1 — Fermeture/actualisation de fenêtre sans « Fin de session » : on
    // clôture la session via beacon (résumé + alerte préservés). Anti-double-envoi.
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
</script>
@endscript
