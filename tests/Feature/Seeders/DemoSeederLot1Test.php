<?php

namespace Tests\Feature\Seeders;

use App\Models\Alert;
use App\Models\AlertAction;
use App\Models\AlertLifecycle;
use App\Models\Child;
use App\Models\FollowUp;
use App\Models\ParentSynthesis;
use App\Models\ParentThread;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Lot 1 §8 — seeder de démonstration : référent, parent consentant, alertes traitées, fil, synthèse. */
class DemoSeederLot1Test extends TestCase
{
    use RefreshDatabase;

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
            return;
        }
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public function test_seeder_creates_referent_parent_and_treated_alerts(): void
    {
        $this->setEnv('DEMO_ADMIN_PASSWORD', 'AdminTestPwd!2026');
        $this->setEnv('DEMO_CHILD_PASSWORD', 'ChildTestPwd!2026');
        try {
            $this->seed(DatabaseSeeder::class);
        } finally {
            $this->setEnv('DEMO_ADMIN_PASSWORD', null);
            $this->setEnv('DEMO_CHILD_PASSWORD', null);
        }

        $ref = User::where('email', 'referent@carenest.ma')->first();
        $this->assertSame('referent', $ref->role);
        $this->assertTrue($ref->schools()->wherePivot('role', 'referent')->exists());
        $this->assertTrue(Hash::check('AdminTestPwd!2026', $ref->password), 'DEMO_ADMIN_PASSWORD sert à tous les adultes démo sans DEMO_STAFF_PASSWORD');

        $parent = User::where('email', 'parent@carenest.ma')->first();
        $this->assertSame('parent', $parent->role);
        $this->assertGreaterThanOrEqual(1, $parent->consentedChildren()->count());

        $this->assertGreaterThanOrEqual(3, Alert::count());
        $this->assertGreaterThanOrEqual(2, Alert::distinct('type')->count('type'));
        $this->assertGreaterThan(0, AlertLifecycle::count());
        $this->assertGreaterThan(0, AlertAction::count());
        $this->assertGreaterThan(0, FollowUp::count());
        $this->assertSame(1, ParentThread::count());
        $this->assertGreaterThanOrEqual(2, ParentThread::first()->messages()->count());
        $this->assertSame(1, ParentSynthesis::count());

        $this->assertStringContainsString('Démo', $parent->name);
        $this->assertTrue(Child::all()->every(fn ($c) => str_contains($c->name, 'Démo')), 'Tous les enfants portent la mention Démo');
    }
}
