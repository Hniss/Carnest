@php
    use App\Enums\AlertType;
    use App\Models\ParentChild;
    use App\Support\Humanize;

    $directionMeta = fn (string $dir) => match ($dir) {
        'improving' => ['label' => 'En amélioration', 'cls' => 'badge-success', 'icon' => 'trending-up'],
        'worsening' => ['label' => 'En dégradation',  'cls' => 'badge-danger',  'icon' => 'trending-down'],
        'stable'    => ['label' => 'Stable',          'cls' => 'badge-neutral', 'icon' => 'activity'],
        default     => ['label' => 'Données insuffisantes', 'cls' => 'badge-neutral', 'icon' => 'activity'],
    };
    $kindMeta = fn (string $k) => match ($k) {
        'signal'    => 'bg-red-400',
        'lifecycle' => 'bg-brand-500',
        'action'    => 'bg-orange-400',
        'follow'    => 'bg-sky-400',
        'synthesis' => 'bg-stone-400',
        default     => 'bg-stone-300',
    };
@endphp
<div class="space-y-8">

    {{-- En-tête --}}
    <div>
        <nav class="text-xs text-stone-500 mb-3" aria-label="Fil d'Ariane">
            <a href="{{ route('referent.students') }}" wire:navigate class="hover:text-stone-900 hover:underline underline-offset-2">Élèves</a>
            <span class="mx-1.5 text-stone-300">/</span>
            <span class="text-stone-700">{{ $child->name }}</span>
        </nav>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-full bg-brand-50 text-brand-800 flex items-center justify-center text-xl font-semibold shrink-0" aria-hidden="true">
                    {{ strtoupper(mb_substr($child->name, 0, 1)) }}
                </div>
                <div>
                    <h1 class="font-display font-extrabold text-2xl lg:text-[28px] text-stone-900 tracking-tight">{{ $child->name }}</h1>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-stone-500 mt-1">
                        <span>{{ $child->classe }}</span>
                        <span class="text-stone-300">·</span>
                        <span>{{ $child->age }} ans</span>
                        <span class="text-stone-300">·</span>
                        <x-follow-badge :status="$child->latestFollowUp?->status" />
                        @if ($child->deactivated_at) <span class="badge badge-neutral">Compte désactivé</span> @endif
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="openSynthesis" class="btn-primary btn-sm" @disabled(! $hasConsent)>
                    <x-icon name="mail" size="14" /> Informer le parent
                </button>
                @if ($hasConsent)
                    <a href="{{ route('referent.messages', ['enfant' => $child->id]) }}" wire:navigate class="btn-ghost btn-sm">
                        <x-icon name="message-circle" size="14" /> Messagerie
                    </a>
                @endif
            </div>
        </div>
    </div>

    @if ($flash)
        <div class="rounded-xl border border-brand-200 bg-brand-50/60 px-5 py-3 text-sm text-brand-800" role="status">{{ $flash }}</div>
    @endif
    @error('synthesis') <div class="rounded-xl border border-amber-200 bg-amber-50/60 px-5 py-3 text-sm text-amber-900" role="alert">{{ $message }}</div> @enderror
    @unless ($hasConsent)
        <div class="rounded-xl border border-amber-200 bg-amber-50/60 px-5 py-3 text-sm text-amber-900">
            Aucun parent avec un consentement actif : l'information du parent et la messagerie sont indisponibles pour cet élève.
        </div>
    @endunless

    {{-- Synthèse au parent (formulaire) --}}
    @if ($synthesisOpen)
        <section class="card p-6 lg:p-8">
            <div class="eyebrow mb-1">Information du parent</div>
            <h2 class="font-semibold text-stone-900 mb-4">Synthèse en quatre blocs</h2>
            <form wire:submit="sendSynthesis" class="space-y-4">
                @foreach ([
                    ['prop' => 'synthesisIdentified',     'label' => 'Ce que CareNest a identifié'],
                    ['prop' => 'synthesisSchoolDid',      'label' => 'Ce que l\'école a fait'],
                    ['prop' => 'synthesisSchoolProposes', 'label' => 'Ce que l\'école propose'],
                    ['prop' => 'synthesisParentCan',      'label' => 'Ce que vous pouvez faire'],
                ] as $b)
                    <div>
                        <label for="{{ $b['prop'] }}" class="label">{{ $b['label'] }}</label>
                        <textarea id="{{ $b['prop'] }}" wire:model="{{ $b['prop'] }}" rows="2" class="input"></textarea>
                        @error($b['prop']) <p class="text-xs text-red-600 mt-1.5">{{ $message }}</p> @enderror
                    </div>
                @endforeach
                <p class="text-xs text-stone-500">Formulation imposée : jamais le type précis du signal, jamais de mention de l'intelligence artificielle.</p>
                <div class="flex gap-2">
                    <button type="submit" class="btn-primary btn-sm"><x-icon name="send" size="14" /> Envoyer au parent</button>
                    <button type="button" wire:click="$set('synthesisOpen', false)" class="btn-ghost btn-sm">Annuler</button>
                </div>
            </form>
        </section>
    @endif

    {{-- Profil + état actuel --}}
    <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="card p-6">
            <div class="eyebrow mb-1">Profil</div>
            <h2 class="font-semibold text-stone-900 mb-4">Identité et contacts</h2>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div><dt class="text-stone-500">Classe</dt><dd class="text-stone-900 font-medium">{{ $child->classe }}</dd></div>
                <div><dt class="text-stone-500">Âge</dt><dd class="text-stone-900 font-medium">{{ $child->age }} ans @if ($child->birth_date) ({{ Humanize::date($child->birth_date) }}) @endif</dd></div>
                <div><dt class="text-stone-500">Tranche</dt><dd class="text-stone-900 font-medium">{{ $child->age_group }}</dd></div>
                <div><dt class="text-stone-500">Dernière session</dt><dd class="text-stone-900 font-medium">{{ $child->last_session_at ? $child->last_session_at->diffForHumans() : 'Aucune' }}</dd></div>
                <div class="col-span-2">
                    <dt class="text-stone-500 mb-1">Parents et contacts</dt>
                    <dd>
                        @forelse ($child->parents as $p)
                            <div class="flex flex-wrap items-center gap-2 py-1.5 border-t border-stone-100 first:border-t-0">
                                <span class="font-medium text-stone-900">{{ $p->name }}</span>
                                <span class="badge badge-neutral">{{ ParentChild::relationLabel($p->pivot->relation) }}</span>
                                <span class="text-stone-500">{{ $p->email }}</span>
                                @if ($p->phone) <span class="text-stone-500">{{ $p->phone }}</span> @endif
                                @if ($p->pivot->consent_given && ! $p->pivot->consent_withdrawn_at)
                                    <span class="badge badge-success">Consentement actif</span>
                                @elseif ($p->pivot->consent_withdrawn_at)
                                    <span class="badge badge-danger">Consentement retiré</span>
                                @else
                                    <span class="badge badge-warning">Consentement absent</span>
                                @endif
                            </div>
                        @empty
                            <span class="text-stone-400">Aucun parent rattaché.</span>
                        @endforelse
                    </dd>
                </div>
            </dl>
        </div>

        <div class="card p-6">
            <div class="eyebrow mb-1">État actuel</div>
            <h2 class="font-semibold text-stone-900 mb-4">Dernier signal, dernière action, prochain point</h2>
            @if ($lastAlert)
                @php
                    $t = AlertType::tryFrom((string) $lastAlert->type);
                    $signalLabel = $t === AlertType::Harcelement ? 'Signal détecté : situation potentiellement liée au harcèlement' : 'Signal détecté : ' . mb_strtolower(AlertType::labelFor($lastAlert->type));
                @endphp
                <a href="{{ route('referent.alerts.show', $lastAlert) }}" wire:navigate class="block rounded-xl border border-stone-200 p-4 hover:bg-stone-50/60">
                    <div class="flex flex-wrap items-center gap-2 mb-2">
                        <x-level-badge :level="$lastAlert->level" :resolved="$lastAlert->isClosed()" />
                        <x-stage-badge :stage="$lastAlert->currentStage()" />
                        <span class="text-xs text-stone-500 ml-auto">{{ Humanize::dateTime($lastAlert->created_at) }}</span>
                    </div>
                    <div class="text-sm font-medium text-stone-900">{{ $signalLabel }}</div>
                    @if ($lastAlert->summary) <p class="text-sm text-stone-600 mt-1">{{ $lastAlert->summary }}</p> @endif
                    @if (! empty($lastAlert->signals))
                        <ul class="flex flex-wrap gap-1.5 mt-2">
                            @foreach ((array) $lastAlert->signals as $s) <li class="badge badge-neutral">{{ is_string($s) ? $s : json_encode($s, JSON_UNESCAPED_UNICODE) }}</li> @endforeach
                        </ul>
                    @endif
                </a>
            @else
                <p class="text-sm text-stone-400">Aucun signal détecté pour cet élève.</p>
            @endif
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm mt-4">
                <div>
                    <dt class="text-stone-500">Dernière action</dt>
                    <dd class="text-stone-900 font-medium">
                        @if ($lastAction) {{ \App\Models\AlertAction::typeLabel($lastAction->action_type) }} <span class="text-stone-500 font-normal">· {{ Humanize::date($lastAction->performed_at) }}</span> @else Aucune @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-stone-500">Prochain suivi</dt>
                    <dd class="text-stone-900 font-medium">
                        @if ($nextFollowUp) {{ Humanize::date($nextFollowUp->next_review_date) }} <span class="text-stone-500 font-normal">· {{ \App\Models\FollowUp::statusLabel($nextFollowUp->status) }}</span> @else Aucun @endif
                    </dd>
                </div>
            </dl>
        </div>
    </section>

    {{-- Tendance --}}
    <section class="card p-6">
        <div class="eyebrow mb-1">Tendance</div>
        <h2 class="font-semibold text-stone-900 mb-4">Évolution sur 7 et 30 jours</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="rounded-xl border border-stone-200 p-4">
                <div class="eyebrow">Score 7 j</div>
                <div class="metric text-[32px] leading-none mt-2">{{ $report->currentScore !== null ? round($report->currentScore) : '—' }}</div>
                <div class="text-xs text-stone-500 mt-1">{{ $report->shortTerm->sessionsCount }} session{{ $report->shortTerm->sessionsCount > 1 ? 's' : '' }}</div>
            </div>
            @foreach ([['7 jours', $report->shortTermTrend], ['30 jours', $report->longTermTrend]] as [$title, $trend])
                @php $m = $directionMeta($trend->direction); @endphp
                <div class="rounded-xl border border-stone-200 p-4">
                    <div class="eyebrow">Sur {{ $title }}</div>
                    <div class="mt-2"><span class="badge {{ $m['cls'] }}"><x-icon :name="$m['icon']" size="12" /> {{ $m['label'] }}</span></div>
                    <div class="text-xs text-stone-500 mt-2">
                        @if ($trend->delta !== null) Écart {{ $trend->delta > 0 ? '+' : '' }}{{ round($trend->delta) }} points @else Pas encore de référence comparable @endif
                    </div>
                </div>
            @endforeach
        </div>
        @if ($report->worseningSignal)
            <p class="text-sm text-red-700 mt-4">Baisse observée sur {{ $report->worseningStreak }} semaines consécutives.</p>
        @endif
    </section>

    {{-- Frise + notes --}}
    <section class="grid grid-cols-1 xl:grid-cols-[1fr_400px] gap-6">
        <div class="card p-6">
            <div class="eyebrow mb-1">Historique</div>
            <h2 class="font-semibold text-stone-900 mb-4">Frise des événements</h2>
            <ol class="space-y-4">
                @forelse ($timeline as $e)
                    <li class="flex gap-3">
                        <span class="mt-1.5 w-2.5 h-2.5 rounded-full shrink-0 {{ $kindMeta($e['kind']) }}"></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-baseline gap-x-2">
                                @if ($e['link'])
                                    <a href="{{ $e['link'] }}" wire:navigate class="text-sm font-medium text-stone-900 hover:text-brand-700">{{ $e['label'] }}</a>
                                @else
                                    <span class="text-sm font-medium text-stone-900">{{ $e['label'] }}</span>
                                @endif
                                <span class="text-xs text-stone-500">{{ Humanize::dateTime($e['at']) }}</span>
                            </div>
                            @if ($e['detail']) <div class="text-xs text-stone-500 mt-0.5">{{ $e['detail'] }}</div> @endif
                        </div>
                    </li>
                @empty
                    <li class="text-sm text-stone-400">Aucun événement enregistré.</li>
                @endforelse
            </ol>
        </div>

        <div class="card p-6">
            <div class="eyebrow mb-1">Interne</div>
            <h2 class="font-semibold text-stone-900 mb-1">Notes du référent</h2>
            <p class="text-xs text-stone-500 mb-4">Visibles uniquement par le référent titulaire. Chiffrées au repos.</p>
            <form wire:submit="addNote" class="space-y-3">
                <label for="newNote" class="sr-only">Nouvelle note</label>
                <textarea id="newNote" wire:model="newNote" rows="3" class="input" placeholder="Observation, échange, décision (5 à 500 caractères)"></textarea>
                @error('newNote') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                <button type="submit" class="btn-primary btn-sm">Ajouter la note</button>
            </form>
            <ul class="divide-y divide-stone-100 mt-5">
                @forelse ($notes as $n)
                    <li class="py-3">
                        <div class="flex items-center justify-between text-xs text-stone-500">
                            <span>{{ $n->referent?->name ?? $n->user?->name ?? 'Compte supprimé' }}</span>
                            <span>{{ Humanize::dateTime($n->created_at) }}</span>
                        </div>
                        <p class="text-sm text-stone-800 mt-1 whitespace-pre-line">{{ $n->content }}</p>
                    </li>
                @empty
                    <li class="py-3 text-sm text-stone-400">Aucune note pour le moment.</li>
                @endforelse
            </ul>
        </div>
    </section>
</div>
