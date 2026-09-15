<?php

namespace Tests\Feature\Lot2;

use App\Jobs\AdjudicateSignal;
use App\Models\Child;
use App\Models\School;
use App\Services\Adjudicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/** Lot 2 §1 — règles de désaccord, échec technique, idempotence. */
class AdjudicateSignalJobTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    private array $history = [['role' => 'user', 'content' => 'je suis seul']];

    private function child(): Child
    {
        return Child::factory()->for(School::factory()->create())->create(['age' => 10]);
    }

    private function fakeAdjudicator(?array $result, bool $throw = false): void
    {
        $mock = Mockery::mock(Adjudicator::class);
        $exp = $mock->shouldReceive('adjudicate');
        $throw ? $exp->andThrow(new \RuntimeException('timeout')) : $exp->andReturn($result);
        $this->app->instance(Adjudicator::class, $mock);
    }

    public function test_agreement_marks_confirmee_and_stores_signals(): void
    {
        $alert = $this->makeAlert($this->child(), ['type' => 'isolement']);
        $this->fakeAdjudicator(['verdict' => 'confirmee', 'zone' => 'orange', 'type' => 'isolement', 'signals' => ['sujet récurrent']]);

        (new AdjudicateSignal($alert->id, $this->history, 'orange', 'isolement'))->handle(app(Adjudicator::class));

        $alert->refresh();
        $this->assertSame('confirmee', $alert->adjudication);
        $this->assertSame(['sujet récurrent'], $alert->signals);
    }

    public function test_disagreement_on_non_vital_marks_a_confirmer(): void
    {
        $alert = $this->makeAlert($this->child(), ['type' => 'isolement']);
        $this->fakeAdjudicator(['verdict' => 'infirmee', 'zone' => 'yellow', 'type' => null, 'signals' => ['contrariété passagère']]);

        (new AdjudicateSignal($alert->id, $this->history, 'orange', 'isolement'))->handle(app(Adjudicator::class));

        $this->assertSame('a_confirmer', $alert->fresh()->adjudication);
        $this->assertSame(['contrariété passagère'], $alert->fresh()->signals);
    }

    public function test_disagreement_on_vital_still_confirmee(): void
    {
        $alert = $this->makeAlert($this->child(), ['type' => 'danger', 'level' => 'critical']);
        $this->fakeAdjudicator(['verdict' => 'infirmee', 'zone' => 'yellow', 'type' => null, 'signals' => []]);

        (new AdjudicateSignal($alert->id, $this->history, 'red', 'danger'))->handle(app(Adjudicator::class));

        $this->assertSame('confirmee', $alert->fresh()->adjudication);
    }

    public function test_technical_failure_marks_a_confirmer_and_low_confidence(): void
    {
        $alert = $this->makeAlert($this->child(), ['type' => 'isolement']);
        $this->fakeAdjudicator(null, throw: true);

        (new AdjudicateSignal($alert->id, $this->history, 'orange', 'isolement'))->handle(app(Adjudicator::class));

        $this->assertSame('a_confirmer', $alert->fresh()->adjudication);
        $this->assertTrue((bool) $alert->session->fresh()->low_confidence);
    }

    public function test_adjudication_runs_only_once_per_alert(): void
    {
        $alert = $this->makeAlert($this->child(), ['type' => 'isolement']);
        $mock = Mockery::mock(Adjudicator::class);
        $mock->shouldReceive('adjudicate')->once()->andReturn(['verdict' => 'confirmee', 'zone' => 'orange', 'type' => 'isolement', 'signals' => []]);
        $this->app->instance(Adjudicator::class, $mock);

        (new AdjudicateSignal($alert->id, $this->history, 'orange', 'isolement'))->handle(app(Adjudicator::class));
        (new AdjudicateSignal($alert->id, $this->history, 'orange', 'isolement'))->handle(app(Adjudicator::class));

        $this->assertSame('confirmee', $alert->fresh()->adjudication);
    }

    public function test_job_is_never_serialised_to_the_database_queue(): void
    {
        $this->assertFalse(is_subclass_of(AdjudicateSignal::class, \Illuminate\Contracts\Queue\ShouldQueue::class));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
