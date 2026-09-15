<?php

namespace Tests\Unit\Models;

use App\Models\Child;
use App\Models\ReferentDelegation;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_helpers_and_home_path(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ref   = User::factory()->create(['role' => 'referent']);
        $par   = User::factory()->create(['role' => 'parent']);

        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($ref->isReferent());
        $this->assertTrue($par->isParent());
        $this->assertFalse($par->isAdmin());
        $this->assertSame('/dashboard', $admin->homePath());
        $this->assertSame('/dashboard-referent', $ref->homePath());
        $this->assertSame('/parent', $par->homePath());
    }

    public function test_school_and_children_relations(): void
    {
        $school = School::factory()->create();
        $ref = User::factory()->create(['role' => 'referent']);
        $school->users()->attach($ref->id, ['role' => 'referent']);
        $this->assertSame($school->id, $ref->school()->id);

        $par = User::factory()->create(['role' => 'parent']);
        $child = Child::factory()->for($school)->create();
        $par->children()->attach($child->id, ['relation' => 'mere', 'consent_given' => true]);
        $this->assertSame($child->id, $par->children()->first()->id);
        $this->assertNull($par->school());
    }

    public function test_delegation_for_school_is_active_only_when_dates_and_activation_match(): void
    {
        $school = School::factory()->create();
        $ref = User::factory()->create(['role' => 'referent']);
        $school->users()->attach($ref->id, ['role' => 'referent']);
        $delegate = User::factory()->create(['role' => 'admin']);

        $this->assertNull($delegate->delegationFor($school));

        $d = ReferentDelegation::create([
            'school_id' => $school->id, 'referent_id' => $ref->id, 'delegate_id' => $delegate->id,
            'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
        ]);
        $this->assertNull($delegate->delegationFor($school), 'non activee');

        $d->update(['activated_at' => now()]);
        $this->assertSame($d->id, $delegate->delegationFor($school)?->id);

        $d->update(['revoked_at' => now()]);
        $this->assertNull($delegate->delegationFor($school), 'revoquee');

        $d->update(['revoked_at' => null, 'end_date' => now()->subDay()->toDateString()]);
        $this->assertNull($delegate->delegationFor($school), 'expiree');
    }
}
