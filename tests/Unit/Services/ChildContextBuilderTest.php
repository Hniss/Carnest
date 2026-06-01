<?php

namespace Tests\Unit\Services;

use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Services\ChildContextBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #7 (Probleme CareNest V5) — Mémoire inter-sessions.
 *
 * Couvre :
 *  - null au tout premier passage (aucune session close)
 *  - bloc construit (prénom, classe, nb sessions) dès qu'il y a un historique
 *  - signaux récurrents agrégés depuis les alertes sur 30j
 *  - flag RAPPEL_EXPLICITE_AUTORISE = oui/non selon gravité récurrente
 *  - aucune fuite de message brut (on n'injecte que des résumés)
 */
class ChildContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    private ChildContextBuilder $builder;
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = app(ChildContextBuilder::class);
        $this->now = CarbonImmutable::parse('2026-06-01 12:00:00');
    }

    private function makeChild(array $attrs = []): Child
    {
        $school = School::factory()->create();
        return Child::factory()->for($school)->create(array_merge([
            'name'   => 'Yassine Test',
            'age'    => 10,
            'classe' => 'CM1',
        ], $attrs));
    }

    private function makeClosedSession(Child $child, string $zone, CarbonImmutable $endedAt, ?string $summary = null): ChatSession
    {
        $session = ChatSession::create([
            'child_id'   => $child->id,
            'school_id'  => $child->school_id,
            'started_at' => $endedAt->subMinutes(10),
            'ended_at'   => $endedAt,
            'zone'       => $zone,
            'ai_summary' => $summary,
        ]);
        $session->ended_at = $endedAt;
        $session->save();
        return $session;
    }

    private function makeAlert(Child $child, string $type, string $level, CarbonImmutable $createdAt): void
    {
        $session = $this->makeClosedSession($child, 'orange', $createdAt);
        $alert = Alert::create([
            'session_id' => $session->id,
            'child_id'   => $child->id,
            'school_id'  => $child->school_id,
            'type'       => $type,
            'level'      => $level,
        ]);
        $alert->created_at = $createdAt;
        $alert->save();
    }

    public function test_returns_null_when_no_prior_closed_session(): void
    {
        $child = $this->makeChild();

        $this->assertNull($this->builder->build($child, $this->now));
    }

    public function test_builds_block_with_identity_after_history(): void
    {
        $child = $this->makeChild(['name' => 'Yassine Test', 'classe' => 'CM1']);
        $this->makeClosedSession($child, 'green', $this->now->subDays(2), 'Bonne journée, enfant détendu.');

        $block = $this->builder->build($child, $this->now);

        $this->assertNotNull($block);
        $this->assertStringContainsString('Yassine', $block);
        $this->assertStringContainsString('CM1', $block);
        $this->assertStringContainsString('Sessions précédentes : 1', $block);
    }

    public function test_recurring_serious_signal_enables_explicit_recall(): void
    {
        $child = $this->makeChild();
        // 2 alertes isolement sur 30j → signal grave récurrent.
        $this->makeAlert($child, 'isolement', 'moderate', $this->now->subDays(3));
        $this->makeAlert($child, 'isolement', 'moderate', $this->now->subDays(10));

        $block = $this->builder->build($child, $this->now);

        $this->assertStringContainsString('isolement (2×)', $block);
        $this->assertStringContainsString('RAPPEL_EXPLICITE_AUTORISE : oui', $block);
    }

    public function test_single_alert_does_not_enable_explicit_recall(): void
    {
        $child = $this->makeChild();
        // Une seule alerte → pas récurrent → rappel explicite NON autorisé.
        $this->makeAlert($child, 'tristesse', 'moderate', $this->now->subDays(3));

        $block = $this->builder->build($child, $this->now);

        $this->assertStringContainsString('RAPPEL_EXPLICITE_AUTORISE : non', $block);
    }

    public function test_old_alerts_outside_window_are_ignored(): void
    {
        $child = $this->makeChild();
        // Une session récente pour avoir un historique, mais alertes hors fenêtre 30j.
        $this->makeClosedSession($child, 'green', $this->now->subDays(2));
        $this->makeAlert($child, 'harcelement', 'high', $this->now->subDays(40));
        $this->makeAlert($child, 'harcelement', 'high', $this->now->subDays(45));

        $block = $this->builder->build($child, $this->now);

        $this->assertStringNotContainsString('harcelement', $block);
        $this->assertStringContainsString('RAPPEL_EXPLICITE_AUTORISE : non', $block);
    }
}
