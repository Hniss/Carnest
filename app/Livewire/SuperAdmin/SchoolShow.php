<?php

namespace App\Livewire\SuperAdmin;

use App\Livewire\Concerns\RequiresSuperAdmin;
use App\Models\School;
use App\Models\SchoolAlertRecipient;
use App\Models\User;
use App\Notifications\AccountEmailChanged;
use App\Services\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Fiche d'une école (lecture) : destinataires des e-mails d'alerte et comptes admin.
 * Aucun nom d'élève : seulement des chiffres.
 */
#[Layout('layouts.superadmin')]
class SchoolShow extends Component
{
    use RequiresSuperAdmin;

    #[Locked]
    public int $schoolId;

    public string $recipientEmail = '';

    #[Locked]
    public ?int $editingAdminId = null;

    public string $adminName = '';
    public string $adminEmail = '';
    public string $adminPhone = '';

    public ?string $warning = null;

    public ?string $flash = null;

    public function mount(School $school): void
    {
        $this->schoolId = $school->id;
        Audit::log('superadmin.view.school', $school, ['school_id' => $school->id]);
    }

    private function school(): School
    {
        return School::findOrFail($this->schoolId);
    }

    public function addRecipient(): void
    {
        $this->recipientEmail = Str::lower(trim($this->recipientEmail));

        $this->validate([
            'recipientEmail' => [
                'required', 'email', 'max:190',
                Rule::unique('school_alert_recipients', 'email')->where('school_id', $this->schoolId),
            ],
        ], [
            'recipientEmail.unique' => 'Cette adresse reçoit déjà les alertes de cette école.',
        ], ['recipientEmail' => 'adresse e-mail']);

        $recipient = SchoolAlertRecipient::create([
            'school_id'  => $this->schoolId,
            'email'      => $this->recipientEmail,
            'created_by' => Auth::id(),
        ]);
        Audit::log('superadmin.recipient.create', $recipient, ['school_id' => $this->schoolId]);

        $this->recipientEmail = '';
        $this->flash = 'Adresse ajoutée : elle recevra les mêmes e-mails d\'alerte que le référent.';
    }

    public function deleteRecipient(int $id): void
    {
        $recipient = SchoolAlertRecipient::where('school_id', $this->schoolId)->find($id);
        abort_if($recipient === null, 404);
        Audit::log('superadmin.recipient.delete', $recipient, ['school_id' => $this->schoolId]);
        $recipient->delete();

        $this->flash = 'Adresse retirée.';
    }

    /** Un compte admin RATTACHÉ à cette école, sinon 404 (jamais un référent, un parent ou un super-admin). */
    private function ownAdmin(int $id): User
    {
        $user = $this->school()->users()->where('users.role', 'admin')->whereKey($id)->first();
        abort_if($user === null, 404);

        return $user;
    }

    private function activeAdminCount(): int
    {
        return $this->school()->users()->where('users.role', 'admin')->whereNull('users.deactivated_at')->count();
    }

    public function createAdmin(): void
    {
        $this->adminEmail = Str::lower(trim($this->adminEmail));
        $this->validate([
            'adminName'  => ['required', 'string', 'max:150'],
            'adminEmail' => ['required', 'email', 'max:150', Rule::unique('users', 'email')],
            'adminPhone' => ['nullable', 'string', 'max:30'],
        ], ['adminEmail.unique' => 'Cette adresse est déjà utilisée par un compte.'],
            ['adminName' => 'nom', 'adminEmail' => 'e-mail', 'adminPhone' => 'téléphone']);

        $user = DB::transaction(function () {
            $user = User::create([
                'name'              => trim($this->adminName),
                'email'             => $this->adminEmail,
                'phone'             => trim($this->adminPhone) ?: null,
                'password'          => Hash::make(Str::password(24)),
                'role'              => 'admin',
                'email_verified_at' => now(),
            ]);
            $this->school()->users()->attach($user->id, ['role' => 'director']);

            return $user;
        });
        Password::broker()->sendResetLink(['email' => $user->email]);
        Audit::log('superadmin.admin_account.create', $user, ['school_id' => $this->schoolId]);

        $this->cancelEditAdmin();
        $this->flash = "Compte admin créé. Un lien pour choisir son mot de passe a été envoyé à {$user->email}.";
    }

    public function editAdmin(int $id): void
    {
        $u = $this->ownAdmin($id);
        $this->editingAdminId = $u->id;
        $this->adminName  = $u->name;
        $this->adminEmail = $u->email;
        $this->adminPhone = (string) $u->phone;
        $this->resetErrorBag();
    }

    public function cancelEditAdmin(): void
    {
        $this->editingAdminId = null;
        $this->reset('adminName', 'adminEmail', 'adminPhone');
        $this->resetErrorBag();
    }

    public function updateAdmin(): void
    {
        abort_unless($this->editingAdminId, 422);
        $u = $this->ownAdmin($this->editingAdminId);
        $this->adminEmail = Str::lower(trim($this->adminEmail));

        $this->validate([
            'adminName'  => ['required', 'string', 'max:150'],
            'adminEmail' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($u->id)],
            'adminPhone' => ['nullable', 'string', 'max:30'],
        ], ['adminEmail.unique' => 'Cette adresse est déjà utilisée par un compte.'],
            ['adminName' => 'nom', 'adminEmail' => 'e-mail', 'adminPhone' => 'téléphone']);

        $oldEmail     = $u->email;
        $emailChanged = Str::lower($oldEmail) !== $this->adminEmail;

        DB::transaction(function () use ($u, $emailChanged) {
            $u->update(['name' => trim($this->adminName), 'email' => $this->adminEmail, 'phone' => trim($this->adminPhone) ?: null]);
            // Même règle que l'écran Comptes de l'école : nouvelle adresse non vérifiée, accès ouverts coupés.
            if ($emailChanged) {
                $u->forceFill(['email_verified_at' => null])->setRememberToken(Str::random(60));
                $u->save();
                DB::table('sessions')->where('user_id', $u->id)->delete();
            }
        });
        if ($emailChanged) {
            Notification::route('mail', $oldEmail)->notify(new AccountEmailChanged($u->name));
        }
        Audit::log('superadmin.admin_account.update', $u, ['school_id' => $this->schoolId]);

        $this->cancelEditAdmin();
        $this->flash = 'Compte admin mis à jour.';
    }

    public function deactivateAdmin(int $id): void
    {
        $u = $this->ownAdmin($id);
        $u->forceFill(['deactivated_at' => now()])->save();
        Audit::log('superadmin.admin_account.deactivate', $u, ['school_id' => $this->schoolId]);

        $this->warning = $this->activeAdminCount() === 0
            ? "Attention : {$this->school()->name} n'a plus aucun administrateur actif. Vous venez de désactiver le dernier administrateur actif."
            : null;
        $this->flash = 'Compte admin désactivé.';
    }

    public function reactivateAdmin(int $id): void
    {
        $u = $this->ownAdmin($id);
        $u->forceFill(['deactivated_at' => null])->save();
        Audit::log('superadmin.admin_account.reactivate', $u, ['school_id' => $this->schoolId]);

        $this->warning = null;
        $this->flash = 'Compte admin réactivé.';
    }

    public function render()
    {
        $school = $this->school();

        return view('livewire.superadmin.school-show', [
            'school'     => $school,
            'recipients' => $school->alertRecipients()->orderBy('email')->get(),
            'pupils'     => $school->children()->whereNull('deactivated_at')->count(),
            'admins'     => $school->users()->where('users.role', 'admin')->orderBy('users.name')->get(),
        ])->title($school->name);
    }
}
