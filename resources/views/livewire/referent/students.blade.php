@php use App\Support\Humanize; use App\Models\FollowUp; @endphp
<div class="space-y-6">

    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="eyebrow mb-2">{{ $school->name }}</div>
            <h1 class="font-display font-extrabold text-3xl lg:text-[32px] text-stone-900 tracking-tight">Élèves</h1>
            <p class="text-stone-500 text-sm mt-1.5">{{ $children->total() }} élève{{ $children->total() > 1 ? 's' : '' }} · statut de suivi, dernière session, dernier signal.</p>
        </div>
        <button type="button" wire:click="exportCsv" class="btn-ghost btn-sm">
            <x-icon name="download" size="14" /> Exporter la liste (CSV)
        </button>
    </div>

    {{-- Filtres --}}
    <section class="card p-4 lg:p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="relative">
            <label for="search" class="sr-only">Rechercher un élève</label>
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-stone-400 pointer-events-none"><x-icon name="search" size="16" /></span>
            <input id="search" type="search" wire:model.live.debounce.300ms="search" class="input pl-9" placeholder="Rechercher par nom">
        </div>
        <div>
            <label for="classe" class="sr-only">Classe</label>
            <select id="classe" wire:model.live="classe" class="input">
                <option value="">Toutes les classes</option>
                @foreach ($classes as $c) <option value="{{ $c }}">{{ $c }}</option> @endforeach
            </select>
        </div>
        <div>
            <label for="statut" class="sr-only">Statut de suivi</label>
            <select id="statut" wire:model.live="statut" class="input">
                <option value="">Tous les statuts</option>
                @foreach ($statuses as $s) <option value="{{ $s }}">{{ FollowUp::statusLabel($s) }}</option> @endforeach
            </select>
        </div>
        <div>
            <label for="anciennete" class="sr-only">Ancienneté du dernier signal</label>
            <select id="anciennete" wire:model.live="anciennete" class="input">
                <option value="">Dernier signal : indifférent</option>
                <option value="7">Signal dans les 7 jours</option>
                <option value="30">Signal dans les 30 jours</option>
                <option value="90">Signal dans les 90 jours</option>
                <option value="0">Aucun signal</option>
            </select>
        </div>
    </section>

    <section class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th>Élève</th>
                        <th>Classe</th>
                        <th>Âge</th>
                        <th>Statut de suivi</th>
                        <th>Dernière session</th>
                        <th>Dernier signal</th>
                        <th>Parent</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($children as $child)
                        <tr>
                            <td>
                                <a href="{{ route('referent.students.show', $child) }}" wire:navigate class="flex items-center gap-3 group">
                                    <div class="w-8 h-8 rounded-full bg-brand-50 text-brand-800 flex items-center justify-center text-xs font-semibold shrink-0">
                                        {{ strtoupper(mb_substr($child->name, 0, 1)) }}
                                    </div>
                                    <span class="font-medium text-stone-900 group-hover:text-brand-700 group-hover:underline underline-offset-2">{{ $child->name }}</span>
                                    @if ($child->deactivated_at) <span class="badge badge-neutral">Désactivé</span> @endif
                                </a>
                            </td>
                            <td class="text-stone-500">{{ $child->classe }}</td>
                            <td class="text-stone-500">{{ $child->age }} ans</td>
                            <td><x-follow-badge :status="$child->latestFollowUp?->status" /></td>
                            <td class="text-stone-500 text-sm">{{ $child->last_session_at ? $child->last_session_at->diffForHumans() : 'Aucune' }}</td>
                            <td class="text-stone-500 text-sm">{{ $child->last_alert_at ? \Illuminate\Support\Carbon::parse($child->last_alert_at)->diffForHumans() : 'Aucun' }}</td>
                            <td>
                                @if ($child->consentingParents->isNotEmpty())
                                    <a href="{{ route('referent.messages', ['enfant' => $child->id]) }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-700 hover:text-brand-900">
                                        <x-icon name="message-circle" size="14" /> Messagerie
                                    </a>
                                @else
                                    <span class="text-xs text-stone-400">Sans consentement</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-6 py-12 text-center text-sm text-stone-400">Aucun élève ne correspond aux filtres.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($children->hasPages())
            <div class="px-6 py-4 border-t border-stone-100">{{ $children->links() }}</div>
        @endif
    </section>
</div>
