{{-- Sélecteur d'enfant (affiché uniquement si plusieurs enfants). Attend : $children, $child --}}
@if ($children->count() > 1)
    <div class="flex flex-wrap gap-2" role="group" aria-label="Choisir un enfant">
        @foreach ($children as $c)
            <button type="button" wire:click="selectChild({{ $c->id }})"
                    class="{{ $c->id === $child->id ? 'btn-primary' : 'btn-ghost' }} btn-sm rounded-full">
                {{ $c->name }}
            </button>
        @endforeach
    </div>
@endif
