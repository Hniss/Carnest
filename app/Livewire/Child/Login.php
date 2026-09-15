<?php
namespace App\Livewire\Child;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Rule;
use Livewire\Component;

#[Layout('layouts.child')]
class Login extends Component
{
    /** D10 (v3) — tentatives de connexion par minute (email + IP). */
    public const MAX_ATTEMPTS_PER_MINUTE = 10;

    #[Rule('required|email')]
    public string $email = '';

    #[Rule('required|min:6')]
    public string $password = '';

    public function login(): void
    {
        $this->validate();

        // Le middleware throttle de la route couvre la page ; les soumissions
        // Livewire passent par /livewire/update, d'où ce second garde-fou.
        $key = $this->throttleKey();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS_PER_MINUTE)) {
            $seconds = RateLimiter::availableIn($key);
            $this->addError('email', "Trop de tentatives. Réessaie dans {$seconds} secondes.");
            return;
        }

        if (! Auth::guard('child')->attempt(['email' => $this->email, 'password' => $this->password])) {
            RateLimiter::hit($key, 60);
            $this->addError('email', 'Identifiants incorrects.');
            return;
        }

        // Lot 1 §4 — compte enfant désactivé (sans consentement, retrait, décision admin) :
        // refus avec un message neutre, aucune indication sur la cause.
        if (Auth::guard('child')->user()->isDeactivated()) {
            Auth::guard('child')->logout();
            RateLimiter::hit($key, 60);
            $this->addError('email', 'Identifiants incorrects.');
            return;
        }

        RateLimiter::clear($key);

        $this->redirect(route('child.chat'), navigate: true);
    }

    private function throttleKey(): string
    {
        return 'login-child:' . Str::lower(trim($this->email)) . '|' . request()->ip();
    }

    public function render()
    {
        return view('livewire.child.login');
    }
}
