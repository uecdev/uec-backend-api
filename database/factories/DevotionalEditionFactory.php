<?php

namespace Database\Factories;

use App\Models\DevotionalEdition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DevotionalEdition> */
class DevotionalEditionFactory extends Factory
{
    public function definition(): array
    {
        $year = (int) now(config('uec.timezone'))->year;

        return [
            'year' => $year,
            'title' => $year.' Edition',
            'theme' => 'Exceeding Great Joy',
            'is_premium' => false,
            'published_at' => now()->subMonth(),
        ];
    }
}
