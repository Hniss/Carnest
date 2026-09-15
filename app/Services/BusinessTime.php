<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * D5 (MVP v3) — temps ouvré selon les horaires de l'école (jours ouvrés lundi-vendredi).
 * Une alerte créée hors horaires commence à « courir » à l'ouverture suivante.
 */
final class BusinessTime
{
    public static function minutesBetween(Carbon $from, Carbon $to, string $hoursStart, string $hoursEnd): int
    {
        if ($to->lte($from)) {
            return 0;
        }

        [$sh, $sm] = array_map('intval', explode(':', substr($hoursStart, 0, 5)));
        [$eh, $em] = array_map('intval', explode(':', substr($hoursEnd, 0, 5)));

        $total = 0;
        $day = $from->copy()->startOfDay();
        $last = $to->copy()->startOfDay();

        while ($day->lte($last)) {
            if ($day->isWeekday()) {
                $open  = $day->copy()->setTime($sh, $sm);
                $close = $day->copy()->setTime($eh, $em);
                $start = $from->gt($open) ? $from : $open;
                $end   = $to->lt($close) ? $to : $close;
                if ($end->gt($start)) {
                    $total += (int) $start->diffInMinutes($end);
                }
            }
            $day->addDay();
        }

        return $total;
    }
}
