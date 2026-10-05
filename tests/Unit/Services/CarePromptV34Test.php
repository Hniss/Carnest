<?php

namespace Tests\Unit\Services;

use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

/**
 * v3.4 — retours des pédopsychiatres (réunion du 2026-10-05) :
 *  - réponses de Care plus courtes (2-3 phrases courtes, une seule question) ;
 *  - aucun prénom d'un tiers dans les résumés destinés aux adultes ;
 *  - l'exercice de respiration proposé est signalé par une ligne technique (avatar guidé).
 * Toutes les autres règles du prompt (sécurité, mentor, secret, numéros) restent identiques à la v3.3.
 */
class CarePromptV34Test extends TestCase
{
    /** Empreintes de la v3.3 pour tout ce qui ne devait PAS changer en v3.4. */
    private const V33_TEMPLATE_HORS_BLOCS_MODIFIES = 'f5eaf602d04b41dd3d4ed4bcf83bfb036e9e44ea9271b560dc9f44562696ee22';
    private const V33_MEMOIRE_HORAIRES_NOTE = '23c23ba4f17630849ef8ad42342dba59fe51d6341e17d4ad82287cda2ed4bbf4';

    private const BLOCS_MODIFIES = ['LANGUE & TON', 'ACTIONS CONCRÈTES', 'PROTOCOLE DE FIN DE RÉPONSE', 'Ces trois lignes sont techniques', 'Ces quatre lignes sont techniques'];

    private function constant(string $name): string
    {
        return (new ReflectionClass(GeminiService::class))->getConstant($name);
    }

    private function block(string $title): string
    {
        foreach (preg_split("/\n\n/", $this->constant('SYSTEM_TEMPLATE')) as $block) {
            if (str_starts_with($block, $title)) {
                return $block;
            }
        }
        $this->fail("Section « {$title} » absente du prompt.");
    }

    private string $reply = '';
    private bool $faked = false;

    private function turn(string $content): array
    {
        $this->reply = $content;
        if (! $this->faked) {
            Http::fake(fn () => Http::response(['choices' => [['message' => ['content' => $this->reply], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 1]]));
            $this->faked = true;
        }

        return (new GeminiService('k', 'gemini-2.5-flash'))->chat([['role' => 'user', 'content' => 'salut']], 10);
    }

    public function test_prompt_version_is_v34(): void
    {
        $this->assertSame('v3.4', GeminiService::PROMPT_VERSION);
    }

    public function test_answers_are_short_with_a_single_question(): void
    {
        $tone = $this->block('LANGUE & TON');

        $this->assertStringContainsString('2 à 3 phrases courtes maximum', $tone);
        $this->assertStringContainsString('une seule question', $tone);
        $this->assertStringNotContainsString('2 à 4 phrases', $tone);
    }

    public function test_summaries_never_name_a_third_party(): void
    {
        $resume = $this->block('Ces quatre lignes sont techniques');
        $analysis = $this->constant('ANALYSIS_PROMPT');

        foreach ([$resume, $analysis] as $text) {
            $this->assertStringContainsString('« Adam m\'a frappé »', $text);
            $this->assertStringContainsString('« un camarade »', $text);
            $this->assertStringContainsString('« un enseignant »', $text);
            $this->assertStringContainsString('prénom de l\'élève', $text);
        }
    }

    public function test_breathing_exercise_is_reported_on_a_technical_line_and_hidden_from_the_child(): void
    {
        $protocol = $this->block('PROTOCOLE DE FIN DE RÉPONSE');
        $this->assertStringContainsString('EXERCICE: <none|carree|478>', $protocol);
        $this->assertStringContainsString('4-7-8', $this->block('ACTIONS CONCRÈTES'));
        $this->assertStringContainsString('respiration carrée', $this->block('ACTIONS CONCRÈTES'));

        $result = $this->turn("On essaie la respiration 4-7-8 ensemble ?\nALERT_TYPE: stress\nZONE: yellow\nRESUME: Yassine se dit stressé avant un contrôle.\nEXERCICE: 478");
        $this->assertSame('478', $result['exercise']);
        $this->assertStringNotContainsString('EXERCICE', $result['message']);

        $this->assertSame('carree', $this->turn("Respirons.\nEXERCICE: carree\nALERT_TYPE: stress\nZONE: yellow")['exercise']);
        $this->assertNull($this->turn("Bonjour.\nALERT_TYPE: none\nZONE: green\nEXERCICE: none")['exercise']);
        $this->assertNull($this->turn("Bonjour.\nALERT_TYPE: none\nZONE: green")['exercise']);
    }

    public function test_no_safety_rule_changed_since_v33(): void
    {
        $kept = array_filter(
            preg_split("/\n\n/", $this->constant('SYSTEM_TEMPLATE')),
            function ($b) {
                foreach (self::BLOCS_MODIFIES as $title) {
                    if (str_starts_with($b, $title)) {
                        return false;
                    }
                }
                return true;
            },
        );
        $this->assertSame(self::V33_TEMPLATE_HORS_BLOCS_MODIFIES, hash('sha256', implode("\n\n", $kept)),
            'Une règle du prompt autre que la longueur, les micro-aides et le protocole de fin a changé.');

        $others = implode("\n---\n", array_map(fn ($c) => $this->constant($c), ['MEMORY_USAGE_RULES', 'OUT_OF_HOURS_RULES', 'CARE_MEMORY_PROMPT']));
        $this->assertSame(self::V33_MEMOIRE_HORAIRES_NOTE, hash('sha256', $others));
    }
}
