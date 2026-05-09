<?php

namespace App\Services;

/**
 * Résout le niveau d'alerte (low|moderate|high|critical) à partir de :
 *  - la zone finale (yellow|orange|red)
 *  - le type d'alerte (harcelement|detresse|isolement|tristesse|stress|danger)
 *  - les messages bruts de l'enfant (analyse lexicale en mémoire, jamais stockée)
 *
 * Règles (Probleme CareNest V3 §9, §10) :
 *  - red                                          → critical
 *  - harcèlement avec peur représailles + répétition + public → high
 *  - isolement long (≥ 1 semaine) ou détresse familiale forte → high
 *  - orange seul                                  → moderate
 *  - yellow                                       → low
 */
class AlertLevelResolver
{
    private const HIGH_RETALIATION_FEAR = [
        '\bj\'?ai\s+peur\s+d\'?en\s+parler\b',
        '\bils?\s+vont\s+(encore\s+plus\s+)?m[\'e]\s*(emb[eê]ter|frapper|taper)\b',
        '\b(repr[eé]sailles?|vengeance)\b',
    ];

    private const HIGH_REPETITION = [
        '\b(tous\s+les\s+jours|chaque\s+jour|toute\s+la\s+semaine)\b',
        '\b(toujours\s+les\s+m[eê]mes?\s+[eé]l[èe]ves?)\b',
        '\b(depuis\s+(plusieurs\s+(jours|semaines)|longtemps|deux\s+semaines|un\s+mois))\b',
    ];

    private const HIGH_PUBLIC = [
        '\bdevant\s+tout\s+le\s+monde\b',
        '\bdevant\s+(la\s+classe|tous?)\b',
        '\b(en\s+pleine?|dans\s+la)\s+(cour|classe|cantine)\b',
    ];

    private const HIGH_FAMILY_FEAR = [
        '\bj\'?ai\s+(tr[èe]s\s+)?peur\s+de\s+rentrer\b',
        '\b(papa|maman|mes\s+parents)\s+(vont\s+)?(crier|hurler|me\s+frapper|me\s+taper|me\s+punir)\b',
        '\bpeur\s+de\s+(papa|maman|mon\s+p[èe]re|ma\s+m[èe]re)\b',
    ];

    private const HIGH_ISOLATION_DURATION = [
        '\bdepuis\s+(plus\s+d\'?une?\s+semaine|deux\s+semaines|un\s+mois)\b',
        '\bje\s+mange\s+toujours\s+seul\b',
        '\bpersonne\s+ne\s+(veut|me\s+parle)\s+depuis\b',
    ];

    /**
     * P10 (V4) — Humiliation par un adulte de l'école.
     * Indicateurs lexicaux qu'un adulte (enseignant, directeur, surveillant) humilie l'enfant.
     * Une seule détection suffit à pousser l'alerte en 'high' (combinée avec alert_type=humiliation_adulte).
     */
    private const HIGH_ADULT_HUMILIATION = [
        // Le pronom m'/me est OBLIGATOIRE pour ne pas matcher "ma maîtresse insulte les autres" (rapport tiers).
        '\b(ma\s+ma[îi]tresse|mon\s+(prof|enseignant)|mon\s+enseignante|le\s+directeur|la\s+directrice|le\s+surveillant)\s+m[\'e]\s*(insulte|humilie|crie\s+dessus|rabaisse|traite\s+d[\'e]\s*idiot)\b',
        '\b(elle|il)\s+me\s+(dit\s+que\s+je\s+suis|appelle)\s+(idiot|nul|b[êe]te|imb[eé]cile)\b',
    ];

    /**
     * @param array<int,string> $childMessages Messages bruts de l'enfant — utilisés en mémoire uniquement.
     */
    public function resolve(?string $alertType, string $zone, array $childMessages): string
    {
        if ($zone === 'red') {
            return 'critical';
        }

        if ($zone === 'yellow') {
            return 'low';
        }

        // Zone orange → on évalue la gravité contextuelle
        if ($zone !== 'orange') {
            return 'moderate';
        }

        $haystack = mb_strtolower(implode("\n", $childMessages));

        $factors = 0;

        if ($this->matchAny($haystack, self::HIGH_RETALIATION_FEAR)) {
            $factors += 2; // peur représailles = signal très fort
        }
        if ($this->matchAny($haystack, self::HIGH_REPETITION)) {
            $factors += 1;
        }
        if ($this->matchAny($haystack, self::HIGH_PUBLIC)) {
            $factors += 1;
        }
        if ($this->matchAny($haystack, self::HIGH_FAMILY_FEAR)) {
            $factors += 2;
        }
        if ($this->matchAny($haystack, self::HIGH_ISOLATION_DURATION)) {
            $factors += 1;
        }

        // Cas spéciaux : détresse et danger en orange basculent vite en high
        if (in_array($alertType, ['detresse', 'danger'], true)) {
            $factors += 1;
        }

        // P10 (V4) — Humiliation par un adulte de l'école.
        // Si le type est explicitement humiliation_adulte, on pousse direct en high (factors += 2).
        // En complément, si le lexique d'humiliation par adulte est présent dans les messages,
        // on ajoute encore +1 (cumulable avec la répétition pour rester high).
        if ($alertType === 'humiliation_adulte') {
            $factors += 2;
        }
        if ($this->matchAny($haystack, self::HIGH_ADULT_HUMILIATION)) {
            $factors += 1;
        }

        return $factors >= 2 ? 'high' : 'moderate';
    }

    /**
     * @param array<int,string> $patterns
     */
    private function matchAny(string $haystack, array $patterns): bool
    {
        foreach ($patterns as $rx) {
            if (preg_match('/' . $rx . '/iu', $haystack)) {
                return true;
            }
        }
        return false;
    }
}
