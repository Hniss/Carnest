<?php

namespace Tests\Feature\Referent;

use App\Livewire\Referent\AlertTreatment;
use App\Models\Child;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

class AlertTreatmentTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    public function test_referent_of_another_school_is_forbidden(): void
    {
        $school = School::factory()->create();
        $child  = Child::factory()->for($school)->create();
        $alert  = $this->makeAlert($child);
        $other  = $this->makeReferent(School::factory()->create());

        $this->actingAs($other)->get('/dashboard-referent/alertes/' . $alert->id)->assertForbidden();
    }

    public function test_signal_step_shows_labels_summary_and_never_a_score(): void
    {
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create();
        $alert  = $this->makeAlert($child, ['type' => 'harcelement', 'summary' => 'Résumé de démonstration', 'signals' => ['moqueries répétées', 'peur de la cour']]);

        $this->actingAs($ref)->get('/dashboard-referent/alertes/' . $alert->id)
            ->assertOk()
            ->assertSee('situation potentiellement liée au harcèlement')
            ->assertSee('Résumé de démonstration')
            ->assertSee('moqueries répétées')
            ->assertDontSee('harcelé')
            ->assertDontSee('confiance :');
    }

    public function test_action_follow_up_and_closure_require_qualification(): void
    {
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create();
        $alert  = $this->makeAlert($child);
        $this->actingAs($ref);

        Livewire::test(AlertTreatment::class, ['alert' => $alert])
            ->set('actionTypes', ['entretien'])
            ->call('saveAction')->assertHasErrors('qualification');
        $this->assertDatabaseCount('alert_actions', 0);

        Livewire::test(AlertTreatment::class, ['alert' => $alert])
            ->set('followStatus', 'surveillance')
            ->call('saveFollowUp')->assertHasErrors('qualification');
        $this->assertDatabaseCount('follow_ups', 0);

        Livewire::test(AlertTreatment::class, ['alert' => $alert])
            ->call('closeAlert')->assertHasErrors('qualification');
        $this->assertSame('unread', $alert->fresh()->status);
    }

    public function test_full_treatment_flow_writes_lifecycle_actions_follow_up_and_closure(): void
    {
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create();
        $alert  = $this->makeAlert($child);
        $this->actingAs($ref);

        $c = Livewire::test(AlertTreatment::class, ['alert' => $alert]);

        $c->call('qualify', 'pertinent')->assertHasNoErrors();
        $this->assertDatabaseHas('alert_lifecycle', ['alert_id' => $alert->id, 'status' => 'qualifie', 'qualification' => 'pertinent', 'changed_by' => $ref->id]);

        $c->set('actionTypes', ['entretien', 'contact_parent'])->set('actionNotes', 'Entretien réalisé avec l\'élève.')
          ->call('saveAction')->assertHasNoErrors();
        $this->assertDatabaseCount('alert_actions', 2);
        $this->assertDatabaseHas('alert_lifecycle', ['alert_id' => $alert->id, 'status' => 'en_traitement']);

        $c->set('followStatus', 'accompagnement')->set('followDate', now()->addDays(7)->toDateString())
          ->set('followObjective', 'Revoir dans une semaine')->set('followResponsable', $ref->id)
          ->call('saveFollowUp')->assertHasNoErrors();
        $this->assertDatabaseHas('follow_ups', ['alert_id' => $alert->id, 'child_id' => $child->id, 'status' => 'accompagnement', 'responsable_id' => $ref->id]);
        $this->assertDatabaseHas('alert_lifecycle', ['alert_id' => $alert->id, 'status' => 'suivi']);

        $c->call('closeAlert')->assertHasNoErrors();
        $this->assertDatabaseHas('alert_lifecycle', ['alert_id' => $alert->id, 'status' => 'cloture']);
        $this->assertSame('resolved', $alert->fresh()->status);
    }

    public function test_acknowledge_sets_status_read_and_acked_notification(): void
    {
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create();
        $alert  = $this->makeAlert($child, ['level' => 'critical', 'type' => 'danger']);
        $this->actingAs($ref);

        Livewire::test(AlertTreatment::class, ['alert' => $alert])->call('acknowledge')->assertHasNoErrors();

        $this->assertSame('read', $alert->fresh()->status);
        $row = $alert->notifications()->where('recipient_id', $ref->id)->first();
        $this->assertNotNull($row?->acked_at);
    }

    public function test_inform_parent_requires_active_consent_and_notifies_parent(): void
    {
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create();
        $alert  = $this->makeAlert($child);
        $this->actingAs($ref);

        // Sans parent consentant : refus explicite.
        Livewire::test(AlertTreatment::class, ['alert' => $alert])
            ->call('qualify', 'pertinent')
            ->call('sendSynthesis')->assertHasErrors('synthesis');
        $this->assertDatabaseCount('parent_syntheses', 0);

        $parent = $this->makeParent($child, true);

        Livewire::test(AlertTreatment::class, ['alert' => $alert])
            ->call('prefillSynthesis')
            ->assertSet('synthesisIdentified', 'CareNest a identifié des signaux pouvant indiquer une difficulté dans l\'expérience scolaire de votre enfant.')
            ->call('sendSynthesis')->assertHasNoErrors();

        $this->assertDatabaseHas('parent_syntheses', ['alert_id' => $alert->id, 'child_id' => $child->id, 'parent_id' => $parent->id, 'sent_by' => $ref->id]);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $parent->id, 'type' => 'synthese']);
        $this->assertSame(1, $parent->fresh()->unreadNotificationsCount());
    }

    public function test_delegate_can_qualify_and_act_but_not_close_nor_note_nor_inform(): void
    {
        $school   = School::factory()->create();
        $ref      = $this->makeReferent($school);
        $delegate = $this->makeAdmin(School::factory()->create());
        $this->makeActiveDelegation($school, $ref, $delegate);
        $child = Child::factory()->for($school)->create();
        $this->makeParent($child, true);
        $alert = $this->makeAlert($child);
        $this->actingAs($delegate);

        $this->get('/dashboard-referent/alertes/' . $alert->id)->assertOk()->assertSee('Mode délégation');

        $c = Livewire::test(AlertTreatment::class, ['alert' => $alert]);
        $c->call('acknowledge')->assertHasNoErrors();
        $c->call('qualify', 'a_surveiller')->assertHasNoErrors();
        $c->set('actionTypes', ['surveillance'])->call('saveAction')->assertHasNoErrors();

        Livewire::test(AlertTreatment::class, ['alert' => $alert])->call('closeAlert')->assertForbidden();
        Livewire::test(AlertTreatment::class, ['alert' => $alert])->call('sendSynthesis')->assertForbidden();
        Livewire::test(AlertTreatment::class, ['alert' => $alert])->set('followStatus', 'surveillance')->call('saveFollowUp')->assertForbidden();
        $this->assertSame('read', $alert->fresh()->status);
    }
}
