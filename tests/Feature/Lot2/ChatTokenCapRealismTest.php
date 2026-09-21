<?php

namespace Tests\Feature\Lot2;

use App\Livewire\Child\ChatInterface;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Services\AIService;
use App\Services\GeminiService;
use App\Services\TokenBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * D8 — RÉALISME du plafond journalier.
 *
 * Les autres tests du plafond fixent `tokens` à la main (120, 999999…) : ils
 * vérifient la RÈGLE (clôture en vert, jamais de coupure ailleurs) mais jamais
 * le VOLUME réellement consommé par un échange. C'est ce trou qui a laissé
 * passer le défaut remonté par le test de Marouane : Care clôturait la
 * conversation dès le premier échange.
 *
 * Ici, aucun compteur n'est écrit à la main. Le faux fournisseur calcule ses
 * décomptes à partir du PAYLOAD RÉELLEMENT ENVOYÉ (prompt système compris,
 * ~15 500 caractères) selon l'approximation usuelle de 4 caractères par token,
 * exactement comme le ferait l'API. Aucun appel réseau : `Http::fake`.
 *
 * Base explicite de la vérification : un enfant doit pouvoir tenir une
 * conversation normale — au moins quinze échanges — sans jamais atteindre le
 * plafond, celui-ci étant un détecteur d'usage anormal et non un frein
 * technique (verbatim spec : « ce seuil ne doit jamais bloquer techniquement
 * la conversation, quelle que soit la situation »).
 */
class ChatTokenCapRealismTest extends TestCase
{
    use RefreshDatabase;

    /** Nombre d'échanges qu'une conversation normale doit pouvoir atteindre sans plafond. */
    private const NORMAL_EXCHANGES = 15;

    private const REPLY = "Merci de me le raconter. Qu'est-ce que tu as préféré dans ta journée ?\nALERT_TYPE: none\nZONE: green";

    public function test_a_normal_conversation_never_reaches_the_daily_cap(): void
    {
        Mail::fake();
        $school = School::factory()->create();
        $child  = Child::factory()->for($school)->create(['age' => 10]);
        $this->actingAs($child, 'child');

        $cap = app(TokenBudget::class)->cap($school);
        $this->assertSame(TokenBudget::DEFAULT_CAP, $cap, 'Le plafond par défaut doit rester 10 000.');

        $this->fakeProviderWithRealisticUsage();
        $this->app->instance(AIService::class, new GeminiService('fake', 'gemini-2.5-flash'));

        $component = Livewire::test(ChatInterface::class);

        $afterFirst = null;
        for ($i = 1; $i <= self::NORMAL_EXCHANGES; $i++) {
            $component->set('input', "Aujourd'hui à l'école j'ai fait du sport, échange numéro {$i}.")
                ->call('sendMessage')
                ->call('fetchReply');

            $used = (int) ChatSession::find($component->get('sessionId'))->tokens_used;
            $afterFirst ??= $used;

            $this->assertFalse(
                $component->get('sessionClosed'),
                "La conversation a été fermée par le plafond à l'échange {$i} ({$used} tokens comptés sur un plafond de {$cap})."
            );
            $this->assertLessThan(
                $cap,
                $used,
                "Le plafond de {$cap} tokens est atteint dès l'échange {$i} : {$used} tokens comptés."
            );
        }

        // Aucun message de clôture de plafond n'a été servi à l'enfant.
        foreach ($component->get('messages') as $message) {
            $this->assertNotContains($message['content'], ChatInterface::CAP_CLOSING_MESSAGES);
        }

        // Un échange seul ne doit pas consommer une fraction déraisonnable du plafond.
        // 5 % = 500 tokens : très au-dessus d'un tour réel (réponse ~40 tokens +
        // message de l'enfant ~20), très en dessous du prompt système (~3 900).
        $this->assertLessThan(
            (int) ($cap * 0.05),
            $afterFirst,
            "Un seul échange consomme {$afterFirst} tokens, soit plus de 5 % du plafond journalier de {$cap}."
        );
    }

    /**
     * Faux fournisseur : mêmes clés que l'API Chat Completions (prompt_tokens,
     * completion_tokens, total_tokens), calculées sur le contenu réel envoyé et
     * renvoyé — 4 caractères par token.
     */
    private function fakeProviderWithRealisticUsage(): void
    {
        Http::fake(function ($request) {
            $promptChars = 0;
            foreach ((array) ($request->data()['messages'] ?? []) as $message) {
                $promptChars += mb_strlen((string) ($message['content'] ?? ''));
            }
            $promptTokens     = intdiv($promptChars, 4);
            $completionTokens = intdiv(mb_strlen(self::REPLY), 4);

            return Http::response([
                'choices' => [['message' => ['content' => self::REPLY], 'finish_reason' => 'stop']],
                'usage'   => [
                    'prompt_tokens'     => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'total_tokens'      => $promptTokens + $completionTokens,
                ],
                'model' => 'gemini-2.5-flash',
            ]);
        });
    }
}
