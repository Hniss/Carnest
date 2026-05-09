<div class="min-h-screen grid lg:grid-cols-2 bg-stone-50">

    {{-- Left: branding bienveillant pour l'enfant --}}
    <div class="hidden lg:flex relative overflow-hidden bg-brand-700 text-white p-12 flex-col">
        {{-- Cercles décoratifs subtils --}}
        <div class="absolute inset-0 opacity-10" aria-hidden="true">
            <svg class="w-full h-full" viewBox="0 0 400 600" preserveAspectRatio="xMidYMid slice" fill="none">
                <circle cx="80"  cy="120" r="180" stroke="white" stroke-width="0.5" />
                <circle cx="320" cy="480" r="240" stroke="white" stroke-width="0.5" />
                <circle cx="200" cy="300" r="100" stroke="white" stroke-width="0.5" />
            </svg>
        </div>

        {{-- Halos doux --}}
        <div class="absolute top-[-80px] left-[-80px] w-[360px] h-[360px] rounded-full bg-brand-400/15 blur-3xl" aria-hidden="true"></div>
        <div class="absolute bottom-[-100px] right-[-60px] w-[400px] h-[400px] rounded-full bg-brand-300/10 blur-3xl" aria-hidden="true"></div>

        <a href="/" class="relative inline-flex items-center">
            <x-carenest-logo variant="full-white" class="h-10 w-auto" />
        </a>

        <div class="relative mt-auto max-w-md">
            <p class="eyebrow text-brand-200 mb-3">Pour toi</p>
            <h2 class="font-display font-extrabold text-3xl leading-tight text-white mb-4">
                Tu n'as pas besoin d'avoir les bons mots.
            </h2>
            <p class="text-brand-50 text-[15px] leading-relaxed">
                Dis juste ce que tu ressens, comme tu peux. Care est là pour t'écouter avec douceur. 🌿
            </p>
        </div>
    </div>

    {{-- Right: form --}}
    <div class="flex flex-col items-center justify-center px-6 py-12 relative overflow-hidden">

        {{-- Soft background shapes (mobile only — desktop a déjà le panneau gauche) --}}
        <div class="lg:hidden absolute top-[-80px] left-[-80px] w-[300px] h-[300px] rounded-full bg-brand-200/30 blur-3xl" aria-hidden="true"></div>
        <div class="lg:hidden absolute bottom-[-100px] right-[-60px] w-[340px] h-[340px] rounded-full bg-brand-400/20 blur-3xl" aria-hidden="true"></div>

        <div class="relative w-full max-w-[400px]">
            {{-- Logo mobile-only --}}
            <div class="lg:hidden mb-8 flex items-center justify-center">
                <x-carenest-logo variant="full" class="h-12 w-auto" />
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

</div>
