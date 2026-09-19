<?php

namespace Tests\Feature\Schema;

use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Lot 0 (MVP v3) — extensions de schéma (D3, D7, D8, D9 préparation).
 */
class SchemaV3Test extends TestCase
{
    use RefreshDatabase;

    public function test_children_has_v3_columns(): void
    {
        foreach (['birth_date', 'deactivated_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('children', $col), "Missing column: $col");
        }
    }

    public function test_children_accepts_age_group_12_18(): void
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create(['age' => 16]);
        $this->assertSame('12-18', $child->fresh()->age_group);
    }

    public function test_chat_sessions_has_v3_columns(): void
    {
        foreach (['tokens_used', 'prompt_version', 'model', 'care_memory'] as $col) {
            $this->assertTrue(Schema::hasColumn('chat_sessions', $col), "Missing column: $col");
        }
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create();
        $session = ChatSession::create([
            'child_id' => $child->id, 'school_id' => $school->id, 'started_at' => now(),
        ]);
        $this->assertSame(0, $session->fresh()->tokens_used);
    }

    public function test_alerts_has_v3_columns_and_adjudication_default(): void
    {
        foreach (['summary', 'signals', 'prompt_version', 'model', 'adjudication'] as $col) {
            $this->assertTrue(Schema::hasColumn('alerts', $col), "Missing column: $col");
        }
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create();
        $session = ChatSession::create([
            'child_id' => $child->id, 'school_id' => $school->id, 'started_at' => now(),
        ]);
        $alert = Alert::create([
            'session_id' => $session->id, 'child_id' => $child->id, 'school_id' => $school->id,
            'type' => 'pensees_negatives', 'level' => 'critical',
            'signals' => ['mots' => 2],
        ]);
        $alert->refresh();
        $this->assertSame('non_applicable', $alert->adjudication);
        $this->assertSame(['mots' => 2], $alert->signals);
        $this->assertSame('pensees_negatives', $alert->type);
    }

    public function test_school_settings_has_v3_columns_with_defaults(): void
    {
        foreach ([
            'school_hours_start', 'school_hours_end', 'daily_token_cap', 'referent_phone',
        ] as $col) {
            $this->assertTrue(Schema::hasColumn('school_settings', $col), "Missing column: $col");
        }
        $school = School::factory()->create();
        $setting = SchoolSetting::where('school_id', $school->id)->first();
        $this->assertSame(10000, $setting->daily_token_cap);
        $this->assertStringStartsWith('08:00', (string) $setting->school_hours_start);
        $this->assertStringStartsWith('17:00', (string) $setting->school_hours_end);
    }

    public function test_users_has_role_and_phone(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'role'));
        $this->assertTrue(Schema::hasColumn('users', 'phone'));
        $user = User::factory()->create();
        $this->assertSame('admin', $user->fresh()->role);
        $user->update(['role' => 'referent', 'phone' => '+212600000000']);
        $this->assertSame('referent', $user->fresh()->role);
    }

    public function test_school_user_accepts_referent_role(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create();
        $school->users()->attach($user->id, ['role' => 'referent']);
        $this->assertSame('referent', $school->users()->first()->pivot->role);
    }
}
