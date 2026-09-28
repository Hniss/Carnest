<?php

namespace App\Jobs;

use App\Models\Alert;
use App\Models\ChatSession;
use App\Services\AlertUpgrader;
use App\Services\SignalSeverity;
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
 *  - le résumé est le RÉSUMÉ COURANT écrit par le modèle pendant la session (plus
 *    de texte figé « Session terminée automatiquement ») ; le type et le niveau
 *    sont ceux réellement observés (`worst_alert_type`, `worst_level`)
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

        // Le résumé courant a été écrit PENDANT la session, par le modèle, dans le même
        // appel que la zone et le type : il existe donc déjà côté serveur au moment où
        // l'enfant disparaît. C'est lui qui sert de résumé, plus un texte figé.
        $summary = trim((string) $session->ai_summary);

        if ($zone === null) {
            // Session ouverte sans aucun signal — on la classe en green
            // mais on flagge low_confidence pour ne pas surévaluer le climat.
            $zone = 'green';
            $lowConfidence = true;
            if ($summary === '') {
                $summary = 'Session abandonnée sans signal émotionnel exprimé.';
            }
        } elseif ($summary === '') {
            // Aucun résumé courant disponible (échecs du modèle pendant toute la
            // session, ou session antérieure à la correction) : on le dit, plutôt que
            // de faire passer un constat de fermeture pour une analyse.
            $summary = 'Fin d\'échange non observée : l\'enfant a quitté sans clore sa session. Aucun résumé de conversation n\'a pu être enregistré.';
            $lowConfidence = true;
        }

        $session->update([
            'ended_at'         => now(),
            'zone'             => $zone,
            'low_confidence'   => $lowConfidence,
            'ai_summary'       => $summary,
        ]);

        // Type et niveau RÉELS, scellés pendant la session (`worst_alert_type`,
        // `worst_level`) — plus jamais « detresse / moderate » écrit en dur. Une
        // session abandonnée reçoit ainsi la même analyse qu'une session close.
        $type  = $session->worst_alert_type;
        $level = $session->worst_level ?? SignalSeverity::levelFromZone($zone);

        $existing = Alert::where('session_id', $session->id)->orderBy('id')->first();

        if ($existing !== null) {
            // Alerte ouverte pendant la session : elle est mise à niveau (type, niveau,
            // résumé) et la chaîne de notification est relancée si le palier atteint
            // n'avait pas encore été servi.
            app(AlertUpgrader::class)->sync($existing, $type, $level, $summary);
        } elseif (in_array($zone, ['orange', 'red'], true)) {
            $alert = Alert::create([
                'session_id' => $session->id,
                'child_id'   => $session->child_id,
                'school_id'  => $session->school_id,
                // `detresse` ne subsiste que comme dernier recours : aucun type n'a été
                // observé de toute la session alors que la colonne est NOT NULL.
                'type'       => $type ?? 'detresse',
                'level'      => $level,
                'summary'    => $summary,
            ]);

            // Une alerte créée ici était jusqu'à présent la seule à ne jamais être
            // transmise : même chaîne, même politique de destinataires qu'ailleurs.
            app(AlertUpgrader::class)->page($alert);
        }

        // Recalcul du score climat + status enfant — synchrone car on n'a
        // pas de worker queue garanti.
        ProcessSessionClosure::dispatchSync($session);
    }
}
