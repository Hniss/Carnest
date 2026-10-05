<?php

namespace Tests\Feature\Child;

use App\Livewire\Child\ChatInterface;
use App\Models\Child;
use App\Models\School;
use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Retours des pédopsychiatres (2026-10-05), option C décidée par Hamza :
 * avatar de Care qui cligne des yeux, salue à l'arrivée et guide l'exercice de respiration
 * (4-7-8 ou carrée) au rythme exact de la technique ; défilement automatique du fil.
 */
class CareAvatarMotionTest extends TestCase
{
    use RefreshDatabase;

    private function loginChild(int $age = 9): Child
    {
        $child = Child::factory()->for(School::factory()->create())->create(['age' => $age]);
        $this->actingAs($child, 'child');
        RateLimiter::clear(ChatInterface::rateLimitKey((int) $child->id));
        RateLimiter::clear(ChatInterface::replyRateLimitKey((int) $child->id));

        return $child;
    }

    private function aiReplies(array $result): void
    {
        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('chat')->andReturn($result + [
            'alert_type' => 'stress', 'is_critical' => false, 'low_confidence' => false,
            'summary' => null, 'tokens' => 5, 'model' => 'test',
        ]);
        $this->app->instance(AIService::class, $mock);
    }

    public function test_header_avatar_blinks_and_greets_on_arrival(): void
    {
        $this->loginChild();

        Livewire::test(ChatInterface::class)
            ->assertSee('data-care-avatar="header"', false)
            ->assertSee('care-avatar-greet', false)
            ->assertSee('care-eyelid', false)
            ->assertSee('care-avatar.png');
    }

    public function test_motion_respects_reduced_motion_preference(): void
    {
        $this->loginChild();

        Livewire::test(ChatInterface::class)
            ->assertSee('prefers-reduced-motion: reduce', false);
    }

    public function test_breathing_478_proposed_in_yellow_shows_a_guided_avatar_with_exact_rhythm(): void
    {
        $this->loginChild();
        $this->aiReplies(['message' => 'On essaie la respiration 4-7-8 ensemble ?', 'zone' => 'yellow', 'exercise' => '478']);

        $c = Livewire::test(ChatInterface::class)->set('input', 'je suis stressé')->call('sendMessage')->call('fetchReply');

        $messages = $c->get('messages');
        $this->assertSame('478', end($messages)['exercise']);
        $c->assertSee('data-breathing="478"', false)
            ->assertSee('care-breathing-start', false)
            ->assertSee('data-breathing-phases="[{&quot;label&quot;:&quot;Inspire&quot;,&quot;seconds&quot;:4,&quot;scale&quot;:&quot;in&quot;},{&quot;label&quot;:&quot;Retiens&quot;,&quot;seconds&quot;:7,&quot;scale&quot;:&quot;hold&quot;},{&quot;label&quot;:&quot;Expire&quot;,&quot;seconds&quot;:8,&quot;scale&quot;:&quot;out&quot;}]"', false);
    }

    public function test_square_breathing_follows_4_4_4_4(): void
    {
        $this->loginChild();
        $this->aiReplies(['message' => 'On fait la respiration carrée ?', 'zone' => 'yellow', 'exercise' => 'carree']);

        Livewire::test(ChatInterface::class)->set('input', 'contrôle demain')->call('sendMessage')->call('fetchReply')
            ->assertSee('data-breathing="carree"', false)
            ->assertSee('{&quot;label&quot;:&quot;Inspire&quot;,&quot;seconds&quot;:4,&quot;scale&quot;:&quot;in&quot;},{&quot;label&quot;:&quot;Retiens&quot;,&quot;seconds&quot;:4,&quot;scale&quot;:&quot;hold&quot;},{&quot;label&quot;:&quot;Expire&quot;,&quot;seconds&quot;:4,&quot;scale&quot;:&quot;out&quot;},{&quot;label&quot;:&quot;Attends&quot;,&quot;seconds&quot;:4,&quot;scale&quot;:&quot;rest&quot;}', false);
    }

    public function test_no_breathing_guide_outside_yellow_or_when_safety_message_replaces_the_reply(): void
    {
        $this->loginChild();
        $this->aiReplies(['message' => 'On respire ensemble ?', 'zone' => 'orange', 'exercise' => '478', 'alert_type' => 'harcelement']);

        $c = Livewire::test(ChatInterface::class)->set('input', 'ils se moquent de moi')->call('sendMessage')->call('fetchReply');
        $messages = $c->get('messages');
        $this->assertArrayNotHasKey('exercise', end($messages));
        $c->assertDontSee('data-breathing=', false);
    }

    public function test_yellow_fallback_breathing_is_square_and_guided(): void
    {
        $child = $this->loginChild();
        $calls = 0;
        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('chat')->andReturnUsing(function () use (&$calls) {
            if (++$calls % 2 === 1) {
                return ['message' => 'Je comprends.', 'zone' => 'yellow', 'alert_type' => 'stress', 'is_critical' => false, 'low_confidence' => false, 'summary' => null, 'tokens' => 5, 'model' => 'test'];
            }
            throw new \RuntimeException('indisponible');
        });
        $this->app->instance(AIService::class, $mock);

        $c = Livewire::test(ChatInterface::class);
        for ($i = 0; $i < 40; $i++) {
            RateLimiter::clear(ChatInterface::rateLimitKey((int) $child->id));
            RateLimiter::clear(ChatInterface::replyRateLimitKey((int) $child->id));
            $c->set('input', 'je suis stressé')->call('sendMessage')->call('fetchReply');
            $messages = $c->get('messages');
            $last = end($messages);
            if (str_contains($last['content'], 'respir')) {
                $this->assertSame('carree', $last['exercise'] ?? null);
                $this->assertStringContainsString('carrée', $last['content']);
                return;
            }
        }
        $this->fail('Aucun message de respiration servi en repli sur 20 échecs en zone jaune.');
    }

    public function test_model_history_never_carries_the_exercise_marker(): void
    {
        $history = ChatInterface::modelHistory([['role' => 'user', 'content' => 'a'], ['role' => 'assistant', 'content' => 'b', 'exercise' => '478']]);

        $this->assertSame([['role' => 'user', 'content' => 'a'], ['role' => 'assistant', 'content' => 'b']], $history);
    }

    public function test_thread_auto_scroll_follows_the_page_not_only_the_inner_list(): void
    {
        $this->loginChild();

        $this->get('/chat')->assertOk()->assertSee('document.scrollingElement', false);
    }
}
