<?php

namespace Tests\Unit\Services;

use App\Services\Adjudicator;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Corrections du 29/09 — le résumé courant, et les budgets de sortie qui tronquaient.
 *
 * Recette réelle du 28/09 :
 *  - 13 alertes sur 14 affichaient « Aucun résumé disponible pour ce signal. » : le
 *    résumé n'était produit qu'à la clôture, alors que l'alerte partait plus tôt.
 *  - la double vérification était coupée en plein JSON à 400 jetons (3 fois sur 3),
 *    donc aucune n'aboutissait ;
 *  - la mémoire de Care était coupée en plein mot à 200 jetons (« L'enfant a un »).
 */
class RunningSummaryAndBudgetsTest extends TestCase
{
    private function service(): GeminiService
    {
        return new GeminiService('fake-key', 'gemini-2.5-flash');
    }

    private function fakeTurn(string $content): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'model'   => 'gemini-2.5-flash',
            'usage'   => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
        ])]);
    }

    /** Le résumé courant est rendu dans le MÊME appel que la zone et le type. */
    public function test_the_turn_carries_a_running_summary(): void
    {
        $this->fakeTurn(
            "Je comprends que ce soit difficile.\n"
            . "ALERT_TYPE: harcelement\n"
            . "ZONE: orange\n"
            . "RESUME: L'enfant raconte que des camarades se moquent de lui chaque jour à la récréation et qu'il a peur d'en parler."
        );

        $result = $this->service()->chat([['role' => 'user', 'content' => 'ils se moquent de moi']], 10);

        $this->assertSame('harcelement', $result['alert_type']);
        $this->assertSame('orange', $result['zone']);
        $this->assertStringContainsString('se moquent de lui', (string) $result['summary']);
    }

    /** Un résumé accentué (RÉSUMÉ) est reconnu comme RESUME. */
    public function test_the_running_summary_is_read_with_or_without_accents(): void
    {
        $this->fakeTurn("D'accord.\nALERT_TYPE: none\nZONE: green\nRÉSUMÉ: L'enfant parle de sa journée au calme.");

        $result = $this->service()->chat([['role' => 'user', 'content' => 'ça va']], 10);

        $this->assertStringContainsString('au calme', (string) $result['summary']);
    }

    /** Le résumé est une ligne technique : l'enfant ne doit JAMAIS la voir. */
    public function test_the_running_summary_never_reaches_the_child(): void
    {
        $this->fakeTurn(
            "Je suis là pour toi.\nALERT_TYPE: detresse\nZONE: orange\nRESUME: L'enfant exprime une tristesse persistante."
        );

        $result = $this->service()->chat([['role' => 'user', 'content' => 'je suis triste']], 10);

        $this->assertSame('Je suis là pour toi.', $result['message']);
        foreach (['RESUME', 'RÉSUMÉ', 'tristesse persistante'] as $leak) {
            $this->assertStringNotContainsString($leak, $result['message']);
        }
    }

    /** Aucun résumé rendu : la valeur est nulle, on n'invente rien. */
    public function test_a_turn_without_running_summary_returns_null(): void
    {
        $this->fakeTurn("Salut !\nALERT_TYPE: none\nZONE: green");

        $this->assertNull($this->service()->chat([['role' => 'user', 'content' => 'bonjour']], 10)['summary']);
    }

    /**
     * Le budget du second passage doit laisser passer un JSON complet. Mesuré en
     * recette : coupé en plein « {"zone": "red", » à 400 jetons, complet à 800.
     */
    public function test_the_double_check_budget_is_large_enough_for_a_complete_json(): void
    {
        $this->assertGreaterThanOrEqual(800, Adjudicator::MAX_TOKENS);
    }

    /** Même défaut sur la mémoire de Care : tronquée en plein mot à 200 jetons. */
    public function test_the_care_memory_budget_is_large_enough(): void
    {
        $this->assertGreaterThanOrEqual(800, GeminiService::CARE_MEMORY_MAX_TOKENS);

        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->data();

            return Http::response([
                'choices' => [['message' => ['content' => 'Aime le football et le dessin.'], 'finish_reason' => 'stop']],
                'model'   => 'gemini-2.5-flash',
                'usage'   => ['total_tokens' => 30],
            ]);
        });

        $this->service()->generateCareMemory([['role' => 'user', 'content' => 'jaime le foot']], 10);

        $this->assertSame(GeminiService::CARE_MEMORY_MAX_TOKENS, $captured[0]['max_tokens']);
    }

    /**
     * Correction du typage (recette, défaut M2) : des coups entre enfants à la
     * récréation étaient classés « danger » — un type vital qui envoie le nom de
     * l'élève à l'administration — tandis qu'une violence familiale rapportée par
     * une enfant de 6 ans restait « détresse, modérée ».
     */
    public function test_the_system_prompt_draws_the_frontier_between_danger_and_harcelement(): void
    {
        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->data();

            return Http::response([
                'choices' => [['message' => ['content' => "Ok.\nALERT_TYPE: none\nZONE: green"], 'finish_reason' => 'stop']],
                'model'   => 'gemini-2.5-flash',
            ]);
        });

        $this->service()->chat([['role' => 'user', 'content' => 'bonjour']], 10);
        $system = $captured[0]['messages'][0]['content'];

        $this->assertStringContainsString('FRONTIÈRE ENTRE LES TYPES', $system);
        // Des coups entre enfants restent du harcèlement, pas un signal vital.
        $this->assertStringContainsString('restent « harcelement »', $system);
        // Un enfant témoin de violence à la maison relève bien de « danger ».
        $this->assertStringContainsString('témoin d\'une violence familiale', $system);
        // Le protocole de fin de réponse réclame désormais le résumé courant.
        $this->assertStringContainsString('RESUME:', $system);
        // Les interdits de sécurité existants ne sont pas touchés.
        $this->assertStringContainsString('USAGE DU NUMÉRO 2511 — STRICTEMENT ENCADRÉ', $system);
        $this->assertStringContainsString('MODE SÉCURITÉ — ABSOLU', $system);
    }
}
