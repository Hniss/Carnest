<?php
namespace App\Livewire\Admin;

use App\Models\SchoolSetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
class Settings extends Component
{
    #[Validate('required|integer|min:0|max:100')]
    public int $alertThreshold = 30;

    #[Validate('boolean')]
    public bool $emailNotifications = true;

    #[Validate('required|in:fr,ar,en')]
    public string $language = 'fr';

    public ?string $savedFlash = null;

    public function mount(): void
    {
        $school = Auth::user()->schools()->with('setting')->first();
        $setting = $school?->setting;
        if (! $setting && $school) {
            $setting = SchoolSetting::create(['school_id' => $school->id]);
        }

        if ($setting) {
            $this->alertThreshold     = (int) $setting->alert_threshold;
            $this->emailNotifications = (bool) $setting->email_notifications;
            $this->language           = (string) $setting->language;
        }
    }

    public function save(): void
    {
        $this->validate();

        $school = Auth::user()->schools()->first();
        if (! $school) return;

        SchoolSetting::updateOrCreate(
            ['school_id' => $school->id],
            [
                'alert_threshold'     => $this->alertThreshold,
                'email_notifications' => $this->emailNotifications,
                'language'            => $this->language,
            ]
        );

        $this->savedFlash = 'Paramètres enregistrés.';
    }

    public function render()
    {
        $school = Auth::user()->schools()->with('setting')->first();
        return view('livewire.admin.settings', compact('school'));
    }
}
