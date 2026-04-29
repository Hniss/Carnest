<?php
namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ChildFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'     => $this->faker->firstName(),
            'email'    => $this->faker->unique()->safeEmail(),
            'password' => 'password',
            'age'      => $this->faker->numberBetween(5, 14),
            // age_group calculé automatiquement par ChildObserver::creating()
            'classe'   => $this->faker->randomElement(['CE1','CE2','CM1','CM2','5ème','6ème']),
        ];
    }
}
