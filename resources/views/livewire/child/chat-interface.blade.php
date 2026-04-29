<div class="flex flex-col min-h-screen bg-gradient-to-b from-brand-50/40 via-stone-50 to-stone-50"
     x-data
     x-on:scroll-bottom.window="$nextTick(() => { const el = document.getElementById('messages'); if(el) el.scrollTop = el.scrollHeight })">

    {{-- Header --}}
    <header class="bg-white/80 backdrop-blur border-b border-stone-200 px-5 py-3.5 flex items-center justify-between sticky top-0 z-10">
        <div class="flex items-center gap-2.5">
            <span class="w-8 h-8 rounded-lg bg-brand-700 text-white flex items-center justify-center">
                <x-icon name="leaf" size="18" />
            </span>
            <div>
                <div class="font-display font-extrabold text-[15px] text-stone-900 leading-none">CareNest</div>
                <div class="text-[11px] text-stone-500 mt-0.5">Avec Care</div>
            </div>
        </div>
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
            <div class="flex justify-center mt-2.5">
                <button wire:click="endSession"
                        class="text-xs text-stone-400 hover:text-brand-700 transition-colors font-medium">
                    J'ai fini ma session
                </button>
            </div>
        </div>
    </div>
    @endif
</div>
