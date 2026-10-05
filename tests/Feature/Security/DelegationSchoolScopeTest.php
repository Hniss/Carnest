<?php

namespace Tests\Feature\Security;

use App\Livewire\Referent\Delegation;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/** F2 (audit sécurité du 2026-10-05) — un référent ne délègue qu'au personnel de SA propre école. */
class DelegationSchoolScopeTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    public function test_staff_of_another_school_is_neither_listed_nor_accepted_as_delegate(): void
    {
        $school   = School::factory()->create();
        $ref      = $this->makeReferent($school);
        $outsider = $this->makeAdmin(School::factory()->create());
        $this->actingAs($ref);

        $c = Livewire::test(Delegation::class);
        $this->assertFalse($c->viewData('candidates')->contains('id', $outsider->id));

        $c->set('delegateId', $outsider->id)
            ->set('startDate', now()->toDateString())->set('endDate', now()->addDay()->toDateString())
            ->call('create')
            ->assertHasErrors('delegateId');

        $this->assertDatabaseCount('referent_delegations', 0);
    }

    public function test_parent_and_self_are_not_candidates_but_same_school_staff_is(): void
    {
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        $admin  = $this->makeAdmin($school);
        $parent = User::factory()->create(['role' => 'parent']);
        $school->users()->attach($parent->id, ['role' => 'staff']);
        $this->actingAs($ref);

        $ids = Livewire::test(Delegation::class)->viewData('candidates')->pluck('id');
        $this->assertTrue($ids->contains($admin->id));
        $this->assertFalse($ids->contains($parent->id));
        $this->assertFalse($ids->contains($ref->id));
    }
}
