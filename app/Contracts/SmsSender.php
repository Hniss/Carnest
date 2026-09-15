<?php

namespace App\Contracts;

/**
 * Lot 2 — envoi de SMS (alertes vitales). Implémentation par défaut : LogSmsSender.
 * Le message ne contient jamais de donnée nominative.
 */
interface SmsSender
{
    public function send(string $phone, string $message): void;
}
