<?php

namespace App\Services;

interface AIService
{
    /**
     * Analyse l'historique complet de session et retourne le résumé + zone finale.
     *
     * @param array $messages [['role' => 'user|assistant', 'content' => '...']]
     * @param int $childAge âge de l'enfant
     * @param ?string $childGender 'm' | 'f' | 'x' | null — pour accord de genre dans le prompt (P8 V4)
     * @return array{summary:string, zone:string, alert_type:?string, lowConfidence:bool}
     */
    public function analyzeSession(array $messages, int $childAge, ?string $childGender = null): array;

    /**
     * Envoie un tour de chat à l'IA et retourne le message + signal de risque structuré.
     *
     * Le message est nettoyé (markers internes retirés) avant d'être affiché à l'enfant.
     *
     * @param ?string $childGender 'm' | 'f' | 'x' | null — pour accord de genre dans le prompt (P8 V4)
     * @param ?string $childContext Bloc mémoire inter-sessions injecté dans le prompt système
     *                              (#7 V5 — prénom, signaux récurrents, tendance). null = pas de mémoire.
     * @return array{message:string, zone:string, alert_type:?string, is_critical:bool, low_confidence:bool}
     */
    public function chat(array $messages, int $childAge, ?string $childGender = null, ?string $childContext = null): array;
}
