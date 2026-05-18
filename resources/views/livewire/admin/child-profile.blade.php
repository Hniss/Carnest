@php
    use Illuminate\Support\Str;

    /* ===== Mappings communs ===== */

    $statusMeta = match ($report->currentStatus) {
        'critique'     => ['label' => 'Critique',     'cls' => 'badge-danger',  'dot' => 'bg-red-500'],
        'a_suivre'     => ['label' => 'À suivre',     'cls' => 'badge-orange',  'dot' => 'bg-orange-500'],
        'a_surveiller' => ['label' => 'À surveiller', 'cls' => 'badge-warning', 'dot' => 'bg-amber-400'],
        default        => ['label' => 'Stable',       'cls' => 'badge-success', 'dot' => 'bg-brand-500'],
    };

    $zoneMeta = fn(?string $zone) => match ($zone) {
        'green'  => ['label' => 'Zone verte',  'cls' => 'badge-success', 'fill' => '#10b981'],
        'yellow' => ['label' => 'Zone jaune',  'cls' => 'badge-warning', 'fill' => '#f59e0b'],
        'orange' => ['label' => 'Zone orange', 'cls' => 'badge-orange',  'fill' => '#fb923c'],
        'red'    => ['label' => 'Zone rouge',  'cls' => 'badge-danger',  'fill' => '#ef4444'],
        default  => ['label' => '—',           'cls' => 'badge-neutral', 'fill' => '#a8a29e'],
    };

    $scoreFill = fn(?float $s) => match (true) {
        $s === null => '#d6d3d1',
        $s >= 85    => '#10b981',
        $s >= 55    => '#f59e0b',
        $s >= 20    => '#fb923c',
        default     => '#ef4444',
    };

    $genderLabel = match ($child->gender) {
        'm'     => 'Garçon',
        'f'     => 'Fille',
        'x'     => 'Non précisé',
        default => null,
    };

    $directionMeta = fn(string $dir) => match ($dir) {
        'improving' => ['glyph' => '▲', 'color' => 'text-brand-700',  'bg' => 'bg-brand-50',  'aria' => 'en amélioration'],
        'stable'    => ['glyph' => '▬', 'color' => 'text-stone-600',  'bg' => 'bg-stone-100', 'aria' => 'stable'],
        'worsening' => ['glyph' => '▼', 'color' => 'text-red-700',    'bg' => 'bg-red-50',    'aria' => 'en dégradation'],
        default     => ['glyph' => '?', 'color' => 'text-stone-400',  'bg' => 'bg-stone-100', 'aria' => 'données insuffisantes'],
    };

    /* ===== Sparkline (SVG inline) ===== */
    $spark      = $report->weeklySparkline;        // 8 entrées ?float, chrono ASC
    $sparkW     = 320;
    $sparkH     = 80;
    $padX       = 8;
    $padY       = 10;
    $count      = max(count($spark), 1);
    $stepX      = ($count > 1) ? ($sparkW - 2 * $padX) / ($count - 1) : 0;
    $yFor       = fn(float $v) => $padY + (100 - max(0, min(100, $v))) * ($sparkH - 2 * $padY) / 100;

    // Segments continus (interruption sur null) + points
    $segments   = [];
    $current    = [];
    $points     = [];
    foreach ($spark as $i => $val) {
        $cx = $padX + $i * $stepX;
        if ($val === null) {
            if (count($current) > 1) $segments[] = $current;
            $current = [];
            $points[] = ['cx' => $cx, 'cy' => $sparkH / 2, 'null' => true, 'val' => null];
        } else {
            $cy = $yFor((float) $val);
            $current[] = "$cx,$cy";
            $points[] = ['cx' => $cx, 'cy' => $cy, 'null' => false, 'val' => (float) $val];
        }
    }
    if (count($current) > 1) $segments[] = $current;

    $lastIdx       = count($spark) - 1;
    $lastPoint     = $points[$lastIdx] ?? null;
    $lastVal       = $lastPoint && ! $lastPoint['null'] ? $lastPoint['val'] : null;
    $lastDotColor  = $scoreFill($lastVal);
@endphp

<div class="space-y-8">

    {{-- ============================================ --}}
    {{-- 1. HEADER : breadcrumb + identité + statut    --}}
    {{-- ============================================ --}}
    <div>
        <nav class="text-xs text-stone-500 mb-3" aria-label="Fil d'Ariane">
            <a href="{{ route('dashboard') }}" wire:navigate
               class="hover:text-stone-900 hover:underline underline-offset-2 transition-colors">
                Tableau de bord
            </a>
            <span class="mx-1.5 text-stone-300">/</span>
            <span class="text-stone-700">{{ $child->name }}</span>
        </nav>

        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-full bg-brand-50 text-brand-800
                            flex items-center justify-center text-xl font-semibold shrink-0"
                     aria-hidden="true">
                    {{ strtoupper(mb_substr($child->name, 0, 1)) }}
                </div>
                <div>
                    <h1 class="font-display font-extrabold text-2xl lg:text-[28px] text-stone-900 tracking-tight">
                        {{ $child->name }}
                    </h1>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-stone-500 mt-1">
                        @if ($child->classe)
                            <span>{{ $child->classe }}</span>
                            <span class="text-stone-300">·</span>
                        @endif
                        @if ($child->age)
                            <span>{{ $child->age }} ans</span>
                            @if ($genderLabel)
                                <span class="text-stone-300">·</span>
                            @endif
                        @endif
                        @if ($genderLabel)
                            <span>{{ $genderLabel }}</span>
                        @endif
                        <span class="text-stone-300">·</span>
                        <span class="badge {{ $statusMeta['cls'] }}"
                              aria-label="Statut : {{ $statusMeta['label'] }}">
                            <span class="badge-dot {{ $statusMeta['dot'] }}"></span>
                            {{ $statusMeta['label'] }}
                        </span>
                    </div>
                </div>
            </div>

            <div>
                <a href="{{ route('dashboard') }}" wire:navigate class="btn-ghost btn-sm">
                    <x-icon name="chevron-right" size="14" class="rotate-180" />
                    Retour au dashboard
                </a>
            </div>
        </div>
    </div>

    {{-- ============================================ --}}
    {{-- 2. BANNIÈRE D'AGGRAVATION (conditionnelle)    --}}
    {{-- ============================================ --}}
    @if ($report->worseningSignal)
        @php
            $severe = $report->worseningStreak >= 3;
            $banner = $severe
                ? ['wrap' => 'border-red-300 bg-red-600 text-white', 'iconBg' => 'bg-white/20 text-white', 'subtle' => 'text-red-50']
                : ['wrap' => 'border-red-200 bg-red-50',             'iconBg' => 'bg-red-100 text-red-800', 'subtle' => 'text-red-800/80'];
        @endphp
        <div class="flex items-start gap-3 rounded-xl border px-5 py-4 {{ $banner['wrap'] }}"
             role="alert" aria-live="polite">
            <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 mt-0.5 {{ $banner['iconBg'] }}">
                <x-icon name="alert-triangle" size="18" />
            </div>
            <div class="flex-1">
                <div class="font-semibold text-sm {{ $severe ? 'text-white' : 'text-red-900' }}">
                    Signal d'aggravation détecté
                </div>
                <div class="text-sm mt-0.5 {{ $banner['subtle'] }}">
                    L'état de {{ Str::before($child->name, ' ') }} est en baisse depuis
                    <strong>{{ $report->worseningStreak }} semaines</strong> consécutives.
                    Pensez à programmer un échange.
                </div>
            </div>
        </div>
    @endif

    {{-- ============================================ --}}
    {{-- 3. KPI CARDS (3 colonnes)                     --}}
    {{-- ============================================ --}}
    <section class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        {{-- Score actuel --}}
        <div class="card p-5">
            <div class="eyebrow">Score climat 7j</div>
            <div class="flex items-baseline gap-2 mt-3">
                <span class="metric text-[40px] leading-none
                             {{ $report->currentScore !== null ? 'text-stone-900' : 'text-stone-300' }}">
                    {{ $report->currentScore !== null ? round($report->currentScore) : '—' }}
                </span>
                @if ($report->currentScore !== null)
                    <span class="text-stone-400 text-sm font-medium">/ 100</span>
                @endif
            </div>
            <p class="text-xs text-stone-500 mt-2">
                Moyenne pondérée sur 7 jours glissants.
            </p>
        </div>

        {{-- Sessions cette semaine --}}
        <div class="card p-5">
            <div class="eyebrow">Sessions</div>
            <div class="flex items-baseline gap-2 mt-3">
                <span class="metric text-[40px] leading-none text-stone-900">
                    {{ $report->shortTerm->sessionsCount }}
                </span>
                <span class="text-stone-400 text-sm font-medium">cette semaine</span>
            </div>
            <p class="text-xs text-stone-500 mt-2">
                {{ $report->longTerm->sessionsCount }} sur 30 jours.
            </p>
        </div>

        {{-- Alertes 30j --}}
        <div class="card p-5">
            <div class="eyebrow">Alertes 30j</div>
            <div class="flex items-baseline gap-2 mt-3">
                <span class="metric text-[40px] leading-none text-stone-900">
                    {{ $report->longTerm->alertsCount }}
                </span>
            </div>
            @if ($report->longTerm->criticalAlertsCount > 0)
                <p class="text-xs text-red-700 font-medium mt-2">
                    dont {{ $report->longTerm->criticalAlertsCount }} critique{{ $report->longTerm->criticalAlertsCount > 1 ? 's' : '' }}
                </p>
            @else
                <p class="text-xs text-stone-500 mt-2">
                    Aucune alerte critique sur la période.
                </p>
            @endif
        </div>
    </section>

    {{-- ============================================ --}}
    {{-- 4. ÉVOLUTION : badges + sparkline             --}}
    {{-- ============================================ --}}
    <section class="card p-6 lg:p-8">
        <div class="flex items-start justify-between mb-5">
            <div>
                <div class="eyebrow mb-1">Évolution</div>
                <h2 class="font-semibold text-stone-900">Tendance émotionnelle</h2>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-[1fr_auto] gap-8">
            {{-- Badges de tendance --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach ([
                    ['key' => 'short', 'title' => 'Sur 7 jours',  'trend' => $report->shortTermTrend, 'window' => $report->shortTerm],
                    ['key' => 'long',  'title' => 'Sur 30 jours', 'trend' => $report->longTermTrend,  'window' => $report->longTerm],
                ] as $b)
                    @php
                        $dir = $directionMeta($b['trend']->direction);
                        $delta = $b['trend']->delta;
                        $deltaColor = $delta === null
                            ? 'text-stone-500'
                            : ($delta > 0 ? 'text-brand-700' : ($delta < 0 ? 'text-red-700' : 'text-stone-600'));
                        $reasonTip = $b['trend']->reason === 'insufficient_data'
                            ? 'Données insuffisantes pour estimer une tendance fiable.'
                            : ($b['trend']->reason === 'no_baseline'
                                ? 'Pas encore de référence comparable.'
                                : null);
                    @endphp
                    <div class="rounded-xl border border-stone-200 bg-stone-50/40 p-4">
                        <div class="flex items-center justify-between">
                            <div class="eyebrow">{{ $b['title'] }}</div>
                            <span class="w-7 h-7 rounded-full {{ $dir['bg'] }} {{ $dir['color'] }} flex items-center justify-center text-xs font-bold"
                                  aria-label="Tendance {{ $dir['aria'] }}" role="img">
                                {{ $dir['glyph'] }}
                            </span>
                        </div>
                        <div class="mt-2 font-semibold text-stone-900 text-sm">
                            {{ $b['trend']->label }}
                        </div>
                        @if ($delta !== null)
                            <div class="mt-1 text-sm font-medium {{ $deltaColor }}">
                                {{ $delta > 0 ? '+' : '' }}{{ number_format($delta, 1, ',', ' ') }} pts
                            </div>
                        @endif
                        <div class="mt-2 text-xs text-stone-500">
                            {{ $b['window']->sessionsCount }} session{{ $b['window']->sessionsCount > 1 ? 's' : '' }}
                            @if ($reasonTip)
                                <span class="inline-flex items-center" title="{{ $reasonTip }}">
                                    · <span class="ml-1 underline decoration-dotted decoration-stone-400 underline-offset-2 cursor-help">peu de données</span>
                                </span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Sparkline --}}
            <div class="lg:border-l lg:border-stone-100 lg:pl-8">
                <div class="eyebrow mb-2">8 dernières semaines</div>
                <svg viewBox="0 0 {{ $sparkW }} {{ $sparkH }}" width="{{ $sparkW }}" height="{{ $sparkH }}"
                     class="text-brand-700" role="img"
                     aria-label="Courbe d'évolution des scores hebdomadaires sur 8 semaines">
                    {{-- Ligne de référence à 50 --}}
                    <line x1="{{ $padX }}" y1="{{ $yFor(50) }}"
                          x2="{{ $sparkW - $padX }}" y2="{{ $yFor(50) }}"
                          stroke="#e7e5e4" stroke-width="1" stroke-dasharray="2 3" />

                    {{-- Segments (interruption sur null) --}}
                    @foreach ($segments as $seg)
                        <polyline points="{{ implode(' ', $seg) }}"
                                  fill="none"
                                  stroke="currentColor"
                                  stroke-width="2"
                                  stroke-linecap="round"
                                  stroke-linejoin="round" />
                    @endforeach

                    {{-- Points --}}
                    @foreach ($points as $i => $p)
                        @if ($p['null'])
                            <circle cx="{{ $p['cx'] }}" cy="{{ $p['cy'] }}" r="2"
                                    fill="#d6d3d1" opacity="0.5" />
                        @elseif ($i === $lastIdx)
                            {{-- Dernier point : gros disque coloré selon la zone --}}
                            <circle cx="{{ $p['cx'] }}" cy="{{ $p['cy'] }}" r="5"
                                    fill="{{ $lastDotColor }}" stroke="white" stroke-width="2" />
                        @else
                            <circle cx="{{ $p['cx'] }}" cy="{{ $p['cy'] }}" r="2.5"
                                    fill="currentColor" />
                        @endif
                    @endforeach
                </svg>
                <div class="flex justify-between text-[11px] text-stone-400 mt-1 px-1">
                    <span>Il y a 8 sem</span>
                    <span>Maintenant</span>
                </div>
            </div>
        </div>
    </section>

    {{-- ============================================ --}}
    {{-- 5. DERNIÈRE SESSION                           --}}
    {{-- ============================================ --}}
    <section class="card p-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <div class="eyebrow mb-1">Dernière session</div>
                <h2 class="font-semibold text-stone-900">Résumé clinique</h2>
            </div>
        </div>

        @if ($report->lastSession)
            @php
                $ls   = $report->lastSession;
                $zMeta = $zoneMeta($ls->zone);
            @endphp
            <div class="space-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="badge {{ $zMeta['cls'] }}">{{ $zMeta['label'] }}</span>
                    @if ($ls->low_confidence)
                        <span class="badge badge-neutral"
                              title="L'IA n'a pas pu déterminer l'émotion dominante avec certitude.">
                            Confiance faible
                        </span>
                    @endif
                    <span class="text-xs text-stone-500">
                        {{ optional($ls->ended_at)->diffForHumans() ?? 'date inconnue' }}
                    </span>
                </div>

                @if ($ls->ai_summary)
                    <div class="rounded-xl border border-stone-200 bg-stone-50/60 px-4 py-3">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-stone-500 mb-1">
                            Résumé
                        </div>
                        <p class="text-sm text-stone-700 italic leading-relaxed">
                            {{ $ls->ai_summary }}
                        </p>
                    </div>
                @else
                    <p class="text-sm text-stone-400 italic">Aucun résumé disponible pour cette session.</p>
                @endif
            </div>
        @else
            <p class="text-sm text-stone-400">Aucune session clôturée pour cet enfant.</p>
        @endif
    </section>

    {{-- ============================================ --}}
    {{-- 6. ALERTES RÉCENTES                           --}}
    {{-- ============================================ --}}
    <section class="card overflow-hidden">
        <div class="px-6 py-4 border-b border-stone-100">
            <h2 class="font-semibold text-stone-900">Alertes récentes</h2>
            <p class="text-xs text-stone-500 mt-0.5">
                {{ $recentAlerts->count() }} alerte{{ $recentAlerts->count() > 1 ? 's' : '' }} sur les 20 dernières.
            </p>
        </div>
        <div class="divide-y divide-stone-100">
            @forelse ($recentAlerts as $alert)
                @php
                    $levelMeta = match ($alert->level) {
                        'critical' => ['cls' => 'badge-danger',  'label' => 'Critique'],
                        'high'     => ['cls' => 'badge-orange',  'label' => 'Élevée'],
                        'moderate' => ['cls' => 'badge-warning', 'label' => 'Modérée'],
                        'low'      => ['cls' => 'badge-blue',    'label' => 'Faible'],
                        default    => ['cls' => 'badge-neutral', 'label' => ucfirst((string) $alert->level)],
                    };
                    $dotCls = match ($alert->status === 'resolved' ? 'resolved' : $alert->level) {
                        'critical' => 'bg-red-500 animate-pulse',
                        'high'     => 'bg-orange-500',
                        'moderate' => 'bg-amber-400',
                        'low'      => 'bg-sky-400',
                        'resolved' => 'bg-stone-200',
                        default    => 'bg-stone-300',
                    };
                @endphp
                <div class="px-6 py-4 flex items-center gap-4">
                    <span class="w-2 h-2 rounded-full {{ $dotCls }} shrink-0"
                          aria-label="Niveau {{ $levelMeta['label'] }}"></span>
                    <span class="badge {{ $levelMeta['cls'] }} shrink-0">
                        {{ $levelMeta['label'] }}
                    </span>
                    <div class="flex-1 min-w-0">
                        <div class="font-medium text-stone-900 text-sm truncate">
                            {{ ucfirst(str_replace('_', ' ', (string) $alert->type)) }}
                        </div>
                        <div class="text-xs text-stone-500 mt-0.5">
                            {{ $alert->created_at->diffForHumans() }}
                            <span class="text-stone-300">·</span>
                            {{ $alert->created_at->format('d M Y H:i') }}
                        </div>
                    </div>
                    @if ($alert->status !== 'resolved')
                        <button wire:click="resolveAlert({{ $alert->id }})"
                                wire:confirm="Confirmer le traitement de cette alerte ?"
                                class="btn-primary btn-sm shrink-0">
                            Marquer résolue
                        </button>
                    @else
                        <span class="inline-flex items-center gap-1.5 text-xs text-brand-700 font-medium shrink-0">
                            <x-icon name="check" size="14" />
                            Résolue
                        </span>
                    @endif
                </div>
            @empty
                <div class="px-6 py-12 text-center">
                    <div class="mx-auto w-10 h-10 rounded-full bg-stone-100 text-stone-400 flex items-center justify-center mb-3">
                        <x-icon name="check" size="18" />
                    </div>
                    <p class="text-sm text-stone-500">Aucune alerte récente.</p>
                </div>
            @endforelse
        </div>
    </section>

    {{-- ============================================ --}}
    {{-- 7. HISTORIQUE DES SESSIONS                    --}}
    {{-- ============================================ --}}
    <section class="card overflow-hidden">
        <div class="px-6 py-4 border-b border-stone-100">
            <h2 class="font-semibold text-stone-900">Historique des sessions</h2>
            <p class="text-xs text-stone-500 mt-0.5">
                {{ $recentSessions->count() }} session{{ $recentSessions->count() > 1 ? 's' : '' }} clôturée{{ $recentSessions->count() > 1 ? 's' : '' }}.
            </p>
        </div>

        @if ($recentSessions->isEmpty())
            <div class="px-6 py-12 text-center">
                <p class="text-sm text-stone-500">Aucune session sur cette période.</p>
            </div>
        @else
            <ul class="divide-y divide-stone-100">
                @foreach ($recentSessions as $session)
                    @php
                        $zMeta   = $zoneMeta($session->zone);
                        $summary = (string) ($session->ai_summary ?? '');
                        $isLong  = mb_strlen($summary) > 120;
                        $short   = $isLong ? mb_substr($summary, 0, 120) . '…' : $summary;
                    @endphp
                    <li class="px-6 py-4" x-data="{ open: false }">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="text-xs font-medium text-stone-500 tabular-nums shrink-0">
                                    {{ optional($session->ended_at)->format('d M Y H:i') ?? '—' }}
                                </span>
                                <span class="badge {{ $zMeta['cls'] }}">{{ $zMeta['label'] }}</span>
                                @if ($session->low_confidence)
                                    <span class="inline-flex items-center gap-1 text-[11px] text-stone-500"
                                          title="Confiance IA faible">
                                        <span class="w-1.5 h-1.5 rounded-full bg-stone-300"></span>
                                        confiance faible
                                    </span>
                                @endif
                            </div>
                            @if ($isLong)
                                <button type="button"
                                        @click="open = !open"
                                        class="text-xs font-medium text-brand-700 hover:text-brand-800
                                               focus:outline-none focus:ring-2 focus:ring-brand-700/20
                                               rounded px-1 shrink-0"
                                        x-text="open ? 'Réduire' : 'Voir plus'">
                                    Voir plus
                                </button>
                            @endif
                        </div>
                        @if ($summary !== '')
                            <p class="mt-2 text-sm text-stone-700 italic leading-relaxed">
                                <span x-show="!open">{{ $short }}</span>
                                <span x-show="open" x-cloak>{{ $summary }}</span>
                            </p>
                        @else
                            <p class="mt-2 text-sm text-stone-400 italic">Aucun résumé.</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- ============================================ --}}
    {{-- 8. NOTES ADMIN                                --}}
    {{-- ============================================ --}}
    <section class="card p-6">
        <div class="mb-4">
            <h2 class="font-semibold text-stone-900">Notes administratives</h2>
            <p class="text-xs text-stone-500 mt-0.5">
                Notes internes (visibles uniquement par l'équipe encadrante). Conformes à la loi 09-08.
            </p>
        </div>

        {{-- Formulaire d'ajout --}}
        <div class="rounded-xl border border-stone-200 bg-stone-50/40 p-4 mb-5"
             x-data="{ note: '' }">
            <label for="new-note" class="label">Nouvelle note</label>
            <textarea id="new-note"
                      x-model="note"
                      rows="3"
                      maxlength="500"
                      placeholder="Ex. Échange avec le parent prévu vendredi…"
                      class="input"></textarea>
            <div class="flex items-center justify-between mt-2">
                <span class="text-[11px] text-stone-400">
                    Entre 5 et 500 caractères.
                    <span x-text="note.length"></span> / 500
                </span>
                <button type="button"
                        @click="$wire.addNote(note).then(() => { note = '' })"
                        :disabled="note.trim().length < 5"
                        class="btn-primary btn-sm disabled:opacity-50 disabled:cursor-not-allowed">
                    Ajouter une note
                </button>
            </div>
            @error('note')
                <p class="text-xs text-red-700 mt-2">{{ $message }}</p>
            @enderror
        </div>

        {{-- Liste des notes --}}
        @if ($adminNotes->isEmpty())
            <p class="text-sm text-stone-400 italic">Aucune note pour le moment.</p>
        @else
            <ul class="space-y-3">
                @foreach ($adminNotes as $note)
                    <li class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                        <div class="flex items-center justify-between text-xs text-stone-500 mb-1.5">
                            <span class="font-medium text-stone-700">
                                {{ optional($note->user)->name ?? 'Admin' }}
                            </span>
                            <span>
                                {{ $note->created_at->diffForHumans() }}
                                <span class="text-stone-300">·</span>
                                {{ $note->created_at->format('d M Y H:i') }}
                            </span>
                        </div>
                        <p class="text-sm text-stone-800 whitespace-pre-line leading-relaxed">
                            {{ $note->content }}
                        </p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

</div>
