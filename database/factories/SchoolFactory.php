<?php
namespace Database\Factories;

use App\Models\SchoolSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

class SchoolFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'    => $this->faker->company() . ' School',
            'city'    => $this->faker->city(),
            'email'   => $this->faker->unique()->companyEmail(),
            'phone'   => $this->faker->phoneNumber(),
            'address' => $this->faker->address(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($school) {
            SchoolSetting::create(['school_id' => $school->id]);
        });
    }
}
