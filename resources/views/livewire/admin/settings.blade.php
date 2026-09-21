<div class="space-y-8 max-w-3xl">

    <div>
        <div class="eyebrow mb-2">{{ optional($school)->name ?? 'Aucune école assignée' }}</div>
        <h1 class="font-display font-extrabold text-3xl lg:text-[32px] text-stone-900 tracking-tight">Paramètres</h1>
        <p class="text-stone-500 text-sm mt-1.5">Configuration de votre établissement, des horaires et des notifications.</p>
    </div>

    @if ($savedFlash)
        <div class="rounded-xl border border-brand-200 bg-brand-50/60 px-5 py-3 text-sm text-brand-800" role="status">{{ $savedFlash }}</div>
    @endif

    @if (! $school)
        <div class="card p-6 text-sm text-stone-500">Aucune école n'est assignée à votre compte. Contactez l'équipe CareNest.</div>
    @else
    <form wire:submit="save" class="space-y-6">

        <section class="card p-6 lg:p-8 space-y-6">
            <h2 class="font-semibold text-stone-900">Alertes et interface</h2>
            <div>
                <label for="alertThreshold" class="block text-sm font-semibold text-stone-900 mb-1">Seuil d'alerte sur le score climat</label>
                <p class="text-xs text-stone-500 mb-3">Un enfant dont le score descend sous ce seuil est marqué « à suivre ».</p>
                <div class="flex items-center gap-3">
                    <input id="alertThreshold" type="number" min="0" max="100" wire:model="alertThreshold" class="input w-32">
                    <span class="text-stone-400 text-sm">/ 100</span>
                </div>
                @error('alertThreshold') <p class="text-xs text-red-600 mt-1.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" wire:model="emailNotifications" class="mt-0.5 rounded text-brand-700 focus:ring-brand-500">
                    <span>
                        <span class="block text-sm font-semibold text-stone-900">Notifications e-mail</span>
                        <span class="block text-xs text-stone-500 mt-0.5">Recevoir un e-mail pour les alertes critiques.</span>
                    </span>
                </label>
            </div>
            <div>
                <label for="language" class="block text-sm font-semibold text-stone-900 mb-1">Langue de l'interface enfant</label>
                <select id="language" wire:model="language" class="input max-w-xs">
                    <option value="fr">Français</option>
                    <option value="ar">العربية</option>
                    <option value="en">English</option>
                </select>
                @error('language') <p class="text-xs text-red-600 mt-1.5">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="card p-6 lg:p-8 space-y-6">
            <div>
                <h2 class="font-semibold text-stone-900">Plage horaire scolaire</h2>
                <p class="text-xs text-stone-500 mt-0.5">Sert au calcul des délais de traitement en heures ouvrées (jours ouvrés lundi-vendredi).</p>
            </div>
            <div class="grid grid-cols-2 gap-4 max-w-sm">
                <div>
                    <label for="schoolHoursStart" class="label">Début</label>
                    <input id="schoolHoursStart" type="time" wire:model="schoolHoursStart" class="input">
                    @error('schoolHoursStart') <p class="text-xs text-red-600 mt-1.5">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="schoolHoursEnd" class="label">Fin</label>
                    <input id="schoolHoursEnd" type="time" wire:model="schoolHoursEnd" class="input">
                    @error('schoolHoursEnd') <p class="text-xs text-red-600 mt-1.5">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="card p-6 lg:p-8 space-y-6">
            <h2 class="font-semibold text-stone-900">Contacts</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="referentPhone" class="label">Téléphone du référent</label>
                    <input id="referentPhone" type="tel" wire:model="referentPhone" class="input" placeholder="+212…">
                    @error('referentPhone') <p class="text-xs text-red-600 mt-1.5">{{ $message }}</p> @enderror
                </div>

            </div>
        </section>

        <div class="flex items-center justify-between">
            <a href="{{ route('dashboard') }}" wire:navigate class="btn-ghost btn-sm">Retour au tableau de bord</a>
            <button type="submit" class="btn-primary btn-sm">Enregistrer</button>
        </div>
    </form>
    @endif
</div>
