<div class="space-y-8 max-w-2xl">

    {{-- Page header --}}
    <div>
        <div class="eyebrow mb-2">{{ optional($school)->name ?? 'Aucune école assignée' }}</div>
        <h1 class="font-display font-extrabold text-3xl lg:text-[32px] text-stone-900 tracking-tight">
            Paramètres
        </h1>
        <p class="text-stone-500 text-sm mt-1.5">
            Configuration de votre établissement et des seuils d'alerte.
        </p>
    </div>

    @if ($savedFlash)
        <div class="rounded-xl border border-brand-200 bg-brand-50/60 px-5 py-3 text-sm text-brand-800">
            {{ $savedFlash }}
        </div>
    @endif

    @if (! $school)
        <div class="card p-6 text-sm text-stone-500">
            Aucune école n'est assignée à votre compte. Contactez l'équipe CareNest.
        </div>
    @else
    <form wire:submit="save" class="card p-6 lg:p-8 space-y-6">

        {{-- Alert threshold --}}
        <div>
            <label for="alertThreshold" class="block text-sm font-semibold text-stone-900 mb-1">
                Seuil d'alerte sur le score climat
            </label>
            <p class="text-xs text-stone-500 mb-3">
                Un enfant dont le score descend sous ce seuil est marqué « à suivre ».
            </p>
            <div class="flex items-center gap-3">
                <input id="alertThreshold" type="number" min="0" max="100"
                       wire:model="alertThreshold"
                       class="w-32 px-3 py-2 rounded-lg border border-stone-200 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 outline-none text-sm">
                <span class="text-stone-400 text-sm">/ 100</span>
            </div>
            @error('alertThreshold') <p class="text-xs text-red-600 mt-1.5">{{ $message }}</p> @enderror
        </div>

        {{-- Email notifications --}}
        <div>
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" wire:model="emailNotifications"
                       class="mt-0.5 rounded text-brand-700 focus:ring-brand-500">
                <span>
                    <span class="block text-sm font-semibold text-stone-900">Notifications email</span>
                    <span class="block text-xs text-stone-500 mt-0.5">
                        Recevoir un email pour les alertes critiques.
                    </span>
                </span>
            </label>
        </div>

        {{-- Language --}}
        <div>
            <label for="language" class="block text-sm font-semibold text-stone-900 mb-1">
                Langue de l'interface enfant
            </label>
            <select id="language" wire:model="language"
                    class="w-full max-w-xs px-3 py-2 rounded-lg border border-stone-200 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 outline-none text-sm">
                <option value="fr">Français</option>
                <option value="ar">العربية</option>
                <option value="en">English</option>
            </select>
            @error('language') <p class="text-xs text-red-600 mt-1.5">{{ $message }}</p> @enderror
        </div>

        <div class="pt-4 border-t border-stone-100 flex items-center justify-between">
            <a href="{{ route('dashboard') }}" class="btn-ghost btn-sm">Retour au tableau de bord</a>
            <button type="submit" class="btn-primary btn-sm">Enregistrer</button>
        </div>
    </form>
    @endif

</div>
