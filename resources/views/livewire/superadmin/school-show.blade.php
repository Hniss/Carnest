<div class="space-y-6">
    <div>
        <a href="{{ route('superadmin.schools') }}" class="text-sm text-brand-700 hover:text-brand-900">&larr; Toutes les écoles</a>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight">{{ $school->name }}</h1>
        <p class="mt-1 text-sm text-stone-600">{{ $school->city }} · {{ $pupils }} élève{{ $pupils > 1 ? 's' : '' }} actif{{ $pupils > 1 ? 's' : '' }}</p>
    </div>

    @if ($flash)
        <div class="rounded-lg bg-brand-50 text-brand-900 px-4 py-3 text-sm" role="status">{{ $flash }}</div>
    @endif

    <section class="card-padded space-y-4">
        <div>
            <h2 class="font-semibold">Destinataires des e-mails d'alerte</h2>
            <p class="text-sm text-stone-600 mt-1">Ces adresses reçoivent les mêmes e-mails d'alerte que le référent, sans aucun nom d'élève.</p>
        </div>

        <ul class="divide-y divide-stone-100">
            @forelse ($recipients as $r)
                <li class="flex items-center justify-between gap-3 py-2" wire:key="rec-{{ $r->id }}">
                    <span class="text-sm break-all">{{ $r->email }}</span>
                    <button type="button" class="btn-danger-ghost btn-sm shrink-0" wire:click="deleteRecipient({{ $r->id }})"
                            wire:confirm="Retirer {{ $r->email }} des destinataires d'alerte ?">Retirer</button>
                </li>
            @empty
                <li class="py-2 text-sm text-stone-500">Aucune adresse : seul le référent reçoit les e-mails d'alerte.</li>
            @endforelse
        </ul>

        <form wire:submit="addRecipient" class="flex flex-col sm:flex-row gap-2 sm:items-start">
            <div class="flex-1">
                <label for="recipientEmail" class="sr-only">Adresse e-mail</label>
                <input id="recipientEmail" type="email" wire:model="recipientEmail" class="input" placeholder="adresse@ecole.ma">
                @error('recipientEmail') <p class="text-sm text-red-700 mt-1" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn-primary">Ajouter</button>
        </form>
    </section>
</div>
