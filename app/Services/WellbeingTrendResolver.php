<?php

namespace App\Services;

use App\DataTransferObjects\TrendBadge;
use App\DataTransferObjects\WellbeingTrendReport;
use App\DataTransferObjects\WindowStats;
use App\Enums\ZoneScore;
use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use Carbon\CarbonImmutable;

/**
 * Résout le rapport de suivi psychique longitudinal d'un enfant.
 *
 * Données utilisées (lecture seule, agrégation) :
 *  - chat_sessions.zone, ended_at  (sessions closes uniquement)
 *  - alerts.level, created_at, status
 *  - children.score_enfant, status
 *
 * Conformité (CONTEXT.md §4 — règles d'or) :
 *  - JAMAIS le contenu d'un message enfant n'est lu, exporté ou loggé.
 *  - Le service est pur (idempotent, sans effet de bord) — il peut être
 *    appelé à chaque render sans toucher la BDD en écriture.
 */
class WellbeingTrendResolver
{
    /** Delta ≥ +10 → direction 'improving'. */
    public const IMPROVEMENT_THRESHOLD = 10;

    /** Delta ≤ -10 → direction 'worsening'. */
    public const WORSENING_THRESHOLD = -10;

    /** Une fenêtre 7j sous ce seuil est marquée isUnderRepresented. */
    public const MIN_SESSIONS_SHORT = 2;

    /** Une fenêtre 30j sous ce seuil est marquée isUnderRepresented. */
    public const MIN_SESSIONS_LONG = 3;

    /** Streak hebdo en baisse à partir duquel on lève worseningSignal. */
    public const WORSENING_STREAK_TRIGGER = 2;

    /** Nombre de points dans la sparkline (8 semaines). */
    public const SPARKLINE_WEEKS = 8;

    public function resolve(Child $child, ?CarbonImmutable $now = null): WellbeingTrendReport
    {
        $now = $now ?? CarbonImmutable::now();

        // Fenêtres 7j
        $shortTo       = $now;
        $shortFrom     = $now->subDays(7);
        $shortPrevTo   = $shortFrom;
        $shortPrevFrom = $shortFrom->subDays(7);

        // Fenêtres 30j
        $longTo       = $now;
        $longFrom     = $now->subDays(30);
        $longPrevTo   = $longFrom;
        $longPrevFrom = $longFrom->subDays(30);

        $shortTerm     = $this->windowStats($child, $shortFrom, $shortTo, self::MIN_SESSIONS_SHORT);
        $shortTermPrev = $this->windowStats($child, $shortPrevFrom, $shortPrevTo, self::MIN_SESSIONS_SHORT);

        $longTerm     = $this->windowStats($child, $longFrom, $longTo, self::MIN_SESSIONS_LONG);
        $longTermPrev = $this->windowStats($child, $longPrevFrom, $longPrevTo, self::MIN_SESSIONS_LONG);

        $shortTermTrend = $this->compareWindows($shortTerm, $shortTermPrev);
        $longTermTrend  = $this->compareWindows($longTerm, $longTermPrev);

        $worseningStreak = $this->worseningStreak($child, $now);
        $worseningSignal = $worseningStreak >= self::WORSENING_STREAK_TRIGGER;

        $sparkline = $this->weeklySparkline($child, $now);

        $lastSession = ChatSession::query()
            ->where('child_id', $child->id)
            ->whereNotNull('ended_at')
            ->latest('ended_at')
            ->first();

        return new WellbeingTrendReport(
            currentScore:    $child->score_enfant !== null ? (float) $child->score_enfant : null,
            currentStatus:   (string) ($child->status ?? 'ok'),
            shortTerm:       $shortTerm,
            longTerm:        $longTerm,
            shortTermTrend:  $shortTermTrend,
            longTermTrend:   $longTermTrend,
            worseningStreak: $worseningStreak,
            worseningSignal: $worseningSignal,
            weeklySparkline: $sparkline,
            lastSession:     $lastSession,
        );
    }

    /**
     * Agrège les sessions closes d'un enfant sur la fenêtre [$from ; $to[.
     */
    public function windowStats(
        Child $child,
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $minSessions,
    ): WindowStats {
        $sessions = ChatSession::query()
            ->where('child_id', $child->id)
            ->whereNotNull('ended_at')
            ->whereNotNull('zone')
            ->where('ended_at', '>=', $from)
            ->where('ended_at', '<',  $to)
            ->get(['zone']);

        $count = $sessions->count();

        $alertsBaseQuery = Alert::query()
            ->where('child_id', $child->id)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<',  $to);

        $alertsCount         = (clone $alertsBaseQuery)->count();
        $criticalAlertsCount = (clone $alertsBaseQuery)->where('level', 'critical')->count();

        if ($count === 0) {
            return new WindowStats(
                averageScore:        null,
                sessionsCount:       0,
                alertsCount:         $alertsCount,
                criticalAlertsCount: $criticalAlertsCount,
                isEmpty:             true,
                isUnderRepresented:  true,
            );
        }

        $total = 0;
        foreach ($sessions as $s) {
            $total += ZoneScore::fromZone((string) $s->zone);
        }
        $average = $total / $count;

        return new WindowStats(
            averageScore:        $average,
            sessionsCount:       $count,
            alertsCount:         $alertsCount,
            criticalAlertsCount: $criticalAlertsCount,
            isEmpty:             false,
            isUnderRepresented:  $count < $minSessions,
        );
    }

    /**
     * Compare deux fenêtres et produit un badge de tendance.
     *
     * - Si l'une des fenêtres est sous-représentée → direction='stable',
     *   reason='insufficient_data', delta calculé si possible.
     * - Si la fenêtre précédente est vide (pas de baseline) →
     *   direction='unknown', reason='no_baseline', delta=null.
     * - Sinon, delta = current - previous. Seuils ±10 → improving / worsening.
     */
    public function compareWindows(WindowStats $current, WindowStats $previous): TrendBadge
    {
        // Pas de baseline : on ne sait rien dire.
        if ($previous->isEmpty) {
            return new TrendBadge(
                direction: 'unknown',
                delta:     null,
                label:     'Pas encore de tendance',
                reason:    $current->isEmpty ? 'insufficient_data' : 'no_baseline',
            );
        }

        // Fenêtre courante vide → on a une baseline mais rien à comparer.
        if ($current->isEmpty) {
            return new TrendBadge(
                direction: 'stable',
                delta:     null,
                label:     'Données insuffisantes',
                reason:    'insufficient_data',
            );
        }

        $delta = ($current->averageScore ?? 0.0) - ($previous->averageScore ?? 0.0);

        // Sous-représentation → forçage stable mais on garde le delta.
        if ($current->isUnderRepresented || $previous->isUnderRepresented) {
            return new TrendBadge(
                direction: 'stable',
                delta:     $delta,
                label:     'Données insuffisantes',
                reason:    'insufficient_data',
            );
        }

        if ($delta >= self::IMPROVEMENT_THRESHOLD) {
            return new TrendBadge(
                direction: 'improving',
                delta:     $delta,
                label:     'En amélioration',
                reason:    'normal',
            );
        }

        if ($delta <= self::WORSENING_THRESHOLD) {
            return new TrendBadge(
                direction: 'worsening',
                delta:     $delta,
                label:     'En dégradation',
                reason:    'normal',
            );
        }

        return new TrendBadge(
            direction: 'stable',
            delta:     $delta,
            label:     'Stable',
            reason:    'normal',
        );
    }

    /**
     * Compte les semaines consécutives (en partant de la plus récente) où
     * la moyenne hebdomadaire est en baisse de ≥ 10 points vs la semaine
     * d'avant. S'arrête dès qu'une semaine n'est pas en baisse OU est
     * sous-représentée. Borné par SPARKLINE_WEEKS (8).
     */
    public function worseningStreak(Child $child, CarbonImmutable $now): int
    {
        $streak = 0;

        for ($i = 0; $i < self::SPARKLINE_WEEKS; $i++) {
            $to   = $now->subDays(7 * $i);
            $from = $now->subDays(7 * ($i + 1));

            $current  = $this->windowStats($child, $from, $to, self::MIN_SESSIONS_SHORT);
            $previous = $this->windowStats(
                $child,
                $now->subDays(7 * ($i + 2)),
                $now->subDays(7 * ($i + 1)),
                self::MIN_SESSIONS_SHORT,
            );

            // Une semaine vide ou sous-représentée stoppe le streak.
            if ($current->isEmpty || $current->isUnderRepresented) {
                break;
            }
            if ($previous->isEmpty || $previous->isUnderRepresented) {
                break;
            }

            $delta = ($current->averageScore ?? 0.0) - ($previous->averageScore ?? 0.0);

            if ($delta <= self::WORSENING_THRESHOLD) {
                $streak++;
                continue;
            }

            break;
        }

        return $streak;
    }

    /**
     * Renvoie 8 moyennes hebdomadaires (ASC chrono, ?float pour les trous).
     */
    public function weeklySparkline(Child $child, CarbonImmutable $now): array
    {
        $values = [];

        // Construction DESC (de la semaine la plus récente vers la plus ancienne)
        // puis renversement pour ordre ASC.
        for ($i = 0; $i < self::SPARKLINE_WEEKS; $i++) {
            $to   = $now->subDays(7 * $i);
            $from = $now->subDays(7 * ($i + 1));

            $stats = $this->windowStats($child, $from, $to, self::MIN_SESSIONS_SHORT);
            $values[] = $stats->isEmpty ? null : $stats->averageScore;
        }

        return array_reverse($values);
    }
}
