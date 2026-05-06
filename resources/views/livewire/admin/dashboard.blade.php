<div class="space-y-8">

    {{-- Page header --}}
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="eyebrow mb-2">{{ optional($school)->name ?? 'Aucune école assignée' }}</div>
            <h1 class="font-display font-extrabold text-3xl lg:text-[32px] text-stone-900 tracking-tight">
                Tableau de bord
            </h1>
            <p class="text-stone-500 text-sm mt-1.5">
                Suivi émotionnel, climat scolaire et alertes en cours.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.settings') }}" class="btn-ghost btn-sm">
                <x-icon name="settings" size="14" />
                Paramètres
            </a>
        </div>
    </div>

    {{-- Unread alert banner --}}
    @if ($unreadCount > 0)
    <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50/60 px-5 py-4">
        <div class="w-8 h-8 rounded-lg bg-amber-100 text-amber-800 flex items-center justify-center shrink-0 mt-0.5">
            <x-icon name="alert-triangle" size="16" />
        </div>
        <div class="flex-1">
            <div class="font-semibold text-stone-900 text-sm">
                {{ $unreadCount }} alerte{{ $unreadCount > 1 ? 's' : '' }} non traitée{{ $unreadCount > 1 ? 's' : '' }}
            </div>
            <div class="text-stone-600 text-sm mt-0.5">
                À examiner en priorité pour assurer un accompagnement rapide.
            </div>
        </div>
        <a href="#alerts" class="btn-ghost btn-sm shrink-0">
            Examiner
            <x-icon name="chevron-right" size="14" />
        </a>
    </div>
    @endif

    {{-- Stats grid --}}
    <section>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            @php
                $statCards = [
                    ['label' => 'Élèves',         'value' => $children->count()],
                    ['label' => 'À suivre',       'value' => $children->where('status', 'a_suivre')->count()],
                    ['label' => 'Alertes vives',  'value' => $unreadCount],
                    ['label' => 'Sessions 7j',    'value' => $children->whereNotNull('last_session_at')
                                                                     ->where('last_session_at', '>=', now()->subDays(7))->count()],
                ];
            @endphp
            @foreach ($statCards as $s)
                <div class="card p-5">
                    <div class="eyebrow">{{ $s['label'] }}</div>
                    <div class="metric text-[32px] leading-none mt-3">{{ $s['value'] }}</div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Climate score --}}
    <section class="card p-6 lg:p-8">
        <div class="grid grid-cols-1 lg:grid-cols-[1fr_auto] gap-8 items-start">
            <div>
                <div class="eyebrow mb-2">Score climat scolaire</div>
                @if ($climateScore)
                    <div class="flex items-baseline gap-3">
                        <span class="metric text-[56px] leading-none text-stone-900">{{ round($climateScore) }}</span>
                        <span class="text-stone-400 text-lg font-medium">/ 100</span>
                    </div>
                    <p class="text-sm text-stone-500 mt-2 max-w-md">
                        Moyenne pondérée des scores émotionnels individuels sur 7 jours glissants.
                    </p>
                    <div class="mt-5 h-1.5 bg-stone-100 rounded-full overflow-hidden max-w-md">
                        <div class="h-full rounded-full bg-gradient-to-r from-brand-500 to-brand-700 transition-all"
                             style="width: {{ max(2, round($climateScore)) }}%"></div>
                    </div>
                @else
                    <div class="flex items-baseline gap-3">
                        <span class="metric text-[56px] leading-none text-stone-300">—</span>
                    </div>
                    <p class="text-sm text-stone-500 mt-2 max-w-md">
                        Aucune session analysée pour l'instant. Le score s'affichera dès la première clôture de session.
                    </p>
                @endif
            </div>

            @if ($climateScore)
            <div class="flex items-center gap-6 lg:border-l lg:border-stone-100 lg:pl-8">
                <div>
                    <div class="eyebrow mb-1.5">Tendance 7j</div>
                    <div class="flex items-center gap-1.5 text-brand-700 font-semibold text-sm">
                        <x-icon name="trending-up" size="14" />
                        stable
                    </div>
                </div>
                <div>
                    <div class="eyebrow mb-1.5">Seuil alerte</div>
                    <div class="metric text-sm text-stone-900">
                        &lt; {{ optional($school?->setting)->alert_threshold ?? 30 }}
                    </div>
                </div>
            </div>
            @endif
        </div>
    </section>

    {{-- Students table --}}
    <section id="students" class="card overflow-hidden scroll-mt-8">
        <div class="px-6 py-4 flex items-center justify-between border-b border-stone-100">
            <div>
                <h2 class="font-semibold text-stone-900">Élèves</h2>
                <p class="text-xs text-stone-500 mt-0.5">
                    {{ $children->count() }} au total · triés par statut
                </p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th>Élève</th>
                        <th>Classe</th>
                        <th>Âge</th>
                        <th class="text-right">Score</th>
                        <th>Statut</th>
                        <th>Dernière session</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($children as $child)
                        <tr>
                            <td>
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-brand-50 text-brand-800 flex items-center justify-center text-xs font-semibold shrink-0">
                                        {{ strtoupper(substr($child->name, 0, 1)) }}
                                    </div>
                                    <div class="font-medium text-stone-900">{{ $child->name }}</div>
                                </div>
                            </td>
                            <td class="text-stone-500">{{ $child->classe }}</td>
                            <td class="text-stone-500">{{ $child->age }}</td>
                            <td class="text-right metric">
                                {{ $child->score_enfant !== null ? round($child->score_enfant) : '—' }}
                            </td>
                            <td>
                                @if ($child->status === 'a_suivre')
                                    <span class="badge badge-warning">
                                        <span class="badge-dot bg-amber-500"></span>
                                        À suivre
                                    </span>
                                @else
                                    <span class="badge badge-success">
                                        <span class="badge-dot bg-brand-500"></span>
                                        Stable
                                    </span>
                                @endif
                            </td>
                            <td class="text-stone-500 text-sm">
                                {{ $child->last_session_at ? $child->last_session_at->diffForHumans() : 'Aucune' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-sm text-stone-400">
                                Aucun élève enregistré pour cet établissement.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Alerts --}}
    <section id="alerts" class="card overflow-hidden scroll-mt-8">
        <div class="px-6 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-stone-100">
            <div>
                <h2 class="font-semibold text-stone-900">Alertes</h2>
                <p class="text-xs text-stone-500 mt-0.5">
                    {{ $alerts->count() }} résultat{{ $alerts->count() > 1 ? 's' : '' }}
                </p>
            </div>
            <div class="inline-flex p-1 bg-stone-100 rounded-lg">
                @foreach (['all' => 'Toutes', 'unread' => 'Non traitées', 'resolved' => 'Résolues'] as $val => $label)
                    <button wire:click="$set('alertFilter', '{{ $val }}')"
                        class="px-3 py-1.5 rounded-md text-xs font-medium transition-all
                            {{ $alertFilter === $val
                                ? 'bg-white text-stone-900 shadow-sm'
                                : 'text-stone-500 hover:text-stone-900' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="divide-y divide-stone-100">
            @forelse ($alerts as $alert)
                <div class="px-6 py-4 flex items-center gap-4">
                    @if ($alert->status === 'unread')
                        <span class="w-1.5 h-1.5 rounded-full bg-red-500 shrink-0" aria-label="non traitée"></span>
                    @else
                        <span class="w-1.5 h-1.5 rounded-full bg-stone-200 shrink-0" aria-hidden="true"></span>
                    @endif

                    @php
                        $levelMeta = match ($alert->level) {
                            'critical' => ['cls' => 'badge-danger',  'label' => 'Critique'],
                            'high'     => ['cls' => 'badge-danger',  'label' => 'Élevée'],
                            'moderate' => ['cls' => 'badge-warning', 'label' => 'Modérée'],
                            'low'      => ['cls' => 'badge-success', 'label' => 'Faible'],
                            default    => ['cls' => 'badge-warning', 'label' => ucfirst((string) $alert->level)],
                        };
                    @endphp
                    <span class="badge {{ $levelMeta['cls'] }} shrink-0">
                        {{ $levelMeta['label'] }}
                    </span>

                    <div class="flex-1 min-w-0">
                        <div class="font-medium text-stone-900 truncate">{{ $alert->child->name }}</div>
                        <div class="text-xs text-stone-500 mt-0.5">
                            {{ $alert->child->classe }} · {{ ucfirst($alert->type) }} · {{ $alert->created_at->diffForHumans() }}
                        </div>
                    </div>

                    @if ($alert->status !== 'resolved')
                        <button wire:click="resolveAlert({{ $alert->id }})" class="btn-primary btn-sm shrink-0">
                            Traiter
                        </button>
                    @else
                        <span class="inline-flex items-center gap-1.5 text-xs text-brand-700 font-medium shrink-0">
                            <x-icon name="check" size="14" />
                            Résolue
                        </span>
                    @endif
                </div>
            @empty
                <div class="px-6 py-14 text-center">
                    <div class="mx-auto w-10 h-10 rounded-full bg-stone-100 text-stone-400 flex items-center justify-center mb-3">
                        <x-icon name="check" size="18" />
                    </div>
                    <p class="text-sm text-stone-500">Aucune alerte dans cette catégorie.</p>
                </div>
            @endforelse
        </div>
    </section>

</div>
