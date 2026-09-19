@php use App\Models\User; @endphp
<div class="space-y-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="eyebrow mb-2">{{ $school->name }}</div>
            <h1 class="font-display font-extrabold text-3xl lg:text-[32px] text-stone-900 tracking-tight">Journal d'accès</h1>
            <p class="text-stone-500 text-sm mt-1.5">Qui a accédé à quoi, quand, depuis quelle adresse. Aucun contenu n'est journalisé (loi 09-08).</p>
        </div>
    </div>

    <section class="card p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <div>
            <label for="actorId" class="sr-only">Acteur</label>
            <select id="actorId" wire:model.live="actorId" class="input">
                <option value="">Tous les acteurs</option>
                @foreach ($actors as $a) <option value="{{ $a->id }}">{{ $a->name }} ({{ User::roleLabel($a->role) }})</option> @endforeach
            </select>
        </div>
        <div>
            <label for="from" class="sr-only">Du</label>
            <input id="from" type="date" wire:model.live="from" class="input" aria-label="Date de début">
        </div>
        <div>
            <label for="to" class="sr-only">Au</label>
            <input id="to" type="date" wire:model.live="to" class="input" aria-label="Date de fin">
        </div>
        <div>
            <label for="action" class="sr-only">Action</label>
            <input id="action" type="search" wire:model.live.debounce.300ms="action" class="input" placeholder="Action (ex. referent.child)">
        </div>
    </section>

    <section class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table-clean">
                <thead><tr><th>Date</th><th>Acteur</th><th>Rôle</th><th>Action</th><th>Cible</th><th>IP</th></tr></thead>
                <tbody>
                    @forelse ($logs as $l)
                        <tr>
                            <td class="text-stone-600 text-sm whitespace-nowrap">{{ $l->created_at->format('d/m/Y H:i:s') }}</td>
                            <td class="text-stone-900 text-sm">{{ $l->actor?->name ?? ($l->actor_id ? 'Compte ' . $l->actor_id : 'Système') }}</td>
                            <td><span class="badge badge-neutral">{{ User::roleLabel($l->actor_role) === 'Inconnu' ? $l->actor_role : User::roleLabel($l->actor_role) }}</span></td>
                            <td class="font-mono text-xs text-stone-700">{{ $l->action }}</td>
                            <td class="text-stone-500 text-sm">{{ $l->target_type ? (['Alert' => 'Alerte', 'Child' => 'Élève', 'User' => 'Compte', 'ParentThread' => 'Fil de messagerie', 'ParentSynthesis' => 'Synthèse parent', 'SchoolSetting' => 'Paramètres', 'ReferentDelegation' => 'Délégation', 'AdminNote' => 'Note', 'FollowUp' => 'Suivi', 'AlertAction' => 'Action', 'School' => 'École'][class_basename($l->target_type)] ?? class_basename($l->target_type)) . ' n° ' . $l->target_id : '—' }}</td>
                            <td class="text-stone-500 text-xs font-mono">{{ $l->ip ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-10 text-center text-sm text-stone-400">Aucune entrée pour ces filtres.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($logs->hasPages())
            <div class="px-6 py-4 border-t border-stone-100">{{ $logs->links() }}</div>
        @endif
    </section>
</div>
