<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional()->numerify('01#-#######'),
            'password' => 'password',
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function student(): static
    {
        $year = now()->year;

        return $this->state(function () use ($year) {
            return [
                'username' => 'STU'.$year.fake()->unique()->numerify('####'),
                'email' => null,
            ];
        })->afterCreating(fn ($user) => $user->assignRole('student'));
    }

    public function teacher(): static
    {
        return $this->afterCreating(fn ($user) => $user->assignRole('teacher'));
    }

    public function admin(): static
    {
        return $this->afterCreating(fn ($user) => $user->assignRole('admin'));
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
