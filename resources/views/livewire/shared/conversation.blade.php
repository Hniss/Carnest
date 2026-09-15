{{-- Volet conversation partagé référent / parent. Attend : $current (ParentThread|null), $myRole ('referent'|'parent'), $body --}}
@php use App\Support\Humanize; @endphp
@if ($current)
    <div class="px-5 py-4 border-b border-stone-100 flex items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="font-semibold text-stone-900 truncate">{{ $current->subject }}</div>
            <div class="text-xs text-stone-500 mt-0.5">
                @if ($myRole === 'referent')
                    {{ $current->parent?->name }} · parent de {{ $current->child?->name }}
                @else
                    Référent de l'école · au sujet de {{ $current->child?->name }}
                @endif
            </div>
        </div>
        @if ($myRole === 'referent')
            <button type="button" wire:click="toggleImportant({{ $current->id }})" class="btn-ghost btn-sm shrink-0" aria-pressed="{{ $current->important ? 'true' : 'false' }}">
                <x-icon name="star" size="14" class="{{ $current->important ? 'text-amber-500' : '' }}" />
                {{ $current->important ? 'Important' : 'Marquer important' }}
            </button>
        @endif
    </div>
    <div class="px-5 py-4 space-y-3 max-h-[52vh] overflow-y-auto">
        @foreach ($current->messages as $m)
            @php $mine = $m->sender_role === $myRole; @endphp
            <div class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
                <div class="max-w-[85%] rounded-2xl px-4 py-2.5 text-sm {{ $mine ? 'bg-brand-700 text-white' : 'bg-stone-100 text-stone-900' }}">
                    <p class="whitespace-pre-line">{{ $m->body }}</p>
                    <div class="text-[11px] mt-1 {{ $mine ? 'text-brand-100' : 'text-stone-500' }}">
                        {{ $m->sender?->name ?? ($m->sender_role === 'parent' ? 'Parent' : 'Référent') }} · {{ Humanize::dateTime($m->sent_at) }}
                        @if ($mine && $m->read_at) · lu @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    <form wire:submit="send" class="px-5 py-4 border-t border-stone-100 flex items-end gap-2">
        <div class="flex-1">
            <label for="body" class="sr-only">Votre message</label>
            <textarea id="body" wire:model="body" rows="2" class="input" placeholder="Écrire un message"></textarea>
            @error('body') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn-primary" aria-label="Envoyer">
            <x-icon name="send" size="16" />
            <span class="hidden sm:inline">Envoyer</span>
        </button>
    </form>
@else
    <div class="px-6 py-16 text-center">
        <div class="mx-auto w-10 h-10 rounded-full bg-stone-100 text-stone-400 flex items-center justify-center mb-3">
            <x-icon name="message-circle" size="18" />
        </div>
        <p class="text-sm text-stone-500">Sélectionnez un fil pour lire la conversation.</p>
    </div>
@endif
