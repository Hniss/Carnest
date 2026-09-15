<?php

namespace Tests\Unit\Models;

use App\Models\Child;
use App\Models\School;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D3 (MVP v3) — Cible 5-18 : tranches 5-7 / 8-11 / 12-18, âge calculé depuis birth_date.
 */
class ChildAgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_age_group_for_covers_the_three_v3_groups(): void
    {
        $this->assertSame('5-7', Child::ageGroupFor(5));
        $this->assertSame('5-7', Child::ageGroupFor(7));
        $this->assertSame('8-11', Child::ageGroupFor(8));
        $this->assertSame('8-11', Child::ageGroupFor(11));
        $this->assertSame('12-18', Child::ageGroupFor(12));
        $this->assertSame('12-18', Child::ageGroupFor(14));
        $this->assertSame('12-18', Child::ageGroupFor(18));
    }

    public function test_age_is_computed_from_birth_date_when_present(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $school = School::factory()->create();

        $child = Child::factory()->for($school)->create([
            'age'        => 6,
            'birth_date' => '2017-03-01',
        ]);

        $this->assertSame(9, $child->fresh()->age);
        $this->assertSame('8-11', $child->fresh()->age_group);
        Carbon::setTestNow();
    }

    public function test_age_falls_back_to_column_without_birth_date(): void
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create(['age' => 13, 'birth_date' => null]);

        $this->assertSame(13, $child->fresh()->age);
        $this->assertSame('12-18', $child->fresh()->age_group);
    }

    public function test_deactivated_at_is_a_datetime_and_nullable(): void
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create();
        $this->assertNull($child->deactivated_at);

        $child->update(['deactivated_at' => now()]);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $child->fresh()->deactivated_at);
    }
}
