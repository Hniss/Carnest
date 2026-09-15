<?php

namespace Tests\Unit\Observers;

use App\Models\Alert;
use App\Models\AlertLifecycle;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Lot 1 — réouverture automatique : nouvelle alerte high/critical < 30 j après une clôture. */
class AlertReopeningTest extends TestCase
{
    use RefreshDatabase;

    private function alertFor(Child $child, string $level, $createdAt = null): Alert
    {
        $session = ChatSession::create(['child_id' => $child->id, 'school_id' => $child->school_id, 'started_at' => now()]);
        $alert = Alert::create([
            'session_id' => $session->id, 'child_id' => $child->id, 'school_id' => $child->school_id,
            'type' => 'detresse', 'level' => $level,
        ]);
        if ($createdAt) {
            $alert->forceFill(['created_at' => $createdAt])->save();
        }
        return $alert;
    }

    private function close(Alert $alert, $when): void
    {
        AlertLifecycle::create(['alert_id' => $alert->id, 'status' => 'cloture', 'changed_by' => User::factory()->create()->id, 'changed_at' => $when]);
        $alert->update(['status' => 'resolved']);
    }

    public function test_high_alert_within_30_days_of_a_closure_is_marked_reopened(): void
    {
        $child = Child::factory()->for(School::factory()->create())->create();
        $old = $this->alertFor($child, 'moderate', now()->subDays(20));
        $this->close($old, now()->subDays(10));

        $new = $this->alertFor($child, 'high');

        $this->assertSame($old->id, $new->fresh()->reopened_from_id);
    }

    public function test_low_alert_or_old_closure_is_not_reopened(): void
    {
        $child = Child::factory()->for(School::factory()->create())->create();
        $old = $this->alertFor($child, 'moderate', now()->subDays(60));
        $this->close($old, now()->subDays(40));

        $this->assertNull($this->alertFor($child, 'critical')->fresh()->reopened_from_id, 'clôture trop ancienne');

        $recent = $this->alertFor($child, 'moderate', now()->subDays(5));
        $this->close($recent, now()->subDays(2));
        $this->assertNull($this->alertFor($child, 'moderate')->fresh()->reopened_from_id, 'niveau insuffisant');
    }

    public function test_other_child_closure_does_not_reopen(): void
    {
        $school = School::factory()->create();
        $a = Child::factory()->for($school)->create();
        $b = Child::factory()->for($school)->create();
        $this->close($this->alertFor($a, 'moderate'), now()->subDay());

        $this->assertNull($this->alertFor($b, 'critical')->fresh()->reopened_from_id);
    }
}
