<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\ChildProfile;
use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Page profil enfant — sécurité (scope école), audit log et actions.
 */
class ChildProfileTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdminForSchool(School $school): User
    {
        $admin = User::factory()->create();
        $admin->schools()->attach($school->id, ['role' => 'director']);
        return $admin;
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
        Log::shouldReceive('channel')
            ->with('admin_audit')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $ctx) {
                return $action === 'view_profile'
                    && isset($ctx['admin_user_id'], $ctx['child_id'], $ctx['school_id']);
            });

        $school = School::factory()->create();
        $admin  = $this->makeAdminForSchool($school);
        $child  = Child::factory()->for($school)->create(['age' => 10]);

        $this->actingAs($admin)
            ->get(route('admin.children.show', $child))
            ->assertOk();
    }

    public function test_resolve_alert_marks_alert_resolved_and_recomputes_child_status(): void
    {
        $school = School::factory()->create();
        $admin  = $this->makeAdminForSchool($school);
        $child  = Child::factory()->for($school)->create([
            'age'          => 10,
            'score_enfant' => 80, // > 70 → status reviendra à 'ok' une fois l'alerte résolue
        ]);

        $session = ChatSession::create([
            'child_id'   => $child->id,
            'school_id'  => $school->id,
            'started_at' => now(),
            'ended_at'   => now(),
            'zone'       => 'red',
        ]);

        $alert = Alert::create([
            'session_id' => $session->id,
            'child_id'   => $child->id,
            'school_id'  => $school->id,
            'type'       => 'detresse',
            'level'      => 'critical',
            'status'     => 'unread',
        ]);

        $this->actingAs($admin);

        Livewire::test(ChildProfile::class, ['child' => $child])
            ->call('resolveAlert', $alert->id)
            ->assertOk();

        $this->assertSame('resolved', $alert->fresh()->status);
        // Une fois l'alerte critique résolue et score 80 → status doit retomber à 'ok'.
        $this->assertSame('ok', $child->fresh()->status);
    }

    public function test_resolve_alert_rejects_cross_school_alert(): void
    {
        $school1 = School::factory()->create();
        $school2 = School::factory()->create();
        $admin   = $this->makeAdminForSchool($school1);

        $myChild      = Child::factory()->for($school1)->create(['age' => 10]);
        $foreignChild = Child::factory()->for($school2)->create(['age' => 10]);

        $session = ChatSession::create([
            'child_id'   => $foreignChild->id,
            'school_id'  => $school2->id,
            'started_at' => now(),
            'ended_at'   => now(),
            'zone'       => 'red',
        ]);
        $foreignAlert = Alert::create([
            'session_id' => $session->id,
            'child_id'   => $foreignChild->id,
            'school_id'  => $school2->id,
            'type'       => 'detresse',
            'level'      => 'critical',
            'status'     => 'unread',
        ]);

        $this->actingAs($admin);

        // L'admin tente de résoudre via SA page profil, mais avec un alertId
        // qui pointe vers un enfant d'une autre école.
        Livewire::test(ChildProfile::class, ['child' => $myChild])
            ->call('resolveAlert', $foreignAlert->id)
            ->assertStatus(403);

        $this->assertSame('unread', $foreignAlert->fresh()->status);
    }

    public function test_add_note_validates_length(): void
    {
        $school = School::factory()->create();
        $admin  = $this->makeAdminForSchool($school);
        $child  = Child::factory()->for($school)->create(['age' => 10]);

        $this->actingAs($admin);

        Livewire::test(ChildProfile::class, ['child' => $child])
            ->call('addNote', 'abc') // < 5 chars
            ->assertHasErrors('note');

        Livewire::test(ChildProfile::class, ['child' => $child])
            ->call('addNote', 'Observation pertinente après échange avec la mère.')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('admin_notes', [
            'child_id' => $child->id,
            'user_id'  => $admin->id,
        ]);
    }
}
