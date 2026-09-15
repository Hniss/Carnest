@php use App\Models\ParentChild; use App\Support\Humanize; @endphp
<div class="space-y-6">
    <div>
        <h1 class="font-display font-extrabold text-2xl sm:text-3xl text-stone-900 tracking-tight">Consentement</h1>
        <p class="text-stone-500 text-sm mt-1.5">Votre accord pour le compte CareNest de votre enfant, conformément à la loi 09-08.</p>
    </div>

    @if ($flash)
        <div class="rounded-xl border border-brand-200 bg-brand-50/60 px-5 py-3 text-sm text-brand-800" role="status">{{ $flash }}</div>
    @endif

    @foreach ($rows as $r)
        <section class="card p-5 sm:p-7 space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-semibold text-stone-900 flex-1">{{ $r['child']->name }} · {{ $r['child']->school->name }}</h2>
                @if ($r['active'])
                    <span class="badge badge-success">Consentement actif</span>
                @elseif ($r['pivot']->consent_withdrawn_at)
                    <span class="badge badge-danger">Consentement retiré</span>
                @else
                    <span class="badge badge-warning">Consentement non recueilli</span>
                @endif
            </div>

            <blockquote class="rounded-xl bg-stone-50 border border-stone-200 px-4 py-3 text-[15px] text-stone-800 leading-relaxed">
                « {{ $r['text'] }} »
            </blockquote>

            <dl class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                <div><dt class="text-stone-500">Relation</dt><dd class="text-stone-900 font-medium">{{ ParentChild::relationLabel($r['pivot']->relation) }}</dd></div>
                <div><dt class="text-stone-500">Date du consentement</dt><dd class="text-stone-900 font-medium">{{ Humanize::dateTime($r['pivot']->consent_timestamp) }}</dd></div>
                <div><dt class="text-stone-500">Adresse IP enregistrée</dt><dd class="text-stone-900 font-medium font-mono text-xs">{{ $r['pivot']->consent_ip ?? '—' }}</dd></div>
                @if ($r['pivot']->consent_withdrawn_at)
                    <div class="sm:col-span-3"><dt class="text-stone-500">Retiré le</dt><dd class="text-stone-900 font-medium">{{ Humanize::dateTime($r['pivot']->consent_withdrawn_at) }}</dd></div>
                @endif
            </dl>

            @if ($r['active'])
                <div class="pt-4 border-t border-stone-100">
                    <p class="text-xs text-stone-500 mb-3">
                        Retirer votre consentement désactive immédiatement le compte CareNest de {{ $r['child']->name }} et en informe l'école.
                    </p>
                    <button type="button" wire:click="withdraw({{ $r['child']->id }})"
                            wire:confirm="Retirer votre consentement pour {{ $r['child']->name }} ? Le compte sera désactivé immédiatement."
                            class="btn-danger-ghost btn-sm">
                        Retirer mon consentement
                    </button>
                </div>
            @endif
        </section>
    @endforeach
</div>
