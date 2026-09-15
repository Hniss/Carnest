<?php

namespace Tests\Unit\Services;

use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Services\TokenBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** D8 — plafond de tokens par enfant et par jour. */
class TokenBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function child(int $cap = 10000): Child
    {
        $school = School::factory()->create();
        SchoolSetting::updateOrCreate(['school_id' => $school->id], ['daily_token_cap' => $cap]);
        return Child::factory()->for($school)->create(['age' => 10]);
    }

    private function makeSession(Child $child, int $tokens, Carbon $startedAt): void
    {
        $s = ChatSession::create([
            'child_id' => $child->id, 'school_id' => $child->school_id,
            'started_at' => $startedAt, 'last_activity_at' => $startedAt, 'tokens_used' => $tokens,
        ]);
        $s->created_at = $startedAt;
        $s->save();
    }

    public function test_used_today_sums_only_sessions_of_the_day(): void
    {
        Carbon::setTestNow('2026-09-15 14:00:00');
        $child = $this->child();
        $this->makeSession($child, 300, Carbon::parse('2026-09-15 09:00:00'));
        $this->makeSession($child, 500, Carbon::parse('2026-09-15 13:30:00'));
        $this->makeSession($child, 9000, Carbon::parse('2026-09-14 23:30:00'));

        $this->assertSame(800, app(TokenBudget::class)->usedToday($child));
    }

    public function test_cap_reads_school_setting_and_defaults_to_10000(): void
    {
        $child = $this->child(4000);
        $this->assertSame(4000, app(TokenBudget::class)->cap($child->school));

        $bare = School::factory()->create();
        $this->assertSame(10000, app(TokenBudget::class)->cap($bare));
    }

    public function test_is_exceeded_when_usage_reaches_cap(): void
    {
        Carbon::setTestNow('2026-09-15 14:00:00');
        $child = $this->child(1000);
        $this->makeSession($child, 999, now());
        $this->assertFalse(app(TokenBudget::class)->isExceeded($child));

        $this->makeSession($child, 1, now());
        $this->assertTrue(app(TokenBudget::class)->isExceeded($child));
    }

    public function test_cap_zero_means_disabled(): void
    {
        Carbon::setTestNow('2026-09-15 14:00:00');
        $child = $this->child(0);
        $this->makeSession($child, 999999, now());

        $this->assertFalse(app(TokenBudget::class)->isExceeded($child));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
