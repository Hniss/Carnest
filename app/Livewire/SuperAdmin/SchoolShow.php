<?php

namespace App\Livewire\SuperAdmin;

use App\Livewire\Concerns\RequiresSuperAdmin;
use App\Models\School;
use App\Models\SchoolAlertRecipient;
use App\Services\Audit;
use Illuminate\Support\Facades\Auth;
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

    public function render()
    {
        $school = $this->school();

        return view('livewire.superadmin.school-show', [
            'school'     => $school,
            'recipients' => $school->alertRecipients()->orderBy('email')->get(),
            'pupils'     => $school->children()->whereNull('deactivated_at')->count(),
        ])->title($school->name);
    }
}
