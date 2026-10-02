<?php

namespace Tests\Feature\Child;

use App\Livewire\Child\ChatInterface;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Services\AIService;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bout en bout dans le chat : quand le modèle écrit après ses lignes techniques, l'enfant
 * ne voit que ce qui précède la première d'entre elles (correction du 2026-10-02), et le
 * serveur exploite toujours la zone, le type et le résumé.
 */
class ChatVisibleReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_child_only_sees_what_comes_before_the_first_technical_line(): void
    {
        $school = School::factory()->create();
        $this->actingAs(Child::factory()->for($school)->create(['age' => 9, 'gender' => 'm']), 'child');

        $visible = "Je suis un peu comme une coach : je t'écoute et je t'aide à grandir.\n\nQu'est-ce qui t'a fait poser cette question ?";
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => $visible
                . "\nALERT_TYPE: none\nZONE: green\nRESUME: L'enfant demande où vont ses messages ; Care se présente en coach.\n"
                . "Destiné à l'adulte référent : rien de préoccupant à signaler.\n"
                . $visible], 'finish_reason' => 'stop']],
            'model' => 'gemini-2.5-flash',
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 60, 'total_tokens' => 160],
        ])]);
        $this->app->instance(AIService::class, new GeminiService('fake-key', 'gemini-2.5-flash'));

        $page = Livewire::test(ChatInterface::class)
            ->set('input', 'ce que je te dis, ça va où ?')
            ->call('sendMessage')
            ->call('fetchReply');

        $messages = $page->get('messages');
        $this->assertSame($visible, end($messages)['content']);
        // Le texte affiché (sans les données d'amorçage du composant).
        $html = $page->html(true);
        $this->assertSame(1, substr_count($html, 'Qu&#039;est-ce qui t&#039;a fait poser cette question ?'));
        foreach (['RESUME', 'ALERT_TYPE', 'ZONE:', 'adulte référent', 'où vont ses messages'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html);
        }

        $session = ChatSession::find($page->get('sessionId'));
        $this->assertSame("L'enfant demande où vont ses messages ; Care se présente en coach.", $session->ai_summary);
        $this->assertSame('green', $session->zone);
    }
}
