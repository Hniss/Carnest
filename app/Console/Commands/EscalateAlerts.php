<?php

namespace App\Console\Commands;

use App\Services\AlertPager;
use Illuminate\Console\Command;

/** Lot 2 §2 — escalade des alertes non accusées (planifiée chaque minute). */
class EscalateAlerts extends Command
{
    protected $signature = 'carenest:escalate-alerts';

    protected $description = 'Escalade les alertes sans accusé de réception (5 puis 60 minutes ouvrées ; en continu pour les signaux vitaux).';

    public function handle(AlertPager $pager): int
    {
        $pager->escalate();

        $this->info('Escalade exécutée.');

        return self::SUCCESS;
    }
}
