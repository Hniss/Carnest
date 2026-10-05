<?php

namespace Tests\Feature\Security;

use App\Livewire\Admin\Accounts;
use App\Livewire\Admin\Students;
use App\Livewire\Child\ChatInterface;
use App\Models\Child;
use App\Models\School;
use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/**
 * F4 (audit sécurité du 2026-10-05) — un compte désactivé perd immédiatement l'accès,
 * même avec une session déjà ouverte ou un cookie « se souvenir de moi ».
 */
class DeactivatedAccountLogoutTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    public function test_deactivated_adult_with_open_session_is_logged_out_on_next_request(): void
    {
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);

        $this->actingAs($ref)->get('/dashboard-referent')->assertOk();

        $ref->forceFill(['deactivated_at' => now()])->save();

        $this->get('/dashboard-referent')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_deactivated_child_with_open_session_is_logged_out_on_next_request(): void
    {
        $child = Child::factory()->for(School::factory()->create())->create(['age' => 10]);
        $this->actingAs($child, 'child');
        $child->forceFill(['deactivated_at' => now()])->save();

        $this->get('/chat')->assertRedirect(route('child.login'));
        $this->assertGuest('child');
    }

    public function test_chat_refuses_any_action_once_the_child_is_deactivated(): void
    {
        $child = Child::factory()->for(School::factory()->create())->create(['age' => 10]);
        $this->actingAs($child, 'child');

        $mock = Mockery::mock(AIService::class);
        $mock->shouldNotReceive('chat');
        $this->app->instance(AIService::class, $mock);

        $c = Livewire::test(ChatInterface::class);
        Child::whereKey($child->id)->update(['deactivated_at' => now()]);

        $c->call('sendMessage')->assertForbidden();
    }

    public function test_deactivating_an_adult_rotates_the_remember_token(): void
    {
        $school = School::factory()->create();
        $admin  = $this->makeAdmin($school);
        $ref    = $this->makeReferent($school);
        $ref->forceFill(['remember_token' => 'ancien-jeton-de-test'])->save();
        $this->actingAs($admin);

        Livewire::test(Accounts::class)->call('deactivate', $ref->id);

        $this->assertNotNull($ref->fresh()->deactivated_at);
        $this->assertNotSame('ancien-jeton-de-test', $ref->fresh()->remember_token);
    }

    public function test_deactivating_a_child_rotates_the_remember_token(): void
    {
        $school = School::factory()->create();
        $admin  = $this->makeAdmin($school);
        $child  = Child::factory()->for($school)->create();
        $child->forceFill(['remember_token' => 'ancien-jeton-de-test'])->save();
        $this->actingAs($admin);

        Livewire::test(Students::class)->call('deactivate', $child->id);

        $this->assertNotSame('ancien-jeton-de-test', $child->fresh()->remember_token);
    }
}
