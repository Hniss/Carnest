@php use App\Support\Humanize; @endphp
<div class="space-y-6">
    <div>
        <h1 class="font-display font-extrabold text-2xl sm:text-3xl text-stone-900 tracking-tight">Journal</h1>
        <p class="text-stone-500 text-sm mt-1.5">Les grandes étapes de l'accompagnement de {{ $child->name }} à l'école.</p>
    </div>

    @include('livewire.parent-space.partials.child-switch')

    <section class="card p-5 sm:p-7">
        @if ($events->isEmpty())
            <div class="text-center py-6">
                <div class="mx-auto w-12 h-12 rounded-full bg-brand-50 text-brand-700 flex items-center justify-center mb-3">
                    <x-icon name="calendar" size="22" />
                </div>
                <p class="text-sm text-stone-500">Aucun événement pour le moment. Tout va bien de ce côté.</p>
            </div>
        @else
            <ol class="relative border-l border-stone-200 ml-3 space-y-6">
                @foreach ($events as $e)
                    <li class="pl-6">
                        <span class="absolute -left-[13px] w-6 h-6 rounded-full bg-brand-50 text-brand-700 border border-white flex items-center justify-center">
                            <x-icon :name="$e['icon']" size="12" />
                        </span>
                        <div class="text-xs text-stone-500">{{ Humanize::dateTime($e['at']) }}</div>
                        <div class="text-[15px] text-stone-900 font-medium mt-0.5">{{ $e['label'] }}</div>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
</div>
