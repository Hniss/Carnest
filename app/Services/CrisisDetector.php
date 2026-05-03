<?php

namespace App\Services;

/**
 * Filet de sécurité déterministe : si l'IA passe à côté d'un signal critique
 * (P9, P10, P11), on force une montée de zone côté backend à partir de
 * marqueurs lexicaux explicites. Ne stocke aucun message — analyse en mémoire,
 * retourne uniquement zone et type d'alerte.
 */
class CrisisDetector
{
    /** Phrases / motifs « rouge » : détresse forte, intention de se faire du mal, violence subie. */
    private const RED_PATTERNS = [
        '\bme\s+(faire|fer)\s+du\s+mal\b',
        '\b(envie|veux|voudrais)\s+(de\s+)?mourir\b',
        '\bplus\s+envie\s+de\s+vivre\b',
        '\bje\s+veux\s+(plus|pu)\s+(être\s+là|exister|vivre)\b',
        '\bme\s+(tuer|suicider)\b',
        '\bsuicide\b',
        '\b(en\s+finir|finir\s+avec\s+(ça|tout))\b',
        '\bils\s+me\s+(frappent|tapent|battent)\b',
        '\b(papa|maman|mon\s+père|ma\s+mère)\s+me\s+(frappe|tape|bat)\b',
        '\b(touche|attouche)\b.{0,20}\b(parties\s+intimes|sexe|corps)\b',
        '\bje\s+(veux|voudrais)\s+disparaitre\b',
        '\bjsuis\s+nul\s+je\s+sers\s+(à\s+)?rien\b',
    ];

    /** Phrases « orange » : signaux importants mais pas critiques en eux-mêmes. */
    private const ORANGE_PATTERNS = [
        // Harcèlement / moqueries répétées
        ['type' => 'harcelement', 'rx' => '\b(harc[eè]l|harceler)\b'],
        ['type' => 'harcelement', 'rx' => '\bse\s+moquent?\s+de\s+moi\b'],
        ['type' => 'harcelement', 'rx' => '\bils?\s+m[\'e]\s*(embêtent?|insultent?|frappent?|tapent?|poussent?)\b'],
        ['type' => 'harcelement', 'rx' => '\b(tous\s+les\s+jours|chaque\s+jour|souvent)\b.{0,40}\b(moquent|embêtent|insultent)\b'],

        // Isolement
        ['type' => 'isolement',   'rx' => '\bje\s+suis\s+(tout\s+)?seul\b'],
        ['type' => 'isolement',   'rx' => '\bpersonne\s+ne\s+me\s+(parle|aime|comprend)\b'],
        ['type' => 'isolement',   'rx' => '\bj\'?ai\s+pas\s+d\'?amis?\b'],

        // Peur / dévalorisation forte
        ['type' => 'detresse',    'rx' => '\bj\'?ai\s+(tr[èe]s\s+)?peur\s+(de|qu[\'e])\s+(mes?\s+(parents|p[èe]re|m[èe]re)|papa|maman)\b'],
        ['type' => 'tristesse',   'rx' => '\bje\s+(suis|me\s+sens)\s+nul\b'],
        ['type' => 'tristesse',   'rx' => '\bje\s+sers\s+(à\s+)?rien\b'],
        ['type' => 'tristesse',   'rx' => '\bpersonne\s+ne\s+m\'?aime\b'],
    ];

    /**
     * @return array{zone:string, alert_type:?string, matched:bool}
     *         zone: green|yellow|orange|red — niveau déclenché par les motifs (jamais en dessous de la zone fournie).
     */
    public function evaluate(string $childMessage, string $currentZone = 'green'): array
    {
        $msg = $this->normalize($childMessage);

        foreach (self::RED_PATTERNS as $pattern) {
            if (preg_match('/' . $pattern . '/iu', $msg)) {
                return [
                    'zone'       => 'red',
                    'alert_type' => 'detresse',
                    'matched'    => true,
                ];
            }
        }

        foreach (self::ORANGE_PATTERNS as $entry) {
            if (preg_match('/' . $entry['rx'] . '/iu', $msg)) {
                $zone = $this->maxZone($currentZone, 'orange');
                return [
                    'zone'       => $zone,
                    'alert_type' => $entry['type'],
                    'matched'    => true,
                ];
            }
        }

        return [
            'zone'       => $currentZone,
            'alert_type' => null,
            'matched'    => false,
        ];
    }

    public function maxZone(string $a, string $b): string
    {
        $rank = ['green' => 0, 'yellow' => 1, 'orange' => 2, 'red' => 3];
        $ra = $rank[$a] ?? 0;
        $rb = $rank[$b] ?? 0;
        return $ra >= $rb ? $a : $b;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        // Compression des répétitions de lettres (ex. "trooop" -> "trop")
        $text = preg_replace('/(.)\1{2,}/u', '$1$1', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }
}
