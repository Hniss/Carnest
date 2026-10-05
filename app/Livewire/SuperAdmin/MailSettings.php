<?php

namespace App\Livewire\SuperAdmin;

use App\Livewire\Concerns\RequiresSuperAdmin;
use App\Models\MailSetting;
use App\Services\Audit;
use App\Services\MailSettings as Settings;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Boîte d'envoi des e-mails (alertes et liens de mot de passe). Le mot de passe n'est jamais
 * renvoyé au navigateur : champ vide à l'ouverture, vidé après enregistrement, 4 derniers
 * caractères seulement. Laissé vide lors d'une modification, il est conservé.
 */
#[Layout('layouts.superadmin')]
class MailSettings extends Component
{
    use RequiresSuperAdmin;

    public string $host = '';
    public int|string $port = 465;
    public string $encryption = 'ssl';
    public string $username = '';
    public string $password = '';
    public string $fromAddress = '';
    public string $fromName = 'CareNest';
    public ?string $flash = null;

    public function mount(): void
    {
        Audit::log('superadmin.view.mail');

        if ($s = Settings::current()) {
            $this->host        = $s->host;
            $this->port        = $s->port;
            $this->encryption  = $s->encryption ?? 'none';
            $this->username    = (string) $s->username;
            $this->fromAddress = $s->from_address;
            $this->fromName    = $s->from_name;
        }
    }

    public function save(): void
    {
        $existing = Settings::current();

        $data = $this->validate([
            'host'        => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'port'        => ['required', 'integer', 'between:1,65535'],
            'encryption'  => ['required', 'in:ssl,tls,none'],
            'username'    => ['nullable', 'string', 'max:190'],
            'password'    => [$existing?->password_last4 ? 'nullable' : 'required', 'string', 'max:500'],
            'fromAddress' => ['required', 'email', 'max:190'],
            'fromName'    => ['required', 'string', 'max:120'],
        ], [
            'password.required' => 'Saisissez le mot de passe de la boîte d\'envoi.',
            'host.regex'        => 'Nom de serveur invalide (exemple : smtp.hostinger.com).',
        ], [
            'host' => 'serveur', 'port' => 'port', 'fromAddress' => 'adresse d\'expédition', 'fromName' => 'nom d\'expéditeur',
        ]);

        $values = [
            'host'         => trim($data['host']),
            'port'         => (int) $data['port'],
            'encryption'   => $data['encryption'] === 'none' ? null : $data['encryption'],
            'username'     => trim((string) $data['username']) ?: null,
            'from_address' => trim($data['fromAddress']),
            'from_name'    => trim($data['fromName']),
            'updated_by'   => Auth::id(),
        ];
        $passwordChanged = $this->password !== '';
        if ($passwordChanged) {
            $values['password']       = $this->password;
            $values['password_last4'] = mb_substr($this->password, -4);
        }
        $this->password = '';

        $setting = $existing ? tap($existing)->update($values) : MailSetting::create($values);

        Audit::log('superadmin.mail.update', $setting);
        if ($passwordChanged) {
            Audit::log('superadmin.mail.password.set', $setting);
        }
        $this->flash = 'Boîte d\'envoi enregistrée. Elle sert dès maintenant aux alertes et aux e-mails de mot de passe.';
    }

    public function resetToServer(): void
    {
        $this->password = '';
        $setting = Settings::current();
        if ($setting === null) {
            return;
        }
        Audit::log('superadmin.mail.reset', $setting);
        MailSetting::query()->delete();

        $this->reset('host', 'port', 'encryption', 'username', 'fromAddress', 'fromName');
        $this->flash = 'Réglages retirés : l\'application reprend ceux du fichier du serveur.';
    }

    public function render()
    {
        return view('livewire.superadmin.mail-settings', [
            'current' => Settings::current()?->load('updatedBy:id,name'),
        ])->title("Boîte d'envoi");
    }
}
