<?php
namespace App\Livewire\Admin;

use App\Models\SchoolSetting;
use App\Services\Audit;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Paramètres de l'établissement — étendus au lot 1 §4 : plage horaire scolaire,
 * téléphone du référent.
 *
 * Le plafond quotidien de tokens (`school_settings.daily_token_cap`) n'est
 * VOLONTAIREMENT pas exposé ici : c'est un réglage interne CareNest, piloté par
 * le super administrateur, jamais par l'administration de l'établissement.
 * La colonne reste en base et `TokenBudget::cap()` continue de la lire.
 */
#[Layout('layouts.app')]
class Settings extends Component
{
    #[Validate('required|integer|min:0|max:100')]
    public int $alertThreshold = 30;

    #[Validate('boolean')]
    public bool $emailNotifications = true;

    #[Validate('required|in:fr,ar,en')]
    public string $language = 'fr';

    #[Validate('required|date_format:H:i')]
    public string $schoolHoursStart = '08:00';

    #[Validate('required|date_format:H:i|after:schoolHoursStart')]
    public string $schoolHoursEnd = '17:00';

    #[Validate('nullable|string|max:30')]
    public string $referentPhone = '';

    public ?string $savedFlash = null;

    public function mount(): void
    {
        $school = Auth::user()->schools()->with('setting')->first();
        $setting = $school?->setting;
        if (! $setting && $school) {
            $setting = SchoolSetting::create(['school_id' => $school->id]);
        }

        if ($setting) {
            $this->alertThreshold       = (int) $setting->alert_threshold;
            $this->emailNotifications   = (bool) $setting->email_notifications;
            $this->language             = (string) $setting->language;
            $this->schoolHoursStart     = substr((string) ($setting->school_hours_start ?? '08:00'), 0, 5);
            $this->schoolHoursEnd       = substr((string) ($setting->school_hours_end ?? '17:00'), 0, 5);
            $this->referentPhone        = (string) $setting->referent_phone;
        }
    }

    public function save(): void
    {
        $this->validate();

        $school = Auth::user()->schools()->first();
        if (! $school) return;

        $setting = SchoolSetting::updateOrCreate(
            ['school_id' => $school->id],
            [
                'alert_threshold'       => $this->alertThreshold,
                'email_notifications'   => $this->emailNotifications,
                'language'              => $this->language,
                'school_hours_start'    => $this->schoolHoursStart,
                'school_hours_end'      => $this->schoolHoursEnd,
                'referent_phone'        => $this->referentPhone !== '' ? $this->referentPhone : null,
            ]
        );
        Audit::log('admin.settings.update', $setting, ['school_id' => $school->id]);

        $this->savedFlash = 'Paramètres enregistrés.';
    }

    public function render()
    {
        $school = Auth::user()->schools()->with('setting')->first();
        return view('livewire.admin.settings', compact('school'));
    }
}
