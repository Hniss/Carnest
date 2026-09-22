<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * P2 (Probleme CareNest V4) — Ferme les sessions abandonnées (idle ≥ 5 min)
 * toutes les 2 minutes. Stratégie "Cron only" : pas de heartbeat JS, pas de
 * beforeunload beacon. On utilise Schedule::call (synchrone) car le MVP n'a
 * pas de worker queue garanti.
 */
Schedule::call(fn () => app(\App\Jobs\CloseIdleSessions::class)->handle())
    ->everyTwoMinutes()
    ->name('close-idle-chat-sessions')
    ->withoutOverlapping();

/**
 * Lot 2 (MVP v3) — escalade des alertes sans accusé, chaque minute.
 * Paliers réels (`AlertPager::STEP_MINUTES`) : 5 minutes → relance du référent
 * et du délégué ; 60 minutes → administration prévenue pour les seuls signaux
 * vitaux, puis escalade marquée épuisée. Délais comptés en heures ouvrées pour
 * les alertes non vitales, en continu pour les vitales.
 */
Schedule::command('carenest:escalate-alerts')
    ->everyMinute()
    ->withoutOverlapping();
