<?php

namespace App\Services;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

/** Pilote SMS « log » : trace l'envoi dans le journal applicatif (numéro masqué, texte sans donnée nominative). */
class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        Log::info('SMS (pilote log)', [
            'to'      => $this->mask($phone),
            'message' => $message,
        ]);
    }

    private function mask(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) <= 4) {
            return '****';
        }
        return str_repeat('*', strlen($digits) - 4) . substr($digits, -4);
    }
}
