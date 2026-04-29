<?php
namespace Tests\Unit\Models;

use App\Models\School;
use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_has_settings_relationship(): void
    {
        $school = School::factory()->create();
        $this->assertInstanceOf(SchoolSetting::class, $school->setting);
    }

    public function test_school_has_users_relationship(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create();
        $school->users()->attach($user->id, ['role' => 'director']);

        $this->assertCount(1, $school->users);
        $this->assertEquals('director', $school->users->first()->pivot->role);
    }
}
