<?php

namespace App\Livewire\ParentSpace;

use App\Livewire\Concerns\ResolvesParentAlerts;
use App\Services\Audit;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Phase pilote (hp-v2nf) — détail d'une alerte pour le parent : résumé (déjà nettoyé des
 * prénoms de tiers), type, niveau, date et invitation à contacter le référent. Jamais un
 * message de l'enfant. Lecture seule : le parent ne qualifie ni ne clôt une alerte.
 */
#[Layout('layouts.parent')]
class AlertShow extends Component
{
    use ResolvesParentAlerts;

    #[Locked]
    public int $alertId;

    public function mount(int $alert): void
    {
        $model = $this->ownAlert($alert);
        $this->alertId = $model->id;
        Audit::log('parent.alert.view', $model);
    }

    public function render()
    {
        return view('livewire.parent-space.alert-show', [
            'alert' => $this->ownAlert($this->alertId),
        ]);
    }
}
