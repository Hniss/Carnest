@php use App\Support\Humanize; @endphp
<div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false">
    <button type="button" @click="open = ! open"
            class="relative p-2 rounded-lg text-stone-500 hover:text-stone-900 hover:bg-stone-100 transition-colors focus:outline-none focus:ring-4 focus:ring-stone-200/60"
            aria-label="Notifications{{ $unread ? ' : ' . $unread . ' non lue' . ($unread > 1 ? 's' : '') : '' }}" aria-haspopup="true" :aria-expanded="open">
        <x-icon name="bell" size="18" />
        @if ($unread > 0)
            <span class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-white text-[10px] font-semibold flex items-center justify-center" data-unread-count>{{ $unread > 99 ? '99+' : $unread }}</span>
        @endif
    </button>

    <div x-show="open" x-cloak x-transition.opacity.duration.150ms
         class="absolute right-0 z-30 mt-2 w-[320px] max-w-[calc(100vw-2rem)] card shadow-elevated overflow-hidden" role="menu">
        <div class="px-4 py-3 border-b border-stone-100 flex items-center justify-between">
            <span class="font-semibold text-sm text-stone-900">Notifications</span>
            @if ($unread > 0)
                <button type="button" wire:click="markAllRead" class="text-xs font-medium text-brand-700 hover:text-brand-900">Tout marquer lu</button>
            @endif
        </div>
        <ul class="max-h-80 overflow-y-auto divide-y divide-stone-100">
            @forelse ($notifications as $n)
                <li class="{{ $n->read_at ? '' : 'bg-brand-50/40' }}">
                    <button type="button" class="w-full text-left px-4 py-3 hover:bg-stone-50"
                            wire:click="markRead({{ $n->id }})"
                            @if ($n->link) x-on:click="setTimeout(() => window.location.assign(@js($n->link)), 150)" @endif>
                        <div class="flex items-start gap-2">
                            @unless ($n->read_at) <span class="mt-1.5 w-1.5 h-1.5 rounded-full bg-brand-600 shrink-0"></span> @endunless
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium text-stone-900 truncate">{{ $n->title }}</div>
                                @if ($n->body) <div class="text-xs text-stone-600 mt-0.5 line-clamp-2">{{ $n->body }}</div> @endif
                                <div class="text-[11px] text-stone-400 mt-1">{{ $n->created_at->diffForHumans() }}</div>
                            </div>
                        </div>
                    </button>
                </li>
            @empty
                <li class="px-4 py-8 text-center text-sm text-stone-400">Aucune notification.</li>
            @endforelse
        </ul>
    </div>
</div>
