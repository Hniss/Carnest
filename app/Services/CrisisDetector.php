<?php

namespace App\Services;

use App\Enums\AlertType;

/**
 * Filet de sécurité déterministe : si l'IA passe à côté d'un signal critique
 * (P9, P10, P11), on force une montée de zone côté backend à partir de
 * marqueurs lexicaux explicites. Ne stocke aucun message — analyse en mémoire,
 * retourne uniquement zone et type d'alerte.
 *
 * D7 (v3) : types alignés sur App\Enums\AlertType. Les motifs rouges de
 * pensées négatives (envie de mourir, disparaître, se faire du mal, en finir)
 * retournent `pensees_negatives` ; la violence subie retourne `danger`.
 */
class CrisisDetector
{
    /**
     * Motifs « rouge » — chaque entrée porte le type d'alerte vital correspondant.
     * pensees_negatives : intention de se faire du mal, envie de mourir / disparaître.
     * danger            : violence physique ou sexuelle subie.
     */
    private const RED_PATTERNS = [
        // Pensées négatives sur soi, envie de disparaître, ne plus vouloir vivre
        ['type' => 'pensees_negatives', 'rx' => '\bme\s+(faire|fer)\s+du\s+mal\b'],
        ['type' => 'pensees_negatives', 'rx' => '\b(envie|veux|voudrais)\s+(de\s+)?mourir\b'],
        ['type' => 'pensees_negatives', 'rx' => '\bje\s+veux\s+mourir\b'],
        ['type' => 'pensees_negatives', 'rx' => '\bje\s+(veux|voudrais)\s+dispara[iî]tre\b'],
        ['type' => 'pensees_negatives', 'rx' => '\bplus\s+envie\s+de\s+vivre\b'],
        ['type' => 'pensees_negatives', 'rx' => '\bj\'?en\s+peux\s+plus\s+de\s+vivre\b'],
        ['type' => 'pensees_negatives', 'rx' => '\bje\s+veux\s+(plus|pu)\s+(être\s+là|exister|vivre)\b'],
        ['type' => 'pensees_negatives', 'rx' => '\bme\s+(tuer|suicider)\b'],
        ['type' => 'pensees_negatives', 'rx' => '\bsuicide\b'],
        ['type' => 'pensees_negatives', 'rx' => '\b(en\s+finir|finir\s+avec\s+(ça|tout))\b'],
        ['type' => 'pensees_negatives', 'rx' => '\bjsuis\s+nul\s+je\s+sers\s+(à\s+)?rien\b'],

        // Violence subie (danger vital)
        ['type' => 'danger', 'rx' => '\bils\s+me\s+(frappent|tapent|battent)\b'],
        ['type' => 'danger', 'rx' => '\b(papa|maman|mon\s+père|ma\s+mère)\s+me\s+(frappe|tape|bat)\b'],
        ['type' => 'danger', 'rx' => '\b(touche|attouche)\b.{0,20}\b(parties\s+intimes|sexe|corps)\b'],
    ];

    /** Phrases « orange » : signaux importants mais pas critiques en eux-mêmes. */
    private const ORANGE_PATTERNS = [
        // #6 (V5) — Violence physique COMMISE par l'enfant (l'enfant est l'auteur,
        // ex. « j'ai giflé une meuf »). « gifler » vise toujours une personne → sûr.
        // Pour frapper/taper/cogner, on EXIGE un objet « personne » (pronom l'/lui
        // ou article/nom) afin d'éviter les faux positifs (« j'ai tapé dans le ballon »,
        // « j'ai poussé la porte »). Le prompt prend ensuite le relais (ne pas lâcher
        // le sujet, orienter vers un adulte).
        ['type' => 'danger', 'rx' => '\bj\'?ai\s+gifl\w*'],
        ['type' => 'danger', 'rx' => '\bje\s+(l\'|lui\s+)ai\s+(gifl\w*|frapp\w*|tap\w*|cogn\w*|mis\s+un\s+coup|mis\s+une\s+(gifle|claque))'],
        ['type' => 'danger', 'rx' => '\bj\'?ai\s+(frapp\w*|tap\w*|cogn\w*|battu)\s+(un|une|mon|ma|le|la|quelqu|.{0,6}\b(fille|gar[çc]on|copain|copine|camarade|[eé]l[èe]ve))'],

        // P10 (V4) — Humiliation par un adulte de l'école.
        // Important : ces patterns sont placés AVANT les patterns harcèlement génériques
        // pour ne pas être absorbés par "ils m'insultent".
        // Le pronom "m'/me" est OBLIGATOIRE pour éviter les faux positifs comme
        // "ma maîtresse insulte les autres" (l'enfant rapporte mais n'est pas la victime).
        ['type' => 'humiliation_adulte', 'rx' => '\b(ma\s+ma[îi]tresse|mon\s+ma[îi]tre|mon\s+(prof|enseignant)|mon\s+enseignante|le\s+directeur|la\s+directrice|le\s+surveillant|la\s+surveillante)\s+m[\'e]\s*(insulte|humilie|crie|hurle|traite|rabaisse)\b'],
        ['type' => 'humiliation_adulte', 'rx' => '\b(ma\s+ma[îi]tresse|mon\s+(prof|enseignant)|mon\s+enseignante)\s+.{0,30}\b(idiot|nul|b[êe]te|imb[eé]cile|stupide)\b'],
        ['type' => 'humiliation_adulte', 'rx' => '\b(elle|il)\s+m[\'e]\s*(insulte|humilie|rabaisse)\b.{0,40}\b(ma[îi]tresse|prof|enseignant)\b'],

        // Harcèlement / moqueries répétées
        ['type' => 'harcelement', 'rx' => '\b(harc[eè]l|harceler)\b'],
        ['type' => 'harcelement', 'rx' => '\bse\s+moquent?\s+de\s+moi\b'],
        ['type' => 'harcelement', 'rx' => '\bils?\s+m[\'e]\s*(embêtent?|insultent?|frappent?|tapent?|poussent?)\b'],
        ['type' => 'harcelement', 'rx' => '\b(tous\s+les\s+jours|chaque\s+jour|souvent)\b.{0,40}\b(moquent|embêtent|insultent)\b'],

        // Isolement
        ['type' => 'isolement',   'rx' => '\bje\s+suis\s+(tout\s+)?seul\b'],
        ['type' => 'isolement',   'rx' => '\bpersonne\s+ne\s+me\s+(parle|aime|comprend)\b'],
        ['type' => 'isolement',   'rx' => '\bj\'?ai\s+pas\s+d\'?amis?\b'],

        // Peur / dévalorisation forte (D7 : ex-tristesse -> detresse)
        ['type' => 'detresse',    'rx' => '\bj\'?ai\s+(tr[èe]s\s+)?peur\s+(de|qu[\'e])\s+(mes?\s+(parents|p[èe]re|m[èe]re)|papa|maman)\b'],
        ['type' => 'detresse',    'rx' => '\bje\s+(suis|me\s+sens)\s+nul\b'],
        ['type' => 'detresse',    'rx' => '\bje\s+sers\s+(à\s+)?rien\b'],
        ['type' => 'detresse',    'rx' => '\bpersonne\s+ne\s+m\'?aime\b'],
    ];

    /**
     * @return array{zone:string, alert_type:?string, matched:bool}
     *         zone: green|yellow|orange|red — niveau déclenché par les motifs (jamais en dessous de la zone fournie).
     */
    public function evaluate(string $childMessage, string $currentZone = 'green'): array
    {
        $msg = $this->normalize($childMessage);

        foreach (self::RED_PATTERNS as $entry) {
            if (preg_match('/' . $entry['rx'] . '/iu', $msg)) {
                return [
                    'zone'       => 'red',
                    'alert_type' => $entry['type'],
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

    /** Garantit que tout type retourné appartient à la nomenclature unique (D7). */
    public static function knownTypes(): array
    {
        return AlertType::values();
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
