<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'UEC '.fake()->unique()->city(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => 'Rivers',
            'phone' => '+2348012345678',
            'latitude' => 4.8156,
            'longitude' => 7.0498,
            'is_active' => true,
        ];
    }
}
