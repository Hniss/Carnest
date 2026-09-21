<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\AccessLog;
use App\Livewire\Admin\Accounts;
use App\Livewire\Admin\Settings;
use App\Models\School;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/** Lot 1 §4 — comptes école, paramètres étendus, journal d'accès. */
class AccountsSettingsAccessLogTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    public function test_only_one_active_referent_per_school(): void
    {
        Notification::fake();
        $school = School::factory()->create();
        $admin  = $this->makeAdmin($school);
        $this->actingAs($admin);

        Livewire::test(Accounts::class)
            ->set('name', 'Referent Demo')->set('email', 'ref.demo@carenest.test')->set('role', 'referent')
            ->call('create')->assertHasNoErrors();
        $ref = User::where('email', 'ref.demo@carenest.test')->first();
        $this->assertSame('referent', $ref->role);
        $this->assertTrue($school->users()->wherePivot('role', 'referent')->where('users.id', $ref->id)->exists());
        Notification::assertSentTo($ref, ResetPassword::class);

        Livewire::test(Accounts::class)
            ->set('name', 'Referent Demo Bis')->set('email', 'ref2.demo@carenest.test')->set('role', 'referent')
            ->call('create')->assertHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'ref2.demo@carenest.test']);

        // Désactivation → le compte ne peut plus se connecter ; un nouveau référent devient possible.
        Livewire::test(Accounts::class)->call('deactivate', $ref->id)->assertHasNoErrors();
        $this->assertNotNull($ref->fresh()->deactivated_at);
        $ref->forceFill(['password' => 'password'])->save();
        Volt::test('pages.auth.login')->set('form.email', $ref->email)->set('form.password', 'password')->call('login')->assertHasErrors('form.email');

        Livewire::test(Accounts::class)
            ->set('name', 'Referent Demo Bis')->set('email', 'ref2.demo@carenest.test')->set('role', 'referent')
            ->call('create')->assertHasNoErrors();

        Livewire::test(Accounts::class)->call('openEdit', $ref->id)->set('name', 'Referent Demo Renomme')->call('update')->assertHasNoErrors();
        $this->assertSame('Referent Demo Renomme', $ref->fresh()->name);
    }

    public function test_settings_save_extended_fields(): void
    {
        $school = School::factory()->create();
        $admin  = $this->makeAdmin($school);
        $this->actingAs($admin);

        Livewire::test(Settings::class)
            ->set('schoolHoursStart', '08:30')->set('schoolHoursEnd', '16:30')
            ->set('referentPhone', '+212600000001')
            ->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('school_settings', [
            'school_id' => $school->id, 'school_hours_start' => '08:30', 'school_hours_end' => '16:30',
            'referent_phone' => '+212600000001',
        ]);
    }

    public function test_access_log_is_scoped_and_filterable(): void
    {
        $school = School::factory()->create();
        $other  = School::factory()->create();
        $admin  = $this->makeAdmin($school);
        $ref    = $this->makeReferent($school);
        $foreignAdmin = $this->makeAdmin($other);

        $this->actingAs($ref);
        Audit::log('referent.child.view', null, ['school_id' => $school->id]);
        $this->actingAs($foreignAdmin);
        Audit::log('admin.child.create', null, ['school_id' => $other->id]);

        $this->actingAs($admin);
        $this->get('/dashboard/journal')->assertOk()->assertSee('referent.child.view')->assertDontSee('admin.child.create');

        Livewire::test(AccessLog::class)->set('actorId', $ref->id)->assertViewHas('logs', fn ($p) => $p->total() === 1);
        Livewire::test(AccessLog::class)->set('from', now()->addDay()->toDateString())->assertViewHas('logs', fn ($p) => $p->total() === 0);
    }
}
