<?php

namespace Tests\Feature\Security;

use App\Livewire\Admin\Dashboard;
use App\Livewire\Referent\AlertTreatment;
use App\Models\AlertLifecycle;
use App\Models\Child;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/**
 * F5, F7, F10 (audit sécurité du 2026-10-05) — la clôture d'une alerte passe uniquement par
 * le référent ; un délégué ne rouvre pas une alerte close ; le responsable d'un suivi
 * appartient à l'école.
 */
class AlertClosingAndTreatmentScopeTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    public function test_admin_dashboard_no_longer_exposes_alert_resolution(): void
    {
        $school = School::factory()->create();
        $admin  = $this->makeAdmin($school);
        $alert  = $this->makeAlert(Child::factory()->for($school)->create());

        $this->expectException(MethodNotFoundException::class);
        try {
            Livewire::actingAs($admin)->test(Dashboard::class)->call('resolveAlert', $alert->id);
        } finally {
            $this->assertSame('unread', $alert->fresh()->status);
        }
    }

    private function closedAlertWithDelegate(string $how): array
    {
        $school   = School::factory()->create();
        $ref      = $this->makeReferent($school);
        $delegate = $this->makeAdmin($school);
        $this->makeActiveDelegation($school, $ref, $delegate);
        $alert = $this->makeAlert(Child::factory()->for($school)->create());

        if ($how === 'cloture') {
            AlertLifecycle::create(['alert_id' => $alert->id, 'status' => 'qualifie', 'qualification' => 'pertinent', 'changed_by' => $ref->id, 'changed_at' => now()->subMinute()]);
            AlertLifecycle::create(['alert_id' => $alert->id, 'status' => 'cloture', 'changed_by' => $ref->id, 'changed_at' => now()]);
        }
        if ($how !== 'open') {
            $alert->update(['status' => 'resolved']);
        }

        return [$alert, $delegate, $school, $ref];
    }

    public function test_delegate_gets_403_on_a_closed_alert(): void
    {
        [$alert, $delegate] = $this->closedAlertWithDelegate('cloture');

        $this->actingAs($delegate)->get(route('referent.alerts.show', $alert))->assertForbidden();
    }

    public function test_delegate_gets_403_on_a_resolved_alert(): void
    {
        [$alert, $delegate] = $this->closedAlertWithDelegate('resolved');

        $this->actingAs($delegate)->get(route('referent.alerts.show', $alert))->assertForbidden();
    }

    public function test_delegate_loses_access_when_alert_closes_while_page_is_open(): void
    {
        [$alert, $delegate, , $ref] = $this->closedAlertWithDelegate('open');
        $this->actingAs($delegate);

        $c = Livewire::test(AlertTreatment::class, ['alert' => $alert])->assertOk();

        AlertLifecycle::create(['alert_id' => $alert->id, 'status' => 'cloture', 'changed_by' => $ref->id, 'changed_at' => now()]);
        $alert->update(['status' => 'resolved']);

        $c->call('qualify', 'pertinent')->assertForbidden();
        $this->assertSame(0, AlertLifecycle::where('alert_id', $alert->id)->where('status', 'qualifie')->count());
    }

    public function test_titular_referent_still_opens_a_closed_alert(): void
    {
        [$alert, , , $ref] = $this->closedAlertWithDelegate('cloture');

        $this->actingAs($ref)->get(route('referent.alerts.show', $alert))->assertOk();
    }

    public function test_follow_up_responsable_must_belong_to_the_school(): void
    {
        $school  = School::factory()->create();
        $ref     = $this->makeReferent($school);
        $outside = $this->makeAdmin(School::factory()->create());
        $alert   = $this->makeAlert(Child::factory()->for($school)->create());
        AlertLifecycle::create(['alert_id' => $alert->id, 'status' => 'qualifie', 'qualification' => 'pertinent', 'changed_by' => $ref->id, 'changed_at' => now()]);
        $this->actingAs($ref);

        Livewire::test(AlertTreatment::class, ['alert' => $alert])
            ->set('followStatus', 'surveillance')
            ->set('followResponsable', $outside->id)
            ->call('saveFollowUp')
            ->assertHasErrors('followResponsable');

        $this->assertDatabaseCount('follow_ups', 0);

        $colleague = $this->makeAdmin($school);
        Livewire::test(AlertTreatment::class, ['alert' => $alert])
            ->set('followStatus', 'surveillance')
            ->set('followResponsable', $colleague->id)
            ->call('saveFollowUp')
            ->assertHasNoErrors();
        $this->assertDatabaseHas('follow_ups', ['alert_id' => $alert->id, 'responsable_id' => $colleague->id]);
    }
}
