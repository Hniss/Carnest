<?php

namespace App\Services;

use App\Models\Alert;
use Illuminate\Support\Facades\Log;

/**
 * Mise à niveau d'une alerte OUVERTE quand la conversation s'aggrave.
 *
 * Défaut corrigé (recette réelle du 28/09, défauts B1 et B2) : le type et le
 * niveau étaient figés sur le PREMIER signal détecté et n'étaient plus jamais
 * réévalués. Un enfant qui commençait par « ça va pas trop » (détresse, modérée)
 * puis écrivait « j'aimerais juste disparaître » gardait une alerte « détresse /
 * modérée » — donc non vitale, donc aucune notification, aucun e-mail, aucun SMS.
 *
 * Règles appliquées ici :
 *  - Mise à niveau MONOTONE : une alerte ne redescend jamais (base fondatrice B4).
 *    Le type et le niveau ne peuvent que monter, via SignalSeverity.
 *  - Résumé : une alerte doit TOUJOURS porter un résumé exploitable. Dès qu'un
 *    résumé non vide est disponible (résumé courant de session, puis résumé final),
 *    il complète ou remplace celui de l'alerte. Jamais d'écrasement par du vide.
 *  - Chaîne de notification : relancée UNIQUEMENT si le type ou le niveau a monté,
 *    et AlertPager n'émet alors que les paliers qui n'avaient pas encore été servis
 *    (`alerts.paged_tier`). Aucune notification ne part deux fois pour le même palier.
 *  - La politique « qui est notifié pour quel niveau » n'est PAS touchée : elle reste
 *    entièrement dans AlertPager.
 */
class AlertUpgrader
{
    public function __construct(private readonly AlertPager $pager) {}

    /**
     * Aligne une alerte existante sur la gravité réellement observée.
     *
     * @return bool vrai si le type ou le niveau a été relevé (donc chaîne relancée).
     */
    public function sync(Alert $alert, ?string $type, ?string $level, ?string $summary): bool
    {
        $updates = [];

        $worstType = SignalSeverity::maxType($alert->type, $type);
        if ($worstType !== null && $worstType !== $alert->type) {
            $updates['type'] = $worstType;
        }

        $worstLevel = SignalSeverity::maxLevel($alert->level, $level);
        if ($worstLevel !== null && $worstLevel !== $alert->level) {
            $updates['level'] = $worstLevel;
        }

        $summary = $summary !== null ? trim($summary) : '';
        if ($summary !== '' && $summary !== trim((string) $alert->summary)) {
            $updates['summary'] = $summary;
        }

        if ($updates === []) {
            return false;
        }

        $alert->fill($updates)->save();

        $escalated = isset($updates['type']) || isset($updates['level']);
        if ($escalated) {
            $this->page($alert);
        }

        return $escalated;
    }

    /**
     * Paging protégé : un échec de notification ne doit jamais faire échouer le
     * tour de conversation de l'enfant ni la clôture de sa session.
     */
    public function page(Alert $alert): void
    {
        try {
            $this->pager->page($alert);
        } catch (\Throwable $e) {
            Log::error('Alert paging failed', ['alert' => $alert->id, 'error' => $e->getMessage()]);
        }
    }
}
