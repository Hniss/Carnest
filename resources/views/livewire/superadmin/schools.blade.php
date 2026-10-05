<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Écoles</h1>
            <p class="mt-1 text-sm text-stone-600">Ouvrez une école pour gérer ses comptes admin et ses destinataires d'alerte.</p>
        </div>
        <div class="sm:w-72">
            <label for="search" class="sr-only">Rechercher une école</label>
            <input id="search" type="search" wire:model.live.debounce.300ms="search" class="input" placeholder="Rechercher (nom ou ville)">
        </div>
    </div>

    <div class="card overflow-hidden">
        <ul class="divide-y divide-stone-100">
            @forelse ($schools as $s)
                <li wire:key="school-{{ $s->id }}">
                    <a href="{{ route('superadmin.schools.show', $s) }}" class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 px-5 py-4 hover:bg-stone-50">
                        <div class="min-w-0">
                            <p class="font-medium">{{ $s->name }}</p>
                            <p class="text-sm text-stone-500">{{ $s->city }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2 text-xs">
                            <span class="badge badge-neutral">{{ $s->pupils_count }} élève{{ $s->pupils_count > 1 ? 's' : '' }}</span>
                            <span class="badge {{ $s->admins_count ? 'badge-success' : 'badge-warning' }}">{{ $s->admins_count }} admin{{ $s->admins_count > 1 ? 's' : '' }} actif{{ $s->admins_count > 1 ? 's' : '' }}</span>
                            <span class="badge badge-blue">{{ $s->recipients_count }} destinataire{{ $s->recipients_count > 1 ? 's' : '' }} d'alerte</span>
                        </div>
                    </a>
                </li>
            @empty
                <li class="px-5 py-6 text-sm text-stone-500">Aucune école trouvée.</li>
            @endforelse
        </ul>
    </div>

    {{ $schools->links() }}
</div>
