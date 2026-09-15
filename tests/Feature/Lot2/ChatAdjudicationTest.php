<?php

namespace Tests\Feature\Lot2;

use App\Livewire\Child\ChatInterface;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Services\AIService;
use App\Services\Adjudicator;
use App\Services\SessionCloser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Mockery;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/** Lot 2 §1/§2 — déclenchement de l'adjudication (après réponse) et du paging depuis le chat et la clôture. */
class ChatAdjudicationTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    private array $received = [];

    private function fakeAdjudicator(array $result): void
    {
        $mock = Mockery::mock(Adjudicator::class);
        $mock->shouldReceive('adjudicate')->andReturnUsing(function ($messages, $zone, $type) use ($result) {
            $this->received[] = compact('messages', 'zone', 'type');
            return $result;
        });
        $this->app->instance(Adjudicator::class, $mock);
    }

    public function test_realtime_alert_is_adjudicated_after_response_with_in_memory_history(): void
    {
        Mail::fake();
        $school = School::factory()->create();
        $this->makeReferent($school);
        $child = Child::factory()->for($school)->create(['age' => 10]);
        $this->actingAs($child, 'child');
        $this->fakeAdjudicator(['verdict' => 'confirmee', 'zone' => 'orange', 'type' => 'isolement', 'signals' => ['solitude répétée']]);
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('chat')->andReturn(['message' => 'Je comprends.', 'zone' => 'orange', 'alert_type' => 'isolement', 'is_critical' => false, 'low_confidence' => false, 'tokens' => 5, 'model' => 'm']);
        $this->app->instance(AIService::class, $ai);

        $c = Livewire::test(ChatInterface::class)->set('input', 'personne ne joue avec moi')->call('sendMessage')->call('fetchReply');
        $alert = Alert::where('session_id', $c->get('sessionId'))->first();
        $this->assertNotNull($alert);

        // dispatchAfterResponse : le harnais Livewire termine la requête, ce qui exécute le job en mémoire.
        $this->app->terminate();

        $this->assertSame('confirmee', $alert->fresh()->adjudication);
        $this->assertSame(['solitude répétée'], $alert->fresh()->signals);
        $this->assertSame('personne ne joue avec moi', $this->received[0]['messages'][0]['content']);
        $this->assertSame('orange', $this->received[0]['zone']);
        // Aucun message brut ni job sérialisé en base.
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_closure_alert_is_adjudicated_and_paged(): void
    {
        Mail::fake();
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create(['age' => 10]);
        $session = ChatSession::create(['child_id' => $child->id, 'school_id' => $school->id, 'started_at' => now(), 'last_activity_at' => now()]);
        $this->fakeAdjudicator(['verdict' => 'infirmee', 'zone' => 'yellow', 'type' => null, 'signals' => ['contrariété']]);
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('analyzeSession')->andReturn(['summary' => 'Moqueries.', 'zone' => 'orange', 'alert_type' => 'harcelement', 'lowConfidence' => false, 'tokens' => 10, 'model' => 'm']);
        $ai->shouldReceive('generateCareMemory')->andReturn(['memory' => 'Aime dessiner.', 'tokens' => 1, 'model' => 'm']);
        $this->app->instance(AIService::class, $ai);

        app(SessionCloser::class)->close($session, [['role' => 'user', 'content' => 'ils se moquent de moi']], $child, 'orange', 'harcelement');
        $this->app->terminate();

        $alert = Alert::where('session_id', $session->id)->first();
        $this->assertSame('a_confirmer', $alert->adjudication);
        $this->assertSame(['contrariété'], $alert->signals);
        $this->assertSame('Aime dessiner.', $session->fresh()->care_memory);
        // Niveau « moderate » (orange seul) : pas de paging, conformément aux règles.
        $this->assertSame(0, AlertNotification::where('alert_id', $alert->id)->count());
    }

    public function test_closure_vital_alert_is_paged_and_stays_confirmed_despite_disagreement(): void
    {
        Mail::fake();
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create(['age' => 10]);
        $session = ChatSession::create(['child_id' => $child->id, 'school_id' => $school->id, 'started_at' => now(), 'last_activity_at' => now()]);
        $this->fakeAdjudicator(['verdict' => 'infirmee', 'zone' => 'yellow', 'type' => null, 'signals' => []]);
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('analyzeSession')->andReturn(['summary' => 'Violence subie.', 'zone' => 'red', 'alert_type' => 'danger', 'lowConfidence' => false, 'tokens' => 10, 'model' => 'm']);
        $ai->shouldReceive('generateCareMemory')->andReturn(['memory' => '', 'tokens' => 1, 'model' => 'm']);
        $this->app->instance(AIService::class, $ai);

        app(SessionCloser::class)->close($session, [['role' => 'user', 'content' => 'il me frappe']], $child, 'red', 'danger');
        $this->app->terminate();

        $alert = Alert::where('session_id', $session->id)->first();
        $this->assertSame('critical', $alert->level);
        $this->assertSame('confirmee', $alert->adjudication);
        $this->assertNull($session->fresh()->care_memory);
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->where('recipient_id', $ref->id)->where('channel', 'app')->count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
