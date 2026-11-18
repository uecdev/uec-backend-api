<?php

namespace Database\Factories;

use App\Models\SsLesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SsLesson> */
class SsLessonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'manual_year' => (int) now(config('uec.timezone'))->year,
            'number' => fake()->unique()->numberBetween(1, 60),
            'title' => fake()->sentence(3),
            'passage' => 'Matthew 2:1-12',
            'memory_verse_text' => fake()->sentence(),
            'memory_verse_ref' => 'Psalm 119:11',
            'aim' => fake()->sentence(),
            'introduction' => fake()->paragraph(),
            'outline' => [['heading' => 'Point one', 'body' => fake()->paragraph()]],
            'applications' => [fake()->sentence()],
            'conclusion' => fake()->sentence(),
            'revision_questions' => [fake()->sentence().'?'],
        ];
    }
}
