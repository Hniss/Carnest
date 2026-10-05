<?php

namespace Tests\Feature\SuperAdmin;

use App\Livewire\SuperAdmin\Dashboard;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/**
 * Tableau de bord des fondateurs : chiffres agrégés par école, jamais un nom d'enfant ni une
 * conversation ; « jetons échangés » (pas la consommation facturée) ; jours où le plafond a été
 * atteint sur 7 jours, sans étiquette « anormal ». Journal : connexion, échec, consultation.
 */
class DashboardAndJournalTest extends TestCase
{
    use CreatesRoles;
    use RefreshDatabase;

    private function chatSession(Child $child, int $tokens, string $when): ChatSession
    {
        return ChatSession::create([
            'child_id' => $child->id, 'school_id' => $child->school_id,
            'started_at' => $when, 'ended_at' => $when, 'zone' => 'green', 'tokens_used' => $tokens,
        ]);
    }

    public function test_dashboard_shows_aggregates_and_never_a_child_name(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
        $sa = User::factory()->create(['role' => 'superadmin']);
        $school = School::factory()->create(['name' => 'École pilote Test']);
        SchoolSetting::updateOrCreate(['school_id' => $school->id], ['daily_token_cap' => 1000]);
        $a = Child::factory()->for($school)->create(['name' => 'Prenom Confidentiel A']);
        $b = Child::factory()->for($school)->create(['name' => 'Prenom Confidentiel B']);

        $this->chatSession($a, 600, '2026-10-09 10:00:00');
        $this->chatSession($a, 500, '2026-10-09 15:00:00'); // A atteint le plafond le 09
        $this->chatSession($a, 1200, '2026-10-08 10:00:00'); // A atteint le plafond le 08
        $this->chatSession($b, 100, '2026-10-09 11:00:00');
        $this->chatSession($b, 5000, '2026-09-20 11:00:00'); // ancienne unité du compteur : exclu

        $html = $this->actingAs($sa)->get('/superadmin')->assertOk()->getContent();

        $this->assertStringNotContainsString('Prenom Confidentiel', $html);
        $this->assertStringContainsString('École pilote Test', $html);
        $this->assertStringContainsString('jetons échangés', $html);
        $this->assertStringNotContainsString('anormal', mb_strtolower($html));

        $row = Livewire::actingAs($sa)->test(Dashboard::class)->set('period', '7')->viewData('rows')->firstWhere('school_id', $school->id);
        $this->assertSame(4, $row['sessions']);
        $this->assertSame(2, $row['pupils']);
        $this->assertSame(2400, $row['tokens']);
        $this->assertSame(1000, $row['cap']);
        $this->assertSame(2, $row['cap_days']);
        $this->assertSame(1, $row['cap_pupils']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.view.dashboard', 'actor_id' => $sa->id]);
    }

    public function test_superadmin_login_logout_and_failed_login_are_journaled(): void
    {
        $sa = User::factory()->create(['role' => 'superadmin', 'email' => 'fondateur@example.test']);

        Volt::test('pages.auth.login')->set('form.email', 'fondateur@example.test')->set('form.password', 'mauvais')->call('login');
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.login_failed', 'target_id' => $sa->id, 'actor_id' => null]);

        Volt::test('pages.auth.login')->set('form.email', 'fondateur@example.test')->set('form.password', 'password')->call('login');
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'superadmin.login')->where('actor_id', $sa->id)->count(), 'Une connexion = une seule ligne de journal.');

        $this->actingAs($sa)->post('/logout');
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.logout', 'actor_id' => $sa->id]);
    }

    public function test_school_admin_login_is_not_journaled_as_superadmin(): void
    {
        $admin = $this->makeAdmin(School::factory()->create());

        Volt::test('pages.auth.login')->set('form.email', $admin->email)->set('form.password', 'password')->call('login');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'superadmin.login']);
    }
}
