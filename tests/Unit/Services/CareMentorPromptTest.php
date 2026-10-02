<?php

namespace Tests\Unit\Services;

use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

/**
 * v3.3 — Care ne révèle pas son fonctionnement à l'enfant.
 *
 * Défaut observé en conversation réelle : un élève a demandé ce qu'est une alerte et Care
 * a expliqué l'alerte du système CareNest. Décision de Hamza : Care se présente comme un
 * mentor (conseils, accompagnement, devenir un leader), en mots adaptés à l'âge ; l'enfant
 * ne doit pas comprendre que l'échange peut être partagé avec ses parents ou les référents.
 * Ce qui reste non négociable : Care ne promet jamais le secret et ne ment jamais.
 */
class CareMentorPromptTest extends TestCase
{
    /** Empreintes figées de la v3.2 pour tout ce qui ne devait PAS changer. */
    private const V32_TEMPLATE_HORS_CONFIDENTIALITE = 'edfe63f9f390d0cb34425187c95c172f52538ccae01e7b88529cddef1b27bbc8';
    private const V32_AUTRES_PROMPTS = '54163b1805f01d41936524eebae9183f210e42d14b4ee041ed8d73b6cd460ca9';

    private function systemPrompt(int $age = 10): string
    {
        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->data();
            return Http::response(['choices' => [['message' => ['content' => "Ok.\nALERT_TYPE: none\nZONE: green"], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 1]]);
        });
        (new GeminiService('k', 'gemini-2.5-flash'))->chat([['role' => 'user', 'content' => 'salut']], $age);

        return $captured[0]['messages'][0]['content'];
    }

    private function constant(string $name): string
    {
        return (new ReflectionClass(GeminiService::class))->getConstant($name);
    }

    /** Bloc du gabarit (paragraphe séparé par une ligne vide) qui commence par ce titre. */
    private function block(string $title): string
    {
        foreach (preg_split("/\n\n/", $this->constant('SYSTEM_TEMPLATE')) as $block) {
            if (str_starts_with($block, $title)) {
                return $block;
            }
        }
        $this->fail("Section « {$title} » absente du prompt.");
    }

    public function test_care_presents_itself_as_a_mentor_without_describing_the_mechanism(): void
    {
        $rule = $this->block('CE QUE TU ES ET À QUOI TU SERS');

        foreach (['ce qu\'est Care', 'à quoi tu sers', '« alerte »', 'ce qui arrive à ce qu\'il raconte', 'comment tu fonctionnes'] as $trigger) {
            $this->assertStringContainsString($trigger, $rule);
        }
        foreach (['alerte', 'signalement', 'référent', 'psychologue de l\'école', 'analyse', 'zone', 'couleur', 'transmission'] as $word) {
            $this->assertStringContainsString($word, $rule, "Le mot interdit « {$word} » doit être nommé dans la règle.");
        }
        $this->assertStringContainsString('mentor', $rule);
        $this->assertStringContainsString('leader', $rule);
        $this->assertStringContainsString('adulte de confiance', $rule, 'La règle doit rappeler que l\'orientation vers un adulte de confiance continue.');
    }

    public function test_the_mentor_wording_follows_the_existing_age_groups(): void
    {
        $rule = $this->block('CE QUE TU ES ET À QUOI TU SERS');

        // Le groupe d'âge est déjà injecté en tête du prompt (Child::ageGroupFor) : la règle
        // s'y réfère et donne une formulation par tranche, sans nouveau mécanisme.
        foreach (['5-7', '8-11', '12-18'] as $group) {
            $this->assertMatchesRegularExpression('/^\s*•\s*' . preg_quote($group, '/') . '\s*:/m', $rule);
        }
        // Mêmes emplacements qu'en v3.2 (âge, groupe, style de langage, genre, mémoire, hors horaires).
        $this->assertSame(5, substr_count($this->constant('SYSTEM_TEMPLATE'), '%s'), 'Aucun nouvel emplacement : le mécanisme de tranche d\'âge existant suffit.');
        $this->assertSame(1, substr_count($this->constant('SYSTEM_TEMPLATE'), '%d'));

        foreach ([6 => '5-7', 10 => '8-11', 15 => '12-18'] as $age => $group) {
            $this->assertStringContainsString("groupe d'âge: {$group}", $this->systemPrompt($age));
        }
    }

    public function test_confidentiality_no_longer_tells_the_child_that_an_adult_may_be_warned(): void
    {
        $prompt = $this->systemPrompt();

        $this->assertStringNotContainsString('pourra être prévenu', $prompt);
        $this->assertStringNotContainsString("n'est pas envoyé automatiquement à tes parents", $prompt);
        $this->assertStringContainsString('CONFIDENTIALITÉ — JAMAIS DE PROMESSE DE SECRET TOTAL', $prompt);
    }

    public function test_care_never_promises_secrecy_and_never_lies(): void
    {
        $confidentiality = $this->block('CONFIDENTIALITÉ — JAMAIS DE PROMESSE DE SECRET TOTAL');

        $this->assertStringContainsString('N\'utilise JAMAIS les mots « espace secret », « c\'est entre toi et moi », « je ne le dirai à personne », « je ne peux pas en parler aux autres ». Ces phrases sont interdites.', $confidentiality);
        $this->assertStringContainsString('ne promets JAMAIS le secret', $confidentiality);
        $this->assertStringContainsString('ne mens jamais', $confidentiality);
    }

    public function test_direct_question_about_telling_parents_is_answered_neither_yes_nor_no_with_examples_by_age(): void
    {
        $confidentiality = $this->block('CONFIDENTIALITÉ — JAMAIS DE PROMESSE DE SECRET TOTAL');

        $this->assertStringContainsString('tu vas le dire à mes parents ?', $confidentiality);
        $this->assertStringContainsString('ne réponds ni « oui » ni « non »', $confidentiality);
        foreach (['5-7', '8-11', '12-18'] as $group) {
            $this->assertMatchesRegularExpression('/^\s*•\s*' . preg_quote($group, '/') . '\s*:\s*«/m', $confidentiality);
        }
    }

    public function test_no_other_rule_of_the_prompt_changed(): void
    {
        $kept = array_filter(
            preg_split("/\n\n/", $this->constant('SYSTEM_TEMPLATE')),
            fn ($b) => ! str_starts_with($b, 'CONFIDENTIALITÉ — ') && ! str_starts_with($b, 'CE QUE TU ES ET À QUOI TU SERS'),
        );
        $this->assertSame(self::V32_TEMPLATE_HORS_CONFIDENTIALITE, hash('sha256', implode("\n\n", $kept)),
            'Une règle du prompt autre que la confidentialité et la nouvelle règle « mentor » a changé.');

        $others = implode("\n---\n", array_map(fn ($c) => $this->constant($c), ['MEMORY_USAGE_RULES', 'OUT_OF_HOURS_RULES', 'ANALYSIS_PROMPT', 'CARE_MEMORY_PROMPT']));
        $this->assertSame(self::V32_AUTRES_PROMPTS, hash('sha256', $others));
    }

    public function test_prompt_version_is_v33(): void
    {
        $this->assertSame('v3.3', GeminiService::PROMPT_VERSION);
    }
}
