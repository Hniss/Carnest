@php use App\Support\Humanize; use App\Enums\AlertType; @endphp
<div class="space-y-6">
    <a href="{{ route('parent.alerts') }}" wire:navigate class="btn-ghost btn-sm">
        <x-icon name="arrow-left" size="14" /> Toutes les alertes
    </a>

    <div>
        <div class="eyebrow mb-2">Alerte du {{ Humanize::dateTime($alert->created_at) }}</div>
        <h1 class="font-display font-extrabold text-2xl sm:text-3xl text-stone-900 tracking-tight">{{ AlertType::labelFor($alert->type) }}</h1>
        <div class="flex flex-wrap items-center gap-2 mt-3">
            <x-level-badge :level="$alert->level" />
            <span class="badge badge-neutral">Au sujet {{ Humanize::de($alert->child?->name ?? 'votre enfant') }}</span>
        </div>
    </div>

    <section class="card p-5 sm:p-7 space-y-5">
        <div class="flex gap-4">
            <div class="w-10 h-10 rounded-xl bg-brand-50 text-brand-800 flex items-center justify-center shrink-0">
                <x-icon name="sparkles" size="18" />
            </div>
            <div class="min-w-0">
                <h2 class="font-semibold text-stone-900">Ce que CareNest a relevé</h2>
                @if (filled($alert->summary))
                    <p class="text-[15px] text-stone-700 leading-relaxed mt-1">{{ $alert->summary }}</p>
                @else
                    <p class="text-[15px] text-stone-500 leading-relaxed mt-1">Le résumé est en cours de préparation. Revenez dans quelques instants.</p>
                @endif
            </div>
        </div>

        <div class="flex gap-4 pt-5 border-t border-stone-100">
            <div class="w-10 h-10 rounded-xl bg-red-50 text-red-700 flex items-center justify-center shrink-0">
                <x-icon name="heart" size="18" />
            </div>
            <div class="min-w-0">
                <h2 class="font-semibold text-stone-900">Ce que vous pouvez faire</h2>
                <p class="text-[15px] text-stone-700 leading-relaxed mt-1">Contactez le référent de l'école dès que possible pour faire le point ensemble.</p>
            </div>
        </div>

        <div class="pt-4 border-t border-stone-100">
            <a href="{{ route('parent.messages', ['enfant' => $alert->child_id]) }}" wire:navigate class="btn-primary btn-sm">
                <x-icon name="message-circle" size="14" /> Écrire au référent
            </a>
        </div>
    </section>
</div>
