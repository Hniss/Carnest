<?php

namespace Tests\Feature\Lot2;

use App\Contracts\SmsSender;
use App\Mail\AlertPagedMail;
use App\Models\Child;
use App\Models\School;
use App\Services\AlertPager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesRoles;
use Tests\Support\RecordsSms;
use Tests\TestCase;

/**
 * Lot 3 — l'e-mail de paging part même sans worker de file (QUEUE_CONNECTION=database) :
 * il est envoyé en fin de requête, jamais mis dans la table `jobs`.
 */
class AlertPagerMailDeliveryTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    public function test_paging_email_is_sent_without_queue_worker(): void
    {
        Mail::fake();
        $this->app->instance(SmsSender::class, new RecordsSms());
        config(['queue.default' => 'database']);

        $school = School::factory()->create();
        $this->makeReferent($school, ['email' => 'ref@test.ma']);
        $alert = $this->makeAlert(Child::factory()->for($school)->create(), ['type' => 'harcelement', 'level' => 'high']);

        app(AlertPager::class)->page($alert);
        $this->app->terminate();

        Mail::assertNotQueued(AlertPagedMail::class);
        Mail::assertSent(AlertPagedMail::class, fn (AlertPagedMail $m) => $m->hasTo('ref@test.ma'));
        $this->assertSame(0, DB::table('jobs')->count());
    }
}
