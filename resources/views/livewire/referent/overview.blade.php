@php use App\Enums\AlertType; use App\Support\Humanize; @endphp
<div class="space-y-8" wire:poll.30s.visible>

    {{-- En-tête --}}
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="eyebrow mb-2">{{ $school->name }}</div>
            <h1 class="font-display font-extrabold text-3xl lg:text-[32px] text-stone-900 tracking-tight">
                Vue d'ensemble
            </h1>
            <p class="text-stone-500 text-sm mt-1.5">
                Signaux détectés par CareNest, à qualifier par le référent. L'outil signale, vous qualifiez.
            </p>
        </div>
        @if ($delegateMode)
            <div class="badge badge-warning">
                <x-icon name="key" size="12" />
                Mode délégation : accès limité aux alertes actives
            </div>
        @endif
    </div>

    {{-- Compteurs cliquables --}}
    <section class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        @foreach ([
            ['key' => 'urgent',       'label' => 'Urgent',      'hint' => 'Niveau critique ou qualifiée urgente', 'cls' => 'text-red-700'],
            ['key' => 'en_cours',     'label' => 'En cours',    'hint' => 'Actions en traitement',                'cls' => 'text-orange-700'],
            ['key' => 'surveillance', 'label' => 'Surveillance','hint' => 'Qualifiées « à surveiller »',           'cls' => 'text-sky-700'],
        ] as $c)
            <button type="button" wire:click="setFilter('{{ $filter === $c['key'] ? '' : $c['key'] }}')"
                    class="card p-5 text-left transition-shadow hover:shadow-card-hover focus:outline-none focus:shadow-focus {{ $filter === $c['key'] ? 'ring-2 ring-brand-500' : '' }}">
                <div class="eyebrow">{{ $c['label'] }}</div>
                <div class="metric text-[32px] leading-none mt-3 {{ $c['cls'] }}">{{ $counters[$c['key']] }}</div>
                <div class="text-xs text-stone-500 mt-2">{{ $c['hint'] }}</div>
            </button>
        @endforeach
    </section>

    {{-- Liste filtrée par compteur --}}
    @if ($filtered !== null)
        <section class="card overflow-hidden">
            <div class="px-6 py-4 flex items-center justify-between border-b border-stone-100">
                <h2 class="font-semibold text-stone-900">
                    {{ match($filter) { 'urgent' => 'Alertes urgentes', 'en_cours' => 'Alertes en cours', default => 'Alertes sous surveillance' } }}
                </h2>
                <button type="button" wire:click="setFilter('')" class="btn-ghost btn-sm">Fermer</button>
            </div>
            <div class="divide-y divide-stone-100">
                @forelse ($filtered as $alert)
                    @include('livewire.referent.partials.alert-row', ['alert' => $alert])
                @empty
                    <p class="px-6 py-10 text-center text-sm text-stone-400">Aucune alerte dans cette catégorie.</p>
                @endforelse
            </div>
        </section>
    @endif

    <section id="alerts" class="grid grid-cols-1 xl:grid-cols-[1fr_360px] gap-6 scroll-mt-8">
        {{-- File d'attente --}}
        <div class="space-y-6">
            <div class="card overflow-hidden">
                <div class="px-6 py-4 border-b border-stone-100">
                    <h2 class="font-semibold text-stone-900">File d'attente : signaux à qualifier</h2>
                    <p class="text-xs text-stone-500 mt-0.5">{{ $queue->count() }} signal{{ $queue->count() > 1 ? 'aux' : '' }} · du plus ancien au plus récent</p>
                </div>
                <div class="divide-y divide-stone-100">
                    @forelse ($queue as $alert)
                        @include('livewire.referent.partials.alert-row', ['alert' => $alert, 'waiting' => true])
                    @empty
                        <div class="px-6 py-12 text-center">
                            <div class="mx-auto w-10 h-10 rounded-full bg-stone-100 text-stone-400 flex items-center justify-center mb-3">
                                <x-icon name="check" size="18" />
                            </div>
                            <p class="text-sm text-stone-500">Aucun signal en attente de qualification.</p>
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="card overflow-hidden">
                <div class="px-6 py-4 border-b border-stone-100">
                    <h2 class="font-semibold text-stone-900">À confirmer</h2>
                    <p class="text-xs text-stone-500 mt-0.5">Signaux en attente de la double vérification</p>
                </div>
                <div class="divide-y divide-stone-100">
                    @forelse ($toConfirm as $alert)
                        @include('livewire.referent.partials.alert-row', ['alert' => $alert])
                    @empty
                        <p class="px-6 py-8 text-center text-sm text-stone-400">Rien à confirmer.</p>
                    @endforelse
                </div>
            </div>

            @unless ($delegateMode)
            <div class="card overflow-hidden">
                <div class="px-6 py-4 border-b border-stone-100">
                    <h2 class="font-semibold text-stone-900">À relire</h2>
                    <p class="text-xs text-stone-500 mt-0.5">Sessions à faible confiance, sans signal associé</p>
                </div>
                <div class="divide-y divide-stone-100">
                    @forelse ($toReview as $s)
                        <a href="{{ route('referent.students.show', $s->child_id) }}" wire:navigate class="px-6 py-3.5 flex items-center gap-4 hover:bg-stone-50/60">
                            <div class="flex-1 min-w-0">
                                <div class="font-medium text-stone-900 truncate">{{ $s->child?->name }}</div>
                                <div class="text-xs text-stone-500 mt-0.5">{{ $s->child?->classe }} · session close {{ Humanize::dateTime($s->ended_at) }}</div>
                            </div>
                            <span class="badge badge-neutral">Faible confiance</span>
                            <x-icon name="chevron-right" size="14" class="text-stone-400" />
                        </a>
                    @empty
                        <p class="px-6 py-8 text-center text-sm text-stone-400">Aucune session à relire.</p>
                    @endforelse
                </div>
            </div>
            @endunless
        </div>

        {{-- Colonne droite --}}
        <div class="space-y-6">
            <div class="card p-6">
                <div class="eyebrow mb-1">Répartition</div>
                <h2 class="font-semibold text-stone-900 mb-4">Alertes actives par classe</h2>
                @forelse ($byClass as $classe => $n)
                    <div class="mb-3">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-stone-700">{{ $classe }}</span>
                            <span class="metric text-sm">{{ $n }}</span>
                        </div>
                        <div class="mt-1.5 h-2 bg-stone-100 rounded-full overflow-hidden">
                            <div class="h-full rounded-full bg-brand-600" style="width: {{ max(4, round($n / $maxByClass * 100)) }}%"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-stone-400">Aucune alerte active.</p>
                @endforelse
            </div>

            @unless ($delegateMode)
            <div class="card p-6">
                <div class="eyebrow mb-1">Sous 7 jours</div>
                <h2 class="font-semibold text-stone-900 mb-4">Suivis à échéance</h2>
                <ul class="space-y-3">
                    @forelse ($followUps as $f)
                        <li>
                            <a href="{{ route('referent.students.show', $f->child_id) }}" wire:navigate class="flex items-start gap-3 group">
                                <div class="w-8 h-8 rounded-lg bg-brand-50 text-brand-800 flex items-center justify-center shrink-0">
                                    <x-icon name="calendar" size="14" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium text-stone-900 group-hover:text-brand-700 truncate">{{ $f->child?->name }}</div>
                                    <div class="text-xs text-stone-500">
                                        {{ Humanize::date($f->next_review_date) }} · {{ \App\Models\FollowUp::statusLabel($f->status) }}
                                        @if ($f->responsable) · {{ $f->responsable->name }} @endif
                                    </div>
                                </div>
                            </a>
                        </li>
                    @empty
                        <li class="text-sm text-stone-400">Aucun suivi à échéance dans les 7 jours.</li>
                    @endforelse
                </ul>
            </div>
            @endunless
        </div>
    </section>
</div>
