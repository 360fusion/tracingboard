<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\ProvincialOfficer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProvincialOfficer>
 */
class ProvincialOfficerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'club_id' => Club::factory(),
            'office' => fake()->jobTitle(),
            'name' => fake()->name(),
            'rank' => null,
            'position' => 1,
        ];
    }
}
