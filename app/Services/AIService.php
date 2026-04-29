<?php

namespace App\Services;

interface AIService
{
    /**
     * Envoie l'historique de session à l'IA et retourne le résumé + zone.
     *
     * @param array $messages [['role' => 'user|assistant', 'content' => '...']]
     * @param int   $childAge
     * @return array{summary: string, zone: string, lowConfidence: bool}
     */
    public function analyzeSession(array $messages, int $childAge): array;

    /**
     * Envoie un message unique (tour de chat) et retourne la réponse texte.
     */
    public function chat(array $messages, int $childAge): string;
}
