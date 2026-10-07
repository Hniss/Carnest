<?php

namespace App\Livewire\ParentSpace;

use App\Livewire\Concerns\ResolvesParentAlerts;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Phase pilote (hp-v2nf) — liste des alertes envoyées au parent, en lecture seule. */
#[Layout('layouts.parent')]
class Alerts extends Component
{
    use ResolvesParentAlerts;

    public function mount(): void
    {
        $this->parentUser();
    }

    public function render()
    {
        $parent = $this->parentUser();

        return view('livewire.parent-space.alerts', [
            'alerts'      => $this->parentAlertsQuery($parent)->with('child:id,name')->latest()->get(),
            'manyChildren'=> $parent->consentedChildren()->count() > 1,
        ]);
    }
}
