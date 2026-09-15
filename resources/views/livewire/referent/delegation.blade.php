@php use App\Support\Humanize; use App\Models\User; @endphp
<div class="space-y-6">
    <div>
        <div class="eyebrow mb-2">{{ $school->name }}</div>
        <h1 class="font-display font-extrabold text-3xl lg:text-[32px] text-stone-900 tracking-tight">Délégation</h1>
        <p class="text-stone-500 text-sm mt-1.5">
            Confiez temporairement l'accès aux alertes actives à un compte école existant. Le délégué qualifie, agit et accuse réception ; il ne voit ni les fiches complètes ni les notes internes.
        </p>
    </div>

    @if ($flash)
        <div class="rounded-xl border border-brand-200 bg-brand-50/60 px-5 py-3 text-sm text-brand-800" role="status">{{ $flash }}</div>
    @endif

    <section class="card p-6 lg:p-8">
        <h2 class="font-semibold text-stone-900 mb-4">Nouvelle délégation</h2>
        <form wire:submit="create" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="sm:col-span-3">
                <label for="delegateId" class="label">Compte délégué</label>
                <select id="delegateId" wire:model="delegateId" class="input">
                    <option value="">Choisir un compte</option>
                    @foreach ($candidates as $c)
                        <option value="{{ $c->id }}">{{ $c->name }} · {{ User::roleLabel($c->role) }} · {{ $c->email }}</option>
                    @endforeach
                </select>
                @error('delegateId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="startDate" class="label">Début</label>
                <input id="startDate" type="date" wire:model="startDate" class="input">
                @error('startDate') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="endDate" class="label">Fin</label>
                <input id="endDate" type="date" wire:model="endDate" class="input">
                @error('endDate') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-end">
                <button type="submit" class="btn-primary btn-sm w-full sm:w-auto"><x-icon name="user-plus" size="14" /> Créer</button>
            </div>
        </form>
    </section>

    <section class="card overflow-hidden">
        <div class="px-6 py-4 border-b border-stone-100">
            <h2 class="font-semibold text-stone-900">Historique</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="table-clean">
                <thead><tr><th>Délégué</th><th>Période</th><th>Statut</th><th>Créée</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($delegations as $d)
                        @php $label = $d->statusLabel(); @endphp
                        <tr>
                            <td>
                                <div class="font-medium text-stone-900">{{ $d->delegate?->name ?? 'Compte supprimé' }}</div>
                                <div class="text-xs text-stone-500">{{ $d->delegate?->email }}</div>
                            </td>
                            <td class="text-stone-600 text-sm">{{ Humanize::date($d->start_date) }} au {{ Humanize::date($d->end_date) }}</td>
                            <td>
                                <span class="badge {{ match($label) { 'Active' => 'badge-success', 'Révoquée' => 'badge-danger', 'Expirée' => 'badge-neutral', 'Programmée' => 'badge-blue', default => 'badge-warning' } }}">{{ $label }}</span>
                            </td>
                            <td class="text-stone-500 text-sm">{{ Humanize::dateTime($d->created_at) }}</td>
                            <td class="text-right">
                                @if ($d->revoked_at === null)
                                    @if ($d->activated_at === null)
                                        <button type="button" wire:click="activate({{ $d->id }})" class="btn-primary btn-sm">Activer</button>
                                    @endif
                                    <button type="button" wire:click="revoke({{ $d->id }})" wire:confirm="Révoquer cette délégation ?" class="btn-danger-ghost btn-sm">Révoquer</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-6 py-10 text-center text-sm text-stone-400">Aucune délégation enregistrée.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
