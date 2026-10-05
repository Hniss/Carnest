<?php

namespace Tests\Feature\Security;

use App\Livewire\Admin\Accounts;
use App\Models\School;
use App\Notifications\AccountEmailChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/** F9 (audit sécurité du 2026-10-05) — changer l'e-mail d'un compte coupe ses accès et prévient l'ancienne adresse. */
class AccountEmailChangeTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    private function sessionRow(int $userId, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $userId, 'ip_address' => '127.0.0.1', 'user_agent' => 'test',
            'payload' => base64_encode('a:0:{}'), 'last_activity' => time(),
        ]);
    }

    public function test_email_change_resets_verification_cuts_sessions_and_notifies_old_address(): void
    {
        Notification::fake();
        $school = School::factory()->create();
        $admin  = $this->makeAdmin($school);
        $ref    = $this->makeReferent($school, ['email' => 'ancienne.adresse@carenest.test', 'email_verified_at' => now()]);
        $ref->forceFill(['remember_token' => 'ancien-jeton-de-test'])->save();
        $this->sessionRow($ref->id, 'session-du-referent');
        $this->sessionRow($admin->id, 'session-de-l-admin');
        $this->actingAs($admin);

        Livewire::test(Accounts::class)
            ->call('openEdit', $ref->id)
            ->set('email', 'nouvelle.adresse@carenest.test')
            ->call('update')
            ->assertHasNoErrors();

        $ref->refresh();
        $this->assertSame('nouvelle.adresse@carenest.test', $ref->email);
        $this->assertNull($ref->email_verified_at);
        $this->assertNotSame('ancien-jeton-de-test', $ref->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'session-du-referent']);
        $this->assertDatabaseHas('sessions', ['id' => 'session-de-l-admin']);

        Notification::assertSentTo(
            new AnonymousNotifiable,
            AccountEmailChanged::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'ancienne.adresse@carenest.test'
        );
    }

    public function test_update_without_email_change_keeps_access(): void
    {
        Notification::fake();
        $school = School::factory()->create();
        $admin  = $this->makeAdmin($school);
        $ref    = $this->makeReferent($school, ['email_verified_at' => now()]);
        $this->sessionRow($ref->id, 'session-du-referent');
        $this->actingAs($admin);

        Livewire::test(Accounts::class)
            ->call('openEdit', $ref->id)
            ->set('name', 'Nom modifié')
            ->call('update')
            ->assertHasNoErrors();

        $this->assertNotNull($ref->fresh()->email_verified_at);
        $this->assertDatabaseHas('sessions', ['id' => 'session-du-referent']);
        Notification::assertNothingSent();
    }
}
