<div class="space-y-6">
    <div>
        <h1 class="font-display font-extrabold text-2xl sm:text-3xl text-stone-900 tracking-tight">Mes données</h1>
        <p class="text-stone-500 text-sm mt-1.5">Vous pouvez obtenir à tout moment une copie lisible des données concernant votre enfant.</p>
    </div>

    <section class="card p-5 sm:p-7 space-y-5">
        <div class="flex gap-4">
            <div class="w-10 h-10 rounded-xl bg-brand-50 text-brand-800 flex items-center justify-center shrink-0">
                <x-icon name="file-text" size="18" />
            </div>
            <div>
                <h2 class="font-semibold text-stone-900">Ce que contient l'export</h2>
                <ul class="text-sm text-stone-600 mt-2 space-y-1 list-disc pl-5">
                    <li>Les sessions d'écoute : dates, résumés et zones émotionnelles.</li>
                    <li>Les signaux détectés, avec leur libellé et leur niveau.</li>
                    <li>Le journal des étapes de l'accompagnement.</li>
                    <li>Les informations que l'école vous a transmises.</li>
                </ul>
                <p class="text-xs text-stone-500 mt-3">Fichier texte lisible, encodé en UTF-8. La demande est enregistrée dans le journal d'accès de l'école.</p>
            </div>
        </div>
        <div class="pt-4 border-t border-stone-100">
            <button type="button" wire:click="export" class="btn-primary" wire:loading.attr="disabled">
                <x-icon name="download" size="16" />
                <span wire:loading.remove wire:target="export">Demander l'export</span>
                <span wire:loading wire:target="export">Préparation…</span>
            </button>
        </div>
    </section>

    <section class="card p-5 sm:p-7">
        <h2 class="font-semibold text-stone-900 mb-2">Enfants concernés</h2>
        <ul class="text-sm text-stone-700 space-y-1">
            @foreach ($children as $c)
                <li class="flex items-center gap-2"><x-icon name="user-round" size="14" class="text-stone-400" /> {{ $c->name }} · {{ $c->classe }}</li>
            @endforeach
        </ul>
    </section>
</div>
