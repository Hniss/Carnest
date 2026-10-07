<?php

namespace Tests\Feature\ParentAlerts;

use App\Contracts\SmsSender;
use App\Mail\ParentAlertMail;
use App\Models\AlertNotification;
use App\Models\AppNotification;
use App\Models\Child;
use App\Models\School;
use App\Models\User;
use App\Services\AlertPager;
use App\Services\Notifier;
use App\Support\Humanize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesRoles;
use Tests\Support\RecordsSms;
use Tests\TestCase;

/** Correctifs après audit de 98fc6e2 (hp-v2nf) : ordre du circuit, statut des e-mails, élève désactivé, élision. */
class ParentAlertHardeningTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->app->instance(SmsSender::class, new RecordsSms());
        config(['carenest.parent_alerts' => true]);
    }

    public function test_vital_alert_with_consenting_parent_still_escalates_when_referent_does_not_ack(): void
    {
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        $admin  = $this->makeAdmin($school);
        $child  = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child, true);
        $alert  = $this->makeAlert($child, ['type' => 'danger', 'level' => 'critical']);

        app(AlertPager::class)->page($alert);
        $this->assertSame(1, AppNotification::where('user_id', $parent->id)->count());

        app(AlertPager::class)->escalate(Carbon::parse($alert->created_at)->addMinutes(61));

        $this->assertTrue(AlertNotification::where('alert_id', $alert->id)->where('escalation_step', 1)->where('recipient_id', $ref->id)->exists(), 'Relance du référent (étape 1).');
        $this->assertTrue(AlertNotification::where('alert_id', $alert->id)->where('escalation_step', 2)->where('recipient_id', $admin->id)->exists(), 'Administration prévenue (étape 2).');
        $this->assertSame(0, AlertNotification::where('recipient_id', $parent->id)->where('escalation_step', '>', 0)->count(), 'Le parent ne reçoit pas les relances.');
    }

    public function test_parent_failure_never_prevents_referent_and_vital_chain(): void
    {
        $this->app->instance(Notifier::class, new class extends Notifier {
            public function notify(User $user, string $type, string $title, ?string $body = null, ?string $link = null): \App\Models\AppNotification
            {
                if ($user->role === 'parent') {
                    throw new \RuntimeException('panne côté parent');
                }

                return parent::notify($user, $type, $title, $body, $link);
            }
        });

        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        $admin  = $this->makeAdmin($school);
        $child  = Child::factory()->for($school)->create();
        $this->makeParent($child, true);
        $alert  = $this->makeAlert($child, ['type' => 'pensees_negatives', 'level' => 'critical']);

        app(AlertPager::class)->page($alert);

        $this->assertSame(1, AppNotification::where('user_id', $ref->id)->count());
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->count());
        $this->assertSame(2, (int) $alert->fresh()->paged_tier);
    }

    public function test_email_with_log_mailer_is_traced_as_not_sent(): void
    {
        config(['mail.default' => 'log']);
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school, ['email' => 'ref@test.ma']);
        $child  = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child, true);
        $alert  = $this->makeAlert($child, ['type' => 'harcelement', 'level' => 'high']);

        app(AlertPager::class)->page($alert);

        $rows = AlertNotification::where('alert_id', $alert->id)->where('channel', 'email')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNull($row->sent_at);
            $this->assertSame('non_envoye', $row->payload['statut']);
            $this->assertSame('mailer_log', $row->payload['motif']);
        }
    }

    public function test_email_with_array_mailer_is_traced_as_not_sent(): void
    {
        config(['mail.default' => 'array']);
        $school = School::factory()->create();
        $this->makeReferent($school, ['email' => 'ref@test.ma']);
        $alert = $this->makeAlert(Child::factory()->for($school)->create(), ['type' => 'harcelement', 'level' => 'high']);

        app(AlertPager::class)->page($alert);

        $row = AlertNotification::where('alert_id', $alert->id)->where('channel', 'email')->first();
        $this->assertNull($row->sent_at);
        $this->assertSame('non_envoye', $row->payload['statut']);
        $this->assertSame('mailer_array', $row->payload['motif']);
    }

    public function test_email_with_real_mailer_is_traced_as_sent(): void
    {
        config(['mail.default' => 'smtp']);
        $school = School::factory()->create();
        $this->makeReferent($school, ['email' => 'ref@test.ma']);
        $alert = $this->makeAlert(Child::factory()->for($school)->create(), ['type' => 'harcelement', 'level' => 'high']);

        app(AlertPager::class)->page($alert);

        $row = AlertNotification::where('alert_id', $alert->id)->where('channel', 'email')->first();
        $this->assertNotNull($row->sent_at);
        $this->assertSame('envoye', $row->payload['statut']);
    }

    public function test_deactivated_child_alerts_are_neither_notified_nor_visible_to_the_parent(): void
    {
        $school = School::factory()->create();
        $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child, true);
        $seen   = $this->makeAlert($child, ['type' => 'harcelement', 'level' => 'high']);
        app(AlertPager::class)->page($seen);

        $child->forceFill(['deactivated_at' => now()])->save();
        $later = $this->makeAlert($child->fresh(), ['type' => 'danger', 'level' => 'critical']);
        app(AlertPager::class)->page($later);

        $this->assertSame(0, AlertNotification::where('alert_id', $later->id)->where('recipient_id', $parent->id)->count());
        $this->actingAs($parent)->get('/parent/alertes')->assertOk()->assertDontSee('/parent/alertes/' . $seen->id, false);
        $this->actingAs($parent)->get('/parent/alertes/' . $seen->id)->assertForbidden();
    }

    public function test_elision_only_before_a_vowel(): void
    {
        $this->assertSame('de Yanis', Humanize::de('Yanis'));
        $this->assertSame('de Yassine', Humanize::de('Yassine'));
        $this->assertSame('de Hamza', Humanize::de('Hamza'));
        $this->assertSame("d'Amina", Humanize::de('Amina'));
        $this->assertSame("d'Élise", Humanize::de('Élise'));
        $this->assertSame("d'Omar", Humanize::de('Omar'));
    }
}
