<?php
namespace Tests\Unit\Models;

use App\Models\Child;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChildTest extends TestCase
{
    use RefreshDatabase;

    public function test_child_belongs_to_school(): void
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create();

        $this->assertEquals($school->id, $child->school->id);
    }

    public function test_child_status_defaults_to_ok(): void
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create();

        $this->assertEquals('ok', $child->status);
        $this->assertNull($child->score_enfant);
    }
}
