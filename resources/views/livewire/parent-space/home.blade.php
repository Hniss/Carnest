@php use App\Support\Humanize; @endphp
<div class="space-y-6">
    <div>
        <div class="eyebrow mb-2">Bonjour {{ auth()->user()->name }}</div>
        <h1 class="font-display font-extrabold text-2xl sm:text-3xl text-stone-900 tracking-tight">Le point de l'école</h1>
        <p class="text-stone-500 text-sm mt-1.5">Ce que l'école souhaite partager avec vous, en toute simplicité.</p>
    </div>

    @include('livewire.parent-space.partials.child-switch')

    @if ($synthesis)
        <section class="card p-5 sm:p-7 space-y-5">
            <div class="flex items-center justify-between gap-3">
                <div class="eyebrow">Information du {{ Humanize::date($synthesis->sent_at) }}</div>
                <span class="badge badge-success">Au sujet {{ \App\Support\Humanize::de($child->name) }}</span>
            </div>
            @foreach ([
                ['icon' => 'sparkles',     'title' => 'Ce que CareNest a identifié', 'text' => $synthesis->identified],
                ['icon' => 'check-circle', 'title' => 'Ce que l\'école a fait',      'text' => $synthesis->school_did],
                ['icon' => 'calendar',     'title' => 'Ce que l\'école propose',     'text' => $synthesis->school_proposes],
                ['icon' => 'heart',        'title' => 'Ce que vous pouvez faire',    'text' => $synthesis->parent_can],
            ] as $b)
                <div class="flex gap-4">
                    <div class="w-10 h-10 rounded-xl bg-brand-50 text-brand-800 flex items-center justify-center shrink-0">
                        <x-icon :name="$b['icon']" size="18" />
                    </div>
                    <div class="min-w-0">
                        <h2 class="font-semibold text-stone-900">{{ $b['title'] }}</h2>
                        <p class="text-[15px] text-stone-700 leading-relaxed mt-1">{{ $b['text'] }}</p>
                    </div>
                </div>
            @endforeach
            <div class="pt-4 border-t border-stone-100 flex flex-col sm:flex-row gap-2">
                <a href="{{ route('parent.messages', ['enfant' => $child->id]) }}" wire:navigate class="btn-primary btn-sm">
                    <x-icon name="message-circle" size="14" /> Écrire au référent
                </a>
                <a href="{{ route('parent.journal', ['enfant' => $child->id]) }}" wire:navigate class="btn-ghost btn-sm">Voir le journal</a>
            </div>
        </section>
    @else
        <section class="card p-6 sm:p-8 text-center">
            <div class="mx-auto w-12 h-12 rounded-full bg-brand-50 text-brand-700 flex items-center justify-center mb-3">
                <x-icon name="heart" size="22" />
            </div>
            <h2 class="font-semibold text-stone-900">Aucune information pour {{ $child->name }} pour le moment</h2>
            <p class="text-sm text-stone-500 mt-1.5 max-w-md mx-auto">
                L'école vous écrira ici si elle souhaite partager un point. Vous pouvez aussi lui écrire à tout moment.
            </p>
            <a href="{{ route('parent.messages', ['enfant' => $child->id]) }}" wire:navigate class="btn-ghost btn-sm mt-4">
                <x-icon name="message-circle" size="14" /> Écrire au référent
            </a>
        </section>
    @endif

    @if ($previous->isNotEmpty())
        <section class="card overflow-hidden">
            <div class="px-5 py-3.5 border-b border-stone-100 text-sm font-semibold text-stone-900">Informations précédentes</div>
            <ul class="divide-y divide-stone-100">
                @foreach ($previous as $p)
                    <li class="px-5 py-3.5">
                        <div class="text-xs text-stone-500">{{ Humanize::date($p->sent_at) }}</div>
                        <p class="text-sm text-stone-700 mt-0.5">{{ $p->school_did }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
