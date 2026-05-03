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
