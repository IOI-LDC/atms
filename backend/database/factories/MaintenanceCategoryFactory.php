<?php

namespace Database\Factories;

use App\Models\MaintenanceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceCategory>
 */
class MaintenanceCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Mud Motor', 'MWD/LWD', 'Drill Bit', 'Completion', 'Jar',
            'Whipstock', 'Rotor', 'Stator', 'Shock Sub', 'Hole Opener',
        ]);

        return [
            'code' => str($name)->upper()->replace(['/', ' '], '_')->toString(),
            'name' => $name,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
