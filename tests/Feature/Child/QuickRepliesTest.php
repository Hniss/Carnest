<?php

namespace Tests\Feature\Child;

use App\Livewire\Child\ChatInterface;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Services\AIService;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Optimisation de l'interface enfant (2026-10-21), point 2 : 5 réponses rapides sous le
 * message d'accueil. L'écran n'envoie que le numéro du bouton ; le serveur retrouve le texte
 * dans sa propre liste et le fait passer par le chemin d'un message écrit.
 */
class QuickRepliesTest extends TestCase
{
    use RefreshDatabase;

    private const LABELS = ['Ça va', 'Bof', 'Pas top', 'Compliqué', 'Je ne sais pas trop'];

    private function loginChild(int $age = 10): Child
    {
        $child = Child::factory()->for(School::factory()->create())->create(['age' => $age]);
        $this->actingAs($child, 'child');
        RateLimiter::clear(ChatInterface::rateLimitKey((int) $child->id));
        RateLimiter::clear(ChatInterface::replyRateLimitKey((int) $child->id));

        return $child;
    }

    private function aiReply(?callable $onChat = null): void
    {
        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('chat')->andReturnUsing(function (...$args) use ($onChat) {
            if ($onChat) {
                $onChat(...$args);
            }

            return [
                'message' => "D'accord, je t'écoute.", 'zone' => 'green', 'alert_type' => null,
                'is_critical' => false, 'low_confidence' => false, 'summary' => null, 'tokens' => 5, 'model' => 'test',
            ];
        });
        $this->app->instance(AIService::class, $mock);
    }

    public function test_the_five_exact_labels_are_listed_in_order(): void
    {
        $this->assertSame(self::LABELS, array_column(ChatInterface::QUICK_REPLIES, 'label'));
        $this->assertCount(5, array_unique(array_column(ChatInterface::QUICK_REPLIES, 'slug')));
    }

    public function test_buttons_are_shown_under_the_welcome_with_their_text(): void
    {
        $this->loginChild();

        Livewire::test(ChatInterface::class)
            ->assertSee('data-quick-replies', false)
            ->assertSeeInOrder(self::LABELS)
            ->assertSeeHtml('wire:click="quickReply(4)"');
    }

    public function test_buttons_are_shown_to_every_age_group(): void
    {
        $this->loginChild(13);

        Livewire::test(ChatInterface::class)->assertSee('data-quick-replies', false);
    }

    public function test_click_sends_the_server_side_label_through_the_written_message_path(): void
    {
        $child = $this->loginChild();
        $sent = null;
        $this->aiReply(function (array $messages) use (&$sent) {
            $sent = $messages;
        });

        $c = Livewire::test(ChatInterface::class)->call('quickReply', 2);

        $messages = $c->get('messages');
        $this->assertSame(['role' => 'user', 'content' => 'Pas top'], end($messages));
        $this->assertTrue($c->get('isTyping'));
        $this->assertSame(1, RateLimiter::attempts(ChatInterface::rateLimitKey((int) $child->id)));

        $c->call('fetchReply');
        $this->assertSame([['role' => 'user', 'content' => 'Pas top']], $sent);
        $this->assertSame("D'accord, je t'écoute.", $c->get('messages')[2]['content']);
    }

    public function test_unknown_index_is_rejected_and_nothing_is_sent(): void
    {
        $child = $this->loginChild();
        $mock = Mockery::mock(AIService::class);
        $mock->shouldNotReceive('chat');
        $this->app->instance(AIService::class, $mock);

        foreach ([5, -1, '1', 1.0, 'Ça va', null] as $forged) {
            Livewire::test(ChatInterface::class)->call('quickReply', $forged)->assertStatus(422);
        }

        $this->assertSame(0, RateLimiter::attempts(ChatInterface::rateLimitKey((int) $child->id)));
    }

    public function test_buttons_disappear_after_a_quick_reply(): void
    {
        $this->loginChild();
        $this->aiReply();

        Livewire::test(ChatInterface::class)
            ->call('quickReply', 0)
            ->assertDontSee('data-quick-replies', false)
            ->call('fetchReply')
            ->assertDontSee('data-quick-replies', false);
    }

    public function test_buttons_disappear_when_the_child_writes_instead(): void
    {
        $this->loginChild();
        $this->aiReply();

        Livewire::test(ChatInterface::class)
            ->set('input', 'salut')->call('sendMessage')
            ->assertDontSee('data-quick-replies', false);
    }

    public function test_a_second_quick_reply_in_the_session_is_ignored(): void
    {
        $this->loginChild();
        $this->aiReply();

        $c = Livewire::test(ChatInterface::class)->call('quickReply', 0)->call('fetchReply')->call('quickReply', 1);

        $this->assertSame(['Ça va'], collect($c->get('messages'))->where('role', 'user')->pluck('content')->all());
    }

    public function test_typed_text_is_kept_when_a_button_is_used(): void
    {
        $this->loginChild();

        $c = Livewire::test(ChatInterface::class)->set('input', 'je commence à écrire')->call('quickReply', 1);

        $messages = $c->get('messages');
        $this->assertSame('Bof', end($messages)['content']);
        $this->assertSame('je commence à écrire', $c->get('input'));
    }

    public function test_rate_limit_applies_to_quick_replies(): void
    {
        $child = $this->loginChild();
        for ($i = 0; $i < ChatInterface::RATE_LIMIT_PER_MINUTE; $i++) {
            RateLimiter::hit(ChatInterface::rateLimitKey((int) $child->id), 60);
        }

        $c = Livewire::test(ChatInterface::class)->call('quickReply', 3);

        $messages = $c->get('messages');
        $this->assertSame(ChatInterface::RATE_LIMIT_MESSAGE, end($messages)['content']);
        $this->assertFalse($c->get('isTyping'));
    }

    public function test_no_buttons_once_the_session_is_closed(): void
    {
        $this->loginChild();

        Livewire::test(ChatInterface::class)->call('endSession')->assertDontSee('data-quick-replies', false);
    }

    public function test_the_label_is_never_written_to_the_database(): void
    {
        $this->loginChild();
        $this->aiReply();

        Livewire::test(ChatInterface::class)->call('quickReply', 3)->call('fetchReply')->call('endSession');

        $session = ChatSession::firstOrFail();
        foreach ($session->getAttributes() + ['ai_summary' => $session->ai_summary, 'care_memory' => $session->care_memory] as $value) {
            $this->assertStringNotContainsString('Compliqué', (string) $value);
        }
        $this->assertSame(0, DB::table('alerts')->count());
    }

    public function test_prompt_version_is_unchanged(): void
    {
        $this->assertSame('v3.4', GeminiService::PROMPT_VERSION);
    }

    public function test_no_picto_image_is_rendered_while_the_file_is_absent(): void
    {
        $this->loginChild();
        foreach (ChatInterface::QUICK_REPLIES as $reply) {
            $this->assertFileDoesNotExist(public_path("img/care/meteo/{$reply['slug']}.png"));
        }

        Livewire::test(ChatInterface::class)
            ->assertSee('data-quick-replies', false)
            ->assertDontSee('data-quick-reply-picto', false)
            ->assertDontSee('img/care/meteo/', false);
    }

    public function test_picto_is_rendered_once_its_file_exists(): void
    {
        $this->loginChild();
        $dir = public_path('img/care/meteo');
        $createdDir = ! File::isDirectory($dir);
        $file = $dir . '/bof.png';
        File::ensureDirectoryExists($dir);
        File::put($file, 'png');

        try {
            Livewire::test(ChatInterface::class)
                ->assertSee('data-quick-reply-picto', false)
                ->assertSee('img/care/meteo/bof.png', false)
                ->assertDontSee('img/care/meteo/ca-va.png', false);
        } finally {
            File::delete($file);
            if ($createdDir) {
                File::deleteDirectory($dir);
            }
        }
    }
}
