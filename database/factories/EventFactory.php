<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Event> */
class EventFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->startOfYear()->addDays(fake()->numberBetween(0, 300));

        return [
            'title' => fake()->sentence(3),
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(2)->toDateString(),
            'start_time' => '09:00',
            'description' => fake()->paragraph(),
            'location' => 'Uyo, Akwa Ibom',
            'category' => 'youth',
            'published_at' => now()->subDay(),
        ];
    }
}
