<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** Petits formats humains en français pour les écrans référent / parent. */
final class Humanize
{
    /** « 4 min », « 4 h », « 3 j » — temps écoulé depuis une date. */
    public static function waitingSince(Carbon $since, ?Carbon $now = null): string
    {
        $now = $now ?? now();
        $minutes = max(0, (int) $since->diffInMinutes($now));

        if ($minutes < 60) {
            return $minutes . ' min';
        }
        if ($minutes < 60 * 24) {
            return intdiv($minutes, 60) . ' h';
        }

        return intdiv($minutes, 60 * 24) . ' j';
    }

    /** « d'Amina » / « de Yassine » — élision devant une voyelle ou un h muet. */
    public static function de(string $name): string
    {
        $first = mb_strtolower(mb_substr(trim($name), 0, 1));

        return (in_array($first, ['a', 'e', 'i', 'o', 'u', 'y', 'h', 'é', 'è', 'ê', 'à', 'â', 'î', 'ô', 'û'], true) ? "d'" : 'de ') . trim($name);
    }

    public static function date(?Carbon $d): string
    {
        return $d ? $d->format('d/m/Y') : '—';
    }

    public static function dateTime(?Carbon $d): string
    {
        return $d ? $d->format('d/m/Y H:i') : '—';
    }
}
