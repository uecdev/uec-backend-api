<?php

namespace Database\Factories;

use App\Models\DevotionalEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DevotionalEntry> */
class DevotionalEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'edition_year' => (int) now(config('uec.timezone'))->year,
            'date' => now(config('uec.timezone'))->toDateString(),
            'title' => fake()->sentence(4),
            'passage' => 'Joshua 1:1-9',
            'memory_verse_text' => fake()->sentence(),
            'memory_verse_ref' => 'Psalm 119:11',
            'body' => "First paragraph.\n\nSecond paragraph with [(v.1-4)](bible:Joshua 1:1-4).",
            'declaration' => fake()->sentence(),
            'prayer' => fake()->sentence(),
        ];
    }
}
