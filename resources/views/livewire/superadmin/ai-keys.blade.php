<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">Clés d'IA</h1>
        <p class="mt-1 text-sm text-stone-600 max-w-2xl">
            Collez la clé d'un fournisseur puis enregistrez. Elle est chiffrée et ne s'affiche plus jamais :
            seuls ses 4 derniers caractères restent visibles. Les conversations utilisent Gemini si sa clé existe,
            sinon GPT, sinon Claude. La double vérification des alertes utilise un autre fournisseur que celui des
            conversations : il faut donc deux clés pour qu'elle fonctionne.
        </p>
    </div>

    @if ($flash)
        <div class="rounded-lg bg-brand-50 text-brand-900 px-4 py-3 text-sm" role="status">{{ $flash }}</div>
    @endif

    @if ($keyCount < 2)
        <div class="rounded-lg bg-amber-50 text-amber-900 px-4 py-3 text-sm ring-1 ring-inset ring-amber-300/40">
            {{ $keyCount === 0 ? 'Aucune clé : les conversations ne peuvent pas fonctionner.' : 'Une seule clé : la double vérification des alertes est indisponible.' }}
        </div>
    @endif

    <div class="grid gap-4 md:grid-cols-3">
        @foreach ($rows as $row)
            <section class="card-padded flex flex-col gap-4" wire:key="key-{{ $row['provider'] }}">
                <div class="flex items-start justify-between gap-2">
                    <h2 class="font-semibold">{{ $row['label'] }}</h2>
                    @if ($row['used'])
                        <span class="badge badge-success">Conversations</span>
                    @endif
                </div>

                <div class="text-sm text-stone-600 min-h-[3.5rem]">
                    @if ($row['source'] === 'base')
                        <p>Clé enregistrée, se terminant par <span class="font-mono font-semibold text-stone-900">…{{ $row['credential']->last4 }}</span></p>
                        <p class="text-xs text-stone-500 mt-1">
                            Modifiée le {{ $row['credential']->updated_at->timezone(config('app.timezone'))->format('d/m/Y à H:i') }}
                            @if ($row['credential']->updatedBy) par {{ $row['credential']->updatedBy->name }} @endif
                        </p>
                    @elseif ($row['source'] === 'serveur')
                        <p>Clé du fichier de configuration du serveur.</p>
                    @else
                        <p>Aucune clé.</p>
                    @endif
                </div>

                <form wire:submit="save('{{ $row['provider'] }}')" class="flex flex-col gap-2 mt-auto">
                    <label for="key-{{ $row['provider'] }}" class="label">Nouvelle clé</label>
                    <input id="key-{{ $row['provider'] }}" type="password" autocomplete="off" spellcheck="false"
                           wire:model="inputs.{{ $row['provider'] }}" class="input font-mono" placeholder="Collez la clé ici">
                    @error('inputs.'.$row['provider'])
                        <p class="text-sm text-red-700" role="alert">{{ $message }}</p>
                    @enderror
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled">Enregistrer</button>
                </form>

                @if ($row['source'] === 'base')
                    <button type="button" class="btn-danger-ghost btn-sm"
                            wire:click="remove('{{ $row['provider'] }}')"
                            wire:confirm="Retirer cette clé ? L'application reprendra celle du fichier du serveur s'il y en a une.">
                        Retirer la clé
                    </button>
                @endif
            </section>
        @endforeach
    </div>
</div>
