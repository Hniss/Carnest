<?php

namespace Tests\Feature\ParentAlerts;

use App\Contracts\SmsSender;
use App\Livewire\ParentSpace\AlertShow;
use App\Models\Alert;
use App\Models\Child;
use App\Models\School;
use App\Models\User;
use App\Services\AlertPager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\CreatesRoles;
use Tests\Support\RecordsSms;
use Tests\TestCase;

/** Phase pilote (hp-v2nf) — écran « Alertes » de l'espace parent, en lecture seule. */
class ParentAlertScreenTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->app->instance(SmsSender::class, new RecordsSms());
        config(['carenest.parent_alerts' => true]);
    }

    /** @return array{0: User, 1: Child, 2: Alert} */
    private function pagedAlert(array $attrs = []): array
    {
        $school = School::factory()->create();
        $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create(['name' => 'Amina Démo']);
        $parent = $this->makeParent($child, true);
        $alert  = $this->makeAlert($child, $attrs + [
            'type' => 'harcelement', 'level' => 'high',
            'summary' => 'Amina dit être mise à l\'écart à la récréation.',
        ]);
        app(AlertPager::class)->page($alert);

        return [$parent, $child, $alert];
    }

    public function test_list_shows_the_parent_alerts_of_his_child(): void
    {
        [$parent, $child, $alert] = $this->pagedAlert();

        $this->actingAs($parent)->get('/parent/alertes')
            ->assertOk()
            ->assertSee('Alertes')
            ->assertSee('Harcèlement')
            ->assertSee('Élevée')
            ->assertSee('/parent/alertes/' . $alert->id, false);
    }

    public function test_detail_shows_summary_type_level_and_contact_line_without_raw_messages(): void
    {
        [$parent, $child, $alert] = $this->pagedAlert();
        $alert->session->update(['summary' => 'Résumé de séance interne']);

        $this->actingAs($parent)->get('/parent/alertes/' . $alert->id)
            ->assertOk()
            ->assertSee('Amina dit être mise à l\'écart à la récréation.')
            ->assertSee('Harcèlement')
            ->assertSee('Élevée')
            ->assertSee('référent de l\'école')
            ->assertDontSee('Résumé de séance interne')
            ->assertDontSee('Clôturer')
            ->assertDontSee('Qualifier');
    }

    public function test_parent_cannot_open_the_alert_of_another_child(): void
    {
        [, , $alert] = $this->pagedAlert();
        [$otherParent] = $this->pagedAlert();

        $this->actingAs($otherParent)->get('/parent/alertes/' . $alert->id)->assertForbidden();
    }

    public function test_parent_whose_consent_was_withdrawn_cannot_open_the_alert(): void
    {
        [$parent, $child, $alert] = $this->pagedAlert();
        $parent->children()->updateExistingPivot($child->id, ['consent_withdrawn_at' => now()]);

        $this->actingAs($parent)->get('/parent/alertes/' . $alert->id)->assertForbidden();
        $this->actingAs($parent)->get('/parent/alertes')->assertOk()->assertDontSee('/parent/alertes/' . $alert->id, false);
    }

    public function test_alert_never_sent_to_the_parent_is_not_listed_nor_openable(): void
    {
        [$parent, $child] = $this->pagedAlert();
        $quiet = $this->makeAlert($child, ['type' => 'stress', 'level' => 'moderate', 'summary' => 'Signal modéré non transmis']);
        app(AlertPager::class)->page($quiet);

        $this->actingAs($parent)->get('/parent/alertes')->assertOk()->assertDontSee('Signal modéré non transmis');
        $this->actingAs($parent)->get('/parent/alertes/' . $quiet->id)->assertForbidden();
    }

    public function test_alert_id_cannot_be_tampered_in_the_component(): void
    {
        [$parent, , $alert] = $this->pagedAlert();
        [, , $foreign] = $this->pagedAlert();

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($parent)->test(AlertShow::class, ['alert' => $alert->id])->set('alertId', $foreign->id);
    }

    public function test_other_roles_cannot_open_the_parent_alert_screen(): void
    {
        [, $child, $alert] = $this->pagedAlert();
        $referent = $this->makeReferent($child->school);

        $this->actingAs($referent)->get('/parent/alertes/' . $alert->id)->assertRedirect($referent->homePath());
    }

    public function test_switch_off_hides_the_screen_and_the_menu_entry(): void
    {
        [$parent, , $alert] = $this->pagedAlert();
        config(['carenest.parent_alerts' => false]);

        $this->actingAs($parent)->get('/parent/alertes')->assertNotFound();
        $this->actingAs($parent)->get('/parent/alertes/' . $alert->id)->assertNotFound();
        $this->actingAs($parent)->get('/parent')->assertOk()->assertDontSee(route('parent.alerts'), false);
    }

    public function test_menu_shows_the_alerts_entry_when_switch_is_on(): void
    {
        [$parent] = $this->pagedAlert();

        $this->actingAs($parent)->get('/parent')->assertOk()->assertSee(route('parent.alerts'), false);
    }
}
