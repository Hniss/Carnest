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
 * D10 (MVP v3) — limitation de débit : 20 messages / minute / enfant.
 * Au-delà : message doux de Care, aucun appel à l'IA.
 */
class ChatRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function loginChild(): Child
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create(['age' => 10]);
        $this->actingAs($child, 'child');
        return $child;
    }

    public function test_twenty_first_message_in_a_minute_gets_soft_pause_without_ai_call(): void
    {
        $child = $this->loginChild();
        RateLimiter::clear('chat-child:' . $child->id);

        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('chat')->times(20)->andReturn([
            'message' => 'Je t\'écoute.', 'zone' => 'green', 'alert_type' => null,
            'is_critical' => false, 'low_confidence' => false, 'tokens' => 5, 'model' => 'test',
        ]);
        $this->app->instance(AIService::class, $mock);

        $component = Livewire::test(ChatInterface::class);
        for ($i = 1; $i <= 20; $i++) {
            $component->set('input', "message {$i}")->call('sendMessage')->call('fetchReply');
        }

        $component->set('input', 'message 21')->call('sendMessage');
        $this->assertFalse($component->get('isTyping'));
        $messages = $component->get('messages');
        $this->assertSame(ChatInterface::RATE_LIMIT_MESSAGE, end($messages)['content']);

        // fetchReply ne doit rien déclencher (isTyping = false).
        $component->call('fetchReply');
    }

    public function test_rate_limit_is_per_child(): void
    {
        $childA = $this->loginChild();
        RateLimiter::clear('chat-child:' . $childA->id);
        for ($i = 0; $i < 20; $i++) {
            RateLimiter::hit('chat-child:' . $childA->id, 60);
        }

        // Un autre enfant n'est pas affecté.
        $school = School::factory()->create();
        $childB = Child::factory()->for($school)->create(['age' => 9]);
        $this->actingAs($childB, 'child');

        $component = Livewire::test(ChatInterface::class)->set('input', 'coucou')->call('sendMessage');
        $this->assertTrue($component->get('isTyping'));
    }

    public function test_login_routes_are_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->get('/child/login')->assertOk();
        }
        $this->get('/child/login')->assertStatus(429);

        for ($i = 0; $i < 10; $i++) {
            $this->get('/login')->assertOk();
        }
        $this->get('/login')->assertStatus(429);
    }

    public function test_child_login_attempts_are_rate_limited(): void
    {
        $school = School::factory()->create();
        Child::factory()->for($school)->create(['email' => 'eleve@test.local', 'password' => 'bonmotdepasse']);

        $component = Livewire::test(\App\Livewire\Child\Login::class)->set('email', 'eleve@test.local');
        for ($i = 0; $i < 10; $i++) {
            $component->set('password', 'mauvais' . $i)->call('login')->assertHasErrors(['email']);
        }

        $component->set('password', 'bonmotdepasse')->call('login')->assertHasErrors(['email']);
        $this->assertGuest('child');
        $this->assertStringContainsString('Trop de tentatives', $component->errors()->first('email'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
