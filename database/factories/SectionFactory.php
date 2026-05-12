<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Section>
 */
class SectionFactory extends Factory
{
    public function definition(): array
    {
        $week = fake()->numberBetween(1, 12);

        return [
            'course_id' => Course::factory(),
            'title' => "Minggu {$week} 第 {$week} 堂课 (".fake()->date('Y-m-d').')',
            'description' => fake()->optional()->sentence(),
            'sort_order' => $week,
            'is_published' => true,
        ];
    }
}
