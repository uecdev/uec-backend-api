<?php

namespace Database\Factories;

use App\Models\SsManual;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SsManual> */
class SsManualFactory extends Factory
{
    public function definition(): array
    {
        $year = (int) now(config('uec.timezone'))->year;

        return [
            'year' => $year,
            'title' => $year.' Manual',
            'theme' => 'Exceeding Great Joy',
            'is_premium' => false,
            'published_at' => now()->subMonth(),
        ];
    }
}
