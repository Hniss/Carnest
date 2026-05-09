<?php
namespace Tests\Unit\Services;

use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiServiceParseTest extends TestCase
{
    private function makeResponseBody(string $content): array
    {
        return [
            'choices' => [[
                'message' => ['content' => $content],
            ]],
        ];
    }

    private function service(): GeminiService
    {
        return new GeminiService('fake-key', 'gemini-2.5-flash');
    }

    public function test_chat_extracts_zone_and_strips_marker(): void
    {
        Http::fake([
            '*' => Http::response($this->makeResponseBody(
                "Salut ! Comment tu vas ?\nALERT_TYPE: none\nZONE: green"
            )),
        ]);

        $result = $this->service()->chat([
            ['role' => 'user', 'content' => 'bonjour'],
        ], 10);

        $this->assertSame('green', $result['zone']);
        $this->assertNull($result['alert_type']);
        $this->assertFalse($result['is_critical']);
        $this->assertFalse($result['low_confidence']);
        $this->assertStringNotContainsString('ZONE', $result['message']);
        $this->assertStringNotContainsString('ALERT_TYPE', $result['message']);
        $this->assertStringContainsString('Salut', $result['message']);
    }

    public function test_chat_promotes_to_red_on_critical_marker(): void
    {
        Http::fake([
            '*' => Http::response($this->makeResponseBody(
                "[ALERTE_CRITIQUE]Tu n'es pas seul, parle à un adulte de confiance maintenant.\n"
                . "ALERT_TYPE: detresse\nZONE: red"
            )),
        ]);

        $result = $this->service()->chat([
            ['role' => 'user', 'content' => "j'ai envie d'en finir"],
        ], 12);

        $this->assertSame('red', $result['zone']);
        $this->assertSame('detresse', $result['alert_type']);
        $this->assertTrue($result['is_critical']);
        $this->assertStringNotContainsString('[ALERTE_CRITIQUE]', $result['message']);
    }

    public function test_chat_forces_red_when_critical_marker_without_zone(): void
    {
        Http::fake([
            '*' => Http::response($this->makeResponseBody(
                "[ALERTE_CRITIQUE]Je t'écoute, tu n'es pas seul.\nALERT_TYPE: detresse"
            )),
        ]);

        $result = $this->service()->chat([
            ['role' => 'user', 'content' => 'message'],
        ], 12);

        $this->assertSame('red', $result['zone']);
        $this->assertTrue($result['is_critical']);
        $this->assertSame('detresse', $result['alert_type']);
    }

    public function test_chat_low_confidence_when_no_zone(): void
    {
        Http::fake([
            '*' => Http::response($this->makeResponseBody("Réponse sans marqueur de zone.")),
        ]);

        $result = $this->service()->chat([
            ['role' => 'user', 'content' => 'bonjour'],
        ], 10);

        $this->assertTrue($result['low_confidence']);
        $this->assertSame('green', $result['zone']);
    }

    public function test_chat_extracts_alert_type_correctly(): void
    {
        Http::fake([
            '*' => Http::response($this->makeResponseBody(
                "C'est important ce que tu me dis. Est-ce que ça arrive souvent ?\n"
                . "ALERT_TYPE: harcelement\nZONE: orange"
            )),
        ]);

        $result = $this->service()->chat([
            ['role' => 'user', 'content' => 'on se moque de moi'],
        ], 9);

        $this->assertSame('orange', $result['zone']);
        $this->assertSame('harcelement', $result['alert_type']);
        $this->assertFalse($result['is_critical']);
    }

    public function test_chat_ignores_unknown_alert_type(): void
    {
        Http::fake([
            '*' => Http::response($this->makeResponseBody(
                "Réponse normale.\nALERT_TYPE: foobar\nZONE: yellow"
            )),
        ]);

        $result = $this->service()->chat([
            ['role' => 'user', 'content' => 'bonjour'],
        ], 10);

        $this->assertNull($result['alert_type']);
        $this->assertSame('yellow', $result['zone']);
    }

    /**
     * P14 : si finish_reason=length, on tronque proprement à la dernière phrase
     * complète pour ne jamais afficher « Est-ce qu'il y… ».
     */
    public function test_chat_truncates_at_last_sentence_when_length_finish(): void
    {
        // 1er appel : finish_reason=length avec phrase coupée.
        // 2nd appel (retry max_tokens=3000) : retourne aussi length avec coupure.
        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => ['content' => "Je comprends que tu sois triste. C'est important. Est-ce qu'il y\nALERT_TYPE: tristesse\nZONE: orange"],
                        'finish_reason' => 'length',
                    ]],
                ], 200)
                ->push([
                    'choices' => [[
                        'message' => ['content' => "Je comprends que tu sois triste. C'est important. Est-ce qu'il y\nALERT_TYPE: tristesse\nZONE: orange"],
                        'finish_reason' => 'length',
                    ]],
                ], 200),
        ]);

        $result = $this->service()->chat([
            ['role' => 'user', 'content' => "je suis triste"],
        ], 10);

        $this->assertStringNotContainsString("Est-ce qu'il y", $result['message']);
        $this->assertStringContainsString("important", $result['message']);
        $this->assertSame('orange', $result['zone']);
    }

    /**
     * P4 (V4) — ALERT_TYPE multi-valeurs séparées par "|" (cas réel observé) :
     * la ligne entière doit être strippée du message visible et le premier
     * alert_type valide doit être extrait.
     */
    public function test_chat_strips_compound_alert_type_with_pipe(): void
    {
        Http::fake([
            '*' => Http::response($this->makeResponseBody(
                "Je comprends que tu te sentes triste et seul.\n"
                . "ALERT_TYPE: tristesse|isolement\n"
                . "ZONE: orange"
            )),
        ]);

        $result = $this->service()->chat([
            ['role' => 'user', 'content' => 'je suis triste et seul'],
        ], 10);

        $this->assertStringNotContainsString('ALERT_TYPE', $result['message']);
        $this->assertStringNotContainsString('tristesse|isolement', $result['message']);
        $this->assertStringNotContainsString('|', $result['message']);
        $this->assertSame('tristesse', $result['alert_type']);
        $this->assertSame('orange', $result['zone']);
    }

    /**
     * P4 (V4) — Tags techniques inconnus (RISK_LEVEL, CATEGORY, SCORE, CONFIDENCE) :
     * doivent être strippés du message visible quoi qu'il arrive.
     */
    public function test_chat_strips_unknown_technical_tags(): void
    {
        Http::fake([
            '*' => Http::response($this->makeResponseBody(
                "Je t'écoute, raconte-moi ce qui s'est passé.\n"
                . "RISK_LEVEL: 0.8\n"
                . "CATEGORY: bullying\n"
                . "SCORE: 35\n"
                . "CONFIDENCE: high\n"
                . "ALERT_TYPE: harcelement\n"
                . "ZONE: orange"
            )),
        ]);

        $result = $this->service()->chat([
            ['role' => 'user', 'content' => 'on me harcèle'],
        ], 10);

        $this->assertStringNotContainsString('RISK_LEVEL', $result['message']);
        $this->assertStringNotContainsString('CATEGORY', $result['message']);
        $this->assertStringNotContainsString('SCORE', $result['message']);
        $this->assertStringNotContainsString('CONFIDENCE', $result['message']);
        $this->assertStringNotContainsString('bullying', $result['message']);
        $this->assertStringNotContainsString('0.8', $result['message']);
        $this->assertSame('harcelement', $result['alert_type']);
        $this->assertSame('orange', $result['zone']);
    }

    /**
     * P8 (V4) — directive d'accord de genre injectée dans le system prompt
     * selon le gender passé. On utilise Http::assertSent pour inspecter le payload.
     */
    public function test_chat_injects_masculine_gender_directive(): void
    {
        Http::fake([
            '*' => Http::response($this->makeResponseBody("Salut !\nALERT_TYPE: none\nZONE: green")),
        ]);

        $this->service()->chat([
            ['role' => 'user', 'content' => 'bonjour'],
        ], 10, 'm');

        Http::assertSent(function ($request) {
            $system = collect($request->data()['messages'] ?? [])
                ->firstWhere('role', 'system')['content'] ?? '';
            return str_contains($system, 'GARÇON')
                && str_contains($system, 'accords masculins');
        });
    }

    public function test_chat_injects_feminine_gender_directive(): void
    {
        Http::fake([
            '*' => Http::response($this->makeResponseBody("Salut !\nALERT_TYPE: none\nZONE: green")),
        ]);

        $this->service()->chat([
            ['role' => 'user', 'content' => 'bonjour'],
        ], 10, 'f');

        Http::assertSent(function ($request) {
            $system = collect($request->data()['messages'] ?? [])
                ->firstWhere('role', 'system')['content'] ?? '';
            return str_contains($system, 'FILLE')
                && str_contains($system, 'accords féminins');
        });
    }

    public function test_chat_injects_neutral_gender_directive_when_null(): void
    {
        Http::fake([
            '*' => Http::response($this->makeResponseBody("Salut !\nALERT_TYPE: none\nZONE: green")),
        ]);

        $this->service()->chat([
            ['role' => 'user', 'content' => 'bonjour'],
        ], 10, null);

        Http::assertSent(function ($request) {
            $system = collect($request->data()['messages'] ?? [])
                ->firstWhere('role', 'system')['content'] ?? '';
            return str_contains($system, "n'est pas précisé")
                && str_contains($system, 'Évite tout adjectif marqué');
        });
    }

    public function test_analyze_session_returns_summary_and_zone(): void
    {
        Http::fake([
            '*' => Http::response($this->makeResponseBody(
                "SUMMARY: L'enfant exprime de la tristesse récurrente liée à des moqueries.\n"
                . "ALERT_TYPE: harcelement\nZONE: orange"
            )),
        ]);

        $result = $this->service()->analyzeSession([
            ['role' => 'user', 'content' => 'mes camarades se moquent de moi'],
        ], 10);

        $this->assertSame('orange', $result['zone']);
        $this->assertSame('harcelement', $result['alert_type']);
        $this->assertStringContainsString('moqueries', $result['summary']);
        $this->assertFalse($result['lowConfidence']);
    }
}
