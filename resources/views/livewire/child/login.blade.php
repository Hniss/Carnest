<div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-brand-50 via-stone-50 to-brand-100/40 p-6 relative overflow-hidden">

    {{-- Soft background shapes --}}
    <div class="absolute top-[-80px] left-[-80px] w-[360px] h-[360px] rounded-full bg-brand-200/30 blur-3xl" aria-hidden="true"></div>
    <div class="absolute bottom-[-100px] right-[-60px] w-[400px] h-[400px] rounded-full bg-brand-400/20 blur-3xl" aria-hidden="true"></div>

    <div class="relative w-full max-w-md">

        <div class="flex items-center justify-center gap-2.5 mb-8">
            <span class="w-10 h-10 rounded-xl bg-brand-700 text-white flex items-center justify-center shadow-sm">
                <x-icon name="leaf" size="22" />
            </span>
            <span class="font-display font-extrabold text-2xl text-stone-900 tracking-tight">CareNest</span>
        </div>

        <div class="bg-white border border-stone-200 rounded-2xl shadow-elevated p-8">
            <div class="text-center mb-7">
                <h1 class="font-display font-extrabold text-2xl text-stone-900">Salut&nbsp;! 👋</h1>
                <p class="text-sm text-stone-500 mt-1.5">Connecte-toi pour parler à Care.</p>
            </div>

            <form wire:submit="login" class="space-y-5">
                <div>
                    <label class="label">Ton e-mail</label>
                    <input wire:model="email" type="email" placeholder="toi@carenest.ma"
                           class="input input-lg" />
                    @error('email')
                        <p class="text-red-600 text-xs mt-1.5">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="label">Ton mot de passe</label>
                    <input wire:model="password" type="password" placeholder="••••••••"
                           class="input input-lg" />
                    @error('password')
                        <p class="text-red-600 text-xs mt-1.5">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="btn-primary btn-lg w-full" wire:loading.attr="disabled">
                    <span wire:loading.remove>Se connecter</span>
                    <span wire:loading>Connexion…</span>
                </button>
            </form>

            <div class="mt-7 rounded-lg bg-brand-50 border border-brand-100 px-4 py-3 text-xs">
                <div class="font-semibold text-brand-900 mb-0.5">Compte démo</div>
                <div class="text-brand-800 font-mono">yassine@carenest.ma · demo123</div>
            </div>
        </div>

        <div class="text-center mt-5">
            <a href="{{ route('login') }}" class="text-xs text-stone-500 hover:text-brand-700 transition-colors">
                Espace administrateur →
            </a>
        </div>
    </div>
</div>
