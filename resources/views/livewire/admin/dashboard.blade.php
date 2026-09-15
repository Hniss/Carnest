{{-- Tableau de bord administration réduit (lot 1 §4) : aucun nom d'élève, sauf urgences vitales sans accusé. --}}
@php
    use App\Enums\AlertType;
    use App\Models\Alert;
    use App\Models\AlertLifecycle;
    $trendMeta = match ($trend) {
        'improving' => ['label' => 'En amélioration', 'icon' => 'trending-up',   'cls' => 'text-brand-700'],
        'worsening' => ['label' => 'En dégradation',  'icon' => 'trending-down', 'cls' => 'text-red-700'],
        'stable'    => ['label' => 'Stable',          'icon' => 'activity',      'cls' => 'text-stone-700'],
        default     => ['label' => 'Données insuffisantes', 'icon' => 'activity', 'cls' => 'text-stone-400'],
    };
    $zoneMeta = [
        'green'  => ['label' => 'Verte',  'cls' => 'bg-brand-500'],
        'yellow' => ['label' => 'Jaune',  'cls' => 'bg-amber-400'],
        'orange' => ['label' => 'Orange', 'cls' => 'bg-orange-500'],
        'red'    => ['label' => 'Rouge',  'cls' => 'bg-red-500'],
        'none'   => ['label' => 'Sans session', 'cls' => 'bg-stone-300'],
    ];
@endphp
<div class="space-y-8" wire:poll.60s.visible>

    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="eyebrow mb-2">{{ optional($school)->name ?? 'Aucune école assignée' }}</div>
            <h1 class="font-display font-extrabold text-3xl lg:text-[32px] text-stone-900 tracking-tight">Tableau de bord</h1>
            <p class="text-stone-500 text-sm mt-1.5">Climat scolaire et charge de traitement, sans donnée individuelle.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.settings') }}" wire:navigate class="btn-ghost btn-sm">
                <x-icon name="settings" size="14" /> Paramètres
            </a>
        </div>
    </div>

    {{-- Urgences vitales sans accusé --}}
    <section class="card overflow-hidden {{ $vital->isNotEmpty() ? 'ring-1 ring-red-200' : '' }}">
        <div class="px-6 py-4 border-b border-stone-100 flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg {{ $vital->isNotEmpty() ? 'bg-red-100 text-red-800' : 'bg-stone-100 text-stone-500' }} flex items-center justify-center shrink-0">
                <x-icon name="alert-triangle" size="16" />
            </div>
            <div>
                <h2 class="font-semibold text-stone-900">Urgences sans accusé</h2>
                <p class="text-xs text-stone-500 mt-0.5">Signaux vitaux sans prise de connaissance du référent depuis plus de {{ \App\Livewire\Admin\Dashboard::VITAL_ACK_MINUTES }} minutes ouvrées. Chaque affichage est tracé.</p>
            </div>
        </div>
        <div class="divide-y divide-stone-100">
            @forelse ($vital as $a)
                <div class="px-6 py-3.5 flex items-center gap-4">
                    <span class="badge badge-danger shrink-0">Vital</span>
                    <div class="flex-1 min-w-0">
                        <div class="font-medium text-stone-900 truncate">{{ $a->child?->name }}</div>
                        <div class="text-xs text-stone-500">{{ AlertType::labelFor($a->type) }} · signalé {{ $a->created_at->diffForHumans() }}</div>
                    </div>
                </div>
            @empty
                <p class="px-6 py-6 text-sm text-stone-500">Aucune urgence vitale en attente d'accusé de réception.</p>
            @endforelse
        </div>
    </section>

    {{-- Compteurs --}}
    <section class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach ([
            ['label' => 'Élèves inscrits', 'value' => $childrenCount],
            ['label' => 'Comptes actifs',  'value' => $activeCount],
            ['label' => 'Sessions 7 j',    'value' => $sessions7d],
            ['label' => 'Alertes actives', 'value' => ($byStatus['unread'] ?? 0) + ($byStatus['read'] ?? 0)],
        ] as $s)
            <div class="card p-5">
                <div class="eyebrow">{{ $s['label'] }}</div>
                <div class="metric text-[32px] leading-none mt-3">{{ $s['value'] }}</div>
            </div>
        @endforeach
    </section>

    {{-- Score climat + tendance --}}
    <section class="card p-6 lg:p-8">
        <div class="grid grid-cols-1 lg:grid-cols-[1fr_auto] gap-8 items-start">
            <div>
                <div class="eyebrow mb-2">Score climat scolaire</div>
                @if ($climateScore)
                    <div class="flex items-baseline gap-3">
                        <span class="metric text-[56px] leading-none text-stone-900">{{ round($climateScore) }}</span>
                        <span class="text-stone-400 text-lg font-medium">/ 100</span>
                    </div>
                    <p class="text-sm text-stone-500 mt-2 max-w-md">Moyenne des scores émotionnels individuels sur 7 jours glissants.</p>
                    <div class="mt-5 h-1.5 bg-stone-100 rounded-full overflow-hidden max-w-md">
                        <div class="h-full rounded-full bg-gradient-to-r from-brand-500 to-brand-700" style="width: {{ max(2, round($climateScore)) }}%"></div>
                    </div>
                @else
                    <span class="metric text-[56px] leading-none text-stone-300">—</span>
                    <p class="text-sm text-stone-500 mt-2 max-w-md">Aucune session analysée pour l'instant.</p>
                @endif
            </div>
            <div class="flex items-center gap-6 lg:border-l lg:border-stone-100 lg:pl-8">
                <div>
                    <div class="eyebrow mb-1.5">Tendance 7 j</div>
                    <div class="flex items-center gap-1.5 font-semibold text-sm {{ $trendMeta['cls'] }}">
                        <x-icon :name="$trendMeta['icon']" size="14" /> {{ $trendMeta['label'] }}
                    </div>
                </div>
                <div>
                    <div class="eyebrow mb-1.5">Seuil alerte</div>
                    <div class="metric text-sm">&lt; {{ optional($school?->setting)->alert_threshold ?? 30 }}</div>
                </div>
            </div>
        </div>
    </section>

    <section class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        {{-- Zones par classe --}}
        <div class="card p-6">
            <div class="eyebrow mb-1">Répartition</div>
            <h2 class="font-semibold text-stone-900 mb-1">Zones par classe</h2>
            <p class="text-xs text-stone-500 mb-4">Dernière session de chaque élève. Classes de moins de {{ \App\Livewire\Admin\Dashboard::MIN_CLASS_SIZE }} élèves non détaillées.</p>
            <div class="space-y-4">
                @forelse ($zonesByClass as $classe => $row)
                    <div>
                        <div class="flex items-center justify-between text-sm mb-1.5">
                            <span class="font-medium text-stone-900">{{ $classe }}</span>
                            <span class="text-xs text-stone-500">{{ $row['count'] }} élève{{ $row['count'] > 1 ? 's' : '' }}</span>
                        </div>
                        @if ($row['insufficient'])
                            <div class="text-xs text-stone-400 italic">Classe non détaillée : effectif insuffisant (moins de 5 élèves)</div>
                        @else
                            <div class="flex h-2.5 rounded-full overflow-hidden bg-stone-100">
                                @foreach ($row['zones'] as $z => $n)
                                    @if ($n > 0)
                                        <div class="{{ $zoneMeta[$z]['cls'] }}" style="width: {{ round($n / $row['count'] * 100) }}%" title="{{ $zoneMeta[$z]['label'] }} : {{ $n }}"></div>
                                    @endif
                                @endforeach
                            </div>
                            <div class="flex flex-wrap gap-x-3 gap-y-1 mt-1.5 text-[11px] text-stone-500">
                                @foreach ($row['zones'] as $z => $n)
                                    @if ($n > 0) <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full {{ $zoneMeta[$z]['cls'] }}"></span>{{ $zoneMeta[$z]['label'] }} {{ $n }}</span> @endif
                                @endforeach
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-stone-400">Aucun élève enregistré.</p>
                @endforelse
            </div>
        </div>

        {{-- Alertes par gravité / statut --}}
        <div class="card p-6 space-y-6">
            <div>
                <div class="eyebrow mb-1">Charge</div>
                <h2 class="font-semibold text-stone-900 mb-3">Alertes par gravité</h2>
                <div class="grid grid-cols-4 gap-2">
                    @foreach (['critical', 'high', 'moderate', 'low'] as $lvl)
                        <div class="rounded-lg border border-stone-200 p-3 text-center">
                            <div class="metric text-xl">{{ $byLevel[$lvl] ?? 0 }}</div>
                            <div class="text-[11px] text-stone-500 mt-1">{{ Alert::levelLabel($lvl) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div>
                <h2 class="font-semibold text-stone-900 mb-3">Alertes par statut de traitement</h2>
                <div class="grid grid-cols-3 sm:grid-cols-5 gap-2">
                    @foreach (AlertLifecycle::STATUSES as $st)
                        <div class="rounded-lg border border-stone-200 p-3 text-center">
                            <div class="metric text-xl">{{ $byStage[$st] ?? 0 }}</div>
                            <div class="text-[11px] text-stone-500 mt-1">{{ AlertLifecycle::statusLabel($st) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="rounded-xl bg-stone-50 border border-stone-200 p-4 flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium text-stone-900">Temps moyen de qualification</div>
                    <div class="text-xs text-stone-500">Entre la détection du signal et sa qualification par le référent.</div>
                </div>
                <div class="metric text-2xl whitespace-nowrap shrink-0 ml-4">
                    @if ($avgQualificationMinutes === null) — @elseif ($avgQualificationMinutes < 60) {{ $avgQualificationMinutes }} min @else {{ round($avgQualificationMinutes / 60, 1) }} h @endif
                </div>
            </div>
        </div>
    </section>
</div>
