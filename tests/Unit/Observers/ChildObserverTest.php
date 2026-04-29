<?php
namespace Tests\Unit\Observers;

use App\Models\Child;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChildObserverTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('ageGroupProvider')]
    public function test_age_group_is_auto_calculated(int $age, string $expectedGroup): void
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create(['age' => $age]);

        $this->assertEquals($expectedGroup, $child->age_group);
    }

    public static function ageGroupProvider(): array
    {
        return [
            'age 5'  => [5,  '5-7'],
            'age 7'  => [7,  '5-7'],
            'age 8'  => [8,  '8-11'],
            'age 11' => [11, '8-11'],
            'age 12' => [12, '12-14'],
            'age 14' => [14, '12-14'],
        ];
    }

    public function test_age_group_updates_when_age_changes(): void
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create(['age' => 7]);
        $this->assertEquals('5-7', $child->age_group);

        $child->update(['age' => 12]);
        $this->assertEquals('12-14', $child->fresh()->age_group);
    }
}
