@php use App\Models\ParentChild; use App\Support\Humanize; @endphp
<div class="space-y-8">
    <div>
        <nav class="text-xs text-stone-500 mb-3" aria-label="Fil d'Ariane">
            <a href="{{ route('admin.students') }}" wire:navigate class="hover:text-stone-900 hover:underline underline-offset-2">Élèves</a>
            <span class="mx-1.5 text-stone-300">/</span>
            <span class="text-stone-700">{{ $child->name }}</span>
        </nav>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-full bg-brand-50 text-brand-800 flex items-center justify-center text-xl font-semibold shrink-0" aria-hidden="true">
                    {{ strtoupper(mb_substr($child->name, 0, 1)) }}
                </div>
                <div>
                    <div class="eyebrow mb-1">Fiche administrative</div>
                    <h1 class="font-display font-extrabold text-2xl lg:text-[28px] text-stone-900 tracking-tight">{{ $child->name }}</h1>
                </div>
            </div>
            <a href="{{ route('admin.students') }}" wire:navigate class="btn-ghost btn-sm">
                <x-icon name="arrow-left" size="14" /> Retour à la liste
            </a>
        </div>
    </div>

    <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="card p-6">
            <div class="eyebrow mb-1">Identité</div>
            <h2 class="font-semibold text-stone-900 mb-4">Informations administratives</h2>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div><dt class="text-stone-500">Nom</dt><dd class="text-stone-900 font-medium">{{ $child->name }}</dd></div>
                <div><dt class="text-stone-500">Identifiant de connexion</dt><dd class="text-stone-900 font-medium break-all">{{ $child->email }}</dd></div>
                <div><dt class="text-stone-500">Classe</dt><dd class="text-stone-900 font-medium">{{ $child->classe }}</dd></div>
                <div><dt class="text-stone-500">Date de naissance</dt><dd class="text-stone-900 font-medium">{{ $child->birth_date ? Humanize::date($child->birth_date) . ' (' . $child->age . ' ans)' : $child->age . ' ans (date non renseignée)' }}</dd></div>
                <div><dt class="text-stone-500">Tranche d'âge</dt><dd class="text-stone-900 font-medium">{{ $child->age_group }}</dd></div>
                <div><dt class="text-stone-500">Genre</dt><dd class="text-stone-900 font-medium">{{ match($child->gender) { 'm' => 'Garçon', 'f' => 'Fille', 'x' => 'Non précisé', default => 'Non renseigné' } }}</dd></div>
                <div>
                    <dt class="text-stone-500">Statut de compte</dt>
                    <dd>
                        @if ($child->deactivated_at)
                            <span class="badge badge-neutral">Compte désactivé le {{ Humanize::date($child->deactivated_at) }}</span>
                        @else
                            <span class="badge badge-success">Compte actif</span>
                        @endif
                    </dd>
                </div>
                <div><dt class="text-stone-500">Créé le</dt><dd class="text-stone-900 font-medium">{{ Humanize::date($child->created_at) }}</dd></div>
            </dl>
        </div>

        <div class="card p-6">
            <div class="eyebrow mb-1">Consentement</div>
            <h2 class="font-semibold text-stone-900 mb-4">Parents liés et consentement parental</h2>
            @forelse ($child->parents as $p)
                <div class="rounded-xl border border-stone-200 p-4 mb-3 last:mb-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium text-stone-900">{{ $p->name }}</span>
                        <span class="badge badge-neutral">{{ ParentChild::relationLabel($p->pivot->relation) }}</span>
                        @if ($p->pivot->consent_given && ! $p->pivot->consent_withdrawn_at)
                            <span class="badge badge-success">Consentement actif</span>
                        @elseif ($p->pivot->consent_withdrawn_at)
                            <span class="badge badge-danger">Consentement retiré le {{ Humanize::date($p->pivot->consent_withdrawn_at) }}</span>
                        @else
                            <span class="badge badge-warning">Consentement absent</span>
                        @endif
                    </div>
                    <div class="text-sm text-stone-500 mt-1">{{ $p->email }} @if ($p->phone) · {{ $p->phone }} @endif</div>
                    @if ($p->pivot->consent_timestamp)
                        <div class="text-xs text-stone-500 mt-2">Recueilli le {{ Humanize::dateTime($p->pivot->consent_timestamp) }} · IP {{ $p->pivot->consent_ip }}</div>
                        <p class="text-xs text-stone-600 mt-1 italic">« {{ $p->pivot->consent_text }} »</p>
                    @endif
                </div>
            @empty
                <p class="text-sm text-stone-400">Aucun parent rattaché. Rattachez un parent depuis la liste des élèves (modification).</p>
            @endforelse
        </div>
    </section>

    <p class="text-xs text-stone-500">
        Le suivi émotionnel, les signaux et leur traitement sont réservés au référent de l'établissement (loi 09-08, minimisation des accès).
    </p>
</div>
