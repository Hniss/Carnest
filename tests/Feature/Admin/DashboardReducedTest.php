<?php

namespace Tests\Feature\Admin;

use App\Models\AlertLifecycle;
use App\Models\AlertNotification;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/** Lot 1 §4 — tableau de bord administration réduit : aucun nom d'élève, sauf urgences vitales sans accusé. */
class DashboardReducedTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    public function test_dashboard_shows_aggregates_without_student_names(): void
    {
        $school = School::factory()->create();
        $admin  = $this->makeAdmin($school);
        $ref    = $this->makeReferent($school);

        $small = Child::factory()->for($school)->count(3)->create(['classe' => 'CE1', 'score_enfant' => 80]);
        $big   = Child::factory()->for($school)->count(5)->create(['classe' => 'CM2', 'score_enfant' => 60]);
        $named = Child::factory()->for($school)->create(['name' => 'Eleve Demo Invisible', 'classe' => 'CM2', 'score_enfant' => 40]);
        foreach ($big->push($named) as $c) {
            ChatSession::create(['child_id' => $c->id, 'school_id' => $school->id, 'started_at' => now()->subHour(), 'ended_at' => now(), 'zone' => 'yellow']);
        }

        $a = $this->makeAlert($named, ['level' => 'high']);
        $a->forceFill(['created_at' => now()->subMinutes(30)])->save();
        AlertLifecycle::create(['alert_id' => $a->id, 'status' => 'qualifie', 'qualification' => 'pertinent', 'changed_by' => $ref->id, 'changed_at' => now()]);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Eleve Demo Invisible')
            ->assertSee('Score climat')
            ->assertSee('CM2')
            ->assertSee('effectif insuffisant')
            ->assertSee('Temps moyen de qualification')
            ->assertSee('Alertes par gravité')
            ->assertSee('Urgences sans accusé')
            ->assertDontSee('Résumé');
    }

    public function test_vital_alert_unacked_over_60_business_minutes_is_listed_with_name_and_audited(): void
    {
        $school = School::factory()->create();
        $admin  = $this->makeAdmin($school);
        $ref    = $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create(['name' => 'Eleve Demo Vital']);
        $other  = Child::factory()->for($school)->create(['name' => 'Eleve Demo Recent']);

        $old = $this->makeAlert($child, ['level' => 'critical', 'type' => 'danger']);
        $old->forceFill(['created_at' => now()->subDays(2)->setTime(10, 0)])->save();

        $recent = $this->makeAlert($other, ['level' => 'critical', 'type' => 'pensees_negatives']);
        $recent->forceFill(['created_at' => now()])->save();

        $acked = $this->makeAlert(Child::factory()->for($school)->create(['name' => 'Eleve Demo Acked']), ['level' => 'critical', 'type' => 'danger']);
        $acked->forceFill(['created_at' => now()->subDays(2)->setTime(10, 0)])->save();
        AlertNotification::create(['alert_id' => $acked->id, 'channel' => 'app', 'recipient_id' => $ref->id, 'sent_at' => now(), 'acked_at' => now()]);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('Eleve Demo Vital')
            ->assertSee('Danger')
            ->assertDontSee('Eleve Demo Recent')
            ->assertDontSee('Eleve Demo Acked');

        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'admin.vital.view', 'target_id' => $old->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'admin.vital.view', 'target_id' => $recent->id]);
    }

    /**
     * Spec §5.4 / §0 — l'anonymisation de l'administration ne connaît qu'une dérogation :
     * les deux types vitaux. Une alerte NON vitale dont l'escalade est épuisée ne doit
     * donc afficher aucun nom, ni ouvrir de drill-down individuel.
     */
    public function test_non_vital_alert_with_exhausted_escalation_is_never_named(): void
    {
        $school = School::factory()->create();
        $admin  = $this->makeAdmin($school);
        $child  = Child::factory()->for($school)->create(['name' => 'Eleve Demo Non Vital']);

        $alert = $this->makeAlert($child, ['level' => 'high', 'type' => 'harcelement']);
        $alert->forceFill([
            'created_at'              => now()->subDays(2)->setTime(10, 0),
            'escalation_exhausted_at' => now()->subDays(2)->setTime(11, 0),
        ])->save();

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Eleve Demo Non Vital')
            ->assertDontSee('Harcèlement');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'admin.vital.view', 'target_id' => $alert->id]);
    }
}
