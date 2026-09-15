@php use App\Models\User; use App\Support\Humanize; @endphp
<div class="space-y-6">
    <div>
        <div class="eyebrow mb-2">{{ $school->name }}</div>
        <h1 class="font-display font-extrabold text-3xl lg:text-[32px] text-stone-900 tracking-tight">Comptes école</h1>
        <p class="text-stone-500 text-sm mt-1.5">Référent et administrateurs de l'établissement. Un seul référent actif à la fois.</p>
    </div>

    @if ($flash)
        <div class="rounded-xl border border-brand-200 bg-brand-50/60 px-5 py-3 text-sm text-brand-800" role="status">{{ $flash }}</div>
    @endif

    <section class="card p-6 lg:p-8">
        <h2 class="font-semibold text-stone-900 mb-4">{{ $editingId ? 'Modifier le compte' : 'Nouveau compte' }}</h2>
        <form wire:submit="{{ $editingId ? 'update' : 'create' }}" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label for="a-name" class="label">Nom</label>
                <input id="a-name" type="text" wire:model="name" class="input">
                @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="a-email" class="label">E-mail</label>
                <input id="a-email" type="email" wire:model="email" class="input">
                @error('email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="a-phone" class="label">Téléphone</label>
                <input id="a-phone" type="tel" wire:model="phone" class="input" placeholder="+212…">
                @error('phone') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="a-role" class="label">Rôle</label>
                <select id="a-role" wire:model="role" class="input">
                    <option value="referent">Référent</option>
                    <option value="admin">Administration</option>
                </select>
                @error('role') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2 flex gap-2">
                <button type="submit" class="btn-primary btn-sm">{{ $editingId ? 'Enregistrer' : 'Créer le compte' }}</button>
                @if ($editingId)
                    <button type="button" wire:click="cancelEdit" class="btn-ghost btn-sm">Annuler</button>
                @endif
            </div>
            @unless ($editingId)
                <p class="sm:col-span-2 text-xs text-stone-500">Un lien de définition du mot de passe est envoyé par e-mail à la création. Aucun mot de passe ne transite par cet écran.</p>
            @endunless
        </form>
    </section>

    <section class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table-clean">
                <thead><tr><th>Compte</th><th>Rôle</th><th>Téléphone</th><th>Statut</th><th>Créé</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($accounts as $u)
                        <tr>
                            <td>
                                <div class="font-medium text-stone-900">{{ $u->name }}</div>
                                <div class="text-xs text-stone-500">{{ $u->email }}</div>
                            </td>
                            <td><span class="badge {{ $u->role === 'referent' ? 'badge-success' : 'badge-blue' }}">{{ User::roleLabel($u->role) }}</span></td>
                            <td class="text-stone-500 text-sm">{{ $u->phone ?: '—' }}</td>
                            <td>@if ($u->deactivated_at) <span class="badge badge-neutral">Désactivé</span> @else <span class="badge badge-success">Actif</span> @endif</td>
                            <td class="text-stone-500 text-sm">{{ Humanize::date($u->created_at) }}</td>
                            <td class="text-right whitespace-nowrap">
                                <button type="button" wire:click="openEdit({{ $u->id }})" class="btn-ghost btn-sm">Modifier</button>
                                @if ($u->id !== auth()->id())
                                    @if ($u->deactivated_at)
                                        <button type="button" wire:click="reactivate({{ $u->id }})" class="btn-ghost btn-sm">Réactiver</button>
                                    @else
                                        <button type="button" wire:click="deactivate({{ $u->id }})" wire:confirm="Désactiver ce compte ?" class="btn-danger-ghost btn-sm">Désactiver</button>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-10 text-center text-sm text-stone-400">Aucun compte.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
