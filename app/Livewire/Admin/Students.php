<?php

namespace App\Livewire\Admin;

use App\Livewire\Forms\ChildForm;
use App\Models\Child;
use App\Models\School;
use App\Services\Audit;
use App\Services\ParentAccountProvisioner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Gestion administrative des élèves (lot 1 §4 + §6) : liste paginée avec
 * statut de consentement, création (avec compte parent + consentement
 * horodaté), modification, désactivation / réactivation.
 */
#[Layout('layouts.app')]
class Students extends Component
{
    use WithPagination;

    public ChildForm $form;

    #[Url] public string $search = '';
    #[Url] public string $statut = '';

    public bool $showForm = false;
    public ?string $flash = null;

    protected School $school;

    public function mount(): void
    {
        $this->school = $this->resolveSchool();
    }

    public function hydrate(): void
    {
        $this->school = $this->resolveSchool();
    }

    private function resolveSchool(): School
    {
        $school = Auth::user()->schools()->first();
        abort_unless($school, 403);
        return $school;
    }

    private function own(int $id): Child
    {
        $child = Child::find($id);
        abort_unless($child, 404);
        abort_unless($child->school_id === $this->school->id, 403);
        return $child;
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'statut'], true)) {
            $this->resetPage();
        }
    }

    // ── Création / modification ───────────────────────────────────────────

    public function openCreate(): void
    {
        $this->form->reset();
        $this->form->relation = 'mere';
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        $this->form->fillFromChild($this->own($id));
        $this->showForm = true;
    }

    public function save(ParentAccountProvisioner $provisioner): void
    {
        $this->form->validate();

        $child = DB::transaction(function () use ($provisioner) {
            $isNew = $this->form->id === null;
            $child = $isNew ? new Child(['school_id' => $this->school->id]) : $this->own($this->form->id);

            $child->fill([
                'name'       => trim($this->form->name),
                'classe'     => trim($this->form->classe),
                'birth_date' => $this->form->birth_date,
                'gender'     => $this->form->gender !== '' ? $this->form->gender : null,
                'email'      => Str::lower(trim($this->form->email)),
            ]);
            if ($isNew) {
                $child->age = Carbon::parse($this->form->birth_date)->age;
                $child->password = Hash::make($this->form->password !== '' ? $this->form->password : Str::password(16));
            } elseif ($this->form->password !== '') {
                $child->password = Hash::make($this->form->password);
            }
            $child->save();

            if ($this->form->parent_email !== '') {
                $parent = $provisioner->findOrCreateParent($this->form->parent_email, $this->form->parent_name ?: null);
                $provisioner->link($parent, $child, $this->form->relation, $this->form->consent, $this->school, request()->ip());
            } elseif ($isNew) {
                $child->forceFill(['deactivated_at' => now()])->save();
            }

            Audit::log($isNew ? 'admin.child.create' : 'admin.child.update', $child);

            return $child;
        });

        $this->showForm = false;
        $this->flash = $child->deactivated_at
            ? 'Élève enregistré. Sans consentement parental, le compte reste désactivé.'
            : 'Élève enregistré.';
    }

    public function deactivate(int $id): void
    {
        $child = $this->own($id);
        $child->forceFill(['deactivated_at' => now()])->save();
        Audit::log('admin.child.deactivate', $child);
        $this->flash = 'Compte désactivé.';
    }

    public function reactivate(int $id): void
    {
        $child = $this->own($id);
        if (! $child->hasActiveConsent()) {
            $this->addError('reactivate', 'Réactivation impossible sans consentement parental actif.');
            return;
        }
        $child->forceFill(['deactivated_at' => null])->save();
        Audit::log('admin.child.reactivate', $child);
        $this->flash = 'Compte réactivé.';
    }

    public function render()
    {
        $children = Child::query()
            ->where('school_id', $this->school->id)
            ->with('parents:id,name,email')
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%' . $this->search . '%'))
            ->when($this->statut === 'actif', fn ($q) => $q->whereNull('deactivated_at'))
            ->when($this->statut === 'desactive', fn ($q) => $q->whereNotNull('deactivated_at'))
            ->when($this->statut === 'sans_consentement', fn ($q) => $q->whereDoesntHave('consentingParents'))
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.admin.students', [
            'school'   => $this->school,
            'children' => $children,
        ]);
    }
}
