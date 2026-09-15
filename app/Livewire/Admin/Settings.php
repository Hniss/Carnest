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
 * plafond quotidien de tokens, plafond de séance, téléphones, canaux de notification.
 */
#[Layout('layouts.app')]
class Settings extends Component
{
    public const CHANNELS = ['app', 'email', 'sms'];

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

    #[Validate('required|integer|min:0|max:200000')]
    public int $dailyTokenCap = 10000;

    #[Validate('nullable|integer|min:5|max:180')]
    public ?int $sessionMaxMinutes = null;

    #[Validate('nullable|string|max:30')]
    public string $referentPhone = '';

    #[Validate('nullable|string|max:30')]
    public string $adminPhone = '';

    /** @var string[] */
    public array $notificationChannels = ['app'];

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
            $this->dailyTokenCap        = (int) ($setting->daily_token_cap ?? 10000);
            $this->sessionMaxMinutes    = $setting->session_max_minutes;
            $this->referentPhone        = (string) $setting->referent_phone;
            $this->adminPhone           = (string) $setting->admin_phone;
            $this->notificationChannels = $setting->notification_channels ?: ['app'];
        }
    }

    public function save(): void
    {
        $this->validate();
        $this->validate(['notificationChannels' => ['array'], 'notificationChannels.*' => ['in:' . implode(',', self::CHANNELS)]]);

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
                'daily_token_cap'       => $this->dailyTokenCap,
                'session_max_minutes'   => $this->sessionMaxMinutes,
                'referent_phone'        => $this->referentPhone !== '' ? $this->referentPhone : null,
                'admin_phone'           => $this->adminPhone !== '' ? $this->adminPhone : null,
                'notification_channels' => array_values(array_unique($this->notificationChannels)),
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
