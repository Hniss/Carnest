<?php

namespace App\Livewire\Admin;

use App\Models\School;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Comptes école (lot 1 §4) : référent et administrateurs — créer, modifier,
 * désactiver / réactiver. Un seul référent actif par école (vérifié côté serveur).
 */
#[Layout('layouts.app')]
class Accounts extends Component
{
    public ?int $editingId = null;
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $role = 'referent';
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

    private function own(int $id): User
    {
        $user = User::find($id);
        abort_unless($user, 404);
        abort_unless($this->school->users()->where('users.id', $user->id)->exists(), 403);
        abort_if($user->isParent(), 403);
        return $user;
    }

    private function hasActiveReferent(?int $except = null): bool
    {
        return $this->school->users()
            ->wherePivot('role', 'referent')
            ->where('users.role', 'referent')
            ->whereNull('users.deactivated_at')
            ->when($except, fn ($q) => $q->where('users.id', '!=', $except))
            ->exists();
    }

    public function create(): void
    {
        $this->validate([
            'name'  => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
            'role'  => ['required', 'in:referent,admin'],
        ], [], ['name' => 'nom', 'email' => 'e-mail', 'phone' => 'téléphone', 'role' => 'rôle']);

        if ($this->role === 'referent' && $this->hasActiveReferent()) {
            $this->addError('role', 'Un référent actif existe déjà pour cette école. Désactivez-le avant d\'en créer un autre.');
            return;
        }

        $user = DB::transaction(function () {
            $user = User::create([
                'name'              => trim($this->name),
                'email'             => Str::lower(trim($this->email)),
                'phone'             => $this->phone !== '' ? trim($this->phone) : null,
                'password'          => Hash::make(Str::password(24)),
                'role'              => $this->role,
                'email_verified_at' => now(),
            ]);
            $this->school->users()->attach($user->id, ['role' => $this->role === 'referent' ? 'referent' : 'director']);
            return $user;
        });
        Password::broker()->sendResetLink(['email' => $user->email]);
        Audit::log('admin.account.create', $user, ['school_id' => $this->school->id]);

        $this->reset('name', 'email', 'phone');
        $this->role = 'referent';
        $this->flash = 'Compte créé. Un lien de définition du mot de passe a été envoyé.';
    }

    public function openEdit(int $id): void
    {
        $u = $this->own($id);
        $this->editingId = $u->id;
        $this->name  = $u->name;
        $this->email = $u->email;
        $this->phone = (string) $u->phone;
        $this->role  = $u->role;
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'name', 'email', 'phone');
        $this->role = 'referent';
    }

    public function update(): void
    {
        abort_unless($this->editingId, 422);
        $u = $this->own($this->editingId);

        $this->validate([
            'name'  => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($u->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role'  => ['required', 'in:referent,admin'],
        ], [], ['name' => 'nom', 'email' => 'e-mail', 'phone' => 'téléphone', 'role' => 'rôle']);

        if ($this->role === 'referent' && $u->deactivated_at === null && $this->hasActiveReferent($u->id)) {
            $this->addError('role', 'Un référent actif existe déjà pour cette école.');
            return;
        }

        DB::transaction(function () use ($u) {
            $u->update([
                'name'  => trim($this->name),
                'email' => Str::lower(trim($this->email)),
                'phone' => $this->phone !== '' ? trim($this->phone) : null,
                'role'  => $this->role,
            ]);
            $this->school->users()->updateExistingPivot($u->id, ['role' => $this->role === 'referent' ? 'referent' : 'director']);
        });
        Audit::log('admin.account.update', $u, ['school_id' => $this->school->id]);
        $this->cancelEdit();
        $this->flash = 'Compte mis à jour.';
    }

    public function deactivate(int $id): void
    {
        $u = $this->own($id);
        abort_if($u->id === Auth::id(), 422);
        $u->forceFill(['deactivated_at' => now()])->save();
        Audit::log('admin.account.deactivate', $u, ['school_id' => $this->school->id]);
        $this->flash = 'Compte désactivé.';
    }

    public function reactivate(int $id): void
    {
        $u = $this->own($id);
        if ($u->isReferent() && $this->hasActiveReferent($u->id)) {
            $this->addError('role', 'Un référent actif existe déjà pour cette école.');
            return;
        }
        $u->forceFill(['deactivated_at' => null])->save();
        Audit::log('admin.account.reactivate', $u, ['school_id' => $this->school->id]);
        $this->flash = 'Compte réactivé.';
    }

    public function render()
    {
        $accounts = $this->school->users()->where('users.role', '!=', 'parent')->orderBy('users.role')->orderBy('users.name')->get();

        return view('livewire.admin.accounts', [
            'school'   => $this->school,
            'accounts' => $accounts,
        ]);
    }
}
