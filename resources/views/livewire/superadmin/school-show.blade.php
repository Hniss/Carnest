<div class="space-y-6">
    <div>
        <a href="{{ route('superadmin.schools') }}" class="text-sm text-brand-700 hover:text-brand-900">&larr; Toutes les écoles</a>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight">{{ $school->name }}</h1>
        <p class="mt-1 text-sm text-stone-600">{{ $school->city }} · {{ $pupils }} élève{{ $pupils > 1 ? 's' : '' }} actif{{ $pupils > 1 ? 's' : '' }}</p>
    </div>

    @if ($warning)
        <div class="rounded-lg bg-amber-50 text-amber-900 px-4 py-3 text-sm ring-1 ring-inset ring-amber-300/40" role="alert">{{ $warning }}</div>
    @endif
    @if ($flash)
        <div class="rounded-lg bg-brand-50 text-brand-900 px-4 py-3 text-sm" role="status">{{ $flash }}</div>
    @endif

    <section class="card-padded space-y-4">
        <div>
            <h2 class="font-semibold">Comptes admin de l'école</h2>
            <p class="text-sm text-stone-600 mt-1">Un compte créé reçoit par e-mail un lien pour choisir son mot de passe.</p>
        </div>

        <ul class="divide-y divide-stone-100">
            @forelse ($admins as $a)
                <li class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 py-3" wire:key="adm-{{ $a->id }}">
                    <div class="min-w-0">
                        <p class="text-sm font-medium">{{ $a->name }}
                            @if ($a->deactivated_at) <span class="badge badge-neutral ml-1">Désactivé</span> @else <span class="badge badge-success ml-1">Actif</span> @endif
                        </p>
                        <p class="text-xs text-stone-500 break-all">{{ $a->email }}@if ($a->phone) · {{ $a->phone }}@endif</p>
                    </div>
                    <div class="flex gap-2 shrink-0">
                        <button type="button" class="btn-ghost btn-sm" wire:click="editAdmin({{ $a->id }})">Modifier</button>
                        @if ($a->deactivated_at)
                            <button type="button" class="btn-ghost btn-sm" wire:click="reactivateAdmin({{ $a->id }})">Réactiver</button>
                        @else
                            <button type="button" class="btn-danger-ghost btn-sm" wire:click="deactivateAdmin({{ $a->id }})"
                                    wire:confirm="Désactiver le compte de {{ $a->name }} ? Sa session sera fermée.">Désactiver</button>
                        @endif
                    </div>
                </li>
            @empty
                <li class="py-2 text-sm text-stone-500">Aucun compte admin pour cette école.</li>
            @endforelse
        </ul>

        <form wire:submit="{{ $editingAdminId ? 'updateAdmin' : 'createAdmin' }}" class="grid gap-3 sm:grid-cols-3 pt-2 border-t border-stone-100">
            <p class="sm:col-span-3 text-sm font-medium pt-2">{{ $editingAdminId ? 'Modifier le compte' : 'Nouveau compte admin' }}</p>
            <div>
                <label for="adminName" class="label">Nom</label>
                <input id="adminName" type="text" wire:model="adminName" class="input">
                @error('adminName') <p class="text-sm text-red-700 mt-1" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="adminEmail" class="label">E-mail</label>
                <input id="adminEmail" type="email" wire:model="adminEmail" class="input {{ $editingAdminId ? 'bg-stone-100 text-stone-500' : '' }}" @if ($editingAdminId) readonly aria-describedby="adminEmailHelp" @endif>
                @if ($editingAdminId)
                    <p id="adminEmailHelp" class="text-xs text-stone-500 mt-1">L'adresse ne se modifie pas : pour en changer, créer un nouveau compte admin puis désactiver celui-ci.</p>
                @endif
                @error('adminEmail') <p class="text-sm text-red-700 mt-1" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="adminPhone" class="label">Téléphone</label>
                <input id="adminPhone" type="tel" wire:model="adminPhone" class="input">
                @error('adminPhone') <p class="text-sm text-red-700 mt-1" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-3 flex flex-wrap gap-2">
                <button type="submit" class="btn-primary">{{ $editingAdminId ? 'Enregistrer' : 'Créer le compte' }}</button>
                @if ($editingAdminId)
                    <button type="button" class="btn-ghost" wire:click="cancelEditAdmin">Annuler</button>
                @endif
            </div>
        </form>
    </section>

    <section class="card-padded space-y-4">
        <div>
            <h2 class="font-semibold">Destinataires des e-mails d'alerte</h2>
            <p class="text-sm text-stone-600 mt-1">Ces adresses reçoivent les mêmes e-mails d'alerte que le référent, sans aucun nom d'élève.</p>
        </div>

        <ul class="divide-y divide-stone-100">
            @forelse ($recipients as $r)
                <li class="flex items-center justify-between gap-3 py-2" wire:key="rec-{{ $r->id }}">
                    <span class="text-sm break-all">{{ $r->email }}</span>
                    <button type="button" class="btn-danger-ghost btn-sm shrink-0" wire:click="deleteRecipient({{ $r->id }})"
                            wire:confirm="Retirer {{ $r->email }} des destinataires d'alerte ?">Retirer</button>
                </li>
            @empty
                <li class="py-2 text-sm text-stone-500">Aucune adresse : seul le référent reçoit les e-mails d'alerte.</li>
            @endforelse
        </ul>

        <form wire:submit="addRecipient" class="flex flex-col sm:flex-row gap-2 sm:items-start">
            <div class="flex-1">
                <label for="recipientEmail" class="sr-only">Adresse e-mail</label>
                <input id="recipientEmail" type="email" wire:model="recipientEmail" class="input" placeholder="adresse@ecole.ma">
                @error('recipientEmail') <p class="text-sm text-red-700 mt-1" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn-primary">Ajouter</button>
        </form>
    </section>
</div>
