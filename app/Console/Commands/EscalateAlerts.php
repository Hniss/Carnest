<?php

namespace App\Console\Commands;

use App\Models\PagerHeartbeat;
use App\Services\AlertPager;
use Illuminate\Console\Command;

/** Lot 2 §2 — escalade des alertes non accusées + battement du pager (planifiée chaque minute). */
class EscalateAlerts extends Command
{
    protected $signature = 'carenest:escalate-alerts';

    protected $description = 'Escalade les alertes sans accusé de réception (5 / 15 / 60 minutes ouvrées) et écrit un battement.';

    public function handle(AlertPager $pager): int
    {
        $pager->escalate();

        PagerHeartbeat::create(['worker' => gethostname() ?: 'pager', 'beat_at' => now()]);
        PagerHeartbeat::where('beat_at', '<', now()->subDay())->delete();

        $this->info('Escalade exécutée.');

        return self::SUCCESS;
    }
}
