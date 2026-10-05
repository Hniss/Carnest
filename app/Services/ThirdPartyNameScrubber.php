<?php

namespace App\Services;

use App\Models\Child;
use App\Models\User;

/**
 * Retour des pédopsychiatres (2026-10-05) — un résumé destiné aux adultes ne nomme jamais un
 * tiers. Le prompt l'impose au modèle ; ce contrôle serveur rattrape les noms que l'école
 * connaît : un autre élève de l'école devient « un camarade », un membre du personnel ou un
 * parent d'élève de l'école « un adulte ». Le prénom de l'élève concerné reste.
 *
 * Limite : un prénom inconnu de l'école (cousin, voisin, enfant d'une autre école) n'est
 * couvert que par la consigne du prompt.
 */
class ThirdPartyNameScrubber
{
    private const TITLES = ['m', 'mme', 'mlle', 'mr', 'dr', 'pr', 'démo', 'demo'];

    public function scrub(?string $text, ?Child $child): ?string
    {
        if ($text === null || trim($text) === '' || $child === null || $child->school_id === null) {
            return $text;
        }

        $own = $this->tokens((string) $child->name);

        $classmates = Child::where('school_id', $child->school_id)->whereKeyNot($child->id)->pluck('name');
        $adults = User::query()
            ->whereHas('schools', fn ($q) => $q->where('schools.id', $child->school_id))
            ->orWhereHas('children', fn ($q) => $q->where('children.school_id', $child->school_id))
            ->pluck('name');

        $replacements = [];
        foreach ([[$adults, 'un adulte'], [$classmates, 'un camarade']] as [$names, $label]) {
            foreach ($names as $name) {
                $full = trim((string) preg_replace('/\([^)]*\)/u', '', (string) $name));
                $parts = array_values(array_filter(
                    $this->tokens($full),
                    fn ($t) => ! in_array($t, $own, true),
                ));
                if (count($parts) > 1) {
                    $replacements[$full] = $label;
                }
                foreach ($parts as $part) {
                    $replacements[$part] ??= $label;
                }
            }
        }

        // Les noms complets d'abord, puis les plus longs : « Adam Kettani » avant « Adam ».
        uksort($replacements, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($replacements as $name => $label) {
            $text = $this->replaceName($text, $name, $label);
        }

        return $text;
    }

    /** @return list<string> mots porteurs d'un nom, en minuscules, sans titres ni initiales. */
    private function tokens(string $name): array
    {
        $words = preg_split('/[\s\-.]+/u', mb_strtolower(trim($name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::TITLES, true)));
    }

    private function replaceName(string $text, string $name, string $label): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        $pattern = implode('\s+', array_map(fn ($w) => preg_quote($w, '/'), $words));

        $regex = '/(?<![\p{L}\p{N}])(?:(qu|d|jusqu|lorsqu|puisqu)[\'’]|\b(que|de)\s+)?' . $pattern . '(?![\p{L}\p{N}])/iu';

        $text = (string) preg_replace_callback($regex, function (array $m) use ($label) {
            $elided = $m[1] ?? '';
            $full = $m[2] ?? '';
            if ($elided !== '') {
                return $elided . "'" . $label;
            }
            if ($full !== '') {
                $lead = mb_strtolower($full) === 'que' ? 'qu' : 'd';
                $lead = ctype_upper($full[0]) ? ucfirst($lead) : $lead;

                return $lead . "'" . $label;
            }

            return $label;
        }, $text);

        // Majuscule en début de phrase.
        return (string) preg_replace_callback(
            '/(^|[.!?…]\s+)(' . preg_quote($label, '/') . ')/u',
            fn ($m) => $m[1] . mb_strtoupper(mb_substr($m[2], 0, 1)) . mb_substr($m[2], 1),
            $text,
        );
    }
}
