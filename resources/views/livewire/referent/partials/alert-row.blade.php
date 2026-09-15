@php
    use App\Enums\AlertType;
    use App\Support\Humanize;
    $type = AlertType::tryFrom((string) $alert->type);
    $signalLabel = $type === AlertType::Harcelement
        ? 'Signal détecté : situation potentiellement liée au harcèlement'
        : 'Signal détecté : ' . mb_strtolower(AlertType::labelFor($alert->type));
@endphp
<a href="{{ route('referent.alerts.show', $alert) }}" wire:navigate
   class="px-6 py-4 flex flex-wrap sm:flex-nowrap items-center gap-x-4 gap-y-2 hover:bg-stone-50/60 transition-colors">
    <x-level-badge :level="$alert->level" class="shrink-0" />
    <div class="flex-1 min-w-0">
        <div class="font-medium text-stone-900 truncate">{{ $alert->child?->name }}</div>
        <div class="text-xs text-stone-500 mt-0.5 truncate">
            {{ $alert->child?->classe }} · {{ $signalLabel }}
            @if ($alert->reopened_from_id) · <span class="text-orange-700 font-medium">Réouverture</span> @endif
        </div>
    </div>
    @if (! empty($waiting))
        <span class="inline-flex items-center gap-1.5 text-xs text-stone-600 shrink-0 basis-full sm:basis-auto pl-[calc(theme(spacing.4))] sm:pl-0">
            <x-icon name="clock" size="14" />
            en attente depuis {{ Humanize::waitingSince($alert->created_at) }}
        </span>
    @else
        <x-stage-badge :stage="$alert->stage_current ?? $alert->currentStage()" class="shrink-0" />
    @endif
    <x-icon name="chevron-right" size="14" class="text-stone-400 shrink-0 hidden sm:block" />
</a>
