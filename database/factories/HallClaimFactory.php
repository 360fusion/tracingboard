<?php

namespace Database\Factories;

use App\Models\HallClaim;
use App\Models\MasonicHall;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HallClaim>
 */
class HallClaimFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'masonic_hall_id' => MasonicHall::factory(),
            'user_id' => User::factory(),
            'status' => HallClaim::PENDING,
            'claimant_role' => 'secretary',
            'message' => 'I am the hall secretary and would like to run our hall here.',
        ];
    }
}
