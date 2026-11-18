<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan_id' => 'plan_monthly',
            'reference' => 'uec_pay_'.strtolower((string) Str::ulid()),
            'provider' => 'paystack',
            'purpose' => 'subscription',
            'amount_kobo' => 250000,
            'currency' => 'NGN',
            'status' => PaymentStatus::Pending,
            'expires_at' => now()->addHour(),
        ];
    }
}
