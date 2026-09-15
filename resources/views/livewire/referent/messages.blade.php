@php use App\Support\Humanize; @endphp
<div class="space-y-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="eyebrow mb-2">{{ $school->name }}</div>
            <h1 class="font-display font-extrabold text-3xl lg:text-[32px] text-stone-900 tracking-tight">Messagerie</h1>
            <p class="text-stone-500 text-sm mt-1.5">Échanges avec les parents ayant un consentement actif.</p>
        </div>
        <button type="button" wire:click="startCompose" class="btn-primary btn-sm">
            <x-icon name="mail" size="14" /> Nouveau fil
        </button>
    </div>

    <section class="card overflow-hidden grid grid-cols-1 lg:grid-cols-[320px_1fr]">
        {{-- Liste des fils (empilée sur mobile) --}}
        <div class="border-b lg:border-b-0 lg:border-r border-stone-100 {{ $current || $composing ? 'hidden lg:block' : '' }}">
            <div class="px-5 py-3 border-b border-stone-100 text-xs text-stone-500">{{ $threads->count() }} fil{{ $threads->count() > 1 ? 's' : '' }}</div>
            <ul class="divide-y divide-stone-100 max-h-[70vh] overflow-y-auto">
                @forelse ($threads as $t)
                    <li>
                        <button type="button" wire:click="selectThread({{ $t->id }})"
                                class="w-full text-left px-5 py-3.5 hover:bg-stone-50/60 {{ $threadId === $t->id ? 'bg-brand-50/60' : '' }}">
                            <div class="flex items-center gap-2">
                                @if ($t->important) <x-icon name="star" size="12" class="text-amber-500 shrink-0" /> @endif
                                <span class="font-medium text-stone-900 truncate flex-1 {{ $t->unread_count ? 'font-semibold' : '' }}">{{ $t->subject }}</span>
                                @if ($t->unread_count) <span class="badge badge-success">{{ $t->unread_count }} non lu{{ $t->unread_count > 1 ? 's' : '' }}</span> @endif
                            </div>
                            <div class="text-xs text-stone-500 mt-0.5 truncate">{{ $t->parent?->name }} · {{ $t->child?->name }} · {{ $t->updated_at->diffForHumans() }}</div>
                        </button>
                    </li>
                @empty
                    <li class="px-5 py-10 text-center text-sm text-stone-400">Aucun fil pour le moment.</li>
                @endforelse
            </ul>
        </div>

        {{-- Conversation / composition --}}
        <div class="min-w-0">
            @if ($current || $composing)
                <div class="lg:hidden px-5 py-2 border-b border-stone-100">
                    <button type="button" wire:click="$set('threadId', null); $set('composing', false)" class="btn-ghost btn-sm">
                        <x-icon name="arrow-left" size="14" /> Tous les fils
                    </button>
                </div>
            @endif

            @if ($composing)
                <form wire:submit="createThread" class="p-5 space-y-4">
                    <h2 class="font-semibold text-stone-900">Nouveau fil</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="newChildId" class="label">Élève</label>
                            <select id="newChildId" wire:model.live="newChildId" class="input">
                                <option value="">Choisir un élève</option>
                                @foreach ($children as $c) <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->classe }})</option> @endforeach
                            </select>
                            @error('newChildId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="newParentId" class="label">Parent</label>
                            <select id="newParentId" wire:model="newParentId" class="input">
                                <option value="">Choisir un parent</option>
                                @foreach ($parents as $p) <option value="{{ $p->id }}">{{ $p->name }}</option> @endforeach
                            </select>
                            @error('newParentId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label for="newSubject" class="label">Objet</label>
                        <input id="newSubject" type="text" wire:model="newSubject" class="input" placeholder="Objet de l'échange">
                        @error('newSubject') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="newBody" class="label">Message</label>
                        <textarea id="newBody" wire:model="newBody" rows="4" class="input"></textarea>
                        @error('newBody') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="btn-primary btn-sm"><x-icon name="send" size="14" /> Envoyer</button>
                        <button type="button" wire:click="$set('composing', false)" class="btn-ghost btn-sm">Annuler</button>
                    </div>
                </form>
            @else
                @include('livewire.shared.conversation')
            @endif
        </div>
    </section>
</div>
