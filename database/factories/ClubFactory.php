<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\ClubType;
use App\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Club>
 */
class ClubFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true).' Lodge';

        return [
            'club_type_id' => ClubType::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'active',
        ];
    }

    public function hall(): static
    {
        return $this->state(fn () => ['club_type_id' => ClubType::hall()->id]);
    }

    public function provinceGroup(Province $province): static
    {
        return $this->state(fn () => ['club_type_id' => ClubType::provinceGroup()->id, 'province_id' => $province->id]);
    }

    public function provinceSection(Club $group, ClubType $order): static
    {
        return $this->state(fn () => [
            'club_type_id' => ClubType::provinceSection()->id,
            'province_id' => $group->province_id,
            'parent_club_id' => $group->id,
            'order_club_type_id' => $order->id,
        ]);
    }
}
