<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan_id' => 'plan_monthly',
            'status' => SubscriptionStatus::Active,
            'provider' => 'paystack',
            'provider_customer_code' => 'CUS_'.fake()->bothify('????####'),
            'authorization' => ['token' => 'AUTH_test', 'brand' => 'visa', 'last4' => '4081', 'reusable' => true],
            'started_at' => now()->subDays(10),
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
            'next_billing_date' => now()->addDays(20),
        ];
    }
}
