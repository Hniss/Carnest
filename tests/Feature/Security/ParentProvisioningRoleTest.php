<?php

namespace Tests\Feature\Security;

use App\Livewire\Admin\Students;
use App\Models\Child;
use App\Models\School;
use App\Services\ParentAccountProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/**
 * F3 (audit sécurité du 2026-10-05) — l'e-mail parent saisi à la création d'un élève ne
 * peut jamais rattacher (ni désactiver) un compte adulte qui n'est pas un compte parent.
 */
class ParentProvisioningRoleTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    public function test_staff_email_as_parent_email_is_refused_and_staff_account_untouched(): void
    {
        $school = School::factory()->create();
        $admin  = $this->makeAdmin($school);
        $ref    = $this->makeReferent($school);
        $this->actingAs($admin);

        Livewire::test(Students::class)
            ->call('openCreate')
            ->set('form.name', 'Élève Test')
            ->set('form.classe', 'CM1')
            ->set('form.birth_date', now()->subYears(9)->toDateString())
            ->set('form.email', 'eleve.test@carenest.test')
            ->set('form.parent_email', strtoupper($ref->email))
            ->set('form.relation', 'mere')
            ->set('form.consent', false)
            ->call('save')
            ->assertHasErrors('form.parent_email');

        $ref->refresh();
        $this->assertSame('referent', $ref->role);
        $this->assertNull($ref->deactivated_at);
        $this->assertSame(0, $ref->children()->count());
        $this->assertSame(0, Child::where('email', 'eleve.test@carenest.test')->count());
    }

    public function test_sync_activation_never_touches_a_non_parent_account(): void
    {
        $ref = $this->makeReferent(School::factory()->create());

        app(ParentAccountProvisioner::class)->syncActivation($ref);

        $this->assertNull($ref->fresh()->deactivated_at);
    }
}
