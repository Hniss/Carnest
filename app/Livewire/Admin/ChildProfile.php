<?php

namespace App\Livewire\Admin;

use App\Models\Child;
use App\Services\Audit;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Fiche ADMINISTRATIVE d'un élève (lot 1 §4) : identité, classe, date de
 * naissance, statut de compte, consentement parental, parents liés.
 * Plus aucune zone, alerte ni résumé IA côté administration : le suivi
 * individuel appartient au référent (`App\Livewire\Referent\StudentProfile`).
 *
 * Sécurité : route `auth` + `verified` + `role:admin`, puis cloisonnement école en mount().
 * Chaque ouverture est tracée dans audit_logs (`admin.child.view`).
 */
#[Layout('layouts.app')]
class ChildProfile extends Component
{
    public Child $child;

    public function mount(Child $child): void
    {
        $userSchoolIds = auth()->user()->schools()->pluck('schools.id');
        abort_unless($userSchoolIds->contains($child->school_id), 403);

        $this->child = $child;
        Audit::log('admin.child.view', $child);
    }

    public function render()
    {
        $this->child->load('parents');

        return view('livewire.admin.child-profile');
    }
}
