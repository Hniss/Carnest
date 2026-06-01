<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use Carbon\CarbonImmutable;

/**
 * Mémoire inter-sessions (#7 — Probleme CareNest V5).
 *
 * Construit un bloc texte injectable dans le prompt système de Care pour qu'elle
 * « se souvienne » de l'enfant d'une session à l'autre. Service PUR, lecture
 * seule (même esprit que WellbeingTrendResolver) — peut être appelé à chaque
 * mount() sans aucun effet de bord.
 *
 * Conformité 09-08 / RGPD (CONTEXT.md §4 — règle d'or #1) :
 *  - On n'utilise QUE des données déjà persistées et autorisées :
 *    children.{name,age,classe}, chat_sessions.ai_summary (résumé IA, jamais
 *    le message brut), alerts.{type,level}, et la tendance calculée à la volée.
 *  - AUCUN message brut d'enfant n'est lu, et le bloc instruit explicitement
 *    Care de ne JAMAIS répéter un résumé mot pour mot à l'enfant.
 *
 * Retourne null quand l'enfant n'a aucune session close antérieure (premier
 * passage) → le prompt reste identique au comportement historique.
 */
class ChildContextBuilder
{
    /** Fenêtre d'agrégation des signaux récurrents et résumés. */
    public const LOOKBACK_DAYS = 30;

    /** Nombre de résumés récents injectés (pour le contexte, jamais répétés). */
    public const RECENT_SUMMARIES = 3;

    /** Un type d'alerte vu ≥ ce seuil sur la fenêtre = signal récurrent. */
    public const RECURRING_THRESHOLD = 2;

    /** Types d'alerte considérés « graves » → autorisent le rappel explicite doux. */
    private const SERIOUS_TYPES = ['harcelement', 'detresse', 'danger', 'isolement', 'humiliation_adulte'];

    public function __construct(
        private readonly WellbeingTrendResolver $trendResolver,
    ) {}

    /**
     * @return string|null Bloc prêt à injecter dans le prompt système, ou null
     *                     si aucune session close antérieure.
     */
    public function build(Child $child, ?CarbonImmutable $now = null): ?string
    {
        $now  = $now ?? CarbonImmutable::now();
        $from = $now->subDays(self::LOOKBACK_DAYS);

        $closedCount = ChatSession::query()
            ->where('child_id', $child->id)
            ->whereNotNull('ended_at')
            ->count();

        // Premier passage : pas de mémoire à injecter.
        if ($closedCount === 0) {
            return null;
        }

        // Signaux récurrents (alertes groupées par type sur la fenêtre).
        $alertCounts = Alert::query()
            ->where('child_id', $child->id)
            ->where('created_at', '>=', $from)
            ->get(['type'])
            ->countBy('type')
            ->sortDesc();

        $recurringLines = [];
        $hasRecurringSeriousSignal = false;
        foreach ($alertCounts as $type => $count) {
            if ($count >= self::RECURRING_THRESHOLD) {
                $recurringLines[] = "{$type} ({$count}×)";
                if (in_array($type, self::SERIOUS_TYPES, true)) {
                    $hasRecurringSeriousSignal = true;
                }
            }
        }

        // Tendance récente (réutilise le resolver longitudinal existant).
        $trend = $this->trendResolver->resolve($child, $now);
        if ($trend->worseningSignal) {
            $hasRecurringSeriousSignal = true;
        }
        $trendLabel = $this->trendLabel($trend->shortTermTrend->direction);

        // Résumés récents (jamais répétés verbatim — pour information de Care).
        $summaries = ChatSession::query()
            ->where('child_id', $child->id)
            ->whereNotNull('ended_at')
            ->whereNotNull('ai_summary')
            ->where('ended_at', '>=', $from)
            ->latest('ended_at')
            ->limit(self::RECENT_SUMMARIES)
            ->pluck('ai_summary')
            ->all();

        return $this->render(
            child: $child,
            closedCount: $closedCount,
            recurringLines: $recurringLines,
            trendLabel: $trendLabel,
            summaries: $summaries,
            allowExplicitRecall: $hasRecurringSeriousSignal,
        );
    }

    private function trendLabel(string $direction): string
    {
        return match ($direction) {
            'improving' => 'en amélioration',
            'worsening' => 'en dégradation',
            'stable'    => 'stable',
            default     => 'pas encore de tendance claire',
        };
    }

    /**
     * @param string[] $recurringLines
     * @param string[] $summaries
     */
    private function render(
        Child $child,
        int $closedCount,
        array $recurringLines,
        string $trendLabel,
        array $summaries,
        bool $allowExplicitRecall,
    ): string {
        $recurring = $recurringLines === []
            ? 'aucun signal récurrent marquant'
            : implode(', ', $recurringLines);

        $lines = [
            'MÉMOIRE — CE QUE TU SAIS DÉJÀ DE CET ENFANT',
            "Prénom : {$child->name}",
            "Classe : {$child->classe}",
            "Sessions précédentes : {$closedCount}",
            "Signaux récurrents (30 derniers jours) : {$recurring}",
            "Tendance récente : {$trendLabel}",
        ];

        if ($summaries !== []) {
            $lines[] = 'Résumés récents (pour TON information uniquement — NE répète JAMAIS ces phrases mot pour mot à l\'enfant) :';
            foreach ($summaries as $summary) {
                $lines[] = '- ' . trim((string) $summary);
            }
        }

        $lines[] = 'RAPPEL_EXPLICITE_AUTORISE : ' . ($allowExplicitRecall ? 'oui' : 'non');

        return implode("\n", $lines);
    }
}
