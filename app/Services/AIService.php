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
     * @return array{summary:string, zone:string, alert_type:?string, lowConfidence:bool, tokens:int, model:string}
     *         alert_type appartient à App\Enums\AlertType (D7) ; tokens = usage.total_tokens (0 si absent) ; model = modèle réellement utilisé.
     */
    public function analyzeSession(array $messages, int $childAge, ?string $childGender = null): array;

    /**
     * Envoie un tour de chat à l'IA et retourne le message + signal de risque structuré.
     *
     * Le message est nettoyé (markers internes retirés) avant d'être affiché à l'enfant.
     *
     * D10 (v3) : aucune donnée d'identité (prénom, nom, école, classe) ne doit
     * figurer dans $messages ni dans $childContext.
     *
     * @param ?string $childGender 'm' | 'f' | 'x' | null — pour accord de genre dans le prompt (P8 V4)
     * @param ?string $childContext Bloc mémoire inter-sessions injecté dans le prompt système
     *                              (#7 V5 — signaux récurrents, tendance, résumés). null = pas de mémoire.
     * @param array{hors_horaires_scolaires?: bool} $flags Indicateurs calculés côté serveur (D5 : école fermée).
     * @return array{message:string, zone:string, alert_type:?string, is_critical:bool, low_confidence:bool, tokens:int, model:string}
     */
    public function chat(array $messages, int $childAge, ?string $childGender = null, ?string $childContext = null, array $flags = []): array;

    /**
     * Lot 2 §5 — mémoire de Care : 2 phrases max, sujets neutres et positifs uniquement
     * (jamais un signal, une émotion négative ni un nom de tiers). Distincte du résumé clinique.
     *
     * @return array{memory:string, tokens:int, model:string} memory = '' si aucun sujet neutre.
     */
    public function generateCareMemory(array $messages, int $childAge): array;
}
