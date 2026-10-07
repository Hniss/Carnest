@php use App\Support\Humanize; use App\Enums\AlertType; @endphp
<div class="space-y-6">
    <div>
        <h1 class="font-display font-extrabold text-2xl sm:text-3xl text-stone-900 tracking-tight">Alertes</h1>
        <p class="text-stone-500 text-sm mt-1.5">Les alertes importantes que CareNest vous a transmises. Le référent de l'école est prévenu en même temps que vous.</p>
    </div>

    <section class="card overflow-hidden">
        @if ($alerts->isEmpty())
            <div class="text-center py-10 px-5">
                <div class="mx-auto w-12 h-12 rounded-full bg-brand-50 text-brand-700 flex items-center justify-center mb-3">
                    <x-icon name="check-circle" size="22" />
                </div>
                <p class="text-sm text-stone-500">Aucune alerte pour le moment.</p>
            </div>
        @else
            <ul class="divide-y divide-stone-100">
                @foreach ($alerts as $a)
                    <li>
                        <a href="{{ route('parent.alerts.show', $a->id) }}" wire:navigate
                           class="flex items-center gap-4 px-5 py-4 hover:bg-stone-50/60 focus-visible:bg-stone-50/60">
                            <div class="w-10 h-10 rounded-xl bg-red-50 text-red-700 flex items-center justify-center shrink-0">
                                <x-icon name="alert-triangle" size="18" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-stone-900">{{ AlertType::labelFor($a->type) }}</span>
                                    <x-level-badge :level="$a->level" />
                                </div>
                                <div class="text-xs text-stone-500 mt-1">
                                    @if ($manyChildren) {{ $a->child?->name }} · @endif{{ Humanize::dateTime($a->created_at) }}
                                </div>
                            </div>
                            <x-icon name="chevron-right" size="18" class="text-stone-400 shrink-0" />
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
