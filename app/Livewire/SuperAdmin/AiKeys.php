<?php

namespace App\Livewire\SuperAdmin;

use App\Livewire\Concerns\RequiresSuperAdmin;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.superadmin')]
class AiKeys extends Component
{
    use RequiresSuperAdmin;

    public function render()
    {
        return view('livewire.superadmin.ai-keys');
    }
}
