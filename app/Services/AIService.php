<?php

namespace App\Services;

interface AIService
{
    /**
     * Analyse l'historique complet de session et retourne le résumé + zone finale.
     *
     * @param array $messages [['role' => 'user|assistant', 'content' => '...']]
     * @return array{summary:string, zone:string, alert_type:?string, lowConfidence:bool}
     */
    public function analyzeSession(array $messages, int $childAge): array;

    /**
     * Envoie un tour de chat à l'IA et retourne le message + signal de risque structuré.
     *
     * Le message est nettoyé (markers internes retirés) avant d'être affiché à l'enfant.
     *
     * @return array{message:string, zone:string, alert_type:?string, is_critical:bool, low_confidence:bool}
     */
    public function chat(array $messages, int $childAge): array;
}
