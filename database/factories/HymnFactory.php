<?php

namespace Database\Factories;

use App\Models\Hymn;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Hymn> */
class HymnFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => fake()->unique()->numberBetween(1, 9999),
            'title' => fake()->sentence(4),
            'first_line' => fake()->sentence(6),
            'author' => fake()->name(),
            'scripture_refs' => ['Psalm 23:1'],
            'tags' => ['Praise'],
            'published_at' => now()->subDay(),
        ];
    }

    public function unpublished(): static
    {
        return $this->state(fn () => ['published_at' => null]);
    }
}
