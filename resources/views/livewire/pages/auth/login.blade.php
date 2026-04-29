<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    public function login(): void
    {
        $this->validate();
        $this->form->authenticate();
        Session::regenerate();
        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    <div class="mb-8">
        <div class="eyebrow mb-2">Espace administration</div>
        <h1 class="font-display font-extrabold text-2xl text-stone-900 tracking-tight">
            Connexion
        </h1>
        <p class="text-sm text-stone-500 mt-1.5">
            Accédez au suivi émotionnel de votre établissement.
        </p>
    </div>

    @if (session('status'))
        <div class="mb-5 rounded-lg bg-brand-50 border border-brand-100 px-4 py-3 text-sm text-brand-900">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit="login" class="space-y-5">
        <div>
            <label for="email" class="label">Adresse e-mail</label>
            <div class="relative">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-stone-400 pointer-events-none">
                    <x-icon name="mail" size="16" />
                </span>
                <input wire:model="form.email" id="email" type="email" required autofocus autocomplete="username"
                       class="input pl-9" placeholder="vous@ecole.ma" />
            </div>
            @error('form.email')
                <p class="text-red-600 text-xs mt-1.5" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <div class="flex items-center justify-between mb-1.5">
                <label for="password" class="label mb-0">Mot de passe</label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" wire:navigate
                       class="text-xs font-medium text-brand-700 hover:text-brand-900">
                        Oublié&nbsp;?
                    </a>
                @endif
            </div>
            <div class="relative">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-stone-400 pointer-events-none">
                    <x-icon name="lock" size="16" />
                </span>
                <input wire:model="form.password" id="password" type="password" required autocomplete="current-password"
                       class="input pl-9" placeholder="••••••••" />
            </div>
            @error('form.password')
                <p class="text-red-600 text-xs mt-1.5" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <label class="inline-flex items-center gap-2 text-sm text-stone-600 select-none">
            <input wire:model="form.remember" type="checkbox"
                   class="rounded border-stone-300 text-brand-700 focus:ring-brand-700/30" />
            Rester connecté
        </label>

        <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">Se connecter</span>
            <span wire:loading wire:target="login">Connexion…</span>
        </button>
    </form>

    <div class="mt-8 pt-6 border-t border-stone-100">
        <div class="rounded-lg bg-stone-50 border border-stone-200 px-4 py-3">
            <div class="eyebrow mb-1">Compte démo</div>
            <div class="text-xs text-stone-600 font-mono">admin@carenest.ma · admin123</div>
        </div>
        <div class="mt-5 text-center text-xs text-stone-400">
            Vous êtes un élève&nbsp;?
            <a href="{{ route('child.login') }}" class="text-brand-700 font-medium hover:text-brand-900">
                Accès élève
            </a>
        </div>
    </div>
</div>
