<?php

namespace Tests\Feature\Security;

use App\Livewire\Admin\Accounts;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/** Comptes école : l'e-mail d'un compte existant n'est pas modifiable par l'admin d'école (M2, audit 2026-10-07). */
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

    /**
     * M2 (audit sécurité du 2026-10-07, CWE-269) — l'admin d'école ne peut plus changer l'e-mail
     * d'un compte existant : sinon il met sa propre adresse, demande « mot de passe oublié » et
     * entre dans le compte du référent (données nominatives). Même règle que le super-admin (05/10).
     */
    public function test_school_admin_cannot_change_email_of_existing_account(): void
    {
        Notification::fake();
        $school = School::factory()->create();
        $admin  = $this->makeAdmin($school);
        $ref    = $this->makeReferent($school, ['email' => 'ancienne.adresse@carenest.test', 'email_verified_at' => now()]);
        $ref->forceFill(['remember_token' => 'ancien-jeton-de-test'])->save();
        $this->sessionRow($ref->id, 'session-du-referent');
        $this->actingAs($admin);

        Livewire::test(Accounts::class)
            ->call('openEdit', $ref->id)
            ->set('email', 'adresse.de.l.admin@carenest.test')
            ->call('update')
            ->assertHasErrors('email');

        $ref->refresh();
        $this->assertSame('ancienne.adresse@carenest.test', $ref->email);
        $this->assertNotNull($ref->email_verified_at);
        $this->assertSame('ancien-jeton-de-test', $ref->remember_token);
        $this->assertDatabaseHas('sessions', ['id' => 'session-du-referent']);
        Notification::assertNothingSent();
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
