<?php

namespace App\Jobs;

use App\Models\Alert;
use App\Models\ChatSession;
use Illuminate\Support\Facades\Log;

/**
 * P2 (Probleme CareNest V4) — Ferme automatiquement les sessions de chat
 * abandonnées depuis ≥ 5 minutes.
 *
 * Stratégie : "Cron only" (pas de heartbeat JS, pas de beforeunload beacon).
 * Exécuté toutes les 2 minutes par Schedule (cf. routes/console.php) en mode
 * synchrone — ce job N'IMPLÉMENTE PAS ShouldQueue volontairement, pour ne
 * pas dépendre d'un worker queue actif sur le MVP.
 *
 * Pour chaque session inactive :
 *  - on positionne ended_at = now()
 *  - si zone est null → green + low_confidence (signal silencieux non fiable)
 *  - sinon on garde la pire zone observée pendant la session
 *  - on crée une Alert si la zone finale est orange/red ET qu'aucune Alert
 *    n'a déjà été créée pendant la session (idempotence).
 *  - on dispatchSync ProcessSessionClosure pour recalculer score + status.
 *
 * Conformité 09-08 / RGPD : aucun message brut n'est lu ou stocké. On ne
 * s'appuie que sur les métadonnées déjà persistées (zone, last_activity_at).
 */
class CloseIdleSessions
{
    private const IDLE_MINUTES = 5;

    public function handle(): void
    {
        $threshold = now()->subMinutes(self::IDLE_MINUTES);

        $sessions = ChatSession::query()
            ->whereNull('ended_at')
            ->whereNotNull('last_activity_at')
            ->where('last_activity_at', '<', $threshold)
            ->get();

        foreach ($sessions as $session) {
            try {
                $this->closeSession($session);
            } catch (\Throwable $e) {
                Log::error('CloseIdleSessions failure', [
                    'session_id' => $session->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }

    private function closeSession(ChatSession $session): void
    {
        $zone = $session->zone;
        $lowConfidence = $session->low_confidence;
        $summary = null;

        if ($zone === null) {
            // Session ouverte sans aucun signal — on la classe en green
            // mais on flagge low_confidence pour ne pas surévaluer le climat.
            $zone = 'green';
            $lowConfidence = true;
            $summary = 'Session abandonnée sans signal émotionnel exprimé.';
        } else {
            $summary = "Session terminée automatiquement (inactivité). Pire zone observée pendant la session : {$zone}.";
        }

        $session->update([
            'ended_at'       => now(),
            'zone'           => $zone,
            'low_confidence' => $lowConfidence,
            'ai_summary'     => $summary,
        ]);

        // Alerte fallback uniquement si aucune n'a été créée pendant la session.
        if (in_array($zone, ['orange', 'red'], true)
            && ! Alert::where('session_id', $session->id)->exists()) {
            Alert::create([
                'session_id' => $session->id,
                'child_id'   => $session->child_id,
                'school_id'  => $session->school_id,
                'type'       => 'detresse',
                // On ne dispose pas des messages bruts à ce stade (volontairement) :
                // on applique un mapping zone → level conservateur.
                'level'      => $zone === 'red' ? 'critical' : 'moderate',
            ]);
        }

        // Recalcul du score climat + status enfant — synchrone car on n'a
        // pas de worker queue garanti.
        ProcessSessionClosure::dispatchSync($session);
    }
}
