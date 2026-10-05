<?php

namespace App\Services;

use App\Models\MailSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Boîte d'envoi réglée par le super-admin. Appliquée à la configuration du mailer dès que
 * le gestionnaire d'e-mails est construit : elle sert à TOUS les e-mails de l'application
 * (alertes, liens de mot de passe). Sans réglage en base, le fichier du serveur s'applique.
 */
final class MailSettings
{
    public static function current(): ?MailSetting
    {
        try {
            return MailSetting::query()->latest('id')->first();
        } catch (QueryException) {
            return null;
        }
    }

    public static function apply(): void
    {
        $setting = self::current();
        if ($setting === null) {
            return;
        }

        try {
            $password = $setting->password;
        } catch (DecryptException) {
            Log::warning('Mot de passe de la boîte d\'envoi illisible (clé de l\'application changée) : réglages du serveur conservés.');

            return;
        }

        config([
            'mail.default'               => 'smtp',
            'mail.mailers.smtp.transport'=> 'smtp',
            'mail.mailers.smtp.scheme'   => $setting->encryption === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.url'      => null,
            'mail.mailers.smtp.host'     => $setting->host,
            'mail.mailers.smtp.port'     => (int) $setting->port,
            'mail.mailers.smtp.username' => $setting->username,
            'mail.mailers.smtp.password' => $password,
            'mail.from.address'          => $setting->from_address,
            'mail.from.name'             => $setting->from_name,
        ]);
    }
}
