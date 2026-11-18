<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->firstName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'phone' => '+23480'.fake()->unique()->numerify('########'),
            'phone_verified_at' => now(),
            'password' => static::$password ??= Hash::make('Password123'),
            'locale' => 'en',
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null, 'phone_verified_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['suspended_at' => now(), 'suspension_reason' => 'Test']);
    }

    public function withRole(string $role): static
    {
        return $this->afterCreating(fn (User $u) => $u->assignRole($role));
    }
}
