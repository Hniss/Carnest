<?php

namespace Tests\Feature\Admin;

use App\Models\Child;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/**
 * Fiche élève ADMINISTRATIVE (lot 1 §4) : identité, classe, date de naissance,
 * statut de compte, consentement parental, parents liés. Plus aucune zone,
 * alerte ni résumé IA côté administration (audit conservé).
 */
class ChildProfileTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    private function makeAdminForSchool(School $school): User
    {
        return $this->makeAdmin($school);
    }

    public function test_admin_can_view_profile_of_child_in_his_school(): void
    {
        $school = School::factory()->create();
        $admin  = $this->makeAdminForSchool($school);
        $child  = Child::factory()->for($school)->create(['age' => 10]);

        $this->actingAs($admin)
            ->get(route('admin.children.show', $child))
            ->assertOk()
            ->assertSee($child->name);
    }

    public function test_admin_cannot_view_profile_of_child_in_another_school(): void
    {
        $school1 = School::factory()->create();
        $school2 = School::factory()->create();
        $admin   = $this->makeAdminForSchool($school1);
        $foreignChild = Child::factory()->for($school2)->create(['age' => 10]);

        $this->actingAs($admin)
            ->get(route('admin.children.show', $foreignChild))
            ->assertForbidden();
    }

    public function test_view_profile_writes_audit_log_entry(): void
    {
        $school = School::factory()->create();
        $admin  = $this->makeAdminForSchool($school);
        $child  = Child::factory()->for($school)->create(['age' => 10]);

        $this->actingAs($admin)
            ->get(route('admin.children.show', $child))
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'admin.child.view', 'target_id' => $child->id]);
    }

    public function test_admin_profile_is_administrative_only(): void
    {
        $school = School::factory()->create();
        $admin  = $this->makeAdminForSchool($school);
        $child  = Child::factory()->for($school)->create(['age' => 10, 'birth_date' => now()->subYears(10)->toDateString()]);
        $parent = $this->makeParent($child, true, 'pere');
        $session = \App\Models\ChatSession::create(['child_id' => $child->id, 'school_id' => $school->id, 'started_at' => now(), 'ended_at' => now(), 'zone' => 'red', 'ai_summary' => 'Résumé IA ultra sensible']);
        \App\Models\Alert::create(['session_id' => $session->id, 'child_id' => $child->id, 'school_id' => $school->id, 'type' => 'harcelement', 'level' => 'critical', 'summary' => 'Résumé alerte sensible']);

        $this->actingAs($admin)
            ->get(route('admin.children.show', $child))
            ->assertOk()
            ->assertSee('Fiche administrative')
            ->assertSee($parent->name)
            ->assertSee('Père')
            ->assertSee('Consentement actif')
            ->assertSee('Compte actif')
            ->assertDontSee('ultra sensible')
            ->assertDontSee('alerte sensible')
            ->assertDontSee('Zone rouge')
            ->assertDontSee('Harcèlement');
    }
}
