<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">Boîte d'envoi des e-mails</h1>
        <p class="mt-1 text-sm text-stone-600 max-w-2xl">
            Le compte e-mail qui envoie les alertes et les liens de mot de passe. Le mot de passe est chiffré et ne
            s'affiche plus jamais ; laissez le champ vide pour garder celui qui est enregistré.
        </p>
    </div>

    @if ($flash)
        <div class="rounded-lg bg-brand-50 text-brand-900 px-4 py-3 text-sm" role="status">{{ $flash }}</div>
    @endif

    <div class="card-padded">
        <p class="text-sm text-stone-600 mb-5">
            @if ($current)
                Réglages enregistrés le {{ $current->updated_at->timezone(config('app.timezone'))->format('d/m/Y à H:i') }}@if ($current->updatedBy) par {{ $current->updatedBy->name }}@endif.
                @if ($current->password_last4) Mot de passe se terminant par <span class="font-mono font-semibold text-stone-900">…{{ $current->password_last4 }}</span>. @endif
            @else
                Aucun réglage enregistré ici : l'application utilise ceux du fichier du serveur.
            @endif
        </p>

        <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2 grid gap-4 sm:grid-cols-[1fr_8rem_11rem]">
                <div>
                    <label for="host" class="label">Serveur d'envoi</label>
                    <input id="host" type="text" wire:model="host" class="input" placeholder="smtp.exemple.com" autocomplete="off">
                    @error('host') <p class="text-sm text-red-700 mt-1" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="port" class="label">Port</label>
                    <input id="port" type="number" wire:model="port" class="input">
                    @error('port') <p class="text-sm text-red-700 mt-1" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="encryption" class="label">Chiffrement</label>
                    <select id="encryption" wire:model="encryption" class="input">
                        <option value="ssl">SSL (port 465)</option>
                        <option value="tls">TLS (port 587)</option>
                        <option value="none">Aucun</option>
                    </select>
                    @error('encryption') <p class="text-sm text-red-700 mt-1" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label for="username" class="label">Identifiant</label>
                <input id="username" type="text" wire:model="username" class="input" autocomplete="off">
                @error('username') <p class="text-sm text-red-700 mt-1" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password" class="label">Mot de passe</label>
                <input id="password" type="password" wire:model="password" class="input" autocomplete="new-password"
                       placeholder="{{ $current?->password_last4 ? 'Laisser vide pour le conserver' : '' }}">
                @error('password') <p class="text-sm text-red-700 mt-1" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="fromAddress" class="label">Adresse d'expédition</label>
                <input id="fromAddress" type="email" wire:model="fromAddress" class="input">
                @error('fromAddress') <p class="text-sm text-red-700 mt-1" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="fromName" class="label">Nom d'expéditeur</label>
                <input id="fromName" type="text" wire:model="fromName" class="input">
                @error('fromName') <p class="text-sm text-red-700 mt-1" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2 flex flex-wrap gap-3 pt-2">
                <button type="submit" class="btn-primary" wire:loading.attr="disabled">Enregistrer</button>
                @if ($current)
                    <button type="button" class="btn-danger-ghost" wire:click="resetToServer"
                            wire:confirm="Retirer ces réglages ? L'application reprendra ceux du fichier du serveur.">
                        Revenir au fichier du serveur
                    </button>
                @endif
            </div>
        </form>
    </div>
</div>
