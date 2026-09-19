<?php

namespace Tests\Feature\Lot2;

use App\Contracts\SmsSender;
use App\Mail\AlertPagedMail;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\AppNotification;
use App\Models\AuditLog;
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

/** Lot 2 §2 — escalade en heures ouvrées, étapes 5 / 60 / 60 minutes (spec §2.5b, §5.4). */
class EscalationTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    private RecordsSms $sms;
    private School $school;
    private User $ref;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->sms = new RecordsSms();
        $this->app->instance(SmsSender::class, $this->sms);
        $this->school = School::factory()->create();
        SchoolSetting::updateOrCreate(['school_id' => $this->school->id], ['school_hours_start' => '08:00', 'school_hours_end' => '17:00', 'referent_phone' => '0600000001']);
        $this->ref   = $this->makeReferent($this->school, ['email' => 'ref@test.ma']);
        $this->admin = $this->makeAdmin($this->school);
    }

    /** Alerte pagée à l'instant $createdAt (lundi 2026-09-14 10:00 par défaut). */
    private function pagedAlert(string $type, string $level, string $createdAt = '2026-09-14 10:00:00', string $name = 'Omar Démo'): Alert
    {
        Carbon::setTestNow($createdAt);
        $child = Child::factory()->for($this->school)->create(['name' => $name]);
        $alert = $this->makeAlert($child, ['type' => $type, 'level' => $level]);
        app(AlertPager::class)->page($alert);
        Mail::fake();
        $this->sms->sent = [];
        return $alert;
    }

    private function stepRows(Alert $alert, int $step): int
    {
        return AlertNotification::where('alert_id', $alert->id)->where('escalation_step', $step)->count();
    }

    public function test_step1_after_5_business_minutes_relaunches_referent_and_delegate(): void
    {
        // Lot 3 — l'horloge simulée est posée AVANT la délégation (ses dates dérivent de now()),
        // sinon le test dépend du jour réel d'exécution.
        Carbon::setTestNow('2026-09-14 10:00:00');
        $delegate = User::factory()->create(['role' => 'referent']);
        $this->makeActiveDelegation($this->school, $this->ref, $delegate);
        $alert = $this->pagedAlert('harcelement', 'high');

        Carbon::setTestNow('2026-09-14 10:04:00');
        app(AlertPager::class)->escalate();
        $this->assertSame(0, $this->stepRows($alert, 1));

        Carbon::setTestNow('2026-09-14 10:06:00');
        app(AlertPager::class)->escalate();
        $this->assertGreaterThanOrEqual(3, $this->stepRows($alert, 1));
        $this->assertSame(1, AppNotification::where('user_id', $delegate->id)->count());
        Mail::assertSent(AlertPagedMail::class, fn ($m) => $m->hasTo('ref@test.ma'));
        $this->assertCount(1, $this->sms->sent);

        // Journalisée une seule fois.
        app(AlertPager::class)->escalate();
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->where('escalation_step', 1)->where('channel', 'sms')->count());
    }

    /** Spec §5.4 + §2.5b — vital sans accusé depuis 60 min : l'administration est prévenue, nom + type. */
    public function test_step2_after_60_minutes_notifies_admin_on_vital_signal(): void
    {
        $alert = $this->pagedAlert('danger', 'critical', name: 'Omar Démo');

        Carbon::setTestNow('2026-09-14 10:59:00');
        app(AlertPager::class)->escalate();
        $this->assertSame(0, AuditLog::where('action', 'admin.alert.escalation')->where('target_id', $alert->id)->count());

        Carbon::setTestNow('2026-09-14 11:01:00');
        app(AlertPager::class)->escalate();

        $adminNotif = AppNotification::where('user_id', $this->admin->id)->where('type', 'alerte_admin')->latest('id')->first();
        $this->assertNotNull($adminNotif);
        $this->assertStringContainsString('Omar', $adminNotif->body);
        $this->assertStringContainsString('Danger', $adminNotif->body);
        $this->assertSame(1, AuditLog::where('action', 'admin.alert.escalation')->where('target_id', $alert->id)->count());
        $this->assertGreaterThanOrEqual(1, $this->stepRows($alert, 2));

        app(AlertPager::class)->escalate();
        $this->assertSame(1, AuditLog::where('action', 'admin.alert.escalation')->where('target_id', $alert->id)->count());
    }

    /**
     * Spec §5.4 — « strictement limitée à ces deux types de signaux à risque vital » :
     * une alerte NON vitale jamais accusée ne remonte JAMAIS à l'administration ;
     * la chaîne s'arrête à la relance du référent.
     */
    public function test_non_vital_alert_never_reaches_the_administration(): void
    {
        $alert = $this->pagedAlert('harcelement', 'high', name: 'Omar Démo');

        // Bien au-delà des trois paliers (5 / 60 / 60 minutes ouvrées).
        Carbon::setTestNow('2026-09-14 16:00:00');
        app(AlertPager::class)->escalate();

        $this->assertSame(0, AppNotification::where('user_id', $this->admin->id)->where('type', 'alerte_admin')->count());
        $this->assertSame(0, AuditLog::where('action', 'admin.alert.escalation')->where('target_id', $alert->id)->count());
        $this->assertSame(0, AlertNotification::where('alert_id', $alert->id)->where('escalation_step', 2)->whereNotNull('recipient_id')->count());
        // L'étape 1 (relance du référent), elle, a bien eu lieu.
        $this->assertGreaterThanOrEqual(1, $this->stepRows($alert, 1));
    }

    public function test_step3_after_60_minutes_marks_escalation_exhausted_once(): void
    {
        $alert = $this->pagedAlert('harcelement', 'high');

        Carbon::setTestNow('2026-09-14 11:01:00');
        app(AlertPager::class)->escalate();
        $first = $alert->fresh()->escalation_exhausted_at;
        $this->assertNotNull($first);

        Carbon::setTestNow('2026-09-14 11:30:00');
        app(AlertPager::class)->escalate();
        $this->assertTrue($first->equalTo($alert->fresh()->escalation_exhausted_at));
    }

    public function test_non_vital_counter_does_not_run_outside_school_hours(): void
    {
        // Samedi 10:00 : hors jours ouvrés.
        $alert = $this->pagedAlert('harcelement', 'high', '2026-09-12 10:00:00');

        Carbon::setTestNow('2026-09-13 18:00:00');
        app(AlertPager::class)->escalate();
        $this->assertSame(0, $this->stepRows($alert, 1));

        // Lundi 08:06 : 6 minutes ouvrées écoulées depuis l'ouverture.
        Carbon::setTestNow('2026-09-14 08:06:00');
        app(AlertPager::class)->escalate();
        $this->assertGreaterThanOrEqual(1, $this->stepRows($alert, 1));
    }

    public function test_vital_counter_runs_around_the_clock(): void
    {
        $alert = $this->pagedAlert('danger', 'critical', '2026-09-12 22:00:00');

        Carbon::setTestNow('2026-09-12 22:06:00');
        app(AlertPager::class)->escalate();
        $this->assertGreaterThanOrEqual(1, $this->stepRows($alert, 1));
    }

    public function test_acked_alert_is_not_escalated(): void
    {
        $alert = $this->pagedAlert('harcelement', 'high');
        app(AlertPager::class)->ack($alert, $this->ref);

        Carbon::setTestNow('2026-09-14 12:00:00');
        app(AlertPager::class)->escalate();

        $this->assertSame(0, $this->stepRows($alert, 1));
        $this->assertNull($alert->fresh()->escalation_exhausted_at);
    }

    public function test_command_is_scheduled_every_minute(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $match = $events->first(fn ($e) => str_contains((string) $e->command, 'carenest:escalate-alerts'));
        $this->assertNotNull($match);
        $this->assertSame('* * * * *', $match->expression);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
