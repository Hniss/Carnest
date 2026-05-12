<?php
namespace App\Livewire\Child;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Rule;
use Livewire\Component;

#[Layout('layouts.child')]
class Login extends Component
{
    #[Rule('required|email')]
    public string $email = '';

    #[Rule('required|min:6')]
    public string $password = '';

    public function login(): void
    {
        $this->validate();

        if (! Auth::guard('child')->attempt(['email' => $this->email, 'password' => $this->password])) {
            $this->addError('email', 'Identifiants incorrects.');
            return;
        }

        $this->redirect(route('child.chat'), navigate: true);
    }

    public function render()
    {
        return view('livewire.child.login');
    }
}
