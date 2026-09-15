@php
    use App\Enums\AlertType;
    use App\Models\AlertAction;
    use App\Models\AlertLifecycle;
    use App\Models\FollowUp;
    use App\Support\Humanize;

    $type = AlertType::tryFrom((string) $alert->type);
    $signalLabel = $type === AlertType::Harcelement
        ? 'Signal détecté : situation potentiellement liée au harcèlement'
        : 'Signal détecté : ' . mb_strtolower(AlertType::labelFor($alert->type));
    $initialStep = $closed ? 1 : ($qualified ? 3 : 2);
@endphp
<div class="space-y-6" x-data="{ open: {{ $initialStep }} }">

    {{-- Fil d'Ariane + en-tête --}}
    <div>
        <nav class="text-xs text-stone-500 mb-3" aria-label="Fil d'Ariane">
            <a href="{{ route('referent.overview') }}" wire:navigate class="hover:text-stone-900 hover:underline underline-offset-2">Vue d'ensemble</a>
            <span class="mx-1.5 text-stone-300">/</span>
            <span class="text-stone-700">Alerte n° {{ $alert->id }}</span>
        </nav>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="font-display font-extrabold text-2xl lg:text-[28px] text-stone-900 tracking-tight">
                    {{ $alert->child->name }}
                </h1>
                <div class="flex flex-wrap items-center gap-2 text-sm text-stone-500 mt-2">
                    <span>{{ $alert->child->classe }}</span>
                    <span class="text-stone-300">·</span>
                    <x-level-badge :level="$alert->level" :resolved="$closed" />
                    <x-stage-badge :stage="$stage" />
                    @if ($qualification) <x-qualification-badge :qualification="$qualification" /> @endif
                    @if ($alert->reopened_from_id)
                        <span class="badge badge-orange">Réouverture (alerte n° {{ $alert->reopened_from_id }})</span>
                    @endif
                    @if ($delegateMode)
                        <span class="badge badge-warning"><x-icon name="key" size="12" /> Mode délégation</span>
                    @endif
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($acked)
                    <span class="inline-flex items-center gap-1.5 text-xs text-brand-700 font-medium"><x-icon name="check" size="14" /> Prise de connaissance enregistrée</span>
                @else
                    <button type="button" wire:click="acknowledge" class="btn-primary btn-sm">
                        <x-icon name="check-circle" size="14" /> J'ai pris connaissance
                    </button>
                @endif
                @unless ($delegateMode)
                    <a href="{{ route('referent.students.show', $alert->child_id) }}" wire:navigate class="btn-ghost btn-sm">
                        Fiche élève <x-icon name="chevron-right" size="14" />
                    </a>
                @endunless
            </div>
        </div>
    </div>

    @if ($flash)
        <div class="rounded-xl border border-brand-200 bg-brand-50/60 px-5 py-3 text-sm text-brand-800" role="status">{{ $flash }}</div>
    @endif
    @error('qualification')
        <div class="rounded-xl border border-amber-200 bg-amber-50/60 px-5 py-3 text-sm text-amber-900" role="alert">{{ $message }}</div>
    @enderror

    {{-- ÉTAPE 1 : SIGNAL --}}
    <section class="card overflow-hidden">
        <button type="button" @click="open = open === 1 ? 0 : 1" class="w-full px-6 py-4 flex items-center gap-3 text-left">
            <span class="w-7 h-7 rounded-full bg-brand-700 text-white text-xs font-semibold flex items-center justify-center shrink-0">1</span>
            <span class="font-semibold text-stone-900 flex-1">Signal</span>
            <x-icon name="chevron-down" size="16" class="text-stone-400 transition-transform" x-bind:class="open === 1 ? 'rotate-180' : ''" />
        </button>
        <div x-show="open === 1" x-collapse class="px-6 pb-6 border-t border-stone-100">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4 pt-5 text-sm">
                <div><dt class="eyebrow mb-1">Type de signal</dt><dd class="text-stone-900 font-medium">{{ $signalLabel }}</dd></div>
                <div><dt class="eyebrow mb-1">Date</dt><dd class="text-stone-900">{{ Humanize::dateTime($alert->created_at) }}</dd></div>
                <div><dt class="eyebrow mb-1">Niveau</dt><dd><x-level-badge :level="$alert->level" /></dd></div>
                <div><dt class="eyebrow mb-1">Double vérification</dt><dd class="text-stone-900">{{ \App\Models\Alert::adjudicationLabel($alert->adjudication) }}</dd></div>
                <div class="sm:col-span-2"><dt class="eyebrow mb-1">Résumé</dt>
                    <dd class="text-stone-800 leading-relaxed">{{ $alert->summary ?: 'Aucun résumé disponible pour ce signal.' }}</dd></div>
                <div class="sm:col-span-2"><dt class="eyebrow mb-1">Signaux qualitatifs</dt>
                    <dd>
                        @if (! empty($alert->signals))
                            <ul class="flex flex-wrap gap-2">
                                @foreach ((array) $alert->signals as $s)
                                    <li class="badge badge-neutral">{{ is_string($s) ? $s : json_encode($s, JSON_UNESCAPED_UNICODE) }}</li>
                                @endforeach
                            </ul>
                        @else
                            <span class="text-stone-400">Aucun signal qualitatif renseigné.</span>
                        @endif
                    </dd></div>
            </dl>
            <p class="text-xs text-stone-500 mt-5">CareNest signale ; le référent qualifie. Aucun message de l'élève n'est conservé ni affiché.</p>
        </div>
    </section>

    {{-- ÉTAPE 2 : QUALIFICATION --}}
    <section class="card overflow-hidden">
        <button type="button" @click="open = open === 2 ? 0 : 2" class="w-full px-6 py-4 flex items-center gap-3 text-left">
            <span class="w-7 h-7 rounded-full {{ $qualified ? 'bg-brand-700 text-white' : 'bg-amber-100 text-amber-900' }} text-xs font-semibold flex items-center justify-center shrink-0">2</span>
            <span class="font-semibold text-stone-900 flex-1">Qualification <span class="text-stone-400 font-normal text-sm">(obligatoire avant toute action)</span></span>
            @if ($qualification) <x-qualification-badge :qualification="$qualification" /> @endif
            <x-icon name="chevron-down" size="16" class="text-stone-400 transition-transform" x-bind:class="open === 2 ? 'rotate-180' : ''" />
        </button>
        <div x-show="open === 2" x-collapse class="px-6 pb-6 border-t border-stone-100">
            <div class="flex flex-wrap gap-2 pt-5">
                @foreach (AlertLifecycle::QUALIFICATIONS as $q)
                    <button type="button" wire:click="qualify('{{ $q }}')" @disabled($closed)
                            class="{{ $qualification === $q ? 'btn-primary' : 'btn-ghost' }} btn-sm">
                        {{ AlertLifecycle::qualificationLabel($q) }}
                    </button>
                @endforeach
            </div>
            <p class="text-xs text-stone-500 mt-4">Une nouvelle qualification remplace la précédente ; l'historique reste tracé ci-dessous.</p>
        </div>
    </section>

    {{-- ÉTAPE 3 : ACTION --}}
    <section class="card overflow-hidden {{ $qualified ? '' : 'opacity-60' }}">
        <button type="button" @click="open = open === 3 ? 0 : 3" class="w-full px-6 py-4 flex items-center gap-3 text-left">
            <span class="w-7 h-7 rounded-full bg-stone-100 text-stone-700 text-xs font-semibold flex items-center justify-center shrink-0">3</span>
            <span class="font-semibold text-stone-900 flex-1">Action</span>
            <span class="text-xs text-stone-500">{{ $alert->actions->count() }} enregistrée{{ $alert->actions->count() > 1 ? 's' : '' }}</span>
            <x-icon name="chevron-down" size="16" class="text-stone-400 transition-transform" x-bind:class="open === 3 ? 'rotate-180' : ''" />
        </button>
        <div x-show="open === 3" x-collapse class="px-6 pb-6 border-t border-stone-100">
            @if ($alert->actions->isNotEmpty())
                <ul class="divide-y divide-stone-100 mb-5">
                    @foreach ($alert->actions as $a)
                        <li class="py-3 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <span class="font-medium text-stone-900">{{ AlertAction::typeLabel($a->action_type) }}</span>
                                <span class="text-xs text-stone-500">{{ Humanize::dateTime($a->performed_at) }} · {{ $a->performer?->name ?? 'Compte supprimé' }}</span>
                            </div>
                            @if ($a->notes && ! $delegateMode)<p class="text-stone-600 mt-1">{{ $a->notes }}</p>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <form wire:submit="saveAction" class="pt-4 space-y-4">
                <fieldset @disabled(! $qualified || $closed)>
                    <legend class="label">Actions réalisées</legend>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        @foreach (AlertAction::TYPES as $t)
                            <label class="inline-flex items-center gap-2 text-sm text-stone-700 rounded-lg border border-stone-200 px-3 py-2 cursor-pointer hover:bg-stone-50">
                                <input type="checkbox" wire:model="actionTypes" value="{{ $t }}" class="rounded border-stone-300 text-brand-700 focus:ring-brand-700/30">
                                {{ AlertAction::typeLabel($t) }}
                            </label>
                        @endforeach
                    </div>
                    @error('actionTypes') <p class="text-xs text-red-600 mt-1.5">{{ $message }}</p> @enderror
                </fieldset>
                <div>
                    <label for="actionNotes" class="label">Notes (internes, chiffrées)</label>
                    <textarea id="actionNotes" wire:model="actionNotes" rows="3" class="input" @disabled(! $qualified || $closed) placeholder="Ce qui a été fait, avec qui, ce qui a été décidé."></textarea>
                    @error('actionNotes') <p class="text-xs text-red-600 mt-1.5">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn-primary btn-sm" @disabled(! $qualified || $closed)>Enregistrer l'action</button>
            </form>
        </div>
    </section>

    {{-- ÉTAPE 4 : SUIVI --}}
    @unless ($delegateMode)
    <section class="card overflow-hidden {{ $qualified ? '' : 'opacity-60' }}">
        <button type="button" @click="open = open === 4 ? 0 : 4" class="w-full px-6 py-4 flex items-center gap-3 text-left">
            <span class="w-7 h-7 rounded-full bg-stone-100 text-stone-700 text-xs font-semibold flex items-center justify-center shrink-0">4</span>
            <span class="font-semibold text-stone-900 flex-1">Suivi</span>
            @if ($alert->followUps->isNotEmpty()) <x-follow-badge :status="$alert->followUps->last()->status" /> @endif
            <x-icon name="chevron-down" size="16" class="text-stone-400 transition-transform" x-bind:class="open === 4 ? 'rotate-180' : ''" />
        </button>
        <div x-show="open === 4" x-collapse class="px-6 pb-6 border-t border-stone-100">
            @if ($alert->followUps->isNotEmpty())
                <ul class="divide-y divide-stone-100 mb-5">
                    @foreach ($alert->followUps as $f)
                        <li class="py-3 text-sm flex flex-wrap items-center gap-x-3 gap-y-1">
                            <x-follow-badge :status="$f->status" />
                            <span class="text-stone-700">Prochain point : {{ Humanize::date($f->next_review_date) }}</span>
                            <span class="text-xs text-stone-500">{{ $f->responsable?->name ?? 'Sans responsable' }}</span>
                            @if ($f->objectif)<span class="w-full text-stone-600">{{ $f->objectif }}</span>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <form wire:submit="saveFollowUp" class="pt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="followStatus" class="label">Statut du suivi</label>
                    <select id="followStatus" wire:model="followStatus" class="input" @disabled(! $qualified || $closed)>
                        @foreach (FollowUp::STATUSES as $s)
                            <option value="{{ $s }}">{{ FollowUp::statusLabel($s) }}</option>
                        @endforeach
                    </select>
                    @error('followStatus') <p class="text-xs text-red-600 mt-1.5">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="followDate" class="label">Date du prochain point</label>
                    <input id="followDate" type="date" wire:model="followDate" class="input" @disabled(! $qualified || $closed)>
                    @error('followDate') <p class="text-xs text-red-600 mt-1.5">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="followObjective" class="label">Objectif</label>
                    <input id="followObjective" type="text" wire:model="followObjective" class="input" @disabled(! $qualified || $closed) placeholder="Ce que le suivi doit permettre de vérifier.">
                    @error('followObjective') <p class="text-xs text-red-600 mt-1.5">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="followResponsable" class="label">Responsable</label>
                    <select id="followResponsable" wire:model="followResponsable" class="input" @disabled(! $qualified || $closed)>
                        @foreach ($responsables as $r)
                            <option value="{{ $r->id }}">{{ $r->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <button type="submit" class="btn-primary btn-sm" @disabled(! $qualified || $closed)>Planifier le suivi</button>
                </div>
            </form>
        </div>
    </section>

    {{-- ÉTAPE 5 : INFORMATION DU PARENT --}}
    <section class="card overflow-hidden {{ $qualified ? '' : 'opacity-60' }}">
        <button type="button" @click="open = open === 5 ? 0 : 5" class="w-full px-6 py-4 flex items-center gap-3 text-left">
            <span class="w-7 h-7 rounded-full bg-stone-100 text-stone-700 text-xs font-semibold flex items-center justify-center shrink-0">5</span>
            <span class="font-semibold text-stone-900 flex-1">Information du parent</span>
            <span class="text-xs text-stone-500">{{ $alert->syntheses->count() }} envoyée{{ $alert->syntheses->count() > 1 ? 's' : '' }}</span>
            <x-icon name="chevron-down" size="16" class="text-stone-400 transition-transform" x-bind:class="open === 5 ? 'rotate-180' : ''" />
        </button>
        <div x-show="open === 5" x-collapse class="px-6 pb-6 border-t border-stone-100 space-y-4">
            @if (! $hasConsent)
                <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50/60 px-5 py-4 text-sm text-amber-900">
                    Aucun parent avec un consentement actif n'est rattaché à cet élève : l'information du parent est indisponible.
                    Rapprochez-vous de l'administration pour recueillir le consentement.
                </div>
            @endif
            @error('synthesis') <p class="text-sm text-red-600 mt-4" role="alert">{{ $message }}</p> @enderror

            @if ($alert->syntheses->isNotEmpty())
                <ul class="divide-y divide-stone-100 mt-5">
                    @foreach ($alert->syntheses as $s)
                        <li class="py-3 text-sm flex items-center justify-between gap-3">
                            <span class="text-stone-700">Envoyée à {{ $s->parent?->name }}</span>
                            <span class="text-xs text-stone-500">{{ Humanize::dateTime($s->sent_at) }} · {{ $s->read_at ? 'lue le ' . Humanize::dateTime($s->read_at) : 'non lue' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="pt-4">
                <button type="button" wire:click="prefillSynthesis" class="btn-ghost btn-sm" @disabled(! $qualified || ! $hasConsent || $closed)>
                    <x-icon name="mail" size="14" /> Informer le parent : préparer la synthèse
                </button>
            </div>

            @if ($synthesisIdentified !== '')
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
                    <button type="submit" class="btn-primary btn-sm" @disabled(! $hasConsent || $closed)>
                        <x-icon name="send" size="14" /> Envoyer au parent
                    </button>
                </form>
            @endif
        </div>
    </section>

    {{-- CLÔTURE --}}
    <section class="card p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="font-semibold text-stone-900">Clôture</h2>
            <p class="text-xs text-stone-500 mt-0.5">
                Clôturer marque l'alerte résolue. Un nouveau signal élevé ou critique dans les 30 jours sera présenté comme une réouverture.
            </p>
        </div>
        @if ($closed)
            <span class="inline-flex items-center gap-1.5 text-sm text-brand-700 font-medium"><x-icon name="check" size="14" /> Alerte clôturée</span>
        @else
            <button type="button" wire:click="closeAlert" wire:confirm="Clôturer cette alerte ?" class="btn-ghost btn-sm" @disabled(! $qualified)>
                Clôturer
            </button>
        @endif
    </section>
    @endunless

    {{-- Historique --}}
    <section class="card p-6">
        <div class="eyebrow mb-1">Traçabilité</div>
        <h2 class="font-semibold text-stone-900 mb-4">Historique du cycle de vie</h2>
        <ol class="space-y-3 text-sm">
            <li class="flex items-center gap-3">
                <span class="w-2 h-2 rounded-full bg-stone-300 shrink-0"></span>
                <span class="text-stone-700">Signal détecté</span>
                <span class="text-xs text-stone-500 ml-auto">{{ Humanize::dateTime($alert->created_at) }}</span>
            </li>
            @foreach ($alert->lifecycle as $l)
                <li class="flex items-center gap-3">
                    <span class="w-2 h-2 rounded-full bg-brand-500 shrink-0"></span>
                    <span class="text-stone-700">
                        {{ AlertLifecycle::statusLabel($l->status) }}
                        @if ($l->qualification) · {{ AlertLifecycle::qualificationLabel($l->qualification) }} @endif
                    </span>
                    <span class="text-xs text-stone-500 ml-auto">{{ Humanize::dateTime($l->changed_at) }} · {{ $l->changer?->name ?? 'Compte supprimé' }}</span>
                </li>
            @endforeach
        </ol>
    </section>
</div>
