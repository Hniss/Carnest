@php use App\Support\Humanize; @endphp
<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="font-display font-extrabold text-2xl sm:text-3xl text-stone-900 tracking-tight">Messages</h1>
            <p class="text-stone-500 text-sm mt-1.5">Échangez avec le référent de l'école de votre enfant.</p>
        </div>
        <button type="button" wire:click="startCompose" class="btn-primary btn-sm">
            <x-icon name="mail" size="14" /> Nouveau message
        </button>
    </div>

    <section class="card overflow-hidden grid grid-cols-1 md:grid-cols-[260px_1fr]">
        <div class="border-b md:border-b-0 md:border-r border-stone-100 {{ $current || $composing ? 'hidden md:block' : '' }}">
            <ul class="divide-y divide-stone-100 max-h-[60vh] overflow-y-auto">
                @forelse ($threads as $t)
                    <li>
                        <button type="button" wire:click="selectThread({{ $t->id }})"
                                class="w-full text-left px-5 py-3.5 hover:bg-stone-50/60 {{ $threadId === $t->id ? 'bg-brand-50/60' : '' }}">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-stone-900 truncate flex-1 {{ $t->unread_count ? 'font-semibold' : '' }}">{{ $t->subject }}</span>
                                @if ($t->unread_count) <span class="badge badge-success">{{ $t->unread_count }}</span> @endif
                            </div>
                            <div class="text-xs text-stone-500 mt-0.5 truncate">{{ $t->child?->name }} · {{ $t->updated_at->diffForHumans() }}</div>
                        </button>
                    </li>
                @empty
                    <li class="px-5 py-10 text-center text-sm text-stone-400">Aucun échange pour le moment.</li>
                @endforelse
            </ul>
        </div>

        <div class="min-w-0">
            @if ($current || $composing)
                <div class="md:hidden px-5 py-2 border-b border-stone-100">
                    <button type="button" wire:click="$set('threadId', null); $set('composing', false)" class="btn-ghost btn-sm">
                        <x-icon name="arrow-left" size="14" /> Tous les messages
                    </button>
                </div>
            @endif

            @if ($composing)
                <form wire:submit="createThread" class="p-5 space-y-4">
                    <h2 class="font-semibold text-stone-900">Nouveau message au référent</h2>
                    @if ($children->count() > 1)
                        <div>
                            <label for="newChildId" class="label">Au sujet de</label>
                            <select id="newChildId" wire:model="newChildId" class="input">
                                <option value="">Choisir un enfant</option>
                                @foreach ($children as $c) <option value="{{ $c->id }}">{{ $c->name }}</option> @endforeach
                            </select>
                            @error('newChildId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    <div>
                        <label for="newSubject" class="label">Objet</label>
                        <input id="newSubject" type="text" wire:model="newSubject" class="input" placeholder="Par exemple : demande de rendez-vous">
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
