<?php

namespace Tests\Feature\ParentAlerts;

use App\Contracts\SmsSender;
use App\Mail\AlertPagedMail;
use App\Mail\ParentAlertMail;
use App\Models\AlertNotification;
use App\Models\AppNotification;
use App\Models\Child;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Models\User;
use App\Services\AlertPager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesRoles;
use Tests\Support\RecordsSms;
use Tests\TestCase;

/**
 * Phase pilote (hp-v2nf) — le parent consentant est prévenu d'une alerte critique,
 * au même palier que le référent, sans rien changer au circuit existant.
 */
class ParentAlertPagingTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    private RecordsSms $sms;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->sms = new RecordsSms();
        $this->app->instance(SmsSender::class, $this->sms);
        config(['carenest.parent_alerts' => true]);
    }

    private function school(): School
    {
        $school = School::factory()->create();
        SchoolSetting::updateOrCreate(['school_id' => $school->id], ['referent_phone' => '0600000001']);
        return $school;
    }

    private function parentAppRows(User $parent, int $alertId): int
    {
        return AlertNotification::where('alert_id', $alertId)->where('recipient_id', $parent->id)->where('channel', 'app')->count();
    }

    public function test_consenting_parent_is_notified_in_app_and_by_email_on_non_vital_high_alert(): void
    {
        $school = $this->school();
        $this->makeReferent($school, ['email' => 'ref@test.ma']);
        $child  = Child::factory()->for($school)->create(['name' => 'Yassine Démo']);
        $parent = $this->makeParent($child, true);
        $parent->forceFill(['email' => 'parent@test.ma'])->save();
        $alert  = $this->makeAlert($child, ['type' => 'harcelement', 'level' => 'high', 'summary' => 'Résumé confidentiel de test']);

        app(AlertPager::class)->page($alert);

        $notif = AppNotification::where('user_id', $parent->id)->first();
        $this->assertNotNull($notif);
        $this->assertSame('/parent/alertes/' . $alert->id, $notif->link);
        $this->assertStringNotContainsString('Résumé confidentiel', $notif->title . ' ' . $notif->body);
        $this->assertSame(1, $this->parentAppRows($parent, $alert->id));
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->where('recipient_id', $parent->id)->where('channel', 'email')->count());

        Mail::assertSent(ParentAlertMail::class, function (ParentAlertMail $m) use ($alert) {
            $rendered = $m->render();
            return $m->hasTo('parent@test.ma')
                && str_contains($rendered, 'Une alerte importante concerne votre enfant')
                && str_contains($rendered, '/parent/alertes/' . $alert->id)
                && ! str_contains($rendered, 'Résumé confidentiel')
                && ! str_contains($rendered, 'Yassine')
                && ! str_contains(mb_strtolower($rendered), 'harc');
        });
        // Circuit existant inchangé : le référent reçoit toujours son propre e-mail.
        Mail::assertSent(AlertPagedMail::class, fn (AlertPagedMail $m) => $m->hasTo('ref@test.ma'));
    }

    public function test_consenting_parent_is_notified_on_vital_danger_alert(): void
    {
        $school = $this->school();
        $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child, true);
        $alert  = $this->makeAlert($child, ['type' => 'danger', 'level' => 'critical']);

        app(AlertPager::class)->page($alert);

        $this->assertSame(1, $this->parentAppRows($parent, $alert->id));
        Mail::assertSent(ParentAlertMail::class);
    }

    public function test_humiliation_by_adult_alert_also_reaches_the_parent(): void
    {
        $school = $this->school();
        $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child, true);
        $alert  = $this->makeAlert($child, ['type' => 'humiliation_adulte', 'level' => 'high']);

        app(AlertPager::class)->page($alert);

        $this->assertSame(1, $this->parentAppRows($parent, $alert->id));
    }

    public function test_moderate_non_vital_alert_does_not_reach_the_parent(): void
    {
        $school = $this->school();
        $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child, true);
        $alert  = $this->makeAlert($child, ['type' => 'stress', 'level' => 'moderate']);

        app(AlertPager::class)->page($alert);

        $this->assertSame(0, AppNotification::where('user_id', $parent->id)->count());
        Mail::assertNotSent(ParentAlertMail::class);
    }

    public function test_parent_without_consent_or_withdrawn_or_deactivated_is_not_notified(): void
    {
        $school = $this->school();
        $this->makeReferent($school);
        $child = Child::factory()->for($school)->create();

        $noConsent = $this->makeParent($child, false);
        $withdrawn = $this->makeParent($child, true, 'pere');
        $withdrawn->children()->updateExistingPivot($child->id, ['consent_withdrawn_at' => now()]);
        $deactivated = $this->makeParent($child, true, 'tuteur');
        $deactivated->forceFill(['deactivated_at' => now()])->save();

        $alert = $this->makeAlert($child, ['type' => 'pensees_negatives', 'level' => 'critical']);
        app(AlertPager::class)->page($alert);

        foreach ([$noConsent, $withdrawn, $deactivated] as $p) {
            $this->assertSame(0, AppNotification::where('user_id', $p->id)->count());
            $this->assertSame(0, AlertNotification::where('recipient_id', $p->id)->count());
        }
        Mail::assertNotSent(ParentAlertMail::class);
    }

    public function test_parent_of_another_child_is_never_notified(): void
    {
        $school = $this->school();
        $this->makeReferent($school);
        $child = Child::factory()->for($school)->create();
        $other = Child::factory()->for($school)->create();
        $otherSchoolChild = Child::factory()->for($this->school())->create();
        $own      = $this->makeParent($child, true);
        $stranger = $this->makeParent($other, true);
        $farAway  = $this->makeParent($otherSchoolChild, true);

        app(AlertPager::class)->page($this->makeAlert($child, ['type' => 'danger', 'level' => 'critical']));

        $this->assertSame(1, AppNotification::where('user_id', $own->id)->count());
        $this->assertSame(0, AppNotification::where('user_id', $stranger->id)->count());
        $this->assertSame(0, AppNotification::where('user_id', $farAway->id)->count());
    }

    public function test_switch_off_sends_nothing_to_parents(): void
    {
        config(['carenest.parent_alerts' => false]);
        $school = $this->school();
        $ref    = $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child, true);
        $alert  = $this->makeAlert($child, ['type' => 'danger', 'level' => 'critical']);

        app(AlertPager::class)->page($alert);

        $this->assertSame(0, AppNotification::where('user_id', $parent->id)->count());
        $this->assertSame(0, AlertNotification::where('recipient_id', $parent->id)->count());
        Mail::assertNotSent(ParentAlertMail::class);
        $this->assertSame(1, AppNotification::where('user_id', $ref->id)->count(), 'Le référent reste prévenu.');
    }

    public function test_parent_is_notified_once_when_alert_is_upgraded_to_vital(): void
    {
        $school = $this->school();
        $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child, true);
        $alert  = $this->makeAlert($child, ['type' => 'harcelement', 'level' => 'high']);

        app(AlertPager::class)->page($alert);
        $alert->update(['type' => 'danger', 'level' => 'critical']);
        app(AlertPager::class)->page($alert->fresh());

        $this->assertSame(1, $this->parentAppRows($parent, $alert->id));
        Mail::assertSent(ParentAlertMail::class, 1);
    }

    public function test_parent_notification_does_not_trigger_escalation_when_school_has_no_referent(): void
    {
        $school = $this->school();
        $child  = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child, true);
        $alert  = $this->makeAlert($child, ['type' => 'harcelement', 'level' => 'high']);

        app(AlertPager::class)->page($alert);
        app(AlertPager::class)->page($alert->fresh());
        $this->assertSame(1, $this->parentAppRows($parent, $alert->id), 'Parent prévenu une seule fois, même sans référent.');

        app(AlertPager::class)->escalate(Carbon::now()->addDays(10));

        $this->assertSame(0, AlertNotification::where('alert_id', $alert->id)->where('escalation_step', '>', 0)->count());
        $this->assertNull($alert->fresh()->escalation_exhausted_at);
    }

    public function test_failed_parent_email_is_traced_as_failure(): void
    {
        $school = $this->school();
        $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child, true);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        $alert  = $this->makeAlert($child, ['type' => 'danger', 'level' => 'critical']);

        app(AlertPager::class)->page($alert);

        $row = AlertNotification::where('alert_id', $alert->id)->where('recipient_id', $parent->id)->where('channel', 'email')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->sent_at);
        $this->assertSame('echec', $row->payload['statut']);
        $this->assertSame('parent', $row->payload['destinataire']);
    }
}
