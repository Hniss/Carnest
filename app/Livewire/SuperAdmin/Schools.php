<?php

namespace App\Livewire\SuperAdmin;

use App\Livewire\Concerns\RequiresSuperAdmin;
use App\Models\School;
use App\Services\Audit;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Liste des écoles en lecture seule (navigation vers la fiche). Des chiffres, jamais de noms d'élèves. */
#[Layout('layouts.superadmin')]
class Schools extends Component
{
    use RequiresSuperAdmin;
    use WithPagination;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        Audit::log('superadmin.view.schools');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $term = trim($this->search);

        $schools = School::query()
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('city', 'like', "%{$term}%")))
            ->withCount([
                'children as pupils_count' => fn ($q) => $q->whereNull('deactivated_at'),
                'users as admins_count'    => fn ($q) => $q->where('users.role', 'admin')->whereNull('users.deactivated_at'),
                'alertRecipients as recipients_count',
            ])
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.superadmin.schools', ['schools' => $schools])->title('Écoles');
    }
}
