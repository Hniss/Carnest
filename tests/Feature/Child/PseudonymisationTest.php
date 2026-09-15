<?php

namespace Tests\Feature\Child;

use App\Livewire\Child\ChatInterface;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Services\AIService;
use App\Services\GeminiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * D10 (MVP v3) — Aucune donnée d'identité (prénom, nom d'école, classe) ne part
 * vers le fournisseur d'IA : ni dans le prompt système, ni dans les messages.
 */
class PseudonymisationTest extends TestCase
{
    use RefreshDatabase;

    public function test_nothing_identifying_is_sent_to_the_provider(): void
    {
        $school = School::factory()->create(['name' => 'Ecole Zorglub Unique']);
        $child = Child::factory()->for($school)->create([
            'name'   => 'Prenomrare Nomrare',
            'age'    => 10,
            'classe' => 'CM2-ZZ',
        ]);
        // Historique pour que le bloc mémoire soit construit.
        $session = ChatSession::create([
            'child_id' => $child->id, 'school_id' => $school->id,
            'started_at' => CarbonImmutable::now()->subDays(2), 'ended_at' => CarbonImmutable::now()->subDays(2),
            'zone' => 'orange', 'ai_summary' => 'Enfant un peu isolé.',
        ]);
        $session->ended_at = CarbonImmutable::now()->subDays(2);
        $session->save();

        $this->actingAs($child, 'child');

        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->data();
            return Http::response([
                'choices' => [['message' => ['content' => "Je t'écoute.\nALERT_TYPE: none\nZONE: green"], 'finish_reason' => 'stop']],
                'usage'   => ['total_tokens' => 10],
                'model'   => 'gemini-2.5-flash',
            ]);
        });
        $this->app->instance(AIService::class, new GeminiService('fake', 'gemini-2.5-flash'));

        Livewire::test(ChatInterface::class)
            ->set('input', 'salut')
            ->call('sendMessage')
            ->call('fetchReply');

        $this->assertNotEmpty($captured);
        $payload = json_encode($captured[0], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Prenomrare', $payload);
        $this->assertStringNotContainsString('Nomrare', $payload);
        $this->assertStringNotContainsString('Zorglub', $payload);
        $this->assertStringNotContainsString('CM2-ZZ', $payload);
        // Le bloc mémoire est bien présent, sans identité.
        $this->assertStringContainsString('MÉMOIRE', $payload);
    }
}
