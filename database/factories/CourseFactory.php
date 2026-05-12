<?php

namespace Database\Factories;

use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->randomElement(['Bahasa Melayu', 'Sejarah', 'Matematik', 'Fizik', 'Kimia', 'Biologi'])
            .' Sem '.fake()->numberBetween(1, 2)
            .' '.fake()->randomElement(['A', 'B', 'C']);

        return [
            'slug' => Str::slug($name).'-'.Str::random(4),
            'code' => strtoupper(Str::random(3).'-'.Str::random(2)),
            'name' => $name,
            'description' => fake()->optional()->sentence(),
            'banner_image' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
