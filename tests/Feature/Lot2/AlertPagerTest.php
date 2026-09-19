<?php

namespace Tests\Feature\Lot2;

use App\Contracts\SmsSender;
use App\Mail\AlertPagedMail;
use App\Models\AlertNotification;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Child;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Models\User;
use App\Services\AlertPager;
use App\Services\LogSmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesRoles;
use Tests\Support\RecordsSms;
use Tests\TestCase;

/** Lot 2 §2 — paging à la création d'une alerte. */
class AlertPagerTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    private RecordsSms $sms;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->sms = new RecordsSms();
        $this->app->instance(SmsSender::class, $this->sms);
    }

    private function school(): School
    {
        $school = School::factory()->create();
        SchoolSetting::updateOrCreate(['school_id' => $school->id], ['referent_phone' => '0600000001']);
        return $school;
    }

    public function test_high_alert_pages_referent_app_and_email_without_names(): void
    {
        $school = $this->school();
        $ref    = $this->makeReferent($school, ['email' => 'ref@test.ma']);
        $child  = Child::factory()->for($school)->create(['name' => 'Yassine Démo']);
        $alert  = $this->makeAlert($child, ['type' => 'harcelement', 'level' => 'high']);

        app(AlertPager::class)->page($alert);

        $this->assertSame(1, AppNotification::where('user_id', $ref->id)->count());
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->where('channel', 'app')->where('recipient_id', $ref->id)->where('escalation_step', 0)->count());
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->where('channel', 'email')->where('escalation_step', 0)->count());
        Mail::assertSent(AlertPagedMail::class, function (AlertPagedMail $m) {
            $rendered = $m->render();
            return $m->hasTo('ref@test.ma')
                && str_contains($rendered, 'Une alerte attend votre accusé dans CareNest')
                && ! str_contains($rendered, 'Yassine')
                && ! str_contains($rendered, 'harc');
        });
        $this->assertSame([], $this->sms->sent, 'Pas de SMS sur une alerte non vitale.');
    }

    public function test_moderate_non_vital_alert_is_not_paged(): void
    {
        $school = $this->school();
        $this->makeReferent($school);
        $alert = $this->makeAlert(Child::factory()->for($school)->create(), ['type' => 'stress', 'level' => 'moderate']);

        app(AlertPager::class)->page($alert);

        $this->assertSame(0, AlertNotification::count());
        Mail::assertNothingSent();
    }

    public function test_vital_alert_notifies_admin_delegate_and_sms_simultaneously(): void
    {
        $school   = $this->school();
        $ref      = $this->makeReferent($school);
        $admin    = $this->makeAdmin($school);
        $delegate = User::factory()->create(['role' => 'referent', 'phone' => '0600000009']);
        $this->makeActiveDelegation($school, $ref, $delegate);
        $child = Child::factory()->for($school)->create(['name' => 'Amina Démo']);
        $alert = $this->makeAlert($child, ['type' => 'pensees_negatives', 'level' => 'critical']);

        app(AlertPager::class)->page($alert);

        $adminNotif = AppNotification::where('user_id', $admin->id)->first();
        $this->assertNotNull($adminNotif);
        $this->assertStringContainsString('Amina', $adminNotif->body);
        $this->assertStringContainsString('Pensées négatives', $adminNotif->body);
        $this->assertSame(1, AppNotification::where('user_id', $delegate->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'admin.vital.notify')->where('target_id', $alert->id)->count());
        $this->assertCount(2, $this->sms->sent, 'SMS référent + délégué.');
        foreach ($this->sms->sent as [$phone, $message]) {
            $this->assertStringNotContainsString('Amina', $message);
        }
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->where('channel', 'sms')->where('recipient_id', $ref->id)->count());
    }

    public function test_page_is_idempotent(): void
    {
        $school = $this->school();
        $this->makeReferent($school);
        $alert = $this->makeAlert(Child::factory()->for($school)->create(), ['type' => 'harcelement', 'level' => 'high']);

        app(AlertPager::class)->page($alert);
        app(AlertPager::class)->page($alert);

        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->where('channel', 'app')->count());
    }

    public function test_child_never_receives_anything(): void
    {
        $school = $this->school();
        $this->makeReferent($school);
        $child = Child::factory()->for($school)->create(['email' => 'enfant@test.ma']);
        $alert = $this->makeAlert($child, ['type' => 'danger', 'level' => 'critical']);

        app(AlertPager::class)->page($alert);

        Mail::assertNotSent(AlertPagedMail::class, fn ($m) => $m->hasTo('enfant@test.ma'));
        $this->assertSame(0, AppNotification::whereHas('user', fn ($q) => $q->where('email', 'enfant@test.ma'))->count());
    }

    public function test_log_sms_sender_is_bound_by_default(): void
    {
        $this->app->forgetInstance(SmsSender::class);
        $this->assertInstanceOf(LogSmsSender::class, app()->make(SmsSender::class));
    }

    public function test_ack_marks_referent_notifications(): void
    {
        $school = $this->school();
        $ref    = $this->makeReferent($school);
        $alert  = $this->makeAlert(Child::factory()->for($school)->create(), ['type' => 'harcelement', 'level' => 'high']);
        app(AlertPager::class)->page($alert);

        app(AlertPager::class)->ack($alert, $ref);

        $this->assertSame(0, AlertNotification::where('alert_id', $alert->id)->where('recipient_id', $ref->id)->whereNull('acked_at')->count());
        $this->assertSame('read', $alert->fresh()->status);
    }
}
