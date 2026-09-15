<?php

namespace App\Services;

use App\Enums\AlertType;
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
 *    chat_sessions.ai_summary (résumé IA, jamais le message brut),
 *    alerts.{type,level}, et la tendance calculée à la volée.
 *  - D10 (v3) : AUCUNE donnée d'identité (prénom, nom, école, classe) n'est
 *    injectée — le bloc part vers le fournisseur d'IA.
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

    /** Types d'alerte considérés « graves » → autorisent le rappel explicite doux (source : AlertType::seriousValues()). */

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

        // Lot 2 §5 — les signaux récurrents deviennent une consigne de POSTURE, sans détail ni compte.
        $recurringLines = [];
        $hasRecurringSeriousSignal = false;
        foreach ($alertCounts as $type => $count) {
            if ($count >= self::RECURRING_THRESHOLD) {
                $recurringLines[] = 'sois particulièrement attentive au thème ' . $this->themeLabel((string) $type);
                if (in_array($type, AlertType::seriousValues(), true)) {
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

        // Lot 2 §5 — mémoire de Care : sujets NEUTRES uniquement (care_memory). Le résumé
        // clinique (ai_summary) n'entre plus dans le prompt de Care : il sert au référent.
        $summaries = ChatSession::query()
            ->where('child_id', $child->id)
            ->whereNotNull('ended_at')
            ->whereNotNull('care_memory')
            ->where('ended_at', '>=', $from)
            ->latest('ended_at')
            ->limit(self::RECENT_SUMMARIES)
            ->pluck('care_memory')
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

    /** Libellé de thème pour la consigne de posture (jamais le code technique brut). */
    private function themeLabel(string $type): string
    {
        return match ($type) {
            'harcelement'        => 'du harcèlement',
            'detresse'           => 'de la détresse',
            'pensees_negatives'  => 'des pensées négatives',
            'danger'             => 'du danger',
            'isolement'          => "de l'isolement",
            'stress'             => 'du stress',
            'humiliation_adulte' => "de l'humiliation par un adulte",
            default              => 'de ' . $type,
        };
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
            : implode(' ; ', $recurringLines);

        // D10 (v3) — pas de prénom ni de classe : le bloc est envoyé au fournisseur d'IA.
        $lines = [
            'MÉMOIRE — CE QUE TU SAIS DÉJÀ DE CET ENFANT',
            "Sessions précédentes : {$closedCount}",
            "Posture (30 derniers jours) : {$recurring}",
            "Tendance récente : {$trendLabel}",
        ];

        if ($summaries !== []) {
            $lines[] = 'Sujets neutres dont tu peux te souvenir (activités, sport, matières, animaux, projets). Ne cite JAMAIS une donnée qui ne figure pas ici, ne récite pas ces phrases mot pour mot :';
            foreach ($summaries as $summary) {
                $lines[] = '- ' . trim((string) $summary);
            }
        } else {
            $lines[] = 'Aucun sujet neutre mémorisé : ne cite jamais un souvenir précis de conversation.';
        }

        $lines[] = 'RAPPEL_EXPLICITE_AUTORISE : ' . ($allowExplicitRecall ? 'oui' : 'non');

        return implode("\n", $lines);
    }
}
