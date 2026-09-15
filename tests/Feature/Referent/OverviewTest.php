<?php

namespace Tests\Feature\Referent;

use App\Models\AlertLifecycle;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\FollowUp;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

class OverviewTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    public function test_referent_sees_queue_counters_and_files_of_his_school_only(): void
    {
        $school = School::factory()->create();
        $other  = School::factory()->create();
        $ref    = $this->makeReferent($school);

        $mine    = Child::factory()->for($school)->create(['name' => 'Eleve Demo Ici', 'classe' => 'CM2']);
        $foreign = Child::factory()->for($other)->create(['name' => 'Eleve Demo Ailleurs']);

        $queued   = $this->makeAlert($mine);
        $critical = $this->makeAlert($mine, ['level' => 'critical']);
        $inProg   = $this->makeAlert($mine);
        AlertLifecycle::create(['alert_id' => $inProg->id, 'status' => 'qualifie', 'qualification' => 'pertinent', 'changed_by' => $ref->id, 'changed_at' => now()]);
        AlertLifecycle::create(['alert_id' => $inProg->id, 'status' => 'en_traitement', 'changed_by' => $ref->id, 'changed_at' => now()]);
        $toConfirm = $this->makeAlert($mine, ['adjudication' => 'a_confirmer']);
        $this->makeAlert($foreign, ['level' => 'critical']);

        ChatSession::create(['child_id' => $mine->id, 'school_id' => $school->id, 'started_at' => now(), 'ended_at' => now(), 'zone' => 'green', 'low_confidence' => true]);
        FollowUp::create(['child_id' => $mine->id, 'status' => 'surveillance', 'next_review_date' => now()->addDays(3)->toDateString(), 'responsable_id' => $ref->id]);

        $this->actingAs($ref)
            ->get('/dashboard-referent')
            ->assertOk()
            ->assertSee('Eleve Demo Ici')
            ->assertDontSee('Eleve Demo Ailleurs')
            ->assertSee('en attente depuis')
            ->assertSee('À confirmer')
            ->assertSee('À relire')
            ->assertSee('CM2')
            ->assertSee('Suivis à échéance')
            ->assertDontSee('harcelé');
    }

    public function test_admin_without_delegation_is_forbidden_and_delegate_sees_limited_overview(): void
    {
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        $admin  = $this->makeAdmin(School::factory()->create());
        $child  = Child::factory()->for($school)->create(['name' => 'Eleve Demo Delegue']);
        $this->makeAlert($child);

        $this->actingAs($admin)->get('/dashboard-referent')->assertForbidden();

        $this->makeActiveDelegation($school, $ref, $admin);

        $this->actingAs($admin)
            ->get('/dashboard-referent')
            ->assertOk()
            ->assertSee('Mode délégation')
            ->assertSee('Eleve Demo Delegue');
        $this->actingAs($admin)->get('/dashboard-referent/eleves')->assertForbidden();
        $this->actingAs($admin)->get('/dashboard-referent/eleves/' . $child->id)->assertForbidden();
    }
}
