<?php

namespace App\Services;

use App\Jobs\ProcessSessionClosure;
use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use Illuminate\Support\Facades\Log;

/**
 * Clôture de session de chat — logique mutualisée (V5).
 *
 * Extrait de ChatInterface::endSession() pour être réutilisé par DEUX chemins
 * sans divergence de comportement :
 *  1. Le bouton « J'ai fini ma session » (Livewire ChatInterface).
 *  2. Le beacon de fermeture/actualisation de fenêtre (#1 — SessionCloseController),
 *     pour qu'aucune alerte ni résumé ne soit perdu si l'enfant ferme l'onglet.
 *
 * Comportement :
 *  - Analyse IA de l'historique (résumé + zone finale).
 *  - On conserve la PIRE zone observée pendant la session (currentZone), l'IA
 *    pouvant redescendre artificiellement en fin d'échange.
 *  - Création d'alerte idempotente (jamais deux pour la même session).
 *  - dispatchSync(ProcessSessionClosure) pour recalculer score + statut enfant.
 *  - Repli zone-only si l'analyse IA échoue (Gemini 503, etc.).
 *
 * Conformité 09-08 / RGPD : les messages bruts ne servent qu'EN TRANSIT (analyse
 * IA + résolution du niveau d'alerte). Seuls summary/zone/flags sont persistés.
 */
class SessionCloser
{
    public function __construct(
        private readonly AIService $ai,
        private readonly CrisisDetector $detector,
        private readonly AlertLevelResolver $levelResolver,
    ) {}

    /**
     * @param array<int,array{role:string,content:string}> $messages Historique (welcome déjà retiré idéalement).
     * @return array{zone:string, alert_created:bool}
     */
    public function close(
        ChatSession $session,
        array $messages,
        Child $child,
        string $currentZone = 'green',
        ?string $currentAlertType = null,
        bool $alertAlreadyCreated = false,
    ): array {
        // Idempotence : si la session est déjà close, on ne refait rien.
        if ($session->ended_at !== null) {
            return [
                'zone'          => (string) ($session->zone ?? $currentZone),
                'alert_created' => Alert::where('session_id', $session->id)->exists(),
            ];
        }

        $userContents = collect($messages)
            ->where('role', 'user')
            ->pluck('content')
            ->all();

        // Aucun message enfant (ex. onglet fermé juste après le welcome) :
        // inutile d'appeler l'IA. On clôture sur la zone observée + un résumé neutre.
        if ($userContents === []) {
            $zone = $session->zone ?? 'green';
            $session->update([
                'ended_at'       => now(),
                'zone'           => $zone,
                'low_confidence' => true,
                'ai_summary'     => $session->ai_summary
                    ?? 'Session interrompue sans message exprimé (fenêtre fermée).',
            ]);
            $alertCreated = $this->maybeCreateAlert($session, $child, $zone, $currentAlertType, [], $alertAlreadyCreated);
            ProcessSessionClosure::dispatchSync($session);

            return ['zone' => $zone, 'alert_created' => $alertCreated];
        }

        try {
            $analysis = $this->ai->analyzeSession($messages, $child->age, $child->gender ?? null);

            $finalZone      = $this->detector->maxZone($currentZone, $analysis['zone']);
            $finalAlertType = $analysis['alert_type'] ?? $currentAlertType;

            $session->update([
                'ended_at'       => now(),
                'zone'           => $finalZone,
                'ai_summary'     => $analysis['summary'],
                'low_confidence' => $analysis['lowConfidence'],
            ]);

            $alertCreated = $this->maybeCreateAlert(
                $session,
                $child,
                $finalZone,
                $finalAlertType,
                $userContents,
                $alertAlreadyCreated,
            );

            ProcessSessionClosure::dispatchSync($session);

            return ['zone' => $finalZone, 'alert_created' => $alertCreated];
        } catch (\Throwable $e) {
            Log::error('Session analysis failure', [
                'session' => $session->id,
                'error'   => $e->getMessage(),
            ]);

            // Repli : on persiste au moins la pire zone observée backend.
            $session->update([
                'ended_at'       => now(),
                'zone'           => $currentZone,
                'low_confidence' => true,
            ]);

            $alertCreated = $this->maybeCreateAlert(
                $session,
                $child,
                $currentZone,
                $currentAlertType,
                $userContents,
                $alertAlreadyCreated,
            );

            ProcessSessionClosure::dispatchSync($session);

            return ['zone' => $currentZone, 'alert_created' => $alertCreated];
        }
    }

    /**
     * Crée une alerte de clôture si la zone le justifie et qu'aucune n'existe déjà.
     *
     * @param array<int,string> $userContents
     */
    private function maybeCreateAlert(
        ChatSession $session,
        Child $child,
        string $zone,
        ?string $alertType,
        array $userContents,
        bool $alertAlreadyCreated,
    ): bool {
        if (! in_array($zone, ['orange', 'red'], true)) {
            return false;
        }

        if ($alertAlreadyCreated || Alert::where('session_id', $session->id)->exists()) {
            return true;
        }

        $level = $zone === 'red'
            ? 'critical'
            : $this->levelResolver->resolve($alertType, $zone, $userContents);

        Alert::create([
            'session_id' => $session->id,
            'child_id'   => $child->id,
            'school_id'  => $child->school_id,
            'type'       => $alertType ?? 'detresse',
            'level'      => $level,
        ]);

        return true;
    }
}
