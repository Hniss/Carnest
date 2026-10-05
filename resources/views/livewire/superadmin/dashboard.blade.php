<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Tableau de bord</h1>
            <p class="mt-1 text-sm text-stone-600 max-w-2xl">Chiffres par école, sans aucun nom d'élève ni conversation. Les jetons échangés mesurent le texte des conversations, pas la facture du fournisseur d'IA.</p>
        </div>
        <div class="sm:w-56">
            <label for="period" class="sr-only">Période</label>
            <select id="period" wire:model.live="period" class="input">
                @foreach (\App\Livewire\SuperAdmin\Dashboard::PERIODS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="card-padded"><p class="eyebrow">Sessions</p><p class="metric text-2xl mt-1">{{ number_format($totals['sessions'], 0, ',', ' ') }}</p></div>
        <div class="card-padded"><p class="eyebrow">Élèves actifs</p><p class="metric text-2xl mt-1">{{ number_format($totals['pupils'], 0, ',', ' ') }}</p></div>
        <div class="card-padded"><p class="eyebrow">Jetons échangés</p><p class="metric text-2xl mt-1">{{ number_format($totals['tokens'], 0, ',', ' ') }}</p></div>
        <div class="card-padded"><p class="eyebrow">Jours au plafond (7 j)</p><p class="metric text-2xl mt-1">{{ number_format($totals['cap_days'], 0, ',', ' ') }}</p></div>
    </div>

    <div class="space-y-3">
        @forelse ($rows as $r)
            <section class="card-padded" wire:key="row-{{ $r['school_id'] }}">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1 mb-3">
                    <h2 class="font-semibold">
                        <a href="{{ route('superadmin.schools.show', $r['school_id']) }}" class="hover:text-brand-800">{{ $r['name'] }}</a>
                    </h2>
                    <span class="text-sm text-stone-500">{{ $r['city'] }} · plafond {{ number_format($r['cap'], 0, ',', ' ') }} jetons par élève et par jour</span>
                </div>
                <dl class="grid grid-cols-2 md:grid-cols-5 gap-3 text-sm">
                    <div><dt class="text-stone-500">Sessions</dt><dd class="metric text-lg">{{ $r['sessions'] }}</dd></div>
                    <div><dt class="text-stone-500">Élèves actifs</dt><dd class="metric text-lg">{{ $r['pupils'] }}</dd></div>
                    <div><dt class="text-stone-500">Jetons échangés</dt><dd class="metric text-lg">{{ number_format($r['tokens'], 0, ',', ' ') }}</dd></div>
                    <div><dt class="text-stone-500">Jours au plafond (7 j)</dt><dd class="metric text-lg">{{ $r['cap_days'] }}</dd></div>
                    <div><dt class="text-stone-500">Élèves concernés (7 j)</dt><dd class="metric text-lg">{{ $r['cap_pupils'] }}</dd></div>
                </dl>
            </section>
        @empty
            <p class="text-sm text-stone-500">Aucune école.</p>
        @endforelse
    </div>
</div>
