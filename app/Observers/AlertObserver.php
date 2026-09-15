<?php

namespace App\Observers;

use App\Models\Alert;
use App\Models\AlertLifecycle;

/**
 * Lot 1 — réouverture automatique : une nouvelle alerte de niveau high/critical
 * créée moins de 30 jours après la clôture d'une alerte du même enfant porte
 * la mention « réouverture » (alerts.reopened_from_id).
 */
class AlertObserver
{
    public const REOPEN_WINDOW_DAYS = 30;

    public function creating(Alert $alert): void
    {
        if ($alert->reopened_from_id !== null || ! in_array($alert->level, ['high', 'critical'], true)) {
            return;
        }

        $closure = AlertLifecycle::query()
            ->where('status', 'cloture')
            ->where('changed_at', '>=', now()->subDays(self::REOPEN_WINDOW_DAYS))
            ->whereIn('alert_id', Alert::query()->where('child_id', $alert->child_id)->select('id'))
            ->latest('changed_at')
            ->first();

        if ($closure) {
            $alert->reopened_from_id = $closure->alert_id;
        }
    }
}
