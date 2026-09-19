@php use App\Models\ParentChild; use App\Support\Humanize; @endphp
<div class="space-y-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="eyebrow mb-2">{{ $school->name }}</div>
            <h1 class="font-display font-extrabold text-3xl lg:text-[32px] text-stone-900 tracking-tight">Élèves</h1>
            <p class="text-stone-500 text-sm mt-1.5">Comptes élèves, consentement parental, activation.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="openCreate" class="btn-primary btn-sm"><x-icon name="user-plus" size="14" /> Nouvel élève</button>
        </div>
    </div>

    @if ($flash)
        <div class="rounded-xl border border-brand-200 bg-brand-50/60 px-5 py-3 text-sm text-brand-800" role="status">{{ $flash }}</div>
    @endif
    @error('reactivate') <div class="rounded-xl border border-amber-200 bg-amber-50/60 px-5 py-3 text-sm text-amber-900" role="alert">{{ $message }}</div> @enderror

    {{-- Formulaire --}}
    @if ($showForm)
        <section class="card p-6 lg:p-8">
            <h2 class="font-semibold text-stone-900 mb-1">{{ $form->id ? 'Modifier l\'élève' : 'Nouvel élève' }}</h2>
            <p class="text-xs text-stone-500 mb-5">Le consentement parental est recueilli au nom de l'école, responsable de traitement (loi 09-08).</p>
            <form wire:submit="save" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="f-name" class="label">Nom et prénom</label>
                    <input id="f-name" type="text" wire:model="form.name" class="input">
                    @error('form.name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="f-classe" class="label">Classe</label>
                    <input id="f-classe" type="text" wire:model="form.classe" class="input" placeholder="CM2, 6ème…">
                    @error('form.classe') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="f-birth" class="label">Date de naissance</label>
                    <input id="f-birth" type="date" wire:model="form.birth_date" class="input">
                    @error('form.birth_date') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="f-gender" class="label">Genre</label>
                    <select id="f-gender" wire:model="form.gender" class="input">
                        <option value="">Non renseigné</option>
                        <option value="f">Fille</option>
                        <option value="m">Garçon</option>
                        <option value="x">Non précisé</option>
                    </select>
                </div>
                <div>
                    <label for="f-email" class="label">Identifiant de connexion (e-mail)</label>
                    <input id="f-email" type="email" wire:model="form.email" class="input">
                    @error('form.email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="f-password" class="label">Mot de passe {{ $form->id ? '(laisser vide pour conserver)' : '(généré si vide)' }}</label>
                    <input id="f-password" type="password" wire:model="form.password" class="input" autocomplete="new-password">
                    @error('form.password') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2 border-t border-stone-100 pt-4">
                    <div class="eyebrow mb-3">Parent {{ $form->id ? '(rattacher un parent supplémentaire, facultatif)' : '' }}</div>
                </div>
                <div>
                    <label for="f-parent-email" class="label">E-mail du parent</label>
                    <input id="f-parent-email" type="email" wire:model="form.parent_email" class="input">
                    <p class="text-xs text-stone-500 mt-1">Un compte parent est créé ou rattaché ; un lien de définition du mot de passe lui est envoyé.</p>
                    @error('form.parent_email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="f-parent-name" class="label">Nom du parent</label>
                    <input id="f-parent-name" type="text" wire:model="form.parent_name" class="input">
                </div>
                <div>
                    <label for="f-relation" class="label">Relation</label>
                    <select id="f-relation" wire:model="form.relation" class="input">
                        @foreach (ParentChild::RELATIONS as $r) <option value="{{ $r }}">{{ ParentChild::relationLabel($r) }}</option> @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="flex items-start gap-3 rounded-xl border border-stone-200 p-4 cursor-pointer hover:bg-stone-50">
                        <input type="checkbox" wire:model="form.consent" class="mt-0.5 rounded border-stone-300 text-brand-700 focus:ring-brand-700/30">
                        <span>
                            <span class="block text-sm font-semibold text-stone-900">Consentement parental recueilli</span>
                            <span class="block text-xs text-stone-600 mt-1">{{ ParentChild::consentText($school->name) }}</span>
                            <span class="block text-xs text-stone-500 mt-1">Horodaté avec l'adresse IP de cette saisie. Sans consentement, le compte élève est créé désactivé.</span>
                        </span>
                    </label>
                </div>
                <div class="sm:col-span-2 flex gap-2">
                    <button type="submit" class="btn-primary btn-sm">Enregistrer</button>
                    <button type="button" wire:click="$set('showForm', false)" class="btn-ghost btn-sm">Annuler</button>
                </div>
            </form>
        </section>
    @endif

    {{-- Filtres + liste --}}
    <section class="card p-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="relative">
            <label for="search" class="sr-only">Rechercher</label>
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-stone-400 pointer-events-none"><x-icon name="search" size="16" /></span>
            <input id="search" type="search" wire:model.live.debounce.300ms="search" class="input pl-9" placeholder="Rechercher par nom">
        </div>
        <div>
            <label for="statut" class="sr-only">Statut</label>
            <select id="statut" wire:model.live="statut" class="input">
                <option value="">Tous les comptes</option>
                <option value="actif">Comptes actifs</option>
                <option value="desactive">Comptes désactivés</option>
                <option value="sans_consentement">Sans consentement actif</option>
            </select>
        </div>
    </section>

    <section class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table-clean">
                <thead><tr><th>Élève</th><th>Classe</th><th>Naissance</th><th>Parent</th><th>Consentement</th><th>Compte</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($children as $child)
                        @php $consenting = $child->parents->first(fn ($p) => $p->pivot->consent_given && ! $p->pivot->consent_withdrawn_at); @endphp
                        <tr>
                            <td>
                                <a href="{{ route('admin.children.show', $child) }}" wire:navigate class="font-medium text-stone-900 hover:text-brand-700 hover:underline underline-offset-2">{{ $child->name }}</a>
                            </td>
                            <td class="text-stone-500">{{ $child->classe }}</td>
                            <td class="text-stone-500 text-sm">{{ $child->birth_date ? Humanize::date($child->birth_date) : $child->age . ' ans' }}</td>
                            <td class="text-sm text-stone-600">
                                @forelse ($child->parents as $p) <div>{{ $p->name }} <span class="text-stone-400">({{ ParentChild::relationLabel($p->pivot->relation) }})</span></div> @empty <span class="text-stone-400">Aucun</span> @endforelse
                            </td>
                            <td>
                                @if ($consenting) <span class="badge badge-success">Actif</span>
                                @elseif ($child->parents->contains(fn ($p) => $p->pivot->consent_withdrawn_at)) <span class="badge badge-danger">Retiré</span>
                                @else <span class="badge badge-warning">Absent</span> @endif
                            </td>
                            <td>
                                @if ($child->deactivated_at) <span class="badge badge-neutral">Désactivé</span> @else <span class="badge badge-success">Actif</span> @endif
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <button type="button" wire:click="openEdit({{ $child->id }})" class="btn-ghost btn-sm">Modifier</button>
                                @if ($child->deactivated_at)
                                    <button type="button" wire:click="reactivate({{ $child->id }})" class="btn-ghost btn-sm">Réactiver</button>
                                @else
                                    <button type="button" wire:click="deactivate({{ $child->id }})" wire:confirm="Désactiver ce compte élève ?" class="btn-danger-ghost btn-sm">Désactiver</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-6 py-12 text-center text-sm text-stone-400">Aucun élève.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($children->hasPages())
            <div class="px-6 py-4 border-t border-stone-100">{{ $children->links() }}</div>
        @endif
    </section>
</div>
