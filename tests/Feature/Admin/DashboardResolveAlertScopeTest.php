<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Dashboard;
use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * D10 (MVP v3) — cloisonnement multi-école sur Dashboard::resolveAlert().
 */
class DashboardResolveAlertScopeTest extends TestCase
{
    use RefreshDatabase;

    private function makeAlertFor(School $school): Alert
    {
        $child = Child::factory()->for($school)->create();
        $session = ChatSession::create([
            'child_id' => $child->id, 'school_id' => $school->id, 'started_at' => now(),
        ]);
        return Alert::create([
            'session_id' => $session->id, 'child_id' => $child->id, 'school_id' => $school->id,
            'type' => 'isolement', 'level' => 'moderate',
        ]);
    }

    private function adminOf(School $school): User
    {
        $user = User::factory()->create();
        $school->users()->attach($user->id, ['role' => 'director']);
        return $user;
    }

    public function test_admin_of_other_school_cannot_resolve_alert(): void
    {
        $schoolA = School::factory()->create();
        $schoolB = School::factory()->create();
        $alertA = $this->makeAlertFor($schoolA);
        $adminB = $this->adminOf($schoolB);

        Livewire::actingAs($adminB)
            ->test(Dashboard::class)
            ->call('resolveAlert', $alertA->id)
            ->assertForbidden();

        $this->assertSame('unread', $alertA->fresh()->status);
    }

    public function test_admin_of_same_school_can_resolve_alert(): void
    {
        $schoolA = School::factory()->create();
        $alertA = $this->makeAlertFor($schoolA);
        $adminA = $this->adminOf($schoolA);

        Livewire::actingAs($adminA)
            ->test(Dashboard::class)
            ->call('resolveAlert', $alertA->id)
            ->assertOk();

        $this->assertSame('resolved', $alertA->fresh()->status);
    }

    public function test_unknown_alert_returns_404(): void
    {
        $schoolA = School::factory()->create();
        $adminA = $this->adminOf($schoolA);

        Livewire::actingAs($adminA)
            ->test(Dashboard::class)
            ->call('resolveAlert', 999999)
            ->assertNotFound();
    }
}
