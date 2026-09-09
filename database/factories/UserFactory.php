<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Console;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Default state.
     *
     * `status` defaults to 'invited' to match the model, because accounts are
     * issued and only become usable when the invitation is accepted. A test
     * that needs a working account must say so with ->active(), which keeps
     * that step visible rather than assumed.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            // Hashed by the model's `password` => 'hashed' cast.
            'password' => 'password',
            'console' => Console::Estate->value,
            'status' => 'invited',
            'remember_token' => Str::random(10),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }

    public function geminiStaff(): static
    {
        return $this->state(fn () => [
            'console' => Console::Gemini->value,
            'status' => 'active',
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
